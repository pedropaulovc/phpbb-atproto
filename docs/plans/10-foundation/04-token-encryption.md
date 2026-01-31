# Task 4: Token Encryption Service

**Files:**
- Create: `ext/phpbb/atproto/auth/token_encryption.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/auth/TokenEncryptionTest.php
<?php

namespace phpbb\atproto\tests\auth;

class TokenEncryptionTest extends \phpbb_test_case
{
    private $originalEnv = [];

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

    public function test_encrypt_returns_versioned_string()
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();
        $encrypted = $encryption->encrypt('test-token');

        $this->assertStringStartsWith('v1:', $encrypted);
    }

    public function test_decrypt_round_trip()
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();
        $original = 'my-secret-token-12345';

        $encrypted = $encryption->encrypt($original);
        $decrypted = $encryption->decrypt($encrypted);

        $this->assertEquals($original, $decrypted);
    }

    public function test_encrypt_produces_different_output_each_time()
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();
        $token = 'same-token';

        $encrypted1 = $encryption->encrypt($token);
        $encrypted2 = $encryption->encrypt($token);

        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function test_needs_reencryption_returns_true_for_old_version()
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();

        $this->assertTrue($encryption->needsReEncryption('v0:somedata'));
        $this->assertFalse($encryption->needsReEncryption('v1:somedata'));
    }

    public function test_key_rotation_decrypts_old_tokens()
    {
        // Encrypt with v1
        $encryption1 = new \phpbb\atproto\auth\token_encryption();
        $token = 'test-token-for-rotation';
        $encrypted = $encryption1->encrypt($token);

        // Add v2 key and set as current
        $keys = json_decode(getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS'), true);
        $keys['v2'] = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode($keys));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v2');

        // New encryption instance should still decrypt v1 token
        $encryption2 = new \phpbb\atproto\auth\token_encryption();
        $decrypted = $encryption2->decrypt($encrypted);

        $this->assertEquals($token, $decrypted);
    }

    public function test_throws_on_missing_key()
    {
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS={}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token encryption key not configured');

        new \phpbb\atproto\auth\token_encryption();
    }

    public function test_throws_on_unknown_version()
    {
        $encryption = new \phpbb\atproto\auth\token_encryption();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown encryption key version');

        $encryption->decrypt('v99:invaliddata');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/TokenEncryptionTest.php`
Expected: FAIL with "Class '\phpbb\atproto\auth\token_encryption' not found"

**Step 3: Create token_encryption.php**

```php
<?php

namespace phpbb\atproto\auth;

/**
 * Token encryption service using XChaCha20-Poly1305.
 *
 * Encrypts OAuth tokens at rest with key rotation support.
 * Format: version:base64(nonce || ciphertext || tag)
 */
class token_encryption
{
    /** @var array<string, string> Key version => base64-encoded 32-byte key */
    private array $keys;

    /** @var string Current key version for encryption */
    private string $currentVersion;

    public function __construct()
    {
        $keysJson = getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS') ?: '{}';
        $this->keys = json_decode($keysJson, true) ?: [];
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

        // Zero out the plaintext token from memory
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
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid encrypted token format');
        }

        [$version, $payload] = $parts;

        if (!isset($this->keys[$version])) {
            throw new \RuntimeException("Unknown encryption key version: $version");
        }

        $key = base64_decode($this->keys[$version]);
        $decoded = base64_decode($payload);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new \RuntimeException('Invalid encrypted token payload');
        }

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
     *
     * @param string $stored Encrypted token
     * @return bool True if encrypted with an older key version
     */
    public function needsReEncryption(string $stored): bool
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) {
            return true;
        }
        return $parts[0] !== $this->currentVersion;
    }

    /**
     * Re-encrypt a token with the current key version.
     *
     * @param string $stored Encrypted token (possibly with old key)
     * @return string Encrypted token with current key version
     */
    public function reEncrypt(string $stored): string
    {
        $plaintext = $this->decrypt($stored);
        return $this->encrypt($plaintext);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/TokenEncryptionTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/auth/token_encryption.php tests/ext/phpbb/atproto/auth/TokenEncryptionTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add XChaCha20-Poly1305 token encryption

- Encrypt OAuth tokens at rest with authenticated encryption
- Support key rotation for seamless key updates
- Format: version:base64(nonce || ciphertext)
- Zero sensitive data from memory after use

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
