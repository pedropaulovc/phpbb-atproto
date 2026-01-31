<?php

declare(strict_types=1);

/**
 * Stubs for phpBB request class.
 * Used for unit testing controllers.
 */

namespace phpbb\request;

if (!class_exists('\phpbb\request\request')) {
    class request
    {
        private array $variables = [];
        private bool $postSet = false;

        public function __construct(array $variables = [])
        {
            $this->variables = $variables;
        }

        public function variable(string $name, $default, bool $multibyte = false, int $super_global = 0)
        {
            return $this->variables[$name] ?? $default;
        }

        public function is_set_post(string $name): bool
        {
            return $this->postSet && isset($this->variables[$name]);
        }

        public function setVariable(string $name, $value): void
        {
            $this->variables[$name] = $value;
        }

        public function setPostActive(bool $active): void
        {
            $this->postSet = $active;
        }
    }
}
