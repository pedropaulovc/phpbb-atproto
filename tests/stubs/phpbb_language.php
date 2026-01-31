<?php

declare(strict_types=1);

/**
 * Stubs for phpBB language class.
 * Used for unit testing services that use localization.
 */

namespace phpbb\language;

if (!class_exists('\phpbb\language\language')) {
    class language
    {
        private array $strings = [];

        public function __construct(array $strings = [])
        {
            $this->strings = $strings;
        }

        public function lang(string $key, ...$args): string
        {
            $string = $this->strings[$key] ?? $key;
            if (!empty($args)) {
                return sprintf($string, ...$args);
            }
            return $string;
        }

        public function add_lang(string $langFile, string $extension = ''): void
        {
            // No-op for testing
        }

        public function setString(string $key, string $value): void
        {
            $this->strings[$key] = $value;
        }
    }
}
