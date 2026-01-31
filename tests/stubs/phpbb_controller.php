<?php

declare(strict_types=1);

/**
 * Stubs for phpBB controller helper class.
 * Used for unit testing controllers.
 */

namespace phpbb\controller;

use Symfony\Component\HttpFoundation\Response;

if (!class_exists('\phpbb\controller\helper')) {
    class helper
    {
        private string $phpbbRootPath;
        private array $routes = [];

        public function __construct(string $phpbbRootPath = '')
        {
            $this->phpbbRootPath = $phpbbRootPath;
        }

        public function route(string $routeName, array $params = []): string
        {
            return $this->routes[$routeName] ?? '/app.php/' . $routeName;
        }

        public function render(string $template, string $title = '', int $statusCode = 200, bool $displayOnlineList = false): Response
        {
            return new Response('', $statusCode);
        }

        public function get_phpbb_root_path(): string
        {
            return $this->phpbbRootPath;
        }

        public function setRoute(string $name, string $url): void
        {
            $this->routes[$name] = $url;
        }
    }
}
