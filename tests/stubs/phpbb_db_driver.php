<?php

declare(strict_types=1);

/**
 * Stub for phpBB database driver interface.
 * Used for unit testing services that depend on database access.
 */

namespace phpbb\db\driver;

if (!interface_exists('\phpbb\db\driver\driver_interface')) {
    interface driver_interface
    {
        /**
         * Execute a SQL query.
         *
         * @param string $query The SQL query
         *
         * @return mixed Query result resource or false on failure
         */
        public function sql_query(string $query): mixed;

        /**
         * Fetch a row from the query result.
         *
         * @param mixed $result Query result resource
         *
         * @return array|false Row data as associative array or false if no more rows
         */
        public function sql_fetchrow(mixed $result): array|false;

        /**
         * Free the result resource.
         *
         * @param mixed $result Query result resource
         *
         * @return bool True on success
         */
        public function sql_freeresult(mixed $result): bool;

        /**
         * Escape a string for use in a query.
         *
         * @param string $msg The string to escape
         *
         * @return string Escaped string
         */
        public function sql_escape(string $msg): string;

        /**
         * Start, commit, or rollback a transaction.
         *
         * @param string $status Transaction operation: 'begin', 'commit', or 'rollback'
         *
         * @return bool True on success
         */
        public function sql_transaction(string $status = 'begin'): bool;
    }
}
