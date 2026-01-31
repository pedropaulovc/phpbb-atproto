<?php

declare(strict_types=1);

/**
 * Stub for phpBB cache driver interface.
 * Used for unit testing services that depend on caching.
 */

namespace phpbb\cache\driver;

if (!interface_exists('\phpbb\cache\driver\driver_interface')) {
    interface driver_interface
    {
        /**
         * Get cached data by key.
         *
         * @param string $key Cache key
         *
         * @return mixed Cached data or false if not found
         */
        public function get(string $key): mixed;

        /**
         * Store data in cache.
         *
         * @param string $key  Cache key
         * @param mixed  $data Data to cache
         * @param int    $ttl  Time to live in seconds
         */
        public function put(string $key, mixed $data, int $ttl = 0): void;

        /**
         * Delete cached data by key.
         *
         * @param string $key Cache key
         */
        public function destroy(string $key): void;
    }
}
