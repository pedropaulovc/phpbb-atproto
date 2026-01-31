<?php

declare(strict_types=1);

namespace phpbb\atproto\migrations\v1;

/**
 * Initial database schema migration for AT Protocol integration.
 *
 * Creates 6 tables:
 * - atproto_users: DID-to-user mapping with encrypted tokens
 * - atproto_posts: AT URI-to-post_id mapping
 * - atproto_forums: AT URI-to-forum_id mapping
 * - atproto_labels: Cached moderation labels
 * - atproto_cursors: Firehose cursor positions
 * - atproto_queue: Retry queue for failed PDS writes
 */
class m1_initial_schema extends \phpbb\db\migration\migration
{
    /**
     * Define migration dependencies.
     *
     * @return array Array of migration class names this migration depends on
     */
    public static function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    /**
     * Check if migration is effectively installed.
     *
     * @return bool True if the atproto_users table already exists
     */
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'atproto_users');
    }

    /**
     * Define schema updates.
     *
     * @return array Schema changes to apply
     */
    public function update_schema()
    {
        return [
            'add_tables' => [
                // User DID mapping and OAuth tokens
                $this->table_prefix . 'atproto_users' => [
                    'COLUMNS' => [
                        'user_id' => ['UINT', 0],
                        'did' => ['VCHAR:255', ''],
                        'handle' => ['VCHAR:255', null],
                        'pds_url' => ['VCHAR:512', ''],
                        'access_token' => ['TEXT', null],
                        'refresh_token' => ['TEXT', null],
                        'token_expires_at' => ['UINT', null],
                        'migration_status' => ['VCHAR:20', 'none'],
                        'created_at' => ['UINT', 0],
                        'updated_at' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'user_id',
                    'KEYS' => [
                        'idx_did' => ['UNIQUE', 'did'],
                        'idx_handle' => ['INDEX', 'handle'],
                        'idx_token_expires' => ['INDEX', 'token_expires_at'],
                    ],
                ],

                // Post AT URI mapping
                $this->table_prefix . 'atproto_posts' => [
                    'COLUMNS' => [
                        'post_id' => ['UINT', 0],
                        'at_uri' => ['VCHAR:512', ''],
                        'at_cid' => ['VCHAR:64', ''],
                        'synced_at' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'post_id',
                    'KEYS' => [
                        'idx_at_uri' => ['UNIQUE', 'at_uri'],
                    ],
                ],

                // Forum AT URI mapping
                $this->table_prefix . 'atproto_forums' => [
                    'COLUMNS' => [
                        'forum_id' => ['UINT', 0],
                        'at_uri' => ['VCHAR:512', ''],
                        'at_cid' => ['VCHAR:64', ''],
                        'synced_at' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'forum_id',
                    'KEYS' => [
                        'idx_at_uri' => ['UNIQUE', 'at_uri'],
                    ],
                ],

                // Cached moderation labels from labelers
                $this->table_prefix . 'atproto_labels' => [
                    'COLUMNS' => [
                        'label_id' => ['UINT', null, 'auto_increment'],
                        'subject_uri' => ['VCHAR:512', ''],
                        'label_value' => ['VCHAR:128', ''],
                        'source_did' => ['VCHAR:255', ''],
                        'negated' => ['BOOL', 0],
                        'created_at' => ['UINT', 0],
                        'expires_at' => ['UINT', null],
                    ],
                    'PRIMARY_KEY' => 'label_id',
                    'KEYS' => [
                        'idx_subject' => ['INDEX', 'subject_uri'],
                        'idx_source' => ['INDEX', 'source_did'],
                        'idx_expires' => ['INDEX', 'expires_at'],
                    ],
                ],

                // Firehose cursor positions for resumable subscriptions
                $this->table_prefix . 'atproto_cursors' => [
                    'COLUMNS' => [
                        'cursor_id' => ['UINT', null, 'auto_increment'],
                        'service_name' => ['VCHAR:255', ''],
                        'cursor_value' => ['VCHAR:255', ''],
                        'updated_at' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'cursor_id',
                    'KEYS' => [
                        'idx_service' => ['UNIQUE', 'service_name'],
                    ],
                ],

                // Retry queue for failed PDS write operations
                $this->table_prefix . 'atproto_queue' => [
                    'COLUMNS' => [
                        'queue_id' => ['UINT', null, 'auto_increment'],
                        'operation' => ['VCHAR:64', ''],
                        'payload' => ['TEXT', ''],
                        'attempts' => ['UINT', 0],
                        'max_attempts' => ['UINT', 5],
                        'last_error' => ['TEXT', null],
                        'next_retry_at' => ['UINT', 0],
                        'created_at' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'queue_id',
                    'KEYS' => [
                        'idx_next_retry' => ['INDEX', 'next_retry_at'],
                        'idx_operation' => ['INDEX', 'operation'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Define schema revert operations.
     *
     * @return array Schema changes to revert
     */
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
