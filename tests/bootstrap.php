<?php
// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

// Mock phpBB globals for unit tests
define('IN_PHPBB', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
define('PHP_EXT', 'php');
