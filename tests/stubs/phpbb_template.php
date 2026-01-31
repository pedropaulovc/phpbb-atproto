<?php

declare(strict_types=1);

/**
 * Stubs for phpBB template class.
 * Used for unit testing controllers.
 */

namespace phpbb\template;

if (!class_exists('\phpbb\template\template')) {
    class template
    {
        private array $vars = [];

        public function assign_vars(array $vars): void
        {
            $this->vars = array_merge($this->vars, $vars);
        }

        public function assign_var(string $key, $value): void
        {
            $this->vars[$key] = $value;
        }

        public function getVar(string $key)
        {
            return $this->vars[$key] ?? null;
        }

        public function getVars(): array
        {
            return $this->vars;
        }
    }
}
