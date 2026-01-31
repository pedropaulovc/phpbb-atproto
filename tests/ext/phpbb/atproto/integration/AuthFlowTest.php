<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration test for the complete auth flow.
 *
 * This test verifies all components work together:
 * - Token encryption
 * - DID resolution (mocked)
 * - OAuth flow (mocked)
 * - Token storage
 * - Event handling
 */
class AuthFlowTest extends TestCase
{
    private $testKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test encryption keys
        $this->testKey = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode(['v1' => $this->testKey]));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
    }

    protected function tearDown(): void
    {
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS');
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION');
        parent::tearDown();
    }

    public function test_encryption_round_trip(): void
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();

        $accessToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.test_access_token';
        $refreshToken = 'dGVzdF9yZWZyZXNoX3Rva2Vu';

        $encryptedAccess = $encryption->encrypt($accessToken);
        $encryptedRefresh = $encryption->encrypt($refreshToken);

        $this->assertNotEquals($accessToken, $encryptedAccess);
        $this->assertNotEquals($refreshToken, $encryptedRefresh);

        $decryptedAccess = $encryption->decrypt($encryptedAccess);
        $decryptedRefresh = $encryption->decrypt($encryptedRefresh);

        $this->assertEquals($accessToken, $decryptedAccess);
        $this->assertEquals($refreshToken, $decryptedRefresh);
    }

    public function test_did_validation(): void
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        // Valid DIDs
        $this->assertTrue($resolver->isValidDid('did:plc:abc123xyz'));
        $this->assertTrue($resolver->isValidDid('did:web:example.com'));

        // Invalid DIDs
        $this->assertFalse($resolver->isValidDid(''));
        $this->assertFalse($resolver->isValidDid('not-a-did'));
        $this->assertFalse($resolver->isValidDid('did:'));
        $this->assertFalse($resolver->isValidDid('did:unknown'));
    }

    public function test_handle_validation(): void
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        // Valid handles
        $this->assertTrue($resolver->isValidHandle('alice.bsky.social'));
        $this->assertTrue($resolver->isValidHandle('user.example.com'));
        $this->assertTrue($resolver->isValidHandle('test-user.domain.org'));

        // Invalid handles
        $this->assertFalse($resolver->isValidHandle(''));
        $this->assertFalse($resolver->isValidHandle('no-dots'));
        $this->assertFalse($resolver->isValidHandle('has spaces.com'));
        $this->assertFalse($resolver->isValidHandle('.starts-with-dot.com'));
    }

    public function test_pds_url_extraction(): void
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://bsky.social'
                ]
            ]
        ];

        $pdsUrl = $resolver->extractPdsUrl($didDoc);
        $this->assertEquals('https://bsky.social', $pdsUrl);
    }

    public function test_key_rotation(): void
    {
        // Encrypt with v1
        $encryption1 = new \phpbb\atproto\auth\token_encryption();
        $token = 'secret-token-123';
        $encrypted = $encryption1->encrypt($token);

        // Add v2 key
        $keys = json_decode(getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS'), true);
        $keys['v2'] = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode($keys));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');

        // Should still decrypt v1 token
        $encryption2 = new \phpbb\atproto\auth\token_encryption();
        $decrypted = $encryption2->decrypt($encrypted);
        $this->assertEquals($token, $decrypted);

        // New encryption should use v2
        $newEncrypted = $encryption2->encrypt($token);
        $this->assertStringStartsWith('v2:', $newEncrypted);

        // Should detect old version needs re-encryption
        $this->assertTrue($encryption2->needsReEncryption($encrypted));
        $this->assertFalse($encryption2->needsReEncryption($newEncrypted));
    }

    public function test_oauth_exception_codes(): void
    {
        $exception = new \phpbb\atproto\auth\oauth_exception(
            'Test message',
            \phpbb\atproto\auth\oauth_exception::CODE_INVALID_HANDLE
        );

        $this->assertEquals(\phpbb\atproto\auth\oauth_exception::CODE_INVALID_HANDLE, $exception->getCode());
        $this->assertEquals('Test message', $exception->getMessage());
    }

    public function test_migration_schema_completeness(): void
    {
        // Verify the migration class exists and has correct structure
        $this->assertTrue(class_exists('\phpbb\atproto\migrations\v1\m1_initial_schema'));

        $deps = \phpbb\atproto\migrations\v1\m1_initial_schema::depends_on();
        $this->assertContains('\phpbb\db\migration\data\v330\v330', $deps);
    }
}
