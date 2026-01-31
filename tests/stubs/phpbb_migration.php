<?php

declare(strict_types=1);

/**
 * Stub for phpBB migration base class.
 * Used for unit testing migrations outside of a full phpBB installation.
 */

namespace phpbb\db\migration;

if (!class_exists('\phpbb\db\migration\migration')) {
    abstract class migration
    {
        /** @var object */
        protected $db;

        /** @var object */
        protected $db_tools;

        /** @var string */
        protected $table_prefix;

        /** @var string */
        protected $phpbb_root_path;

        /** @var string */
        protected $php_ext;

        /**
         * Constructor.
         */
        public function __construct($config = null, $db = null, $db_tools = null, $phpbb_root_path = '', $php_ext = 'php', $table_prefix = 'phpbb_')
        {
            $this->db = $db;
            $this->db_tools = $db_tools ?? new \stdClass();
            $this->table_prefix = $table_prefix;
            $this->phpbb_root_path = $phpbb_root_path;
            $this->php_ext = $php_ext;
        }

        /**
         * Defines other migrations to be applied first.
         *
         * @return array Array of migration class names
         */
        public static function depends_on()
        {
            return [];
        }

        /**
         * Checks whether the migration is effectively installed.
         *
         * @return bool True if installed, false otherwise
         */
        public function effectively_installed()
        {
            return false;
        }

        /**
         * Updates the database schema.
         *
         * @return array Array of schema changes
         */
        public function update_schema()
        {
            return [];
        }

        /**
         * Reverts the database schema changes.
         *
         * @return array Array of schema changes to revert
         */
        public function revert_schema()
        {
            return [];
        }

        /**
         * Updates data.
         *
         * @return array Array of data changes
         */
        public function update_data()
        {
            return [];
        }

        /**
         * Reverts data changes.
         *
         * @return array Array of data changes to revert
         */
        public function revert_data()
        {
            return [];
        }
    }
}

// Also create stub for the v330 migration dependency

namespace phpbb\db\migration\data\v330;

if (!class_exists('\phpbb\db\migration\data\v330\v330')) {
    class v330 extends \phpbb\db\migration\migration
    {
        public static function depends_on()
        {
            return [];
        }
    }
}
