# Task 12: DPoP (Demonstration of Proof-of-Possession) Service

> **AT Protocol Requirement:** All OAuth token requests MUST include DPoP proofs. The authorization server will reject requests without valid DPoP headers.

**Files:**
- Create: `ext/phpbb/atproto/auth/dpop_service.php`
- Create: `ext/phpbb/atproto/auth/dpop_service_interface.php`
- Modify: `ext/phpbb/atproto/auth/oauth_client.php` (add DPoP to requests)
- Modify: `ext/phpbb/atproto/config/services.yml`
- Create: `tests/ext/phpbb/atproto/auth/DpopServiceTest.php`

**Reference:** https://docs.bsky.app/docs/advanced-guides/oauth-client#dpop

---

## Step 1: Write the failing test for DPoP service

```php
// tests/ext/phpbb/atproto/auth/DpopServiceTest.php
<?php

namespace phpbb\atproto\tests\auth;

class DpopServiceTest extends \phpbb_test_case
{
    private \phpbb\atproto\auth\dpop_service $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a fresh keypair for testing
        $this->service = new \phpbb\atproto\auth\dpop_service();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\dpop_service'));
    }

    public function test_generates_es256_keypair(): void
    {
        $keypair = $this->service->getKeypair();

        $this->assertArrayHasKey('private', $keypair);
        $this->assertArrayHasKey('public', $keypair);
        $this->assertArrayHasKey('jwk', $keypair);

        // JWK should be ES256
        $this->assertEquals('EC', $keypair['jwk']['kty']);
        $this->assertEquals('P-256', $keypair['jwk']['crv']);
        $this->assertEquals('ES256', $keypair['jwk']['alg']);
    }

    public function test_creates_valid_dpop_proof(): void
    {
        $proof = $this->service->createProof(
            'POST',
            'https://bsky.social/oauth/token'
        );

        // DPoP proof is a JWT
        $parts = explode('.', $proof);
        $this->assertCount(3, $parts);

        // Decode header
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertEquals('dpop+jwt', $header['typ']);
        $this->assertEquals('ES256', $header['alg']);
        $this->assertArrayHasKey('jwk', $header);

        // Decode payload
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertEquals('POST', $payload['htm']);
        $this->assertEquals('https://bsky.social/oauth/token', $payload['htu']);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function test_proof_includes_ath_for_access_token(): void
    {
        $accessToken = 'test.access.token';
        $proof = $this->service->createProof(
            'GET',
            'https://bsky.social/xrpc/com.atproto.server.getSession',
            $accessToken
        );

        $parts = explode('.', $proof);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // ath = base64url(sha256(access_token))
        $this->assertArrayHasKey('ath', $payload);
        $expectedAth = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
        $this->assertEquals($expectedAth, $payload['ath']);
    }

    public function test_keypair_persistence(): void
    {
        // Same service instance should return same keypair
        $keypair1 = $this->service->getKeypair();
        $keypair2 = $this->service->getKeypair();

        $this->assertEquals($keypair1['jwk']['x'], $keypair2['jwk']['x']);
        $this->assertEquals($keypair1['jwk']['y'], $keypair2['jwk']['y']);
    }

    public function test_jwk_thumbprint_calculation(): void
    {
        $thumbprint = $this->service->getJwkThumbprint();

        // JWK thumbprint is base64url encoded SHA-256
        $this->assertNotEmpty($thumbprint);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $thumbprint);
    }
}
```

---

## Step 2: Run test to verify it fails

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/auth/DpopServiceTest.php`
Expected: FAIL with "Class '\phpbb\atproto\auth\dpop_service' not found"

---

## Step 3: Create dpop_service_interface.php

```php
<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * Interface for DPoP (Demonstration of Proof-of-Possession) service.
 *
 * DPoP is required by AT Protocol OAuth for binding tokens to the client.
 * All token requests must include a DPoP proof header.
 */
interface dpop_service_interface
{
    /**
     * Get the ES256 keypair for DPoP proofs.
     *
     * @return array{private: string, public: string, jwk: array}
     */
    public function getKeypair(): array;

    /**
     * Create a DPoP proof JWT for a request.
     *
     * @param string      $method      HTTP method (GET, POST, etc.)
     * @param string      $url         Full URL of the request
     * @param string|null $accessToken Access token (include for resource requests)
     *
     * @return string The DPoP proof JWT
     */
    public function createProof(string $method, string $url, ?string $accessToken = null): string;

    /**
     * Get the JWK thumbprint of the public key.
     *
     * Used as the 'jkt' claim to bind tokens to this key.
     *
     * @return string Base64url-encoded SHA-256 thumbprint
     */
    public function getJwkThumbprint(): string;

    /**
     * Get the public key in JWK format.
     *
     * @return array The JWK
     */
    public function getPublicJwk(): array;
}
```

---

## Step 4: Create dpop_service.php

```php
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
```

---

## Step 5: Update services.yml to register DPoP service

Add to `ext/phpbb/atproto/config/services.yml`:

```yaml
    phpbb.atproto.auth.dpop_service:
        class: phpbb\atproto\auth\dpop_service
        arguments: []
```

---

## Step 6: Run test to verify it passes

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/auth/DpopServiceTest.php`
Expected: All tests PASS

---

## Step 7: Commit

```bash
git add ext/phpbb/atproto/auth/dpop_service.php ext/phpbb/atproto/auth/dpop_service_interface.php ext/phpbb/atproto/config/services.yml tests/ext/phpbb/atproto/auth/DpopServiceTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add DPoP service for AT Protocol OAuth

Implements RFC 9449 DPoP (Demonstration of Proof-of-Possession):
- ES256 keypair generation and persistence
- DPoP proof JWT creation with jti, htm, htu, iat claims
- Access token hash (ath) for resource requests
- JWK thumbprint calculation for token binding
- DER to R||S signature format conversion

Required by AT Protocol OAuth for all token requests.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
