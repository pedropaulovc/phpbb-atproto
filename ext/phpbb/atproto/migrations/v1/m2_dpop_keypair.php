<?php

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
