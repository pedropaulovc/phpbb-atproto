# Task 3: Database Migration

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
