<?php

declare(strict_types=1);

namespace phpbb\atproto\tests;

use PHPUnit\Framework\TestCase;

class ExtensionTest extends TestCase
{
    public function test_extension_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\ext'));
    }

    public function test_extension_is_enableable_checks_requirements(): void
    {
        $ext = new \phpbb\atproto\ext();

        // is_enableable returns true only if sodium extension is loaded
        // AND PHP version is 8.4+
        $expected = extension_loaded('sodium') && PHP_VERSION_ID >= 80400;
        $this->assertSame($expected, $ext->is_enableable());
    }

    public function test_extension_requires_sodium(): void
    {
        // Verify the extension checks for sodium
        // If sodium is not loaded, is_enableable should return false
        $ext = new \phpbb\atproto\ext();

        if (!extension_loaded('sodium')) {
            $this->assertFalse($ext->is_enableable());
        } else {
            // If sodium is loaded, the result depends on PHP version
            $this->assertIsBool($ext->is_enableable());
        }
    }
}
