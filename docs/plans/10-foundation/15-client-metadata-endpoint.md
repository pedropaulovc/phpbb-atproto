# Task 15: Client Metadata Endpoint

> **AT Protocol Requirement:** The `client_id` must be a publicly accessible URL that serves the client metadata JSON. The authorization server fetches this to verify the client.

**Files:**
- Create: `ext/phpbb/atproto/controller/client_metadata_controller.php`
- Modify: `ext/phpbb/atproto/config/routing.yml`
- Modify: `ext/phpbb/atproto/config/services.yml`
- Create: `tests/ext/phpbb/atproto/controller/ClientMetadataControllerTest.php`

**Reference:** https://docs.bsky.app/docs/advanced-guides/oauth-client#client-metadata

---

## Step 1: Write the failing test

```php
// tests/ext/phpbb/atproto/controller/ClientMetadataControllerTest.php
<?php

namespace phpbb\atproto\tests\controller;

class ClientMetadataControllerTest extends \phpbb_test_case
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\controller\client_metadata_controller'));
    }

    public function test_returns_valid_client_metadata(): void
    {
        $config = $this->createMock(\phpbb\config\config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Example Forum'],
        ]);

        $dpopService = $this->createMock(\phpbb\atproto\auth\dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'test-x-coordinate',
            'y' => 'test-y-coordinate',
        ]);

        $controller = new \phpbb\atproto\controller\client_metadata_controller(
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
        $config = $this->createMock(\phpbb\config\config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Example Forum'],
        ]);

        $dpopService = $this->createMock(\phpbb\atproto\auth\dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'x',
            'y' => 'y',
        ]);

        $controller = new \phpbb\atproto\controller\client_metadata_controller(
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
        $config = $this->createMock(\phpbb\config\config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Forum'],
        ]);

        $testJwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'test-x',
            'y' => 'test-y',
        ];

        $dpopService = $this->createMock(\phpbb\atproto\auth\dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn($testJwk);

        $controller = new \phpbb\atproto\controller\client_metadata_controller(
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
        $config = $this->createMock(\phpbb\config\config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Forum'],
        ]);

        $dpopService = $this->createMock(\phpbb\atproto\auth\dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y']);

        $controller = new \phpbb\atproto\controller\client_metadata_controller(
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
        $config = $this->createMock(\phpbb\config\config::class);
        $config->method('offsetGet')->willReturnMap([
            ['server_name', 'forum.example.com'],
            ['server_protocol', 'https://'],
            ['script_path', '/'],
            ['sitename', 'Forum'],
        ]);

        $dpopService = $this->createMock(\phpbb\atproto\auth\dpop_service_interface::class);
        $dpopService->method('getPublicJwk')->willReturn(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'x', 'y' => 'y']);

        $controller = new \phpbb\atproto\controller\client_metadata_controller(
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
}
```

---

## Step 2: Run test to verify it fails

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/controller/ClientMetadataControllerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\controller\client_metadata_controller' not found"

---

## Step 3: Create client_metadata_controller.php

```php
<?php

declare(strict_types=1);

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\dpop_service_interface;
use phpbb\config\config;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller that serves OAuth client metadata.
 *
 * AT Protocol requires the client_id to be a publicly accessible URL
 * that returns the client metadata JSON. The authorization server
 * fetches this to verify client configuration.
 *
 * @see https://docs.bsky.app/docs/advanced-guides/oauth-client#client-metadata
 */
class client_metadata_controller
{
    private config $config;
    private dpop_service_interface $dpopService;
    private string $phpbbRootPath;
    private string $phpEx;

    public function __construct(
        config $config,
        dpop_service_interface $dpopService,
        string $phpbbRootPath,
        string $phpEx
    ) {
        $this->config = $config;
        $this->dpopService = $dpopService;
        $this->phpbbRootPath = $phpbbRootPath;
        $this->phpEx = $phpEx;
    }

    /**
     * Return client metadata JSON.
     */
    public function handle(): Response
    {
        $baseUrl = $this->getBaseUrl();
        $clientId = $baseUrl . 'client-metadata.json';

        $metadata = [
            // Client identification
            'client_id' => $clientId,
            'client_name' => $this->config['sitename'] . ' - AT Protocol Login',
            'client_uri' => $baseUrl,

            // OAuth endpoints
            'redirect_uris' => [
                $baseUrl . 'app.' . $this->phpEx . '/atproto/callback',
            ],

            // Supported OAuth flows
            'grant_types' => [
                'authorization_code',
                'refresh_token',
            ],
            'response_types' => ['code'],
            'scope' => 'atproto transition:generic',

            // Client authentication - public client, no secret
            'token_endpoint_auth_method' => 'none',

            // DPoP requirement - AT Protocol mandates DPoP
            'dpop_bound_access_tokens' => true,

            // Application type
            'application_type' => 'web',

            // JWKS containing our DPoP public key
            'jwks' => [
                'keys' => [
                    $this->dpopService->getPublicJwk(),
                ],
            ],
        ];

        $response = new JsonResponse($metadata);
        $response->headers->set('Content-Type', 'application/json');

        // Allow the authorization server to cache this
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * Get the base URL for this phpBB installation.
     */
    private function getBaseUrl(): string
    {
        $protocol = $this->config['server_protocol'];
        $serverName = $this->config['server_name'];
        $scriptPath = $this->config['script_path'];

        return rtrim($protocol . $serverName . $scriptPath, '/') . '/';
    }
}
```

---

## Step 4: Add routing for client metadata endpoint

Add to `ext/phpbb/atproto/config/routing.yml`:

```yaml
phpbb_atproto_client_metadata:
    path: /client-metadata.json
    defaults:
        _controller: phpbb.atproto.controller.client_metadata:handle
    methods: [GET]
```

---

## Step 5: Register controller in services.yml

Add to `ext/phpbb/atproto/config/services.yml`:

```yaml
    phpbb.atproto.controller.client_metadata:
        class: phpbb\atproto\controller\client_metadata_controller
        arguments:
            - '@config'
            - '@phpbb.atproto.auth.dpop_service'
            - '%core.root_path%'
            - '%core.php_ext%'
```

---

## Step 6: Run test to verify it passes

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/controller/ClientMetadataControllerTest.php`
Expected: All tests PASS

---

## Step 7: Commit

```bash
git add ext/phpbb/atproto/controller/client_metadata_controller.php ext/phpbb/atproto/config/routing.yml ext/phpbb/atproto/config/services.yml tests/ext/phpbb/atproto/controller/ClientMetadataControllerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add client metadata endpoint

AT Protocol OAuth requires client_id to be a URL serving metadata:
- Serves JSON at /client-metadata.json
- Includes DPoP public key in JWKS
- Declares dpop_bound_access_tokens: true
- Uses token_endpoint_auth_method: none (public client)
- Specifies redirect_uris and grant_types

The authorization server fetches this to verify the client.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
