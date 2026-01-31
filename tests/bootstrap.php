<?php

declare(strict_types=1);
// tests/bootstrap.php

// Load phpBB stubs first (before autoloader)
require_once __DIR__ . '/stubs/phpbb_extension_base.php';
require_once __DIR__ . '/stubs/phpbb_migration.php';
require_once __DIR__ . '/stubs/phpbb_cache_driver.php';
require_once __DIR__ . '/stubs/phpbb_db_driver.php';
require_once __DIR__ . '/stubs/phpbb_config.php';
require_once __DIR__ . '/stubs/phpbb_controller.php';
require_once __DIR__ . '/stubs/phpbb_language.php';
require_once __DIR__ . '/stubs/phpbb_request.php';
require_once __DIR__ . '/stubs/phpbb_template.php';
require_once __DIR__ . '/stubs/phpbb_user.php';

require_once __DIR__ . '/../vendor/autoload.php';

// Register AT Protocol extension autoloader
// phpBB's vendor autoloader doesn't include the extension namespace,
// so we register it manually for tests
spl_autoload_register(function (string $class): void {
    $prefix = 'phpbb\\atproto\\';
    $baseDir = __DIR__ . '/../ext/phpbb/atproto/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Mock phpBB globals for unit tests
define('IN_PHPBB', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
define('PHP_EXT', 'php');

// Mock phpBB global functions
if (!function_exists('append_sid')) {
    function append_sid(string $url, $params = false, bool $isAmp = true, string $sessionId = ''): string
    {
        return $url;
    }
}
