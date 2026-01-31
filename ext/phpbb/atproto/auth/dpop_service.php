<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

use phpbb\db\driver\driver_interface;

/**
 * DPoP (Demonstration of Proof-of-Possession) service with optional database persistence.
 *
 * Implements DPoP proofs for AT Protocol OAuth as specified in RFC 9449.
 * Uses ES256 (ECDSA with P-256 and SHA-256) for signing.
 *
 * The keypair is stored encrypted in the database to ensure:
 * 1. Consistency across multiple app servers
 * 2. Persistence across restarts
 * 3. Token binding remains valid
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9449
 * @see https://docs.bsky.app/docs/advanced-guides/oauth-client#dpop
 */
class dpop_service implements dpop_service_interface
{
    private const CONFIG_KEY = 'dpop_keypair';

    private ?driver_interface $db;
    private ?token_encryption $encryption;
    private string $tablePrefix;
    private ?array $keypair = null;
    private ?string $privateKeyPem = null;
    private ?\OpenSSLAsymmetricKey $privateKey = null;

    /**
     * Constructor.
     *
     * @param driver_interface|null   $db          Database driver (optional for testing)
     * @param token_encryption|null   $encryption  Token encryption service (optional for testing)
     * @param string                  $tablePrefix Table prefix (default: 'phpbb_')
     * @param string|null             $storedKeypair JSON-encoded keypair from storage (for testing only)
     */
    public function __construct(
        ?driver_interface $db = null,
        ?token_encryption $encryption = null,
        string $tablePrefix = 'phpbb_',
        ?string $storedKeypair = null
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->tablePrefix = $tablePrefix;

        // Direct keypair loading for testing
        if ($storedKeypair !== null) {
            $this->loadKeypairFromJson($storedKeypair);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getKeypair(): array
    {
        if ($this->keypair !== null) {
            return $this->keypair;
        }

        // Try to load from database if available
        if ($this->db !== null) {
            $stored = $this->loadFromDatabase();
            if ($stored !== null) {
                $this->loadKeypairFromJson($stored);
                return $this->keypair;
            }
        }

        // Generate new keypair
        $this->generateKeypair();

        // Store to database if available
        if ($this->db !== null) {
            $this->saveToDatabase();
        }

        return $this->keypair;
    }

    /**
     * {@inheritdoc}
     */
    public function createProof(string $method, string $url, ?string $accessToken = null): string
    {
        return $this->createProofWithNonce($method, $url, null, $accessToken);
    }

    /**
     * Create a DPoP proof with optional nonce.
     *
     * @param string      $method      HTTP method
     * @param string      $url         Request URL
     * @param string|null $nonce       Server-provided nonce
     * @param string|null $accessToken Access token for resource requests
     *
     * @return string DPoP proof JWT
     */
    public function createProofWithNonce(
        string $method,
        string $url,
        ?string $nonce = null,
        ?string $accessToken = null
    ): string {
        $keypair = $this->getKeypair();

        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $keypair['jwk'],
        ];

        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => $url,
            'iat' => time(),
        ];

        if ($nonce !== null) {
            $payload['nonce'] = $nonce;
        }

        if ($accessToken !== null) {
            $payload['ath'] = $this->hashAccessToken($accessToken);
        }

        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));
        $signingInput = $headerB64 . '.' . $payloadB64;

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
     * Load keypair from database.
     *
     * @return string|null Decrypted keypair JSON or null if not found
     */
    private function loadFromDatabase(): ?string
    {
        $configTable = $this->tablePrefix . 'atproto_config';

        // Check if table exists by trying the query
        try {
            $sql = 'SELECT config_value
                    FROM ' . $configTable . '
                    WHERE config_name = \'' . $this->db->sql_escape(self::CONFIG_KEY) . '\'';

            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if ($row === false || empty($row['config_value'])) {
                return null;
            }

            // Decrypt if encryption is available
            if ($this->encryption !== null) {
                return $this->encryption->decrypt($row['config_value']);
            }

            return $row['config_value'];
        } catch (\Throwable $e) {
            // Table doesn't exist yet (migrations not run)
            return null;
        }
    }

    /**
     * Save keypair to database.
     */
    private function saveToDatabase(): void
    {
        $configTable = $this->tablePrefix . 'atproto_config';
        $keypairJson = $this->exportKeypair();

        // Encrypt if encryption is available
        $valueToStore = $this->encryption !== null
            ? $this->encryption->encrypt($keypairJson)
            : $keypairJson;

        try {
            $sql = 'INSERT INTO ' . $configTable . '
                    (config_name, config_value)
                    VALUES (\'' . $this->db->sql_escape(self::CONFIG_KEY) . '\',
                            \'' . $this->db->sql_escape($valueToStore) . '\')';

            $this->db->sql_query($sql);
        } catch (\Throwable $e) {
            // Table doesn't exist yet (migrations not run) - skip silently
            // The keypair will be persisted once migrations run
        }
    }

    /**
     * Generate a new ES256 keypair.
     */
    private function generateKeypair(): void
    {
        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('Failed to generate EC keypair: ' . openssl_error_string());
        }

        openssl_pkey_export($key, $privateKeyPem);

        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('Failed to get EC key details');
        }

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
     * Load a keypair from JSON.
     */
    private function loadKeypairFromJson(string $json): void
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
            $this->getKeypair();
        }

        $success = openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new \RuntimeException('Failed to sign: ' . openssl_error_string());
        }

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
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature: missing SEQUENCE');
        }
        $this->readDerLength($der, $offset);

        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: missing INTEGER for R');
        }
        $rLen = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature: missing INTEGER for S');
        }
        $sLen = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);

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
        $int = ltrim($int, "\x00");

        if (strlen($int) > $length) {
            $int = substr($int, -$length);
        }

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
