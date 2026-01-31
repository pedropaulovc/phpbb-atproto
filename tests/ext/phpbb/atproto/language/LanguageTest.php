<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\language;

use PHPUnit\Framework\TestCase;

class LanguageTest extends TestCase
{
    public function test_english_language_file_exists(): void
    {
        $path = __DIR__ . '/../../../../../ext/phpbb/atproto/language/en/common.php';
        $this->assertFileExists($path);
    }

    public function test_language_file_returns_array(): void
    {
        $lang = [];
        include __DIR__ . '/../../../../../ext/phpbb/atproto/language/en/common.php';
        $this->assertIsArray($lang);
        $this->assertNotEmpty($lang);
    }

    public function test_has_required_keys(): void
    {
        $lang = [];
        include __DIR__ . '/../../../../../ext/phpbb/atproto/language/en/common.php';

        $requiredKeys = [
            'ATPROTO_LOGIN',
            'ATPROTO_LOGIN_HANDLE',
            'ATPROTO_LOGIN_BUTTON',
            'ATPROTO_ERROR_INVALID_HANDLE',
            'ATPROTO_ERROR_DID_RESOLUTION',
            'ATPROTO_ERROR_OAUTH_DENIED',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $lang, "Missing language key: $key");
        }
    }
}
