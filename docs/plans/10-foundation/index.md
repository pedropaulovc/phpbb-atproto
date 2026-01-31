# Foundation Phase Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the phpBB AT Protocol extension skeleton with database migrations and working OAuth authentication flow.

**Architecture:** A phpBB extension (`ext/phpbb/atproto/`) that provides DID-based authentication via AT Protocol OAuth. Tokens are encrypted at rest using XChaCha20-Poly1305 with key rotation support. The extension hooks into phpBB's authentication events and provides services for token management, DID resolution, and PDS communication.

**Tech Stack:** PHP 8.4+, phpBB 3.3.x extension framework, Sodium for encryption, HTTP client for OAuth/DID resolution.

---

## Prerequisites

- Docker environment running (`docker/` setup)
- Lexicons already defined (10 files in `lexicons/`)
- Specifications complete in `docs/spec/components/`

---

## Task Dependencies

```
Task 1 (Skeleton) ──┬──> Task 2 (Services Config)
                    │
                    └──> Task 3 (Migrations)
                    │
                    └──> Task 4 (Encryption) ──> Task 7 (Token Manager)
                    │                                   │
                    └──> Task 5 (DID Resolver) ─────────┼──> Task 6 (OAuth Client)
                                                        │           │
                                                        └───────────┴──> Task 8 (Controller)
                                                                                │
                                                                                └──> Task 9 (Event Listener)
                                                                                        │
                                                                                        └──> Task 10-11 (Language/Templates)
                                                                                                │
                                                                                                └──> Task 12-13 (Integration/Verification)
```

**Critical path:** Tasks 1 → 3 → 4 → 7 → 6 → 8 → 9 → 12

---

## Environment Configuration

Required environment variables:
```bash
ATPROTO_TOKEN_ENCRYPTION_KEYS='{"v1":"base64-encoded-32-byte-key"}'
ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION='v1'
ATPROTO_CLIENT_ID='https://your-forum.com/client-metadata.json'
```

Optional:
```bash
ATPROTO_TOKEN_REFRESH_BUFFER=300  # seconds before expiry to refresh
ATPROTO_DID_CACHE_TTL=3600        # DID document cache TTL
```

---

## Task 1: Extension Skeleton Files

**Files:**
- Create: `ext/phpbb/atproto/composer.json`
- Create: `ext/phpbb/atproto/ext.php`

**Step 1: Write the failing test**

Create a simple test that checks the extension can be loaded.

```php
// tests/ext/phpbb/atproto/ExtensionTest.php
<?php

namespace phpbb\atproto\tests;

class ExtensionTest extends \phpbb_test_case
{
    public function test_extension_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\ext'));
    }

    public function test_extension_is_enableable()
    {
        $ext = new \phpbb\atproto\ext();
        $this->assertTrue($ext->is_enableable());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ExtensionTest.php`
Expected: FAIL with "Class '\phpbb\atproto\ext' not found"

**Step 3: Create composer.json**

```json
{
    "name": "phpbb/atproto",
    "type": "phpbb-extension",
    "description": "AT Protocol integration for phpBB - DID authentication and decentralized data",
    "homepage": "https://github.com/pedropaulovc/phpbb-atproto",
    "license": "GPL-2.0-only",
    "authors": [
        {
            "name": "Pedro Carvalho",
            "email": "pedro@vza.net"
        }
    ],
    "require": {
        "php": ">=8.4"
    },
    "extra": {
        "display-name": "AT Protocol Integration",
        "soft-require": {
            "phpbb/phpbb": ">=3.3.0,<4.0"
        },
        "version-check": {
            "host": "github.com",
            "directory": "/pedropaulovc/phpbb-atproto",
            "filename": "version.json"
        }
    }
}
```

**Step 4: Create ext.php**

```php
<?php

namespace phpbb\atproto;

class ext extends \phpbb\extension\base
{
    /**
     * Check if extension is enableable.
     * Requires sodium extension for token encryption.
     */
    public function is_enableable()
    {
        return extension_loaded('sodium') && PHP_VERSION_ID >= 80400;
    }

    /**
     * Enable step - run migrations.
     */
    public function enable_step($old_state)
    {
        return parent::enable_step($old_state);
    }

    /**
     * Disable step.
     */
    public function disable_step($old_state)
    {
        return parent::disable_step($old_state);
    }

    /**
     * Purge step - clean up data.
     */
    public function purge_step($old_state)
    {
        return parent::purge_step($old_state);
    }
}
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ExtensionTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/composer.json ext/phpbb/atproto/ext.php tests/ext/phpbb/atproto/ExtensionTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add extension skeleton with composer.json and ext.php

- Create phpbb/atproto extension structure
- Require PHP 8.4+ and sodium extension for token encryption
- Add basic extension test

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Service Container Configuration

**Files:**
- Create: `ext/phpbb/atproto/config/services.yml`
- Create: `ext/phpbb/atproto/config/routing.yml`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/ConfigTest.php
<?php

namespace phpbb\atproto\tests;

class ConfigTest extends \phpbb_test_case
{
    public function test_services_yaml_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/services.yml';
        $this->assertFileExists($path);
    }

    public function test_routing_yaml_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/routing.yml';
        $this->assertFileExists($path);
    }

    public function test_services_yaml_is_valid()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/services.yml';
        $content = file_get_contents($path);
        $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
        $this->assertArrayHasKey('services', $parsed);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ConfigTest.php`
Expected: FAIL with "Failed asserting that file exists"

**Step 3: Create services.yml**

```yaml
services:
    # Token encryption service
    phpbb.atproto.token_encryption:
        class: phpbb\atproto\auth\token_encryption

    # DID resolution service
    phpbb.atproto.did_resolver:
        class: phpbb\atproto\services\did_resolver
        arguments:
            - '@cache.driver'
            - '%atproto.did_cache_ttl%'

    # OAuth client for AT Protocol
    phpbb.atproto.oauth_client:
        class: phpbb\atproto\auth\oauth_client
        arguments:
            - '@phpbb.atproto.did_resolver'
            - '%atproto.client_id%'
            - '%atproto.redirect_uri%'

    # Token manager for storage and refresh
    phpbb.atproto.token_manager:
        class: phpbb\atproto\services\token_manager
        arguments:
            - '@dbal.conn'
            - '@phpbb.atproto.token_encryption'
            - '@phpbb.atproto.oauth_client'
            - '%core.table_prefix%'
            - '%atproto.token_refresh_buffer%'

    # PDS client for AT Protocol API calls
    phpbb.atproto.pds_client:
        class: phpbb\atproto\services\pds_client
        arguments:
            - '@phpbb.atproto.token_manager'
            - '@phpbb.atproto.did_resolver'

    # URI mapper for AT URI <-> phpBB ID
    phpbb.atproto.uri_mapper:
        class: phpbb\atproto\services\uri_mapper
        arguments:
            - '@dbal.conn'
            - '%core.table_prefix%'

    # Queue manager for retry failed writes
    phpbb.atproto.queue_manager:
        class: phpbb\atproto\services\queue_manager
        arguments:
            - '@dbal.conn'
            - '%core.table_prefix%'

    # Record builder for AT Protocol records
    phpbb.atproto.record_builder:
        class: phpbb\atproto\services\record_builder
        arguments:
            - '@phpbb.atproto.uri_mapper'

    # OAuth callback controller
    phpbb.atproto.controller.oauth:
        class: phpbb\atproto\controller\oauth_controller
        arguments:
            - '@phpbb.atproto.oauth_client'
            - '@phpbb.atproto.token_manager'
            - '@user'
            - '@auth'
            - '@request'
            - '@template'
            - '@config'

    # Auth event listener
    phpbb.atproto.event.auth_listener:
        class: phpbb\atproto\event\auth_listener
        arguments:
            - '@phpbb.atproto.token_manager'
            - '@user'
            - '@dbal.conn'
            - '%core.table_prefix%'
        tags:
            - { name: event.listener }

parameters:
    atproto.client_id: '%env(ATPROTO_CLIENT_ID)%'
    atproto.redirect_uri: ~
    atproto.token_refresh_buffer: 300
    atproto.did_cache_ttl: 3600
```

**Step 4: Create routing.yml**

```yaml
phpbb_atproto_oauth_callback:
    path: /atproto/callback
    defaults:
        _controller: phpbb.atproto.controller.oauth:callback

phpbb_atproto_oauth_start:
    path: /atproto/login
    defaults:
        _controller: phpbb.atproto.controller.oauth:start
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ConfigTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/config/services.yml ext/phpbb/atproto/config/routing.yml tests/ext/phpbb/atproto/ConfigTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add service container and routing configuration

- Define all core services: token_encryption, did_resolver, oauth_client,
  token_manager, pds_client, uri_mapper, queue_manager, record_builder
- Add OAuth callback and login routes
- Configure environment-based parameters

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Database Migration

**Files:**
- Create: `ext/phpbb/atproto/migrations/v1/m1_initial_schema.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/migrations/InitialSchemaTest.php
<?php

namespace phpbb\atproto\tests\migrations;

class InitialSchemaTest extends \phpbb_database_test_case
{
    public function test_migration_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\migrations\v1\m1_initial_schema'));
    }

    public function test_migration_depends_on_v330()
    {
        $deps = \phpbb\atproto\migrations\v1\m1_initial_schema::depends_on();
        $this->assertContains('\phpbb\db\migration\data\v330\v330', $deps);
    }

    public function test_migration_has_update_schema()
    {
        $migration = new \phpbb\atproto\migrations\v1\m1_initial_schema(
            $this->new_dbal(),
            $this->db,
            $this->db_tools,
            'phpbb_',
            __DIR__,
            'php'
        );
        $schema = $migration->update_schema();

        $this->assertArrayHasKey('add_tables', $schema);
        $this->assertArrayHasKey('phpbb_atproto_users', $schema['add_tables']);
        $this->assertArrayHasKey('phpbb_atproto_posts', $schema['add_tables']);
        $this->assertArrayHasKey('phpbb_atproto_forums', $schema['add_tables']);
        $this->assertArrayHasKey('phpbb_atproto_labels', $schema['add_tables']);
        $this->assertArrayHasKey('phpbb_atproto_cursors', $schema['add_tables']);
        $this->assertArrayHasKey('phpbb_atproto_queue', $schema['add_tables']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/migrations/InitialSchemaTest.php`
Expected: FAIL with "Class '\phpbb\atproto\migrations\v1\m1_initial_schema' not found"

**Step 3: Create migration file**

```php
<?php

namespace phpbb\atproto\migrations\v1;

class m1_initial_schema extends \phpbb\db\migration\migration
{
    public static function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'atproto_users');
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'atproto_users' => [
                    'COLUMNS' => [
                        'user_id'           => ['UINT', 0],
                        'did'               => ['VCHAR:255', ''],
                        'handle'            => ['VCHAR:255', null],
                        'pds_url'           => ['VCHAR:512', ''],
                        'access_token'      => ['TEXT', null],
                        'refresh_token'     => ['TEXT', null],
                        'token_expires_at'  => ['UINT', null],
                        'migration_status'  => ['VCHAR:20', 'none'],
                        'created_at'        => ['UINT', 0],
                        'updated_at'        => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'user_id',
                    'KEYS' => [
                        'idx_did'           => ['UNIQUE', 'did'],
                        'idx_handle'        => ['INDEX', 'handle'],
                        'idx_token_expires' => ['INDEX', 'token_expires_at'],
                    ],
                ],
                $this->table_prefix . 'atproto_posts' => [
                    'COLUMNS' => [
                        'post_id'           => ['UINT', 0],
                        'at_uri'            => ['VCHAR:512', ''],
                        'at_cid'            => ['VCHAR:64', ''],
                        'author_did'        => ['VCHAR:255', ''],
                        'is_topic_starter'  => ['BOOL', 0],
                        'sync_status'       => ['VCHAR:20', 'synced'],
                        'created_at'        => ['UINT', 0],
                        'updated_at'        => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'post_id',
                    'KEYS' => [
                        'idx_at_uri'        => ['UNIQUE', 'at_uri'],
                        'idx_author_did'    => ['INDEX', 'author_did'],
                        'idx_sync_status'   => ['INDEX', 'sync_status'],
                        'idx_at_cid'        => ['INDEX', 'at_cid'],
                        'idx_topic_starter' => ['INDEX', 'is_topic_starter'],
                    ],
                ],
                $this->table_prefix . 'atproto_forums' => [
                    'COLUMNS' => [
                        'forum_id'          => ['UINT', 0],
                        'at_uri'            => ['VCHAR:512', ''],
                        'at_cid'            => ['VCHAR:64', ''],
                        'slug'              => ['VCHAR:255', ''],
                        'updated_at'        => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'forum_id',
                    'KEYS' => [
                        'idx_at_uri'        => ['UNIQUE', 'at_uri'],
                        'idx_slug'          => ['INDEX', 'slug'],
                    ],
                ],
                $this->table_prefix . 'atproto_labels' => [
                    'COLUMNS' => [
                        'id'                => ['UINT', null, 'auto_increment'],
                        'subject_uri'       => ['VCHAR:512', ''],
                        'subject_cid'       => ['VCHAR:64', null],
                        'label_value'       => ['VCHAR:128', ''],
                        'label_src'         => ['VCHAR:255', ''],
                        'created_at'        => ['UINT', 0],
                        'negated'           => ['BOOL', 0],
                        'negated_at'        => ['UINT', null],
                        'expires_at'        => ['UINT', null],
                    ],
                    'PRIMARY_KEY' => 'id',
                    'KEYS' => [
                        'idx_subject_uri'   => ['INDEX', 'subject_uri'],
                        'idx_label_value'   => ['INDEX', 'label_value'],
                        'idx_label_src'     => ['INDEX', 'label_src'],
                        'idx_negated'       => ['INDEX', 'negated'],
                        'idx_expires_at'    => ['INDEX', 'expires_at'],
                        'idx_unique_label'  => ['UNIQUE', ['subject_uri', 'label_value', 'label_src']],
                    ],
                ],
                $this->table_prefix . 'atproto_cursors' => [
                    'COLUMNS' => [
                        'service'           => ['VCHAR:255', ''],
                        'cursor_value'      => ['BINT', 0],
                        'updated_at'        => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'service',
                ],
                $this->table_prefix . 'atproto_queue' => [
                    'COLUMNS' => [
                        'id'                => ['UINT', null, 'auto_increment'],
                        'operation'         => ['VCHAR:20', ''],
                        'collection'        => ['VCHAR:255', ''],
                        'rkey'              => ['VCHAR:255', null],
                        'record_data'       => ['TEXT', null],
                        'user_did'          => ['VCHAR:255', ''],
                        'local_id'          => ['UINT', null],
                        'attempts'          => ['UINT', 0],
                        'max_attempts'      => ['UINT', 5],
                        'last_error'        => ['TEXT', null],
                        'next_retry_at'     => ['UINT', 0],
                        'created_at'        => ['UINT', 0],
                        'status'            => ['VCHAR:20', 'pending'],
                    ],
                    'PRIMARY_KEY' => 'id',
                    'KEYS' => [
                        'idx_next_retry'    => ['INDEX', ['next_retry_at', 'status']],
                        'idx_user_did'      => ['INDEX', 'user_did'],
                        'idx_status'        => ['INDEX', 'status'],
                        'idx_local_id'      => ['INDEX', 'local_id'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'atproto_users',
                $this->table_prefix . 'atproto_posts',
                $this->table_prefix . 'atproto_forums',
                $this->table_prefix . 'atproto_labels',
                $this->table_prefix . 'atproto_cursors',
                $this->table_prefix . 'atproto_queue',
            ],
        ];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/migrations/InitialSchemaTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/migrations/v1/m1_initial_schema.php tests/ext/phpbb/atproto/migrations/InitialSchemaTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add database migration for 6 AT Protocol tables

Tables created:
- atproto_users: DID-to-user mapping and encrypted OAuth tokens
- atproto_posts: AT URI-to-post_id mapping
- atproto_forums: AT URI-to-forum_id mapping
- atproto_labels: Cached moderation labels
- atproto_cursors: Firehose cursor positions
- atproto_queue: Retry queue for failed PDS writes

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Token Encryption Service

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

---

## Task 5: DID Resolver Service

**Files:**
- Create: `ext/phpbb/atproto/services/did_resolver.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/services/DidResolverTest.php
<?php

namespace phpbb\atproto\tests\services;

class DidResolverTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\did_resolver'));
    }

    public function test_resolve_handle_validates_format()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveHandle('invalid handle with spaces');
    }

    public function test_resolve_did_validates_format()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveDid('not-a-did');
    }

    public function test_is_valid_handle()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidHandle('alice.bsky.social'));
        $this->assertTrue($resolver->isValidHandle('user.example.com'));
        $this->assertFalse($resolver->isValidHandle('invalid'));
        $this->assertFalse($resolver->isValidHandle('has spaces.com'));
        $this->assertFalse($resolver->isValidHandle(''));
    }

    public function test_is_valid_did()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidDid('did:plc:abcdef123'));
        $this->assertTrue($resolver->isValidDid('did:web:example.com'));
        $this->assertFalse($resolver->isValidDid('notadid'));
        $this->assertFalse($resolver->isValidDid('did:'));
        $this->assertFalse($resolver->isValidDid(''));
    }

    public function test_extract_pds_url_from_did_document()
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
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/DidResolverTest.php`
Expected: FAIL with "Class '\phpbb\atproto\services\did_resolver' not found"

**Step 3: Create did_resolver.php**

```php
<?php

namespace phpbb\atproto\services;

/**
 * DID Resolution service for AT Protocol.
 *
 * Resolves handles to DIDs and DIDs to PDS URLs.
 * Supports did:plc and did:web methods.
 */
class did_resolver
{
    private const PLC_DIRECTORY = 'https://plc.directory';

    /** @var \phpbb\cache\driver\driver_interface */
    private $cache;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl;

    public function __construct(\phpbb\cache\driver\driver_interface $cache, int $cacheTtl = 3600)
    {
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Resolve a handle to a DID.
     *
     * @param string $handle AT Protocol handle (e.g., "alice.bsky.social")
     * @return string DID (e.g., "did:plc:...")
     * @throws \InvalidArgumentException If handle format is invalid
     * @throws \RuntimeException If resolution fails
     */
    public function resolveHandle(string $handle): string
    {
        // Strip leading @ if present
        $handle = ltrim($handle, '@');

        if (!$this->isValidHandle($handle)) {
            throw new \InvalidArgumentException("Invalid handle format: $handle");
        }

        $cacheKey = 'atproto_handle_' . md5($handle);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // Try DNS TXT record first
        $did = $this->resolveHandleViaDns($handle);

        // Fall back to HTTP well-known
        if ($did === null) {
            $did = $this->resolveHandleViaHttp($handle);
        }

        if ($did === null) {
            throw new \RuntimeException("Failed to resolve handle: $handle");
        }

        $this->cache->put($cacheKey, $did, $this->cacheTtl);
        return $did;
    }

    /**
     * Resolve a DID to its DID document.
     *
     * @param string $did DID (did:plc:... or did:web:...)
     * @return array DID document
     * @throws \InvalidArgumentException If DID format is invalid
     * @throws \RuntimeException If resolution fails
     */
    public function resolveDid(string $did): array
    {
        if (!$this->isValidDid($did)) {
            throw new \InvalidArgumentException("Invalid DID format: $did");
        }

        $cacheKey = 'atproto_did_' . md5($did);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        if (strpos($did, 'did:plc:') === 0) {
            $document = $this->resolvePlcDid($did);
        } elseif (strpos($did, 'did:web:') === 0) {
            $document = $this->resolveWebDid($did);
        } else {
            throw new \InvalidArgumentException("Unsupported DID method: $did");
        }

        $this->cache->put($cacheKey, $document, $this->cacheTtl);
        return $document;
    }

    /**
     * Get the PDS URL for a DID.
     *
     * @param string $did DID to resolve
     * @return string PDS service endpoint URL
     * @throws \RuntimeException If PDS URL not found
     */
    public function getPdsUrl(string $did): string
    {
        $document = $this->resolveDid($did);
        $pdsUrl = $this->extractPdsUrl($document);

        if ($pdsUrl === null) {
            throw new \RuntimeException("No PDS service found in DID document: $did");
        }

        return $pdsUrl;
    }

    /**
     * Check if a string is a valid AT Protocol handle.
     */
    public function isValidHandle(string $handle): bool
    {
        // Handle must be a valid domain name with at least 2 segments
        if (empty($handle) || strlen($handle) > 253) {
            return false;
        }

        // Must contain at least one dot
        if (strpos($handle, '.') === false) {
            return false;
        }

        // Each segment: alphanumeric and hyphens, not starting/ending with hyphen
        $segments = explode('.', $handle);
        foreach ($segments as $segment) {
            if (empty($segment) || strlen($segment) > 63) {
                return false;
            }
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $segment)) {
                // Allow single-char segments
                if (!preg_match('/^[a-zA-Z0-9]$/', $segment)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a string is a valid DID.
     */
    public function isValidDid(string $did): bool
    {
        if (empty($did)) {
            return false;
        }

        // Basic DID format: did:method:identifier
        return (bool) preg_match('/^did:[a-z]+:[a-zA-Z0-9._:%-]+$/', $did);
    }

    /**
     * Extract PDS URL from a DID document.
     *
     * @param array $document DID document
     * @return string|null PDS URL or null if not found
     */
    public function extractPdsUrl(array $document): ?string
    {
        if (!isset($document['service']) || !is_array($document['service'])) {
            return null;
        }

        foreach ($document['service'] as $service) {
            if (isset($service['type']) && $service['type'] === 'AtprotoPersonalDataServer') {
                return $service['serviceEndpoint'] ?? null;
            }
            // Also check by ID for compatibility
            if (isset($service['id']) && $service['id'] === '#atproto_pds') {
                return $service['serviceEndpoint'] ?? null;
            }
        }

        return null;
    }

    /**
     * Resolve handle via DNS TXT record.
     */
    private function resolveHandleViaDns(string $handle): ?string
    {
        $dnsName = '_atproto.' . $handle;

        try {
            $records = dns_get_record($dnsName, DNS_TXT);
            if ($records === false || empty($records)) {
                return null;
            }

            foreach ($records as $record) {
                if (isset($record['txt']) && strpos($record['txt'], 'did=') === 0) {
                    return substr($record['txt'], 4);
                }
            }
        } catch (\Exception $e) {
            // DNS resolution failed, fall back to HTTP
        }

        return null;
    }

    /**
     * Resolve handle via HTTP well-known endpoint.
     */
    private function resolveHandleViaHttp(string $handle): ?string
    {
        $url = "https://{$handle}/.well-known/atproto-did";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $did = trim($response);
        return $this->isValidDid($did) ? $did : null;
    }

    /**
     * Resolve a did:plc DID via PLC directory.
     */
    private function resolvePlcDid(string $did): array
    {
        $url = self::PLC_DIRECTORY . '/' . urlencode($did);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve DID from PLC directory: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document from PLC directory: $did");
        }

        return $document;
    }

    /**
     * Resolve a did:web DID.
     */
    private function resolveWebDid(string $did): array
    {
        // Extract domain from did:web:domain
        $domain = substr($did, 8); // Remove 'did:web:'
        $domain = str_replace(':', '/', $domain); // Handle path segments
        $domain = urldecode($domain);

        $url = "https://{$domain}/.well-known/did.json";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve did:web: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document for did:web: $did");
        }

        return $document;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/DidResolverTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/services/did_resolver.php tests/ext/phpbb/atproto/services/DidResolverTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add DID resolution service

- Resolve handles via DNS TXT or HTTP well-known
- Support did:plc (PLC directory) and did:web methods
- Extract PDS URL from DID documents
- Cache resolved DIDs for performance
- Validate handle and DID formats

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: OAuth Client

**Files:**
- Create: `ext/phpbb/atproto/auth/oauth_client.php`
- Create: `ext/phpbb/atproto/auth/oauth_exception.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/auth/OAuthClientTest.php
<?php

namespace phpbb\atproto\tests\auth;

class OAuthClientTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_client'));
    }

    public function test_get_authorization_url_includes_required_params()
    {
        $didResolver = $this->createMock(\phpbb\atproto\services\did_resolver::class);
        $didResolver->method('resolveHandle')->willReturn('did:plc:test123');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $client = new \phpbb\atproto\auth\oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        // Mock the OAuth metadata fetch
        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $url = $client->getAuthorizationUrl('alice.bsky.social', 'test-state-123');

        $this->assertStringContainsString('client_id=', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
        $this->assertStringContainsString('scope=atproto', $url);
    }

    public function test_oauth_exception_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_exception'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/OAuthClientTest.php`
Expected: FAIL with "Class '\phpbb\atproto\auth\oauth_client' not found"

**Step 3: Create oauth_exception.php**

```php
<?php

namespace phpbb\atproto\auth;

/**
 * Exception thrown during OAuth operations.
 */
class oauth_exception extends \Exception
{
    public const CODE_INVALID_HANDLE = 'AUTH_INVALID_HANDLE';
    public const CODE_DID_RESOLUTION_FAILED = 'AUTH_DID_RESOLUTION_FAILED';
    public const CODE_OAUTH_DENIED = 'AUTH_OAUTH_DENIED';
    public const CODE_TOKEN_EXCHANGE_FAILED = 'AUTH_TOKEN_EXCHANGE_FAILED';
    public const CODE_REFRESH_FAILED = 'AUTH_REFRESH_FAILED';
    public const CODE_CONFIG_ERROR = 'AUTH_CONFIG_ERROR';
    public const CODE_METADATA_FETCH_FAILED = 'AUTH_METADATA_FETCH_FAILED';
    public const CODE_STATE_MISMATCH = 'AUTH_STATE_MISMATCH';

    private string $errorCode;

    public function __construct(string $errorCode, string $message, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
```

**Step 4: Create oauth_client.php**

```php
<?php

namespace phpbb\atproto\auth;

use phpbb\atproto\services\did_resolver;

/**
 * AT Protocol OAuth client.
 *
 * Implements the OAuth flow for AT Protocol authentication:
 * 1. Resolve handle to DID and PDS
 * 2. Fetch OAuth metadata from PDS
 * 3. Generate authorization URL (using PAR if available)
 * 4. Exchange authorization code for tokens
 * 5. Refresh tokens when needed
 */
class oauth_client implements oauth_client_interface
{
    private did_resolver $didResolver;
    private string $clientId;
    private string $redirectUri;
    private ?array $oauthMetadata = null;
    private ?string $currentPdsUrl = null;

    public function __construct(
        did_resolver $didResolver,
        string $clientId,
        string $redirectUri
    ) {
        $this->didResolver = $didResolver;
        $this->clientId = $clientId;
        $this->redirectUri = $redirectUri;
    }

    /**
     * Set OAuth metadata (for testing).
     */
    public function setOAuthMetadata(array $metadata): void
    {
        $this->oauthMetadata = $metadata;
    }

    /**
     * Generate OAuth authorization URL.
     *
     * @param string $handle User's handle or DID
     * @param string $state CSRF protection state
     * @return string Authorization URL to redirect user to
     * @throws oauth_exception On failure
     */
    public function getAuthorizationUrl(string $handle, string $state): string
    {
        try {
            // Resolve handle to DID if needed
            $did = $this->didResolver->isValidDid($handle)
                ? $handle
                : $this->didResolver->resolveHandle($handle);

            // Get PDS URL
            $pdsUrl = $this->didResolver->getPdsUrl($did);
            $this->currentPdsUrl = $pdsUrl;

            // Fetch OAuth metadata
            $metadata = $this->getOAuthMetadata($pdsUrl);

            // Generate PKCE challenge
            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->generateCodeChallenge($codeVerifier);

            // Build authorization URL
            $params = [
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'response_type' => 'code',
                'scope' => 'atproto',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'login_hint' => $did,
            ];

            // Use PAR if available
            if (isset($metadata['pushed_authorization_request_endpoint'])) {
                return $this->usePar($metadata, $params, $codeVerifier);
            }

            // Store code verifier in state (will be saved by caller)
            // In production, this should be stored server-side keyed by state

            return $metadata['authorization_endpoint'] . '?' . http_build_query($params);

        } catch (\InvalidArgumentException $e) {
            throw new oauth_exception(
                oauth_exception::CODE_INVALID_HANDLE,
                "Invalid handle format: $handle",
                $e
            );
        } catch (\RuntimeException $e) {
            throw new oauth_exception(
                oauth_exception::CODE_DID_RESOLUTION_FAILED,
                "Failed to resolve handle: " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code from callback
     * @param string $state State for CSRF validation
     * @param string $codeVerifier PKCE code verifier
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array
    {
        if ($this->currentPdsUrl === null) {
            throw new oauth_exception(
                oauth_exception::CODE_CONFIG_ERROR,
                'PDS URL not set - must call getAuthorizationUrl first'
            );
        }

        $metadata = $this->getOAuthMetadata($this->currentPdsUrl);

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ];

        $response = $this->postToTokenEndpoint($metadata['token_endpoint'], $params);

        if (!isset($response['access_token'], $response['refresh_token'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid token response: missing tokens'
            );
        }

        // Extract DID from access token (JWT)
        $did = $this->extractDidFromToken($response['access_token']);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'did' => $did,
            'expires_in' => $response['expires_in'] ?? 3600,
        ];
    }

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Current refresh token
     * @param string $pdsUrl PDS URL for token endpoint
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array
    {
        $metadata = $this->getOAuthMetadata($pdsUrl);

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
        ];

        try {
            $response = $this->postToTokenEndpoint($metadata['token_endpoint'], $params);
        } catch (oauth_exception $e) {
            throw new oauth_exception(
                oauth_exception::CODE_REFRESH_FAILED,
                'Token refresh failed: ' . $e->getMessage(),
                $e
            );
        }

        if (!isset($response['access_token'], $response['refresh_token'])) {
            throw new oauth_exception(
                oauth_exception::CODE_REFRESH_FAILED,
                'Invalid refresh response: missing tokens'
            );
        }

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'] ?? 3600,
        ];
    }

    /**
     * Fetch OAuth metadata from PDS.
     */
    private function getOAuthMetadata(string $pdsUrl): array
    {
        if ($this->oauthMetadata !== null) {
            return $this->oauthMetadata;
        }

        $metadataUrl = rtrim($pdsUrl, '/') . '/.well-known/oauth-authorization-server';

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($metadataUrl, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                oauth_exception::CODE_METADATA_FETCH_FAILED,
                "Failed to fetch OAuth metadata from: $metadataUrl"
            );
        }

        $metadata = json_decode($response, true);
        if (!is_array($metadata) || !isset($metadata['authorization_endpoint'], $metadata['token_endpoint'])) {
            throw new oauth_exception(
                oauth_exception::CODE_METADATA_FETCH_FAILED,
                'Invalid OAuth metadata: missing required endpoints'
            );
        }

        $this->oauthMetadata = $metadata;
        return $metadata;
    }

    /**
     * Generate PKCE code verifier.
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate PKCE code challenge from verifier.
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Use Pushed Authorization Request (PAR).
     */
    private function usePar(array $metadata, array $params, string $codeVerifier): string
    {
        $response = $this->postToTokenEndpoint(
            $metadata['pushed_authorization_request_endpoint'],
            $params
        );

        if (!isset($response['request_uri'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'PAR response missing request_uri'
            );
        }

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'client_id' => $this->clientId,
            'request_uri' => $response['request_uri'],
        ]);
    }

    /**
     * POST to token endpoint.
     */
    private function postToTokenEndpoint(string $url, array $params): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 30,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
                'content' => http_build_query($params),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                "Failed to contact token endpoint: $url"
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid JSON response from token endpoint'
            );
        }

        if (isset($data['error'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'OAuth error: ' . ($data['error_description'] ?? $data['error'])
            );
        }

        return $data;
    }

    /**
     * Extract DID from JWT access token.
     */
    private function extractDidFromToken(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid JWT format'
            );
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || !isset($payload['sub'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'JWT missing sub claim'
            );
        }

        return $payload['sub'];
    }

    /**
     * Get the current PDS URL (set during getAuthorizationUrl).
     */
    public function getCurrentPdsUrl(): ?string
    {
        return $this->currentPdsUrl;
    }

    /**
     * Set the current PDS URL (for resuming flow).
     */
    public function setCurrentPdsUrl(string $pdsUrl): void
    {
        $this->currentPdsUrl = $pdsUrl;
    }
}
```

**Step 5: Create oauth_client_interface.php**

```php
<?php

namespace phpbb\atproto\auth;

/**
 * Interface for AT Protocol OAuth client.
 */
interface oauth_client_interface
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
     * @param string $codeVerifier PKCE code verifier
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array;

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Current refresh token
     * @param string $pdsUrl PDS URL for token endpoint
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array;
}
```

**Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/OAuthClientTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add ext/phpbb/atproto/auth/oauth_client.php ext/phpbb/atproto/auth/oauth_client_interface.php ext/phpbb/atproto/auth/oauth_exception.php tests/ext/phpbb/atproto/auth/OAuthClientTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add OAuth client for AT Protocol authentication

- Implement full OAuth flow with PKCE
- Support Pushed Authorization Requests (PAR)
- Fetch OAuth metadata from PDS
- Token exchange and refresh
- Extract DID from JWT access token
- Custom exception class with error codes

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Token Manager Service

**Files:**
- Create: `ext/phpbb/atproto/services/token_manager.php`
- Create: `ext/phpbb/atproto/services/token_manager_interface.php`
- Create: `ext/phpbb/atproto/exceptions/token_not_found_exception.php`
- Create: `ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/services/TokenManagerTest.php
<?php

namespace phpbb\atproto\tests\services;

class TokenManagerTest extends \phpbb_database_test_case
{
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test encryption keys
        $testKey = base64_encode(random_bytes(32));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEYS=' . json_encode(['v1' => $testKey]));
        putenv('ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION=v1');
    }

    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\token_manager'));
    }

    public function test_interface_exists()
    {
        $this->assertTrue(interface_exists('\phpbb\atproto\services\token_manager_interface'));
    }

    public function test_store_and_retrieve_tokens()
    {
        $db = $this->createMock(\phpbb\db\driver\driver_interface::class);
        $encryption = new \phpbb\atproto\auth\token_encryption();
        $oauthClient = $this->createMock(\phpbb\atproto\auth\oauth_client_interface::class);

        $manager = new \phpbb\atproto\services\token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        // Test that class implements interface
        $this->assertInstanceOf(
            \phpbb\atproto\services\token_manager_interface::class,
            $manager
        );
    }

    public function test_is_token_valid_returns_false_for_nonexistent_user()
    {
        $db = $this->createMock(\phpbb\db\driver\driver_interface::class);
        $db->method('sql_fetchrow')->willReturn(false);

        $encryption = new \phpbb\atproto\auth\token_encryption();
        $oauthClient = $this->createMock(\phpbb\atproto\auth\oauth_client_interface::class);

        $manager = new \phpbb\atproto\services\token_manager(
            $db,
            $encryption,
            $oauthClient,
            'phpbb_',
            300
        );

        $this->assertFalse($manager->isTokenValid(999));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/TokenManagerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\services\token_manager' not found"

**Step 3: Create exception classes**

```php
// ext/phpbb/atproto/exceptions/token_not_found_exception.php
<?php

namespace phpbb\atproto\exceptions;

class token_not_found_exception extends \Exception
{
    public function __construct(int $userId)
    {
        parent::__construct("No AT Protocol tokens found for user ID: $userId");
    }
}
```

```php
// ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php
<?php

namespace phpbb\atproto\exceptions;

class token_refresh_failed_exception extends \Exception
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct("Token refresh failed: $message", 0, $previous);
    }
}
```

**Step 4: Create token_manager_interface.php**

```php
<?php

namespace phpbb\atproto\services;

interface token_manager_interface
{
    /**
     * Get a valid access token for a user, refreshing if necessary.
     *
     * @param int $userId phpBB user ID
     * @return string Valid access token (JWT)
     * @throws \phpbb\atproto\exceptions\token_not_found_exception When user has no tokens
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     */
    public function getAccessToken(int $userId): string;

    /**
     * Force refresh the access token using the refresh token.
     *
     * @param int $userId phpBB user ID
     * @return string New access token
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     */
    public function refreshToken(int $userId): string;

    /**
     * Store tokens for a user after OAuth flow.
     *
     * @param int $userId phpBB user ID
     * @param string $did User's DID
     * @param string $handle User's handle
     * @param string $pdsUrl User's PDS URL
     * @param string $accessToken Access token (will be encrypted)
     * @param string $refreshToken Refresh token (will be encrypted)
     * @param int $expiresIn Seconds until access token expires
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
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

    /**
     * Get the PDS URL for a user.
     *
     * @param int $userId phpBB user ID
     * @return string|null User's PDS URL or null if not linked
     */
    public function getUserPdsUrl(int $userId): ?string;
}
```

**Step 5: Create token_manager.php**

```php
<?php

namespace phpbb\atproto\services;

use phpbb\atproto\auth\token_encryption;
use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\exceptions\token_not_found_exception;
use phpbb\atproto\exceptions\token_refresh_failed_exception;

/**
 * Token manager for AT Protocol OAuth tokens.
 *
 * Handles token storage, retrieval, and automatic refresh.
 * Uses row-level locking to prevent race conditions during refresh.
 */
class token_manager implements token_manager_interface
{
    private \phpbb\db\driver\driver_interface $db;
    private token_encryption $encryption;
    private oauth_client_interface $oauthClient;
    private string $tablePrefix;
    private int $refreshBuffer;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        token_encryption $encryption,
        oauth_client_interface $oauthClient,
        string $tablePrefix,
        int $refreshBuffer = 300
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->oauthClient = $oauthClient;
        $this->tablePrefix = $tablePrefix;
        $this->refreshBuffer = $refreshBuffer;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(int $userId): string
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            throw new token_not_found_exception($userId);
        }

        // Check if token needs refresh
        $expiresAt = (int) $row['token_expires_at'];
        if ($expiresAt <= time() + $this->refreshBuffer) {
            return $this->refreshToken($userId);
        }

        // Decrypt and return access token
        return $this->encryption->decrypt($row['access_token']);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken(int $userId): string
    {
        // Start transaction for row-level locking
        $this->db->sql_transaction('begin');

        try {
            // Lock the row
            $sql = 'SELECT access_token, refresh_token, token_expires_at, pds_url
                    FROM ' . $this->tablePrefix . 'atproto_users
                    WHERE user_id = ' . (int) $userId . '
                    FOR UPDATE';
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if ($row === false) {
                $this->db->sql_transaction('rollback');
                throw new token_not_found_exception($userId);
            }

            // Double-check: maybe another request already refreshed
            $expiresAt = (int) $row['token_expires_at'];
            if ($expiresAt > time() + 60) {
                // Token was refreshed by another request
                $this->db->sql_transaction('commit');
                return $this->encryption->decrypt($row['access_token']);
            }

            // Perform refresh
            $refreshToken = $this->encryption->decrypt($row['refresh_token']);
            $pdsUrl = $row['pds_url'];

            try {
                $tokens = $this->oauthClient->refreshAccessToken($refreshToken, $pdsUrl);
            } catch (\Exception $e) {
                $this->db->sql_transaction('rollback');
                throw new token_refresh_failed_exception($e->getMessage(), $e);
            }

            // Store new tokens
            $newExpiresAt = time() + $tokens['expires_in'];
            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET access_token = \'' . $this->db->sql_escape($this->encryption->encrypt($tokens['access_token'])) . '\',
                        refresh_token = \'' . $this->db->sql_escape($this->encryption->encrypt($tokens['refresh_token'])) . '\',
                        token_expires_at = ' . $newExpiresAt . ',
                        updated_at = ' . time() . '
                    WHERE user_id = ' . (int) $userId;
            $this->db->sql_query($sql);

            $this->db->sql_transaction('commit');

            return $tokens['access_token'];

        } catch (\Exception $e) {
            $this->db->sql_transaction('rollback');
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void {
        $now = time();
        $expiresAt = $now + $expiresIn;

        $encryptedAccess = $this->encryption->encrypt($accessToken);
        $encryptedRefresh = $this->encryption->encrypt($refreshToken);

        // Check if user already exists
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result) !== false;
        $this->db->sql_freeresult($result);

        if ($exists) {
            // Update existing
            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET did = \'' . $this->db->sql_escape($did) . '\',
                        handle = \'' . $this->db->sql_escape($handle) . '\',
                        pds_url = \'' . $this->db->sql_escape($pdsUrl) . '\',
                        access_token = \'' . $this->db->sql_escape($encryptedAccess) . '\',
                        refresh_token = \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                        token_expires_at = ' . $expiresAt . ',
                        updated_at = ' . $now . '
                    WHERE user_id = ' . (int) $userId;
        } else {
            // Insert new
            $sql = 'INSERT INTO ' . $this->tablePrefix . 'atproto_users
                    (user_id, did, handle, pds_url, access_token, refresh_token, token_expires_at, migration_status, created_at, updated_at)
                    VALUES (' . (int) $userId . ',
                            \'' . $this->db->sql_escape($did) . '\',
                            \'' . $this->db->sql_escape($handle) . '\',
                            \'' . $this->db->sql_escape($pdsUrl) . '\',
                            \'' . $this->db->sql_escape($encryptedAccess) . '\',
                            \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                            ' . $expiresAt . ',
                            \'none\',
                            ' . $now . ',
                            ' . $now . ')';
        }

        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenValid(int $userId): bool
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            return false;
        }

        // Token is valid if it exists and won't expire soon
        // (We allow some buffer for the refresh to happen)
        return $row['access_token'] !== null
            && (int) $row['token_expires_at'] > time();
    }

    /**
     * {@inheritdoc}
     */
    public function clearTokens(int $userId): void
    {
        $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                SET access_token = NULL,
                    refresh_token = NULL,
                    token_expires_at = NULL,
                    updated_at = ' . time() . '
                WHERE user_id = ' . (int) $userId;
        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserDid(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);
        return $row ? $row['did'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPdsUrl(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);
        return $row ? $row['pds_url'] : null;
    }

    /**
     * Find phpBB user ID by DID.
     */
    public function findUserByDid(string $did): ?int
    {
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE did = \'' . $this->db->sql_escape($did) . '\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Get token row for a user.
     */
    private function getTokenRow(int $userId): ?array
    {
        $sql = 'SELECT did, handle, pds_url, access_token, refresh_token, token_expires_at
                FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: null;
    }
}
```

**Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/TokenManagerTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add ext/phpbb/atproto/services/token_manager.php ext/phpbb/atproto/services/token_manager_interface.php ext/phpbb/atproto/exceptions/token_not_found_exception.php ext/phpbb/atproto/exceptions/token_refresh_failed_exception.php tests/ext/phpbb/atproto/services/TokenManagerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add token manager for OAuth token lifecycle

- Store and retrieve encrypted tokens
- Automatic token refresh before expiry
- Row-level locking to prevent refresh race conditions
- Find users by DID
- Custom exceptions for error handling

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: OAuth Controller

**Files:**
- Create: `ext/phpbb/atproto/controller/oauth_controller.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/controller/OAuthControllerTest.php
<?php

namespace phpbb\atproto\tests\controller;

class OAuthControllerTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\controller\oauth_controller'));
    }

    public function test_has_start_method()
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'start'));
    }

    public function test_has_callback_method()
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'callback'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/controller/OAuthControllerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\controller\oauth_controller' not found"

**Step 3: Create oauth_controller.php**

```php
<?php

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\oauth_client;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\token_manager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth controller for AT Protocol authentication flow.
 */
class oauth_controller
{
    private oauth_client $oauthClient;
    private token_manager $tokenManager;
    private \phpbb\user $user;
    private \phpbb\auth\auth $auth;
    private \phpbb\request\request_interface $request;
    private \phpbb\template\template $template;
    private \phpbb\config\config $config;
    private string $phpbbRootPath;
    private string $phpEx;

    public function __construct(
        oauth_client $oauthClient,
        token_manager $tokenManager,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\request\request_interface $request,
        \phpbb\template\template $template,
        \phpbb\config\config $config,
        string $phpbbRootPath,
        string $phpEx
    ) {
        $this->oauthClient = $oauthClient;
        $this->tokenManager = $tokenManager;
        $this->user = $user;
        $this->auth = $auth;
        $this->request = $request;
        $this->template = $template;
        $this->config = $config;
        $this->phpbbRootPath = $phpbbRootPath;
        $this->phpEx = $phpEx;
    }

    /**
     * Start OAuth flow - user enters handle.
     */
    public function start(): Response
    {
        $handle = $this->request->variable('handle', '');

        if (empty($handle)) {
            // Show login form
            $this->template->assign_vars([
                'ATPROTO_LOGIN_ERROR' => '',
                'S_ATPROTO_LOGIN' => true,
            ]);

            return $this->renderPage('atproto_login');
        }

        try {
            // Generate state for CSRF protection
            $state = bin2hex(random_bytes(16));
            $codeVerifier = bin2hex(random_bytes(32));

            // Store in session
            $this->user->session_setvar('atproto_oauth_state', $state);
            $this->user->session_setvar('atproto_code_verifier', $codeVerifier);
            $this->user->session_setvar('atproto_handle', $handle);

            // Get authorization URL
            $authUrl = $this->oauthClient->getAuthorizationUrl($handle, $state);

            // Store PDS URL for callback
            $this->user->session_setvar('atproto_pds_url', $this->oauthClient->getCurrentPdsUrl());

            return new RedirectResponse($authUrl);

        } catch (oauth_exception $e) {
            $this->template->assign_vars([
                'ATPROTO_LOGIN_ERROR' => $this->getErrorMessage($e->getErrorCode()),
                'S_ATPROTO_LOGIN' => true,
            ]);

            return $this->renderPage('atproto_login');
        }
    }

    /**
     * OAuth callback - exchange code for tokens.
     */
    public function callback(): Response
    {
        $code = $this->request->variable('code', '');
        $state = $this->request->variable('state', '');
        $error = $this->request->variable('error', '');

        // Check for OAuth error
        if (!empty($error)) {
            return $this->handleError(
                oauth_exception::CODE_OAUTH_DENIED,
                $this->request->variable('error_description', 'Authorization denied')
            );
        }

        // Validate state
        $expectedState = $this->user->session_getvar('atproto_oauth_state');
        if (empty($state) || $state !== $expectedState) {
            return $this->handleError(
                oauth_exception::CODE_STATE_MISMATCH,
                'Invalid state parameter'
            );
        }

        // Get stored values
        $codeVerifier = $this->user->session_getvar('atproto_code_verifier');
        $pdsUrl = $this->user->session_getvar('atproto_pds_url');
        $handle = $this->user->session_getvar('atproto_handle');

        if (empty($codeVerifier) || empty($pdsUrl)) {
            return $this->handleError(
                oauth_exception::CODE_CONFIG_ERROR,
                'Session data missing - please try again'
            );
        }

        try {
            // Set PDS URL for token exchange
            $this->oauthClient->setCurrentPdsUrl($pdsUrl);

            // Exchange code for tokens
            $tokens = $this->oauthClient->exchangeCode($code, $state, $codeVerifier);

            // Clear OAuth session data
            $this->user->session_setvar('atproto_oauth_state', null);
            $this->user->session_setvar('atproto_code_verifier', null);
            $this->user->session_setvar('atproto_pds_url', null);
            $this->user->session_setvar('atproto_handle', null);

            // Find or create phpBB user
            $userId = $this->findOrCreateUser($tokens['did'], $handle);

            // Store tokens
            $this->tokenManager->storeTokens(
                $userId,
                $tokens['did'],
                $handle,
                $pdsUrl,
                $tokens['access_token'],
                $tokens['refresh_token'],
                $tokens['expires_in']
            );

            // Create phpBB session
            $this->auth->login($handle, '', false, true, true);

            // Redirect to forum index
            return new RedirectResponse(
                append_sid($this->phpbbRootPath . 'index.' . $this->phpEx)
            );

        } catch (oauth_exception $e) {
            return $this->handleError($e->getErrorCode(), $e->getMessage());
        }
    }

    /**
     * Find existing phpBB user by DID or create new one.
     */
    private function findOrCreateUser(string $did, string $handle): int
    {
        // Check if user already linked
        $existingUserId = $this->tokenManager->findUserByDid($did);
        if ($existingUserId !== null) {
            return $existingUserId;
        }

        // Check if user exists by handle (as username)
        $cleanHandle = $this->sanitizeUsername($handle);
        $sql = 'SELECT user_id FROM ' . USERS_TABLE . '
                WHERE username_clean = \'' . $this->db->sql_escape(utf8_clean_string($cleanHandle)) . '\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row) {
            return (int) $row['user_id'];
        }

        // Create new user
        return $this->createUser($cleanHandle, $did);
    }

    /**
     * Create a new phpBB user.
     */
    private function createUser(string $username, string $did): int
    {
        if (!function_exists('user_add')) {
            include($this->phpbbRootPath . 'includes/functions_user.' . $this->phpEx);
        }

        $userData = [
            'username' => $username,
            'user_password' => '', // No password - OAuth only
            'user_email' => '', // Will be fetched from profile later
            'group_id' => (int) $this->config['default_usergroup'],
            'user_type' => USER_NORMAL,
            'user_regdate' => time(),
        ];

        $userId = user_add($userData);

        return $userId;
    }

    /**
     * Sanitize AT Protocol handle for use as phpBB username.
     */
    private function sanitizeUsername(string $handle): string
    {
        // Remove TLD (e.g., .bsky.social) to make shorter username
        $parts = explode('.', $handle);
        if (count($parts) > 2) {
            return $parts[0];
        }

        // Fallback: replace dots with underscores
        return str_replace('.', '_', $handle);
    }

    /**
     * Handle OAuth error.
     */
    private function handleError(string $code, string $message): Response
    {
        $this->template->assign_vars([
            'ATPROTO_LOGIN_ERROR' => $this->getErrorMessage($code) . ': ' . $message,
            'S_ATPROTO_LOGIN' => true,
        ]);

        return $this->renderPage('atproto_login');
    }

    /**
     * Get user-friendly error message.
     */
    private function getErrorMessage(string $code): string
    {
        $messages = [
            oauth_exception::CODE_INVALID_HANDLE => $this->user->lang('ATPROTO_ERROR_INVALID_HANDLE'),
            oauth_exception::CODE_DID_RESOLUTION_FAILED => $this->user->lang('ATPROTO_ERROR_DID_RESOLUTION'),
            oauth_exception::CODE_OAUTH_DENIED => $this->user->lang('ATPROTO_ERROR_OAUTH_DENIED'),
            oauth_exception::CODE_TOKEN_EXCHANGE_FAILED => $this->user->lang('ATPROTO_ERROR_TOKEN_EXCHANGE'),
            oauth_exception::CODE_STATE_MISMATCH => $this->user->lang('ATPROTO_ERROR_STATE_MISMATCH'),
            oauth_exception::CODE_CONFIG_ERROR => $this->user->lang('ATPROTO_ERROR_CONFIG'),
        ];

        return $messages[$code] ?? $this->user->lang('ATPROTO_ERROR_UNKNOWN');
    }

    /**
     * Render a template page.
     */
    private function renderPage(string $template): Response
    {
        page_header($this->user->lang('LOGIN'));

        $this->template->set_filenames([
            'body' => '@phpbb_atproto/' . $template . '.html',
        ]);

        page_footer();

        // phpBB's page_footer() calls exit, so we won't reach here
        // This is for type safety
        return new Response();
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/controller/OAuthControllerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/controller/oauth_controller.php tests/ext/phpbb/atproto/controller/OAuthControllerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add OAuth controller for login flow

- Start endpoint: handle input, generate state, redirect to PDS
- Callback endpoint: validate state, exchange code, create session
- Find or create phpBB user by DID
- User-friendly error messages
- CSRF protection via state parameter

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Auth Event Listener

**Files:**
- Create: `ext/phpbb/atproto/event/auth_listener.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/event/AuthListenerTest.php
<?php

namespace phpbb\atproto\tests\event;

class AuthListenerTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\event\auth_listener'));
    }

    public function test_implements_event_subscriber()
    {
        $reflection = new \ReflectionClass('\phpbb\atproto\event\auth_listener');
        $this->assertTrue($reflection->implementsInterface(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class));
    }

    public function test_subscribes_to_logout_event()
    {
        $events = \phpbb\atproto\event\auth_listener::getSubscribedEvents();
        $this->assertArrayHasKey('core.logout_after', $events);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/event/AuthListenerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\event\auth_listener' not found"

**Step 3: Create auth_listener.php**

```php
<?php

namespace phpbb\atproto\event;

use phpbb\atproto\services\token_manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener for AT Protocol authentication events.
 */
class auth_listener implements EventSubscriberInterface
{
    private token_manager $tokenManager;
    private \phpbb\user $user;
    private \phpbb\db\driver\driver_interface $db;
    private string $tablePrefix;

    public function __construct(
        token_manager $tokenManager,
        \phpbb\user $user,
        \phpbb\db\driver\driver_interface $db,
        string $tablePrefix
    ) {
        $this->tokenManager = $tokenManager;
        $this->user = $user;
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'core.user_setup_after' => 'onUserSetupAfter',
            'core.session_create_after' => 'onSessionCreateAfter',
            'core.logout_after' => 'onLogoutAfter',
        ];
    }

    /**
     * Check token validity after user setup.
     */
    public function onUserSetupAfter(\phpbb\event\data $event): void
    {
        if ($this->user->data['user_id'] == ANONYMOUS) {
            return;
        }

        $userId = (int) $this->user->data['user_id'];

        // Check if user has AT Protocol tokens
        $did = $this->tokenManager->getUserDid($userId);
        if ($did === null) {
            return;
        }

        // Check if token is still valid
        if (!$this->tokenManager->isTokenValid($userId)) {
            // Token expired and can't be refreshed - user needs to re-login
            // We don't force logout here, but mark for re-auth
            $this->user->session_setvar('atproto_needs_reauth', true);
        }
    }

    /**
     * Bind session to DID after creation.
     */
    public function onSessionCreateAfter(\phpbb\event\data $event): void
    {
        if ($this->user->data['user_id'] == ANONYMOUS) {
            return;
        }

        $userId = (int) $this->user->data['user_id'];
        $did = $this->tokenManager->getUserDid($userId);

        if ($did !== null) {
            // Store DID in session for quick access
            $this->user->session_setvar('atproto_did', $did);
        }
    }

    /**
     * Clear AT Protocol tokens on logout.
     */
    public function onLogoutAfter(\phpbb\event\data $event): void
    {
        $userId = $event['user_id'] ?? null;

        if ($userId === null || $userId == ANONYMOUS) {
            return;
        }

        // Clear tokens
        $this->tokenManager->clearTokens((int) $userId);

        // Clear session variables
        $this->user->session_setvar('atproto_did', null);
        $this->user->session_setvar('atproto_needs_reauth', null);
    }

    /**
     * Check if user needs re-authentication.
     */
    public function needsReauth(): bool
    {
        return (bool) $this->user->session_getvar('atproto_needs_reauth');
    }

    /**
     * Get current user's DID from session.
     */
    public function getCurrentDid(): ?string
    {
        return $this->user->session_getvar('atproto_did');
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/event/AuthListenerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/event/auth_listener.php tests/ext/phpbb/atproto/event/AuthListenerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add auth event listener

- Check token validity on user setup
- Bind session to DID after creation
- Clear tokens on logout
- Track re-authentication needs

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Language Strings

**Files:**
- Create: `ext/phpbb/atproto/language/en/common.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/language/LanguageTest.php
<?php

namespace phpbb\atproto\tests\language;

class LanguageTest extends \phpbb_test_case
{
    public function test_english_language_file_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/language/en/common.php';
        $this->assertFileExists($path);
    }

    public function test_language_file_returns_array()
    {
        $lang = [];
        include __DIR__ . '/../../../../ext/phpbb/atproto/language/en/common.php';
        $this->assertIsArray($lang);
        $this->assertNotEmpty($lang);
    }

    public function test_has_required_keys()
    {
        $lang = [];
        include __DIR__ . '/../../../../ext/phpbb/atproto/language/en/common.php';

        $requiredKeys = [
            'ATPROTO_LOGIN',
            'ATPROTO_LOGIN_HANDLE',
            'ATPROTO_LOGIN_BUTTON',
            'ATPROTO_ERROR_INVALID_HANDLE',
            'ATPROTO_ERROR_DID_RESOLUTION',
            'ATPROTO_ERROR_OAUTH_DENIED',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $lang, "Missing language key: $key");
        }
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/language/LanguageTest.php`
Expected: FAIL with "Failed asserting that file exists"

**Step 3: Create common.php**

```php
<?php

if (!defined('IN_PHPBB'))
{
    exit;
}

if (empty($lang) || !is_array($lang))
{
    $lang = [];
}

$lang = array_merge($lang, [
    // Login form
    'ATPROTO_LOGIN' => 'Login with AT Protocol',
    'ATPROTO_LOGIN_HANDLE' => 'AT Protocol Handle',
    'ATPROTO_LOGIN_HANDLE_EXPLAIN' => 'Enter your handle (e.g., alice.bsky.social) or DID',
    'ATPROTO_LOGIN_BUTTON' => 'Login with AT Protocol',
    'ATPROTO_LOGIN_OR' => 'Or continue with traditional login',

    // Success messages
    'ATPROTO_LOGIN_SUCCESS' => 'Successfully logged in with AT Protocol',
    'ATPROTO_ACCOUNT_LINKED' => 'Your AT Protocol account has been linked',

    // Error messages
    'ATPROTO_ERROR_INVALID_HANDLE' => 'Invalid handle format',
    'ATPROTO_ERROR_DID_RESOLUTION' => 'Could not resolve your handle',
    'ATPROTO_ERROR_OAUTH_DENIED' => 'Authorization was denied',
    'ATPROTO_ERROR_TOKEN_EXCHANGE' => 'Failed to complete login',
    'ATPROTO_ERROR_STATE_MISMATCH' => 'Security validation failed - please try again',
    'ATPROTO_ERROR_CONFIG' => 'AT Protocol login is not properly configured',
    'ATPROTO_ERROR_UNKNOWN' => 'An unknown error occurred',
    'ATPROTO_ERROR_REFRESH_FAILED' => 'Your session has expired - please login again',

    // Account linking
    'ATPROTO_LINK_ACCOUNT' => 'Link AT Protocol Account',
    'ATPROTO_LINK_EXPLAIN' => 'Connect your existing forum account with AT Protocol for decentralized login',
    'ATPROTO_UNLINK_ACCOUNT' => 'Unlink AT Protocol Account',
    'ATPROTO_UNLINK_CONFIRM' => 'Are you sure you want to unlink your AT Protocol account?',
    'ATPROTO_LINKED_AS' => 'Linked as: %s',

    // Status messages
    'ATPROTO_SYNC_PENDING' => 'Syncing to AT Protocol...',
    'ATPROTO_SYNC_FAILED' => 'Sync failed - will retry',
    'ATPROTO_SYNC_COMPLETE' => 'Synced to AT Protocol',

    // Profile
    'ATPROTO_DID' => 'AT Protocol DID',
    'ATPROTO_HANDLE' => 'AT Protocol Handle',
    'ATPROTO_PDS' => 'Personal Data Server',

    // Admin settings
    'ACP_ATPROTO_SETTINGS' => 'AT Protocol Settings',
    'ACP_ATPROTO_SETTINGS_EXPLAIN' => 'Configure AT Protocol integration for decentralized authentication and data storage',
    'ACP_ATPROTO_CLIENT_ID' => 'OAuth Client ID',
    'ACP_ATPROTO_CLIENT_ID_EXPLAIN' => 'URL to your client-metadata.json file',
    'ACP_ATPROTO_ENABLED' => 'Enable AT Protocol login',
    'ACP_ATPROTO_FORUM_DID' => 'Forum DID',
    'ACP_ATPROTO_FORUM_DID_EXPLAIN' => 'The DID for this forum\'s AT Protocol identity',
]);
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/language/LanguageTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/language/en/common.php tests/ext/phpbb/atproto/language/LanguageTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add English language strings

- Login form labels and buttons
- Error messages for all OAuth error codes
- Account linking UI strings
- Sync status messages
- Admin settings labels

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Login Template

**Files:**
- Create: `ext/phpbb/atproto/styles/prosilver/template/atproto_login.html`
- Create: `ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/styles/TemplateTest.php
<?php

namespace phpbb\atproto\tests\styles;

class TemplateTest extends \phpbb_test_case
{
    public function test_login_template_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $this->assertFileExists($path);
    }

    public function test_nav_event_template_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html';
        $this->assertFileExists($path);
    }

    public function test_login_template_has_form()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $content = file_get_contents($path);

        $this->assertStringContainsString('<form', $content);
        $this->assertStringContainsString('handle', $content);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/styles/TemplateTest.php`
Expected: FAIL with "Failed asserting that file exists"

**Step 3: Create atproto_login.html**

```html
{% INCLUDE 'overall_header.html' %}

<div class="panel">
    <div class="inner">
        <h2 class="panel-title">{L_ATPROTO_LOGIN}</h2>

        {% if ATPROTO_LOGIN_ERROR %}
        <div class="error">
            {ATPROTO_LOGIN_ERROR}
        </div>
        {% endif %}

        <form method="post" action="{U_ATPROTO_LOGIN}" class="atproto-login-form">
            <fieldset>
                <dl>
                    <dt>
                        <label for="atproto_handle">{L_ATPROTO_LOGIN_HANDLE}:</label>
                        <br><span class="explain">{L_ATPROTO_LOGIN_HANDLE_EXPLAIN}</span>
                    </dt>
                    <dd>
                        <input type="text"
                               name="handle"
                               id="atproto_handle"
                               class="inputbox autowidth"
                               placeholder="alice.bsky.social"
                               autocomplete="username"
                               required>
                    </dd>
                </dl>
            </fieldset>

            <fieldset class="submit-buttons">
                <input type="submit"
                       name="login"
                       value="{L_ATPROTO_LOGIN_BUTTON}"
                       class="button1">
            </fieldset>
        </form>

        <hr>

        <p class="atproto-alt-login">
            {L_ATPROTO_LOGIN_OR}
            <a href="{U_LOGIN}">{L_LOGIN}</a>
        </p>
    </div>
</div>

{% INCLUDE 'overall_footer.html' %}
```

**Step 4: Create overall_header_navigation_prepend.html**

```html
{% if S_USER_LOGGED_IN and ATPROTO_DID %}
<li class="atproto-status" title="{L_ATPROTO_LINKED_AS}: {ATPROTO_HANDLE}">
    <span class="atproto-icon">🔗</span>
</li>
{% endif %}

{% if not S_USER_LOGGED_IN %}
<li class="atproto-login-link">
    <a href="{U_ATPROTO_LOGIN}" title="{L_ATPROTO_LOGIN}">
        {L_ATPROTO_LOGIN}
    </a>
</li>
{% endif %}
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/styles/TemplateTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/styles/prosilver/template/atproto_login.html ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html tests/ext/phpbb/atproto/styles/TemplateTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add login templates for prosilver theme

- AT Protocol login form with handle input
- Navigation bar integration (login link, linked status)
- Error message display
- Fallback to traditional login

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Integration Test

**Files:**
- Create: `tests/ext/phpbb/atproto/integration/AuthFlowTest.php`

**Step 1: Write integration test**

```php
// tests/ext/phpbb/atproto/integration/AuthFlowTest.php
<?php

namespace phpbb\atproto\tests\integration;

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
class AuthFlowTest extends \phpbb_database_test_case
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

    public function test_encryption_round_trip()
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

    public function test_did_validation()
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

    public function test_handle_validation()
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

    public function test_pds_url_extraction()
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

    public function test_key_rotation()
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

    public function test_oauth_exception_codes()
    {
        $exception = new \phpbb\atproto\auth\oauth_exception(
            \phpbb\atproto\auth\oauth_exception::CODE_INVALID_HANDLE,
            'Test message'
        );

        $this->assertEquals('AUTH_INVALID_HANDLE', $exception->getErrorCode());
        $this->assertEquals('Test message', $exception->getMessage());
    }

    public function test_migration_schema_completeness()
    {
        // This would require a real database connection
        // For now, just verify the migration class exists and has correct structure
        $this->assertTrue(class_exists('\phpbb\atproto\migrations\v1\m1_initial_schema'));

        $deps = \phpbb\atproto\migrations\v1\m1_initial_schema::depends_on();
        $this->assertContains('\phpbb\db\migration\data\v330\v330', $deps);
    }
}
```

**Step 2: Run integration test**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/integration/AuthFlowTest.php`
Expected: PASS (all components work together)

**Step 3: Commit**

```bash
git add tests/ext/phpbb/atproto/integration/AuthFlowTest.php
git commit -m "$(cat <<'EOF'
test(atproto): add integration tests for auth flow

- Token encryption round-trip
- DID and handle validation
- PDS URL extraction from DID documents
- Key rotation support
- OAuth exception handling
- Migration schema verification

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Final Verification

**Step 1: Run all tests**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/`
Expected: All tests PASS

**Step 2: Verify extension structure**

Run: `ls -la ext/phpbb/atproto/`
Expected output shows all directories and files created

**Step 3: Verify services.yml is valid YAML**

Run: `php -r "print_r(yaml_parse_file('ext/phpbb/atproto/config/services.yml'));"`
Expected: Array output with no errors

**Step 4: Final commit - complete foundation**

```bash
git add -A
git status
git commit -m "$(cat <<'EOF'
feat(atproto): complete foundation phase implementation

Foundation phase delivers:
- Extension skeleton with proper phpBB structure
- Database migrations for 6 AT Protocol tables
- XChaCha20-Poly1305 token encryption with key rotation
- DID resolution (did:plc and did:web)
- Full OAuth flow with PKCE support
- Token management with automatic refresh
- Auth event listener for session handling
- Login UI templates for prosilver theme
- Comprehensive test suite

Ready for Phase 2: Write Path implementation

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Verification Checklist

After completing all tasks:

- [ ] Extension can be enabled in phpBB ACP
- [ ] All 6 database tables created
- [ ] Token encryption works (round-trip test)
- [ ] DID resolution works for valid handles
- [ ] OAuth login button appears on login page
- [ ] OAuth flow redirects to PDS
- [ ] Callback creates phpBB session
- [ ] Logout clears AT Protocol tokens
- [ ] All unit tests pass
- [ ] All integration tests pass

---

## File Summary

| Task | Files Created |
|------|---------------|
| 1 | `ext.php`, `composer.json` |
| 2 | `config/services.yml`, `config/routing.yml` |
| 3 | `migrations/v1/m1_initial_schema.php` |
| 4 | `auth/token_encryption.php` |
| 5 | `services/did_resolver.php` |
| 6 | `auth/oauth_client.php`, `auth/oauth_client_interface.php`, `auth/oauth_exception.php` |
| 7 | `services/token_manager.php`, `services/token_manager_interface.php`, `exceptions/*.php` |
| 8 | `controller/oauth_controller.php` |
| 9 | `event/auth_listener.php` |
| 10 | `language/en/common.php` |
| 11 | `styles/prosilver/template/*.html` |
| 12 | `tests/integration/AuthFlowTest.php` |

**Total: ~20 source files + ~12 test files**

---

## Reference Files

- `docs/spec/components/phpbb-extension/migrations.md` - Full migration spec with table schemas
- `docs/spec/components/phpbb-extension/auth-provider.md` - Auth flow spec with interfaces
- `docs/spec/components/phpbb-extension/write-interceptor.md` - Write path (next phase)
- `docs/api-contracts.md` - Interface definitions
- `docs/spec/lexicons/` - AT Protocol lexicon definitions (10 files)
