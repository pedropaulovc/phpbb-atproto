<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\controller;

use phpbb\atproto\auth\dpop_service_interface;
use phpbb\atproto\controller\client_metadata_controller;
use phpbb\config\config;
use PHPUnit\Framework\TestCase;

class ClientMetadataControllerTest extends TestCase
{
    private function createConfigMock(): config
    {
        $config = $this->createMock(config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Example Forum'],
        ]);
        return $config;
    }

    private function createDpopServiceMock(): dpop_service_interface
    {
        $mock = $this->createMock(dpop_service_interface::class);
        $mock->method('getPublicJwk')->willReturn([
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'use' => 'sig',
            'x' => 'test-x-coordinate',
            'y' => 'test-y-coordinate',
        ]);
        return $mock;
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\controller\client_metadata_controller'));
    }

    public function test_returns_valid_client_metadata(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $metadata = json_decode($response->getContent(), true);

        // Required fields per AT Protocol spec
        $this->assertArrayHasKey('client_id', $metadata);
        $this->assertArrayHasKey('client_name', $metadata);
        $this->assertArrayHasKey('client_uri', $metadata);
        $this->assertArrayHasKey('redirect_uris', $metadata);
        $this->assertArrayHasKey('grant_types', $metadata);
        $this->assertArrayHasKey('response_types', $metadata);
        $this->assertArrayHasKey('scope', $metadata);
        $this->assertArrayHasKey('token_endpoint_auth_method', $metadata);
        $this->assertArrayHasKey('dpop_bound_access_tokens', $metadata);
        $this->assertArrayHasKey('jwks', $metadata);
    }

    public function test_client_id_matches_metadata_url(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        // client_id must be the URL where this metadata is served
        $this->assertStringContainsString('client-metadata.json', $metadata['client_id']);
        $this->assertEquals($metadata['client_id'], $metadata['client_uri'] . 'client-metadata.json');
    }

    public function test_includes_dpop_public_key(): void
    {
        $config = $this->createConfigMock();

        $testJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'use' => 'sig',
            'x' => 'unique-test-x',
            'y' => 'unique-test-y',
        ];

        $dpopService = $this->createMock(dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn($testJwk);

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        // JWKS should contain our DPoP public key
        $this->assertArrayHasKey('keys', $metadata['jwks']);
        $this->assertCount(1, $metadata['jwks']['keys']);
        $this->assertEquals($testJwk, $metadata['jwks']['keys'][0]);
    }

    public function test_token_endpoint_auth_method_is_none(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        // Public clients use 'none' for token_endpoint_auth_method
        $this->assertEquals('none', $metadata['token_endpoint_auth_method']);
    }

    public function test_dpop_bound_access_tokens_is_true(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        // AT Protocol requires DPoP-bound tokens
        $this->assertTrue($metadata['dpop_bound_access_tokens']);
    }

    public function test_includes_correct_grant_types(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        $this->assertContains('authorization_code', $metadata['grant_types']);
        $this->assertContains('refresh_token', $metadata['grant_types']);
    }

    public function test_includes_correct_response_types(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        $this->assertContains('code', $metadata['response_types']);
    }

    public function test_includes_atproto_scope(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        $this->assertStringContainsString('atproto', $metadata['scope']);
    }

    public function test_response_has_cache_headers(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', $response->headers->get('Cache-Control'));
    }

    public function test_client_name_includes_sitename(): void
    {
        $config = $this->createConfigMock();
        $dpopService = $this->createDpopServiceMock();

        $controller = new client_metadata_controller(
            $config,
            $dpopService,
            '/',
            'php'
        );

        $response = $controller->handle();
        $metadata = json_decode($response->getContent(), true);

        $this->assertStringContainsString('Example Forum', $metadata['client_name']);
    }
}
