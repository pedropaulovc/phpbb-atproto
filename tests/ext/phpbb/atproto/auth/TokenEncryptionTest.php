<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\auth;

use phpbb\atproto\auth\token_encryption;
use PHPUnit\Framework\TestCase;

class TokenEncryptionTest extends TestCase
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

    public function test_encrypt_returns_versioned_string(): void
    {
        $encryption = new token_encryption();
        $encrypted = $encryption->encrypt('test-token');
        $this->assertStringStartsWith('v1:', $encrypted);
    }

    public function test_decrypt_round_trip(): void
    {
        $encryption = new token_encryption();
        $original = 'my-secret-token-12345';
        $encrypted = $encryption->encrypt($original);
        $decrypted = $encryption->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }

    public function test_encrypt_produces_different_output_each_time(): void
    {
        $encryption = new token_encryption();
        $token = 'same-token';
        $encrypted1 = $encryption->encrypt($token);
        $encrypted2 = $encryption->encrypt($token);
        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function test_needs_reencryption_returns_true_for_old_version(): void
    {
        $encryption = new token_encryption();
        $this->assertTrue($encryption->needsReEncryption('v0:somedata'));
        $this->assertFalse($encryption->needsReEncryption('v1:somedata'));
    }

    public function test_key_rotation_decrypts_old_tokens(): void
    {
        // Encrypt with v1
        $encryption1 = new token_encryption();
        $token = 'test-token-for-rotation';
        $encrypted = $encryption1->encrypt($token);

        // Add v2 key and set as current
        $keys = json_decode(getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS'), true);
        $keys['v2'] = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode($keys));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');

        // New encryption instance should still decrypt v1 token
        $encryption2 = new token_encryption();
        $decrypted = $encryption2->decrypt($encrypted);
        $this->assertEquals($token, $decrypted);
    }

    public function test_reencrypt_updates_to_current_version(): void
    {
        // Encrypt with v1
        $encryption1 = new token_encryption();
        $token = 'test-token-for-reencryption';
        $encryptedV1 = $encryption1->encrypt($token);
        $this->assertStringStartsWith('v1:', $encryptedV1);

        // Add v2 key and set as current
        $keys = json_decode(getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS'), true);
        $keys['v2'] = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode($keys));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');

        // Re-encrypt with new version
        $encryption2 = new token_encryption();
        $encryptedV2 = $encryption2->reEncrypt($encryptedV1);

        // Should now use v2
        $this->assertStringStartsWith('v2:', $encryptedV2);

        // Should still decrypt to original value
        $decrypted = $encryption2->decrypt($encryptedV2);
        $this->assertEquals($token, $decrypted);
    }

    public function test_throws_on_missing_key(): void
    {
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS={}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token encryption key not configured');
        new token_encryption();
    }

    public function test_throws_on_unknown_version(): void
    {
        $encryption = new token_encryption();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown encryption key version');
        $encryption->decrypt('v99:invaliddata');
    }

    public function test_throws_on_invalid_ciphertext(): void
    {
        $encryption = new token_encryption();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');
        $encryption->decrypt('v1:' . base64_encode('invalid-ciphertext-too-short'));
    }

    public function test_throws_on_malformed_stored_value(): void
    {
        $encryption = new token_encryption();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted token format');
        $encryption->decrypt('no-version-prefix');
    }
}
