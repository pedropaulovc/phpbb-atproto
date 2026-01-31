# Task 7: Token Manager Service

**Files:**
- Create: `ext/phpbb/atproto/services/token_manager.php`
- Create: `ext/phpbb/atproto/services/token_manager_interface.php`
- Create: `ext/phpbb/atproto/exceptions/token_not_found_exception.php`
- Create: `ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/services/TokenManagerTest.php
<?php

namespace phpbb\atproto\tests\services;

class TokenManagerTest extends \phpbb_database_test_case
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test encryption keys
        $testKey = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode(['v1' => $testKey]));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
    }

    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\token_manager'));
    }

    public function test_interface_exists()
    {
        $this->assertTrue(interface_exists('\phpbb\atproto\services\token_manager_interface'));
    }

    public function test_store_and_retrieve_tokens()
    {
        $db = $this->createMock(\phpbb\db\driver\driver_interface::class);
        $encryption = new \phpbb\atproto\auth\token_encryption();
        $oauthClient = $this->createMock(\phpbb\atproto\auth\oauth_client_interface::class);

        $manager = new \phpbb\atproto\services\token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        // Test that class implements interface
        $this->assertInstanceOf(
            \phpbb\atproto\services\token_manager_interface::class,
            $manager
        );
    }

    public function test_is_token_valid_returns_false_for_nonexistent_user()
    {
        $db = $this->createMock(\phpbb\db\driver\driver_interface::class);
        $db->method('sql_fetchrow')->willReturn(false);

        $encryption = new \phpbb\atproto\auth\token_encryption();
        $oauthClient = $this->createMock(\phpbb\atproto\auth\oauth_client_interface::class);

        $manager = new \phpbb\atproto\services\token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertFalse($manager->isTokenValid(999));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/TokenManagerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\services\token_manager' not found"

**Step 3: Create exception classes**

```php
// ext/phpbb/atproto/exceptions/token_not_found_exception.php
<?php

namespace phpbb\atproto\exceptions;

class token_not_found_exception extends \Exception
{
    public function __construct(int $userId)
    {
        parent::__construct("No AT Protocol tokens found for user ID: $userId");
    }
}
```

```php
// ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php
<?php

namespace phpbb\atproto\exceptions;

class token_refresh_failed_exception extends \Exception
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct("Token refresh failed: $message", 0, $previous);
    }
}
```

**Step 4: Create token_manager_interface.php**

```php
<?php

namespace phpbb\atproto\services;

interface token_manager_interface
{
    /**
     * Get a valid access token for a user, refreshing if necessary.
     *
     * @param int $userId phpBB user ID
     * @return string Valid access token (JWT)
     * @throws \phpbb\atproto\exceptions\token_not_found_exception When user has no tokens
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     */
    public function getAccessToken(int $userId): string;

    /**
     * Force refresh the access token using the refresh token.
     *
     * @param int $userId phpBB user ID
     * @return string New access token
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     */
    public function refreshToken(int $userId): string;

    /**
     * Store tokens for a user after OAuth flow.
     *
     * @param int $userId phpBB user ID
     * @param string $did User's DID
     * @param string $handle User's handle
     * @param string $pdsUrl User's PDS URL
     * @param string $accessToken Access token (will be encrypted)
     * @param string $refreshToken Refresh token (will be encrypted)
     * @param int $expiresIn Seconds until access token expires
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void;

    /**
     * Check if user has a valid (non-expired) token.
     *
     * @param int $userId phpBB user ID
     * @return bool True if token exists and isn't expired
     */
    public function isTokenValid(int $userId): bool;

    /**
     * Clear all tokens for a user (logout).
     *
     * @param int $userId phpBB user ID
     */
    public function clearTokens(int $userId): void;

    /**
     * Get the DID associated with a user's tokens.
     *
     * @param int $userId phpBB user ID
     * @return string|null User's DID or null if not linked
     */
    public function getUserDid(int $userId): ?string;

    /**
     * Get the PDS URL for a user.
     *
     * @param int $userId phpBB user ID
     * @return string|null User's PDS URL or null if not linked
     */
    public function getUserPdsUrl(int $userId): ?string;
}
```

**Step 5: Create token_manager.php**

```php
<?php

namespace phpbb\atproto\services;

use phpbb\atproto\auth\token_encryption;
use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\exceptions\token_not_found_exception;
use phpbb\atproto\exceptions\token_refresh_failed_exception;

/**
 * Token manager for AT Protocol OAuth tokens.
 *
 * Handles token storage, retrieval, and automatic refresh.
 * Uses row-level locking to prevent race conditions during refresh.
 */
class token_manager implements token_manager_interface
{
    private \phpbb\db\driver\driver_interface $db;
    private token_encryption $encryption;
    private oauth_client_interface $oauthClient;
    private string $tablePrefix;
    private int $refreshBuffer;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        token_encryption $encryption,
        oauth_client_interface $oauthClient,
        string $tablePrefix,
        int $refreshBuffer = 300
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->oauthClient = $oauthClient;
        $this->tablePrefix = $tablePrefix;
        $this->refreshBuffer = $refreshBuffer;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(int $userId): string
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            throw new token_not_found_exception($userId);
        }

        // Check if token needs refresh
        $expiresAt = (int) $row['token_expires_at'];
        if ($expiresAt <= time() + $this->refreshBuffer) {
            return $this->refreshToken($userId);
        }

        // Decrypt and return access token
        return $this->encryption->decrypt($row['access_token']);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken(int $userId): string
    {
        // Start transaction for row-level locking
        $this->db->sql_transaction('begin');

        try {
            // Lock the row
            $sql = 'SELECT access_token, refresh_token, token_expires_at, pds_url
                    FROM ' . $this->tablePrefix . 'atproto_users
                    WHERE user_id = ' . (int) $userId . '
                    FOR UPDATE';
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if ($row === false) {
                $this->db->sql_transaction('rollback');
                throw new token_not_found_exception($userId);
            }

            // Double-check: maybe another request already refreshed
            $expiresAt = (int) $row['token_expires_at'];
            if ($expiresAt > time() + 60) {
                // Token was refreshed by another request
                $this->db->sql_transaction('commit');
                return $this->encryption->decrypt($row['access_token']);
            }

            // Perform refresh
            $refreshToken = $this->encryption->decrypt($row['refresh_token']);
            $pdsUrl = $row['pds_url'];

            try {
                $tokens = $this->oauthClient->refreshAccessToken($refreshToken, $pdsUrl);
            } catch (\Exception $e) {
                $this->db->sql_transaction('rollback');
                throw new token_refresh_failed_exception($e->getMessage(), $e);
            }

            // Store new tokens
            $newExpiresAt = time() + $tokens['expires_in'];
            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET access_token = \'' . $this->db->sql_escape($this->encryption->encrypt($tokens['access_token'])) . '\',
                        refresh_token = \'' . $this->db->sql_escape($this->encryption->encrypt($tokens['refresh_token'])) . '\',
                        token_expires_at = ' . $newExpiresAt . ',
                        updated_at = ' . time() . '
                    WHERE user_id = ' . (int) $userId;
            $this->db->sql_query($sql);

            $this->db->sql_transaction('commit');

            return $tokens['access_token'];

        } catch (\Exception $e) {
            $this->db->sql_transaction('rollback');
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void {
        $now = time();
        $expiresAt = $now + $expiresIn;

        $encryptedAccess = $this->encryption->encrypt($accessToken);
        $encryptedRefresh = $this->encryption->encrypt($refreshToken);

        // Check if user already exists
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result) !== false;
        $this->db->sql_freeresult($result);

        if ($exists) {
            // Update existing
            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET did = \'' . $this->db->sql_escape($did) . '\',
                        handle = \'' . $this->db->sql_escape($handle) . '\',
                        pds_url = \'' . $this->db->sql_escape($pdsUrl) . '\',
                        access_token = \'' . $this->db->sql_escape($encryptedAccess) . '\',
                        refresh_token = \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                        token_expires_at = ' . $expiresAt . ',
                        updated_at = ' . $now . '
                    WHERE user_id = ' . (int) $userId;
        } else {
            // Insert new
            $sql = 'INSERT INTO ' . $this->tablePrefix . 'atproto_users
                    (user_id, did, handle, pds_url, access_token, refresh_token, token_expires_at, migration_status, created_at, updated_at)
                    VALUES (' . (int) $userId . ',
                            \'' . $this->db->sql_escape($did) . '\',
                            \'' . $this->db->sql_escape($handle) . '\',
                            \'' . $this->db->sql_escape($pdsUrl) . '\',
                            \'' . $this->db->sql_escape($encryptedAccess) . '\',
                            \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                            ' . $expiresAt . ',
                            \'none\',
                            ' . $now . ',
                            ' . $now . ')';
        }

        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenValid(int $userId): bool
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            return false;
        }

        // Token is valid if it exists and won't expire soon
        // (We allow some buffer for the refresh to happen)
        return $row['access_token'] !== null
            && (int) $row['token_expires_at'] > time();
    }

    /**
     * {@inheritdoc}
     */
    public function clearTokens(int $userId): void
    {
        $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                SET access_token = NULL,
                    refresh_token = NULL,
                    token_expires_at = NULL,
                    updated_at = ' . time() . '
                WHERE user_id = ' . (int) $userId;
        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserDid(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);
        return $row ? $row['did'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPdsUrl(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);
        return $row ? $row['pds_url'] : null;
    }

    /**
     * Find phpBB user ID by DID.
     */
    public function findUserByDid(string $did): ?int
    {
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE did = \'' . $this->db->sql_escape($did) . '\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Get token row for a user.
     */
    private function getTokenRow(int $userId): ?array
    {
        $sql = 'SELECT did, handle, pds_url, access_token, refresh_token, token_expires_at
                FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: null;
    }
}
```

**Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/TokenManagerTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add ext/phpbb/atproto/services/token_manager.php ext/phpbb/atproto/services/token_manager_interface.php ext/phpbb/atproto/exceptions/token_not_found_exception.php ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php tests/ext/phpbb/atproto/services/TokenManagerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add token manager for OAuth token lifecycle

- Store and retrieve encrypted tokens
- Automatic token refresh before expiry
- Row-level locking to prevent refresh race conditions
- Find users by DID
- Custom exceptions for error handling

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
