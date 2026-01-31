# Component: Database Migrations

## Overview
- **Purpose**: Create database tables for AT Protocol integration mapping and state storage
- **Location**: `ext/phpbb/atproto/migrations/`
- **Dependencies**: phpBB core database layer
- **Dependents**: All phpbb-extension components, all sync-service components

## Acceptance Criteria
- [ ] AC-1: All 6 tables (`phpbb_atproto_users`, `phpbb_atproto_posts`, `phpbb_atproto_forums`, `phpbb_atproto_labels`, `phpbb_atproto_cursors`, `phpbb_atproto_queue`) exist after migration
- [ ] AC-2: Migrations are idempotent - running twice produces no errors
- [ ] AC-3: All foreign key relationships use proper phpBB table prefix
- [ ] AC-4: All indexes are created for query performance
- [ ] AC-5: Token columns support encrypted storage format
- [ ] AC-6: Migration can be reverted cleanly

## File Structure
```
ext/phpbb/atproto/
├── migrations/
│   └── v1/
│       └── m1_initial_schema.php    # All 6 tables
├── composer.json
└── ext.php
```

## Migration Class

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

## Database Interactions

### Tables Created

| Table | Purpose |
|-------|---------|
| `phpbb_atproto_users` | DID to user_id mapping, OAuth token storage |
| `phpbb_atproto_posts` | AT URI to post_id mapping |
| `phpbb_atproto_forums` | AT URI to forum_id mapping |
| `phpbb_atproto_labels` | Cached moderation labels |
| `phpbb_atproto_cursors` | Firehose cursor positions |
| `phpbb_atproto_queue` | Retry queue for failed PDS writes |

### Key Queries After Migration

```sql
-- Verify tables exist
SHOW TABLES LIKE 'phpbb_atproto_%';

-- Verify indexes
SHOW INDEX FROM phpbb_atproto_users;
SHOW INDEX FROM phpbb_atproto_posts;
SHOW INDEX FROM phpbb_atproto_labels;
```

## Error Handling

| Condition | Recovery |
|-----------|----------|
| Table already exists | `effectively_installed()` returns true, skip |
| Partial migration failure | Transaction rollback, retry |
| Foreign key constraint | phpBB DBAL handles without FK constraints |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| N/A | N/A | N/A | No configuration - schema-only migration |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Fresh install migration | All 6 tables created with correct schema |
| Re-run migration | No errors, no duplicate tables |
| Revert migration | All 6 tables dropped |
| Check index performance | Queries use expected indexes |
| Insert test data | Columns accept expected data types |

## Implementation Notes

### Security Considerations
- Token columns (`access_token`, `refresh_token`) store encrypted values
- Encryption format: `version:base64(nonce || ciphertext || tag)`
- Use TEXT type to accommodate variable-length encrypted data

### Performance Considerations
- Composite index on `(next_retry_at, status)` for queue processing
- Unique constraint on `(subject_uri, label_value, label_src)` prevents duplicate labels
- `subject_uri` indexed for label lookups (sticky moderation)

### phpBB DBAL Notes
- Use phpBB column types (UINT, VCHAR, TEXT, BOOL, BINT)
- No explicit foreign keys - referential integrity via application logic
- Table prefix handled automatically via `$this->table_prefix`

## References
- [phpBB Database Migrations](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_migrations.html)
- [api-contracts.md](../../api-contracts.md) - Full SQL schemas
- [architecture.md](../../architecture.md) - Table purposes
