<?php

declare(strict_types=1);

/**
 * Stubs for phpBB user class.
 * Used for unit testing services that use user data.
 */

namespace phpbb;

if (!defined('ANONYMOUS')) {
    define('ANONYMOUS', 1);
}

if (!class_exists('\phpbb\user')) {
    class user
    {
        public array $data = [];

        public function __construct(array $data = [])
        {
            $this->data = array_merge([
                'user_id' => ANONYMOUS,
            ], $data);
        }
    }
}
