<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * Token encryption service using XChaCha20-Poly1305.
 *
 * Provides authenticated encryption for OAuth tokens stored at rest.
 * Supports key rotation by maintaining multiple versioned keys.
 *
 * Format: version:base64(nonce || ciphertext || tag)
 */
class token_encryption
{
    /** @var array<string, string> Map of version => raw key bytes */
    private array $keys;

    /** @var string Current key version for encryption */
    private string $currentVersion;

    /**
     * Constructor - loads keys from environment.
     *
     * @throws \RuntimeException If encryption keys are not configured
     */
    public function __construct()
    {
        $this->loadKeys();
    }

    /**
     * Encrypt a token using the current key version.
     *
     * @param string $token The plaintext token to encrypt
     *
     * @return string Versioned encrypted string (version:base64data)
     */
    public function encrypt(string $token): string
    {
        $key = $this->keys[$this->currentVersion];
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $token,
            '', // Additional authenticated data (empty)
            $nonce,
            $key
        );

        // Zero sensitive data
        sodium_memzero($token);

        $result = $this->currentVersion . ':' . base64_encode($nonce . $ciphertext);

        return $result;
    }

    /**
     * Decrypt a stored encrypted token.
     *
     * @param string $stored The versioned encrypted string
     *
     * @throws \RuntimeException If decryption fails
     *
     * @return string The decrypted plaintext token
     */
    public function decrypt(string $stored): string
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid encrypted token format');
        }

        [$version, $encoded] = $parts;

        if (!isset($this->keys[$version])) {
            throw new \RuntimeException('Unknown encryption key version: ' . $version);
        }

        $key = $this->keys[$version];
        $data = base64_decode($encoded, true);

        if ($data === false) {
            throw new \RuntimeException('Invalid base64 encoding');
        }

        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if (strlen($data) < $nonceLength) {
            throw new \RuntimeException('Decryption failed: data too short');
        }

        $nonce = substr($data, 0, $nonceLength);
        $ciphertext = substr($data, $nonceLength);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '', // Additional authenticated data (empty)
            $nonce,
            $key
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: authentication error');
        }

        return $plaintext;
    }

    /**
     * Check if a stored token needs re-encryption with the current key.
     *
     * @param string $stored The versioned encrypted string
     *
     * @return bool True if re-encryption is needed
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
     * @param string $stored The versioned encrypted string
     *
     * @throws \RuntimeException If decryption fails
     *
     * @return string Newly encrypted string with current version
     */
    public function reEncrypt(string $stored): string
    {
        $plaintext = $this->decrypt($stored);
        $result = $this->encrypt($plaintext);
        sodium_memzero($plaintext);

        return $result;
    }

    /**
     * Load encryption keys from environment variables.
     *
     * @throws \RuntimeException If keys are not configured
     */
    private function loadKeys(): void
    {
        $keysJson = getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS');
        $currentVersion = getenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION');

        if ($keysJson === false || $keysJson === '') {
            throw new \RuntimeException('Token encryption key not configured: ATPROTO_TOKEN_ENCRYPTION_KEYS not set');
        }

        $keysEncoded = json_decode($keysJson, true);

        if (!is_array($keysEncoded) || empty($keysEncoded)) {
            throw new \RuntimeException('Token encryption key not configured: no keys found');
        }

        if ($currentVersion === false || $currentVersion === '') {
            throw new \RuntimeException('Token encryption key not configured: ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION not set');
        }

        if (!isset($keysEncoded[$currentVersion])) {
            throw new \RuntimeException('Token encryption key not configured: current version key not found');
        }

        // Decode all keys from base64
        $this->keys = [];
        foreach ($keysEncoded as $version => $encodedKey) {
            $key = base64_decode($encodedKey, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \RuntimeException(
                    'Token encryption key not configured: invalid key for version ' . $version
                );
            }
            $this->keys[$version] = $key;
        }

        $this->currentVersion = $currentVersion;
    }
}
