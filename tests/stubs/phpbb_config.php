<?php

declare(strict_types=1);

/**
 * Stub for phpBB config class.
 * Used for unit testing services that depend on configuration.
 */

namespace phpbb\config;

if (!class_exists('\phpbb\config\config')) {
    class config implements \ArrayAccess
    {
        private array $data = [];

        public function __construct(array $data = [])
        {
            $this->data = $data;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->data[$offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->data[$offset] ?? '';
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->data[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->data[$offset]);
        }
    }
}
