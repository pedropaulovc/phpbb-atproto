<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\migrations;

use PHPUnit\Framework\TestCase;

class InitialSchemaTest extends TestCase
{
    public function test_migration_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\migrations\v1\m1_initial_schema'));
    }

    public function test_migration_depends_on_v330(): void
    {
        $deps = \phpbb\atproto\migrations\v1\m1_initial_schema::depends_on();
        $this->assertContains('\phpbb\db\migration\data\v330\v330', $deps);
    }

    public function test_migration_has_update_schema(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('add_tables', $schema);
    }

    public function test_migration_creates_users_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_users', $tables);

        $columns = $tables['phpbb_atproto_users']['COLUMNS'];
        $this->assertArrayHasKey('user_id', $columns);
        $this->assertArrayHasKey('did', $columns);
        $this->assertArrayHasKey('handle', $columns);
        $this->assertArrayHasKey('pds_url', $columns);
        $this->assertArrayHasKey('access_token', $columns);
        $this->assertArrayHasKey('refresh_token', $columns);
        $this->assertArrayHasKey('token_expires_at', $columns);
        $this->assertArrayHasKey('migration_status', $columns);
        $this->assertArrayHasKey('created_at', $columns);
        $this->assertArrayHasKey('updated_at', $columns);
    }

    public function test_migration_creates_posts_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_posts', $tables);

        $columns = $tables['phpbb_atproto_posts']['COLUMNS'];
        $this->assertArrayHasKey('post_id', $columns);
        $this->assertArrayHasKey('at_uri', $columns);
        $this->assertArrayHasKey('at_cid', $columns);
        $this->assertArrayHasKey('synced_at', $columns);
    }

    public function test_migration_creates_forums_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_forums', $tables);

        $columns = $tables['phpbb_atproto_forums']['COLUMNS'];
        $this->assertArrayHasKey('forum_id', $columns);
        $this->assertArrayHasKey('at_uri', $columns);
        $this->assertArrayHasKey('at_cid', $columns);
    }

    public function test_migration_creates_labels_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_labels', $tables);

        $columns = $tables['phpbb_atproto_labels']['COLUMNS'];
        $this->assertArrayHasKey('label_id', $columns);
        $this->assertArrayHasKey('subject_uri', $columns);
        $this->assertArrayHasKey('label_value', $columns);
        $this->assertArrayHasKey('source_did', $columns);
        $this->assertArrayHasKey('created_at', $columns);
        $this->assertArrayHasKey('expires_at', $columns);
    }

    public function test_migration_creates_cursors_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_cursors', $tables);

        $columns = $tables['phpbb_atproto_cursors']['COLUMNS'];
        $this->assertArrayHasKey('cursor_id', $columns);
        $this->assertArrayHasKey('service_name', $columns);
        $this->assertArrayHasKey('cursor_value', $columns);
        $this->assertArrayHasKey('updated_at', $columns);
    }

    public function test_migration_creates_queue_table(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('phpbb_atproto_queue', $tables);

        $columns = $tables['phpbb_atproto_queue']['COLUMNS'];
        $this->assertArrayHasKey('queue_id', $columns);
        $this->assertArrayHasKey('operation', $columns);
        $this->assertArrayHasKey('payload', $columns);
        $this->assertArrayHasKey('attempts', $columns);
        $this->assertArrayHasKey('last_error', $columns);
        $this->assertArrayHasKey('next_retry_at', $columns);
        $this->assertArrayHasKey('created_at', $columns);
    }

    public function test_migration_has_revert_schema(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->revert_schema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('drop_tables', $schema);

        $tables = $schema['drop_tables'];
        $this->assertContains('phpbb_atproto_users', $tables);
        $this->assertContains('phpbb_atproto_posts', $tables);
        $this->assertContains('phpbb_atproto_forums', $tables);
        $this->assertContains('phpbb_atproto_labels', $tables);
        $this->assertContains('phpbb_atproto_cursors', $tables);
        $this->assertContains('phpbb_atproto_queue', $tables);
    }

    public function test_users_table_has_unique_did_index(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $keys = $tables['phpbb_atproto_users']['KEYS'];
        $this->assertArrayHasKey('idx_did', $keys);
        $this->assertEquals(['UNIQUE', 'did'], $keys['idx_did']);
    }

    public function test_posts_table_has_primary_key(): void
    {
        $migration = $this->createMigration();
        $schema = $migration->update_schema();
        $tables = $schema['add_tables'];

        $this->assertArrayHasKey('PRIMARY_KEY', $tables['phpbb_atproto_posts']);
        $this->assertEquals('post_id', $tables['phpbb_atproto_posts']['PRIMARY_KEY']);
    }

    private function createMigration(): \phpbb\atproto\migrations\v1\m1_initial_schema
    {
        return new \phpbb\atproto\migrations\v1\m1_initial_schema(
            null,
            null,
            $this->createDbToolsMock(),
            '',
            'php',
            'phpbb_'
        );
    }

    private function createDbToolsMock(): object
    {
        $mock = new class () {
            public function sql_table_exists(string $table): bool
            {
                return false;
            }
        };

        return $mock;
    }
}
