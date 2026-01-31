<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\services;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\token_encryption;
use phpbb\atproto\exceptions\token_not_found_exception;
use phpbb\atproto\exceptions\token_refresh_failed_exception;
use phpbb\atproto\services\token_manager;
use phpbb\atproto\services\token_manager_interface;
use phpbb\db\driver\driver_interface;
use PHPUnit\Framework\TestCase;

class TokenManagerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Save original env
        $this->originalEnv['keys'] = getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS');
        $this->originalEnv['version'] = getenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION');

        // Set test keys
        $testKey = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode(['v1' => $testKey]));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
    }

    protected function tearDown(): void
    {
        // Restore original env
        if ($this->originalEnv['keys'] !== false) {
            putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . $this->originalEnv['keys']);
        } else {
            putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS');
        }
        if ($this->originalEnv['version'] !== false) {
            putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=' . $this->originalEnv['version']);
        } else {
            putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION');
        }
        parent::tearDown();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\token_manager'));
    }

    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists('\phpbb\atproto\services\token_manager_interface'));
    }

    public function test_exceptions_exist(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\exceptions\token_not_found_exception'));
        $this->assertTrue(class_exists('\phpbb\atproto\exceptions\token_refresh_failed_exception'));
    }

    public function test_implements_interface(): void
    {
        $db = $this->createMock(driver_interface::class);
        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertInstanceOf(token_manager_interface::class, $manager);
    }

    public function test_is_token_valid_returns_false_for_nonexistent_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertFalse($manager->isTokenValid(999));
    }

    public function test_is_token_valid_returns_true_for_valid_token(): void
    {
        $futureExpiry = time() + 3600; // 1 hour from now
        $encryption = new token_encryption();
        $encryptedToken = $encryption->encrypt('test-access-token');

        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => $encryptedToken,
            'refresh_token' => $encryptedToken,
            'token_expires_at' => $futureExpiry,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertTrue($manager->isTokenValid(1));
    }

    public function test_is_token_valid_returns_false_for_expired_token(): void
    {
        $pastExpiry = time() - 3600; // 1 hour ago
        $encryption = new token_encryption();
        $encryptedToken = $encryption->encrypt('test-access-token');

        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => $encryptedToken,
            'refresh_token' => $encryptedToken,
            'token_expires_at' => $pastExpiry,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertFalse($manager->isTokenValid(1));
    }

    public function test_get_user_did_returns_did_for_existing_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => time() + 3600,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertEquals('did:plc:test123', $manager->getUserDid(1));
    }

    public function test_get_user_did_returns_null_for_nonexistent_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertNull($manager->getUserDid(999));
    }

    public function test_get_user_pds_url_returns_url_for_existing_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://pds.example.com',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => time() + 3600,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertEquals('https://pds.example.com', $manager->getUserPdsUrl(1));
    }

    public function test_get_user_pds_url_returns_null_for_nonexistent_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertNull($manager->getUserPdsUrl(999));
    }

    public function test_get_access_token_throws_for_nonexistent_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->expectException(token_not_found_exception::class);
        $manager->getAccessToken(999);
    }

    public function test_get_access_token_returns_decrypted_token(): void
    {
        $futureExpiry = time() + 3600; // Well beyond refresh buffer
        $encryption = new token_encryption();
        $originalToken = 'test-access-token-12345';
        $encryptedToken = $encryption->encrypt($originalToken);
        $encryptedRefresh = $encryption->encrypt('test-refresh-token');

        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => $encryptedToken,
            'refresh_token' => $encryptedRefresh,
            'token_expires_at' => $futureExpiry,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertEquals($originalToken, $manager->getAccessToken(1));
    }

    public function test_token_not_found_exception_contains_user_id(): void
    {
        $exception = new token_not_found_exception(42);
        $this->assertEquals(42, $exception->getUserId());
        $this->assertStringContainsString('42', $exception->getMessage());
    }

    public function test_token_refresh_failed_exception_contains_message(): void
    {
        $previous = new \RuntimeException('Network error');
        $exception = new token_refresh_failed_exception('Connection timeout', $previous);

        $this->assertStringContainsString('Connection timeout', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_find_user_by_did_returns_user_id(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(['user_id' => 42]);
        $db->method('sql_freeresult')->willReturn(true);
        $db->method('sql_escape')->willReturnCallback(fn ($s) => $s);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertEquals(42, $manager->findUserByDid('did:plc:test123'));
    }

    public function test_find_user_by_did_returns_null_for_unknown_did(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);
        $db->method('sql_escape')->willReturnCallback(fn ($s) => $s);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertNull($manager->findUserByDid('did:plc:unknown'));
    }

    public function test_get_user_handle_returns_handle(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'alice.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => time() + 3600,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertEquals('alice.bsky.social', $manager->getUserHandle(1));
    }

    public function test_clear_tokens_executes_update_query(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->expects($this->once())
            ->method('sql_query')
            ->with($this->callback(function ($sql) {
                return strpos($sql, 'UPDATE') !== false
                    && strpos($sql, 'access_token = NULL') !== false
                    && strpos($sql, 'refresh_token = NULL') !== false
                    && strpos($sql, 'token_expires_at = NULL') !== false;
            }))
            ->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $manager->clearTokens(1);
    }

    public function test_store_tokens_inserts_new_user(): void
    {
        $db = $this->createMock(driver_interface::class);

        // First query checks if user exists
        $db->expects($this->exactly(2))
            ->method('sql_query')
            ->willReturn(true);

        // First fetchrow returns false (user doesn't exist)
        $db->expects($this->once())
            ->method('sql_fetchrow')
            ->willReturn(false);

        $db->method('sql_freeresult')->willReturn(true);
        $db->method('sql_escape')->willReturnCallback(fn ($s) => $s);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        // Should not throw
        $manager->storeTokens(
            1,
            'did:plc:test123',
            'test.bsky.social',
            'https://bsky.social',
            'access-token',
            'refresh-token',
            3600
        );

        $this->assertTrue(true); // If we get here, test passed
    }

    public function test_store_tokens_updates_existing_user(): void
    {
        $db = $this->createMock(driver_interface::class);

        // Two queries: check existence and update
        $db->expects($this->exactly(2))
            ->method('sql_query')
            ->willReturn(true);

        // First fetchrow returns user (exists)
        $db->expects($this->once())
            ->method('sql_fetchrow')
            ->willReturn(['user_id' => 1]);

        $db->method('sql_freeresult')->willReturn(true);
        $db->method('sql_escape')->willReturnCallback(fn ($s) => $s);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        // Should not throw
        $manager->storeTokens(
            1,
            'did:plc:test123',
            'test.bsky.social',
            'https://bsky.social',
            'access-token',
            'refresh-token',
            3600
        );

        $this->assertTrue(true); // If we get here, test passed
    }

    public function test_get_access_token_triggers_refresh_when_near_expiry(): void
    {
        // Token expires within refresh buffer (300s) and also within the 60s re-check buffer
        // to ensure refresh actually happens
        $expiresAt = time() + 30;  // Within 60s re-check buffer
        $encryption = new token_encryption();
        $encryptedToken = $encryption->encrypt('test-access-token');
        $encryptedRefresh = $encryption->encrypt('test-refresh-token');

        $callCount = 0;
        $db = $this->createMock(driver_interface::class);

        // Set up transaction methods
        $db->method('sql_transaction')->willReturn(true);

        $db->method('sql_query')->willReturn(true);

        // First fetchrow for getTokenRow, second for refreshToken lock
        $db->method('sql_fetchrow')
            ->willReturnCallback(function () use (&$callCount, $encryptedToken, $encryptedRefresh, $expiresAt) {
                $callCount++;
                if ($callCount === 1) {
                    // Initial token row (near expiry)
                    return [
                        'did' => 'did:plc:test123',
                        'handle' => 'test.bsky.social',
                        'pds_url' => 'https://bsky.social',
                        'access_token' => $encryptedToken,
                        'refresh_token' => $encryptedRefresh,
                        'token_expires_at' => $expiresAt,
                    ];
                }

                // Locked row for refresh (still near expiry, needs actual refresh)
                return [
                    'access_token' => $encryptedToken,
                    'refresh_token' => $encryptedRefresh,
                    'token_expires_at' => $expiresAt,  // Still within 60s buffer
                    'pds_url' => 'https://bsky.social',
                ];
            });

        $db->method('sql_freeresult')->willReturn(true);
        $db->method('sql_escape')->willReturnCallback(fn ($s) => $s);

        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->method('refreshAccessToken')
            ->willReturn([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $token = $manager->getAccessToken(1);

        $this->assertEquals('new-access-token', $token);
    }

    public function test_refresh_token_throws_when_user_not_found(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_transaction')->willReturn(true);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->expectException(token_not_found_exception::class);
        $manager->refreshToken(999);
    }

    public function test_refresh_token_returns_cached_when_already_refreshed(): void
    {
        // Token was just refreshed by another request (expires far in future)
        $futureExpiry = time() + 3600;
        $encryption = new token_encryption();
        $encryptedToken = $encryption->encrypt('cached-access-token');
        $encryptedRefresh = $encryption->encrypt('cached-refresh-token');

        $db = $this->createMock(driver_interface::class);
        $db->method('sql_transaction')->willReturn(true);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'access_token' => $encryptedToken,
            'refresh_token' => $encryptedRefresh,
            'token_expires_at' => $futureExpiry,
            'pds_url' => 'https://bsky.social',
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $oauthClient = $this->createMock(oauth_client_interface::class);
        // Should NOT be called since token is still valid
        $oauthClient->expects($this->never())->method('refreshAccessToken');

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $token = $manager->refreshToken(1);

        $this->assertEquals('cached-access-token', $token);
    }

    public function test_refresh_token_throws_on_oauth_failure(): void
    {
        $expiresAt = time() + 30;  // Within the 60s re-check buffer
        $encryption = new token_encryption();
        $encryptedToken = $encryption->encrypt('test-access-token');
        $encryptedRefresh = $encryption->encrypt('test-refresh-token');

        $db = $this->createMock(driver_interface::class);
        $db->method('sql_transaction')->willReturn(true);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'access_token' => $encryptedToken,
            'refresh_token' => $encryptedRefresh,
            'token_expires_at' => $expiresAt,
            'pds_url' => 'https://bsky.social',
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->method('refreshAccessToken')
            ->willThrowException(new \RuntimeException('OAuth server unavailable'));

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->expectException(token_refresh_failed_exception::class);
        $this->expectExceptionMessage('OAuth server unavailable');
        $manager->refreshToken(1);
    }

    public function test_get_user_handle_returns_null_for_nonexistent_user(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn(false);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertNull($manager->getUserHandle(999));
    }

    public function test_is_token_valid_returns_false_when_access_token_null(): void
    {
        $db = $this->createMock(driver_interface::class);
        $db->method('sql_query')->willReturn(true);
        $db->method('sql_fetchrow')->willReturn([
            'did' => 'did:plc:test123',
            'handle' => 'test.bsky.social',
            'pds_url' => 'https://bsky.social',
            'access_token' => null,  // Token is null
            'refresh_token' => null,
            'token_expires_at' => time() + 3600,
        ]);
        $db->method('sql_freeresult')->willReturn(true);

        $encryption = new token_encryption();
        $oauthClient = $this->createMock(oauth_client_interface::class);

        $manager = new token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertFalse($manager->isTokenValid(1));
    }
}
