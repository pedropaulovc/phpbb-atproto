# Task 13: DPoP Keypair Persistence

> **AT Protocol Requirement:** The DPoP keypair must be persistent. Tokens are bound to the public key, so a new keypair would invalidate all existing tokens and break the client metadata.

**Files:**
- Create: `ext/phpbb/atproto/migrations/v1/m2_dpop_keypair.php`
- Modify: `ext/phpbb/atproto/auth/dpop_service.php` (load/save from DB)
- Modify: `ext/phpbb/atproto/config/services.yml`
- Create: `tests/ext/phpbb/atproto/auth/DpopPersistenceTest.php`

**Depends on:** Task 12 (DPoP service)

---

## Step 1: Write the failing test

```php
// tests/ext/phpbb/atproto/auth/DpopPersistenceTest.php
<?php

namespace phpbb\atproto\tests\auth;

class DpopPersistenceTest extends \phpbb_database_test_case
{
    protected static function setup_extensions(): array
    {
        return ['phpbb/atproto'];
    }

    public function getDataSet(): \PHPUnit\DbUnit\DataSet\IDataSet
    {
        return $this->createXMLDataSet(__DIR__ . '/fixtures/dpop_keypair.xml');
    }

    public function test_loads_existing_keypair_from_database(): void
    {
        $db = $this->new_dbal();

        // Create service with database - should load existing keypair
        $service = new \phpbb\atproto\auth\dpop_service($db);

        $keypair = $service->getKeypair();

        // Should match the fixture data
        $this->assertEquals('P-256', $keypair['jwk']['crv']);
        $this->assertEquals('EC', $keypair['jwk']['kty']);
    }

    public function test_generates_and_stores_keypair_if_none_exists(): void
    {
        $db = $this->new_dbal();

        // Clear any existing keypair
        $db->sql_query('DELETE FROM ' . $this->table_prefix . 'atproto_config WHERE config_name = \'dpop_keypair\'');

        // Create service - should generate new keypair
        $service = new \phpbb\atproto\auth\dpop_service($db);
        $keypair1 = $service->getKeypair();

        // Verify it was stored
        $sql = 'SELECT config_value FROM ' . $this->table_prefix . 'atproto_config WHERE config_name = \'dpop_keypair\'';
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        $this->assertNotEmpty($row['config_value']);

        // Create new service instance - should load same keypair
        $service2 = new \phpbb\atproto\auth\dpop_service($db);
        $keypair2 = $service2->getKeypair();

        $this->assertEquals($keypair1['jwk']['x'], $keypair2['jwk']['x']);
        $this->assertEquals($keypair1['jwk']['y'], $keypair2['jwk']['y']);
    }

    public function test_keypair_is_encrypted_at_rest(): void
    {
        $db = $this->new_dbal();

        // Clear and regenerate
        $db->sql_query('DELETE FROM ' . $this->table_prefix . 'atproto_config WHERE config_name = \'dpop_keypair\'');

        $service = new \phpbb\atproto\auth\dpop_service($db);
        $service->getKeypair();

        // Fetch stored value
        $sql = 'SELECT config_value FROM ' . $this->table_prefix . 'atproto_config WHERE config_name = \'dpop_keypair\'';
        $result = $db->sql_query($sql);
        $row = $db->sql_fetchrow($result);
        $db->sql_freeresult($result);

        $stored = $row['config_value'];

        // Should not be plain JSON (encrypted)
        $decoded = json_decode($stored, true);
        $this->assertNull($decoded, 'Keypair should be encrypted, not plain JSON');

        // Should be base64 (encrypted blob)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $stored);
    }
}
```

---

## Step 2: Create fixture file

```xml
<!-- tests/ext/phpbb/atproto/auth/fixtures/dpop_keypair.xml -->
<?xml version="1.0" encoding="UTF-8"?>
<dataset>
    <table name="phpbb_atproto_config">
        <column>config_name</column>
        <column>config_value</column>
        <row>
            <value>dpop_keypair</value>
            <value><!-- Encrypted keypair from test setup --></value>
        </row>
    </table>
</dataset>
```

---

## Step 3: Create migration for config table

```php
<?php
// ext/phpbb/atproto/migrations/v1/m2_dpop_keypair.php

declare(strict_types=1);

namespace phpbb\atproto\migrations\v1;

use phpbb\db\migration\migration;

/**
 * Migration to add atproto_config table for DPoP keypair storage.
 */
class m2_dpop_keypair extends migration
{
    public static function depends_on(): array
    {
        return ['\phpbb\atproto\migrations\v1\m1_initial_schema'];
    }

    public function effectively_installed(): bool
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'atproto_config');
    }

    public function update_schema(): array
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'atproto_config' => [
                    'COLUMNS' => [
                        'config_name' => ['VCHAR:255', ''],
                        'config_value' => ['TEXT', ''],
                    ],
                    'PRIMARY_KEY' => 'config_name',
                ],
            ],
        ];
    }

    public function revert_schema(): array
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'atproto_config',
            ],
        ];
    }
}
```

---

## Step 4: Update dpop_service.php for database persistence

```php
<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

use phpbb\db\driver\driver_interface;

/**
 * DPoP (Demonstration of Proof-of-Possession) service with database persistence.
 *
 * The keypair is stored encrypted in the database to ensure:
 * 1. Consistency across multiple app servers
 * 2. Persistence across restarts
 * 3. Token binding remains valid
 */
class dpop_service implements dpop_service_interface
{
    private const CONFIG_KEY = 'dpop_keypair';

    private driver_interface $db;
    private string $tablePrefix;
    private ?array $keypair = null;
    private ?\OpenSSLAsymmetricKey $privateKey = null;

    /**
     * Constructor.
     *
     * @param driver_interface $db          Database driver
     * @param string           $tablePrefix Table prefix
     */
    public function __construct(driver_interface $db, string $tablePrefix = 'phpbb_')
    {
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * {@inheritdoc}
     */
    public function getKeypair(): array
    {
        if ($this->keypair !== null) {
            return $this->keypair;
        }

        // Try to load from database
        $stored = $this->loadFromDatabase();
        if ($stored !== null) {
            $this->loadKeypair($stored);
            return $this->keypair;
        }

        // Generate new keypair and store
        $this->generateKeypair();
        $this->saveToDatabase();

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
     * Load keypair from database.
     *
     * @return string|null Encrypted keypair or null if not found
     */
    private function loadFromDatabase(): ?string
    {
        $sql = 'SELECT config_value
                FROM ' . $this->tablePrefix . 'atproto_config
                WHERE config_name = \'' . $this->db->sql_escape(self::CONFIG_KEY) . '\'';

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row === false || empty($row['config_value'])) {
            return null;
        }

        return $this->decrypt($row['config_value']);
    }

    /**
     * Save keypair to database.
     */
    private function saveToDatabase(): void
    {
        $encrypted = $this->encrypt($this->exportKeypair());

        $sql = 'INSERT INTO ' . $this->tablePrefix . 'atproto_config
                (config_name, config_value)
                VALUES (\'' . $this->db->sql_escape(self::CONFIG_KEY) . '\',
                        \'' . $this->db->sql_escape($encrypted) . '\')';

        $this->db->sql_query($sql);
    }

    /**
     * Export keypair as JSON.
     */
    private function exportKeypair(): string
    {
        return json_encode([
            'private' => $this->keypair['private'],
            'public' => $this->keypair['public'],
            'jwk' => $this->keypair['jwk'],
        ]);
    }

    /**
     * Encrypt data for storage.
     *
     * Uses sodium for authenticated encryption.
     */
    private function encrypt(string $data): string
    {
        // Use a derived key from the phpBB config
        // In production, this should use a proper key from environment
        $key = $this->getEncryptionKey();

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($data, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt data from storage.
     */
    private function decrypt(string $encrypted): string
    {
        $key = $this->getEncryptionKey();

        $decoded = base64_decode($encrypted);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid encrypted data');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    /**
     * Get encryption key from environment.
     *
     * @return string 32-byte key
     */
    private function getEncryptionKey(): string
    {
        $keyBase64 = getenv('ATPROTO_DPOP_KEY');
        if ($keyBase64 === false || empty($keyBase64)) {
            // Fall back to deriving from existing token encryption key
            $tokenKeys = getenv('ATPROTO_TOKEN_ENCRYPTION_KEYS');
            if ($tokenKeys !== false) {
                $keys = json_decode($tokenKeys, true);
                if (is_array($keys)) {
                    $firstKey = reset($keys);
                    if ($firstKey !== false) {
                        return hash('sha256', 'dpop:' . base64_decode($firstKey), true);
                    }
                }
            }

            throw new \RuntimeException('ATPROTO_DPOP_KEY or ATPROTO_TOKEN_ENCRYPTION_KEYS must be set');
        }

        return base64_decode($keyBase64);
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
    private function loadKeypair(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['private'], $data['public'], $data['jwk'])) {
            throw new \InvalidArgumentException('Invalid keypair JSON');
        }

        $this->privateKey = openssl_pkey_get_private($data['private']);
        if ($this->privateKey === false) {
            throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
        }

        $this->keypair = $data;
    }

    /**
     * Sign with ES256.
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
     * Convert DER to R||S format.
     */
    private function derToRs(string $der): string
    {
        $offset = 0;

        if (ord($der[$offset++]) !== 0x30) {
            throw new \RuntimeException('Invalid DER signature');
        }
        $this->readDerLength($der, $offset);

        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature');
        }
        $rLen = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (ord($der[$offset++]) !== 0x02) {
            throw new \RuntimeException('Invalid DER signature');
        }
        $sLen = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);

        $r = $this->padInteger($r, 32);
        $s = $this->padInteger($s, 32);

        return $r . $s;
    }

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

    private function padInteger(string $int, int $length): string
    {
        $int = ltrim($int, "\x00");

        if (strlen($int) > $length) {
            $int = substr($int, -$length);
        }

        return str_pad($int, $length, "\x00", STR_PAD_LEFT);
    }

    private function hashAccessToken(string $accessToken): string
    {
        return $this->base64UrlEncode(hash('sha256', $accessToken, true));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
```

---

## Step 5: Update services.yml

```yaml
    phpbb.atproto.auth.dpop_service:
        class: phpbb\atproto\auth\dpop_service
        arguments:
            - '@dbal.conn'
            - '%core.table_prefix%'
```

---

## Step 6: Run test to verify it passes

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/auth/DpopPersistenceTest.php`
Expected: All tests PASS

---

## Step 7: Commit

```bash
git add ext/phpbb/atproto/migrations/v1/m2_dpop_keypair.php ext/phpbb/atproto/auth/dpop_service.php ext/phpbb/atproto/config/services.yml tests/ext/phpbb/atproto/auth/DpopPersistenceTest.php tests/ext/phpbb/atproto/auth/fixtures/dpop_keypair.xml
git commit -m "$(cat <<'EOF'
feat(atproto): persist DPoP keypair in database

DPoP keypair must be persistent:
- Tokens are bound to the public key (jkt claim)
- Client metadata includes the public key
- New keypair would invalidate all tokens

Implementation:
- Store encrypted keypair in atproto_config table
- Use sodium for authenticated encryption
- Derive encryption key from ATPROTO_TOKEN_ENCRYPTION_KEYS
- Auto-generate on first use, load on subsequent

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
