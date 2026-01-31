<?php

declare(strict_types=1);

namespace phpbb\atproto\tests;

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $this->configPath = __DIR__ . '/../../../../ext/phpbb/atproto/config';
    }

    public function test_services_yaml_exists(): void
    {
        $path = $this->configPath . '/services.yml';
        $this->assertFileExists($path);
    }

    public function test_routing_yaml_exists(): void
    {
        $path = $this->configPath . '/routing.yml';
        $this->assertFileExists($path);
    }

    public function test_services_yaml_is_valid(): void
    {
        $path = $this->configPath . '/services.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content, 'Failed to read services.yml');

        // Check that it has a services section
        $this->assertStringContainsString('services:', $content);
    }

    public function test_services_yaml_has_required_services(): void
    {
        $path = $this->configPath . '/services.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $requiredServices = [
            'phpbb.atproto.token_encryption',
            'phpbb.atproto.did_resolver',
            'phpbb.atproto.oauth_client',
            'phpbb.atproto.token_manager',
            'phpbb.atproto.pds_client',
            'phpbb.atproto.uri_mapper',
            'phpbb.atproto.queue_manager',
            'phpbb.atproto.record_builder',
            'phpbb.atproto.controller.oauth',
            'phpbb.atproto.event.auth_listener',
        ];

        foreach ($requiredServices as $service) {
            $this->assertStringContainsString($service . ':', $content, "Missing service: $service");
        }
    }

    public function test_routing_yaml_is_valid(): void
    {
        $path = $this->configPath . '/routing.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content, 'Failed to read routing.yml');

        // Basic check that it's not empty
        $this->assertNotEmpty(trim($content));
    }

    public function test_routing_yaml_has_oauth_routes(): void
    {
        $path = $this->configPath . '/routing.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('phpbb_atproto_oauth_callback:', $content);
        $this->assertStringContainsString('phpbb_atproto_oauth_start:', $content);
    }

    public function test_oauth_callback_route_has_correct_path(): void
    {
        $path = $this->configPath . '/routing.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('path: /atproto/callback', $content);
    }

    public function test_oauth_start_route_has_correct_path(): void
    {
        $path = $this->configPath . '/routing.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('path: /atproto/login', $content);
    }

    public function test_services_yaml_has_parameters_section(): void
    {
        $path = $this->configPath . '/services.yml';
        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $this->assertStringContainsString('parameters:', $content);
        $this->assertStringContainsString('atproto.client_id', $content);
        $this->assertStringContainsString('atproto.token_refresh_buffer', $content);
        $this->assertStringContainsString('atproto.did_cache_ttl', $content);
    }
}
