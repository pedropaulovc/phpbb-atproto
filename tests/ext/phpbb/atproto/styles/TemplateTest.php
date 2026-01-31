<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\styles;

use PHPUnit\Framework\TestCase;

class TemplateTest extends TestCase
{
    public function test_login_template_exists(): void
    {
        $path = __DIR__ . '/../../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $this->assertFileExists($path);
    }

    public function test_nav_event_template_exists(): void
    {
        $path = __DIR__ . '/../../../../../ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html';
        $this->assertFileExists($path);
    }

    public function test_login_template_has_form(): void
    {
        $path = __DIR__ . '/../../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $content = file_get_contents($path);

        $this->assertStringContainsString('<form', $content);
        $this->assertStringContainsString('handle', $content);
    }
}
