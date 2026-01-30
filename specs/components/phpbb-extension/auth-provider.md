# Component: Auth Provider

## Overview
- **Purpose**: Handle DID-based authentication using AT Protocol OAuth, manage token storage and refresh
- **Location**: `ext/phpbb/atproto/auth/`
- **Dependencies**: migrations (for `phpbb_atproto_users` table)
- **Dependents**: write-interceptor, config-interceptor, label-display
- **Task**: phpbb-nai

## Acceptance Criteria
- [ ] AC-1: Users can initiate OAuth flow from phpBB login page
- [ ] AC-2: Successful OAuth callback creates/updates user and stores encrypted tokens
- [ ] AC-3: Session is bound to user's DID after authentication
- [ ] AC-4: Expired tokens are automatically refreshed before API calls
- [ ] AC-5: Token refresh failures trigger re-authentication prompt
- [ ] AC-6: Logout clears all AT Protocol tokens
- [ ] AC-7: Tokens are encrypted at rest using XChaCha20-Poly1305
- [ ] AC-8: Key rotation allows decryption of tokens encrypted with old keys

## File Structure
```
ext/phpbb/atproto/
├── auth/
│   ├── provider.php          # Main auth provider (implements auth_provider_interface)
│   ├── oauth_client.php      # AT Protocol OAuth client
│   └── token_encryption.php  # Token encryption/decryption
├── services/
│   ├── token_manager.php     # Token storage and refresh
│   └── did_resolver.php      # DID document resolution
├── controller/
│   └── oauth_controller.php  # OAuth callback handling
├── event/
│   └── auth_listener.php     # phpBB auth event hooks
└── config/
    └── services.yml          # Service definitions
```

## Interface Definitions

### TokenManagerInterface

```php
<?php

namespace phpbb\atproto\services;

interface TokenManagerInterface
{
    /**
     * Get a valid access token for a user, refreshing if necessary.
     *
     * @param int $userId phpBB user ID
     * @return string Valid access token (JWT)
     * @throws TokenNotFoundException When user has no tokens
     * @throws TokenRefreshFailedException When refresh fails
     */
    public function getAccessToken(int $userId): string;

    /**
     * Force refresh the access token using the refresh token.
     *
     * @param int $userId phpBB user ID
     * @return string New access token
     * @throws TokenRefreshFailedException When refresh fails
     */
    public function refreshToken(int $userId): string;

    /**
     * Store tokens for a user after OAuth flow.
     *
     * @param int $userId phpBB user ID
     * @param string $accessToken Access token (will be encrypted)
     * @param string $refreshToken Refresh token (will be encrypted)
     * @param int $expiresAt Unix timestamp when access token expires
     */
    public function storeTokens(
        int $userId,
        string $accessToken,
        string $refreshToken,
        int $expiresAt
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
}
```

### TokenEncryption

```php
<?php

namespace phpbb\atproto\auth;

class TokenEncryption
{
    private array $keys;
    private string $currentVersion;

    public function __construct()
    {
        $keysJson = getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS') ?: '{}';
        $this->keys = json_decode($keysJson, true);
        $this->currentVersion = getenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION') ?: 'v1';

        if (empty($this->keys[$this->currentVersion])) {
            throw new \RuntimeException('Token encryption key not configured');
        }
    }

    /**
     * Encrypt a token for storage.
     *
     * @param string $token Plaintext token
     * @return string Encrypted token in format: version:base64(nonce||ciphertext)
     */
    public function encrypt(string $token): string
    {
        $key = base64_decode($this->keys[$this->currentVersion]);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $token,
            $this->currentVersion, // Additional authenticated data
            $nonce,
            $key
        );
        sodium_memzero($token);
        return $this->currentVersion . ':' . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a stored token.
     *
     * @param string $stored Encrypted token
     * @return string Plaintext token
     * @throws \RuntimeException If decryption fails
     */
    public function decrypt(string $stored): string
    {
        [$version, $payload] = explode(':', $stored, 2);

        if (!isset($this->keys[$version])) {
            throw new \RuntimeException("Unknown encryption key version: $version");
        }

        $key = base64_decode($this->keys[$version]);
        $decoded = base64_decode($payload);
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $version,
            $nonce,
            $key
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Token decryption failed');
        }

        return $plaintext;
    }

    /**
     * Check if a token needs re-encryption with current key.
     */
    public function needsReEncryption(string $stored): bool
    {
        [$version] = explode(':', $stored, 2);
        return $version !== $this->currentVersion;
    }
}
```

### OAuthClient

```php
<?php

namespace phpbb\atproto\auth;

interface OAuthClientInterface
{
    /**
     * Generate OAuth authorization URL.
     *
     * @param string $handle User's handle or DID
     * @param string $state CSRF protection state
     * @return string Authorization URL to redirect user to
     */
    public function getAuthorizationUrl(string $handle, string $state): string;

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code from callback
     * @param string $state State for CSRF validation
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int}
     * @throws OAuthException On failure
     */
    public function exchangeCode(string $code, string $state): array;

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Current refresh token
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws OAuthException On failure
     */
    public function refreshAccessToken(string $refreshToken): array;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `core.user_setup_after` | Check token validity on each request | `$event['user_data']` |
| `core.session_create_after` | Bind session to DID | `$event['session_id']` |
| `core.logout_after` | Clear AT Protocol tokens | `$event['user_id']` |
| `core.auth_login_session_create_before` | Validate DID before session | `$event['login']` |

## Database Interactions

### Tables Used
- `phpbb_atproto_users` - Primary storage for DID mapping and tokens
- `phpbb_users` - phpBB core user table (joined)

### Key Queries

```php
// Store/update user tokens
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_users
        (user_id, did, handle, pds_url, access_token, refresh_token, token_expires_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            handle = VALUES(handle),
            pds_url = VALUES(pds_url),
            access_token = VALUES(access_token),
            refresh_token = VALUES(refresh_token),
            token_expires_at = VALUES(token_expires_at),
            updated_at = VALUES(updated_at)';

// Get user by DID
$sql = 'SELECT u.*, au.did, au.handle, au.pds_url, au.access_token, au.refresh_token, au.token_expires_at
        FROM ' . USERS_TABLE . ' u
        JOIN ' . $this->table_prefix . 'atproto_users au ON u.user_id = au.user_id
        WHERE au.did = ?';

// Check token expiry
$sql = 'SELECT access_token, refresh_token, token_expires_at
        FROM ' . $this->table_prefix . 'atproto_users
        WHERE user_id = ?';

// Clear tokens on logout
$sql = 'UPDATE ' . $this->table_prefix . 'atproto_users
        SET access_token = NULL, refresh_token = NULL, token_expires_at = NULL, updated_at = ?
        WHERE user_id = ?';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/.well-known/oauth-authorization-server` | GET | Discover PDS OAuth endpoints |
| `/oauth/authorize` | GET (redirect) | User authorization |
| `/oauth/token` | POST | Exchange code / refresh token |
| `https://plc.directory/{did}` | GET | Resolve did:plc DIDs |
| `/.well-known/did.json` | GET | Resolve did:web DIDs |

### OAuth Flow

```
1. User enters handle on login page
   │
   ▼
2. Resolve handle → DID → PDS URL
   │
   ▼
3. Fetch PDS OAuth metadata
   GET {pds}/.well-known/oauth-authorization-server
   │
   ▼
4. Redirect to authorization URL
   {pds}/oauth/authorize?
     client_id=...&
     redirect_uri=...&
     state=...&
     scope=atproto
   │
   ▼
5. User approves, redirected back with code
   │
   ▼
6. Exchange code for tokens
   POST {pds}/oauth/token
   │
   ▼
7. Store encrypted tokens, create/update user
   │
   ▼
8. Create phpBB session
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Invalid handle format | `AUTH_INVALID_HANDLE` | Show error, prompt retry |
| DID resolution failed | `AUTH_DID_RESOLUTION_FAILED` | Show error, suggest retry |
| OAuth authorization denied | `AUTH_OAUTH_DENIED` | Return to login with message |
| Token exchange failed | `AUTH_TOKEN_EXCHANGE_FAILED` | Log error, show generic message |
| Token expired, refresh failed | `AUTH_REFRESH_FAILED` | Clear tokens, prompt re-login |
| Encryption key missing | `AUTH_CONFIG_ERROR` | Log critical, prevent login |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `atproto_client_id` | string | (required) | OAuth client identifier |
| `atproto_redirect_uri` | string | (auto) | OAuth callback URL |
| `atproto_token_refresh_buffer` | int | 300 | Seconds before expiry to refresh |
| `atproto_did_cache_ttl` | int | 3600 | DID document cache TTL |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Login with valid handle | OAuth flow completes, session created |
| Login with DID directly | OAuth flow completes, session created |
| Invalid handle format | Error message displayed |
| OAuth denial | Returns to login with denial message |
| Expired token, valid refresh | Token refreshed transparently |
| Expired token, invalid refresh | User prompted to re-login |
| Concurrent refresh requests | Only one refresh executed (locking) |
| Key rotation | Old tokens decrypt, new tokens use new key |
| Logout | Tokens cleared from database |

## Implementation Notes

### Security Considerations
- CSRF protection via state parameter stored in session
- Tokens encrypted at rest using XChaCha20-Poly1305
- Refresh token rotation: new refresh token on each refresh
- Lock user row during refresh to prevent race conditions
- Never log token values
- Validate DID from token matches expected DID

### Performance Considerations
- Cache DID documents for 1 hour
- Check token expiry before API calls to avoid failed requests
- Refresh tokens 5 minutes before expiry (configurable buffer)

### Token Refresh Race Condition Prevention

```php
public function refreshToken(int $userId): string
{
    // Acquire row-level lock
    $sql = 'SELECT refresh_token, token_expires_at
            FROM ' . $this->table_prefix . 'atproto_users
            WHERE user_id = ?
            FOR UPDATE';

    $this->db->sql_query($sql);
    $row = $this->db->sql_fetchrow();

    // Double-check: maybe another request already refreshed
    if ($row['token_expires_at'] > time() + 60) {
        // Token was refreshed by another request
        return $this->getStoredAccessToken($userId);
    }

    // Perform refresh
    $tokens = $this->oauthClient->refreshAccessToken(
        $this->encryption->decrypt($row['refresh_token'])
    );

    // Store new tokens
    $this->storeTokens($userId, $tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']);

    return $tokens['access_token'];
}
```

## References
- [AT Protocol OAuth](https://atproto.com/specs/oauth)
- [DID Resolution](https://atproto.com/specs/did)
- [phpBB Auth Provider Tutorial](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_authentication.html)
- [docs/api-contracts.md](../../../docs/api-contracts.md) - TokenManagerInterface
- [docs/risks.md](../../../docs/risks.md) - S1: Token Storage, S4: Token Refresh Race
