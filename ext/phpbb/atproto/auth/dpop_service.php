<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * DPoP (Demonstration of Proof-of-Possession) service.
 *
 * Implements DPoP proofs for AT Protocol OAuth as specified in RFC 9449.
 * Uses ES256 (ECDSA with P-256 and SHA-256) for signing.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9449
 * @see https://docs.bsky.app/docs/advanced-guides/oauth-client#dpop
 */
class dpop_service implements dpop_service_interface
{
    private ?array $keypair = null;
    private ?string $privateKeyPem = null;
    private ?\OpenSSLAsymmetricKey $privateKey = null;

    /**
     * Constructor.
     *
     * @param string|null $storedKeypair JSON-encoded keypair from storage (optional)
     */
    public function __construct(?string $storedKeypair = null)
    {
        if ($storedKeypair !== null) {
            $this->loadKeypair($storedKeypair);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getKeypair(): array
    {
        if ($this->keypair === null) {
            $this->generateKeypair();
        }

        return $this->keypair;
    }

    /**
     * {@inheritdoc}
     */
    public function createProof(string $method, string $url, ?string $accessToken = null): string
    {
        $keypair = $this->getKeypair();

        // JWT header
        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $keypair['jwk'],
        ];

        // JWT payload
        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => $url,
            'iat' => time(),
        ];

        // Include access token hash for resource requests
        if ($accessToken !== null) {
            $payload['ath'] = $this->hashAccessToken($accessToken);
        }

        // Create JWT
        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        $signingInput = $headerB64 . '.' . $payloadB64;

        // Sign with ES256
        $signature = $this->signEs256($signingInput);

        return $signingInput . '.' . $signature;
    }

    /**
     * {@inheritdoc}
     */
    public function getJwkThumbprint(): string
    {
        $jwk = $this->getKeypair()['jwk'];

        // JWK thumbprint uses lexicographically sorted required members
        // For EC keys: crv, kty, x, y
        $thumbprintInput = json_encode([
            'crv' => $jwk['crv'],
            'kty' => $jwk['kty'],
            'x' => $jwk['x'],
            'y' => $jwk['y'],
        ], JSON_UNESCAPED_SLASHES);

        return $this->base64UrlEncode(hash('sha256', $thumbprintInput, true));
    }

    /**
     * {@inheritdoc}
     */
    public function getPublicJwk(): array
    {
        return $this->getKeypair()['jwk'];
    }

    /**
     * Export keypair for storage.
     *
     * @return string JSON-encoded keypair
     */
    public function exportKeypair(): string
    {
        $keypair = $this->getKeypair();

        return json_encode([
            'private' => $keypair['private'],
            'public' => $keypair['public'],
            'jwk' => $keypair['jwk'],
        ]);
    }

    /**
     * Generate a new ES256 keypair.
     */
    private function generateKeypair(): void
    {
        // Generate EC key with P-256 curve
        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate EC keypair: ' . openssl_error_string());
        }

        // Export private key
        openssl_pkey_export($key, $privateKeyPem);

        // Get key details for JWK
        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('Failed to get EC key details');
        }

        // Build JWK from EC key parameters
        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'use' => 'sig',
            'x' => $this->base64UrlEncode($details['ec']['x']),
            'y' => $this->base64UrlEncode($details['ec']['y']),
        ];

        $this->privateKeyPem = $privateKeyPem;
        $this->privateKey = $key;
        $this->keypair = [
            'private' => $privateKeyPem,
            'public' => $details['key'],
            'jwk' => $jwk,
        ];
    }

    /**
     * Load a keypair from storage.
     */
    private function loadKeypair(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['private'], $data['public'], $data['jwk'])) {
            throw new \InvalidArgumentException('Invalid keypair JSON');
        }

        $this->privateKeyPem = $data['private'];
        $this->privateKey = openssl_pkey_get_private($data['private']);
        if ($this->privateKey === false) {
            throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
        }

        $this->keypair = $data;
    }

    /**
     * Sign data with ES256 (ECDSA P-256 with SHA-256).
     *
     * @param string $data Data to sign
     *
     * @return string Base64url-encoded signature in R||S format
     */
    private function signEs256(string $data): string
    {
        if ($this->privateKey === null) {
            $this->generateKeypair();
        }

        // Sign with SHA-256
        $success = openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new \RuntimeException('Failed to sign: ' . openssl_error_string());
        }

        // OpenSSL returns DER-encoded signature, convert to R||S format for JWT
        $signature = $this->derToRs($signature);

        return $this->base64UrlEncode($signature);
    }

    /**
     * Convert DER-encoded ECDSA signature to R||S format.
     *
     * JWT requires signatures in R||S format (64 bytes for P-256),
     * but OpenSSL produces DER-encoded signatures.
     *
     * @param string $der DER-encoded signature
     *
     * @return string R||S format signature (64 bytes)
     */
    private function derToRs(string $der): string
    {
        // DER structure: SEQUENCE { INTEGER r, INTEGER s }
        $offset = 0;

        // Skip SEQUENCE tag and length
        if (ord($der[$offset++]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature: missing SEQUENCE');
        }
        $this->readDerLength($der, $offset);

        // Read R
        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: missing INTEGER for R');
        }
        $rLen = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // Read S
        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: missing INTEGER for S');
        }
        $sLen = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);

        // Pad/trim to 32 bytes each (P-256 uses 256-bit integers)
        $r = $this->padInteger($r, 32);
        $s = $this->padInteger($s, 32);

        return $r . $s;
    }

    /**
     * Read DER length field.
     */
    private function readDerLength(string $der, int &$offset): int
    {
        $byte = ord($der[$offset++]);
        if ($byte < 0x80) {
            return $byte;
        }

        $numBytes = $byte & 0x7F;
        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }

        return $length;
    }

    /**
     * Pad or trim integer to exact byte length.
     */
    private function padInteger(string $int, int $length): string
    {
        // Remove leading zeros
        $int = ltrim($int, "\x00");

        // Handle negative numbers (leading 0x00 in DER indicates positive)
        if (strlen($int) > $length) {
            $int = substr($int, -$length);
        }

        // Pad to required length
        return str_pad($int, $length, "\x00", STR_PAD_LEFT);
    }

    /**
     * Hash access token for ath claim.
     *
     * @param string $accessToken The access token
     *
     * @return string Base64url-encoded SHA-256 hash
     */
    private function hashAccessToken(string $accessToken): string
    {
        return $this->base64UrlEncode(hash('sha256', $accessToken, true));
    }

    /**
     * Base64url encode.
     *
     * @param string $data Data to encode
     *
     * @return string Base64url-encoded string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
