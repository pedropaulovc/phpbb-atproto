# Task 14: Pushed Authorization Request (PAR)

> **AT Protocol Requirement:** Authorization requests MUST use PAR. The client must first POST to /oauth/par to get a request_uri, then redirect to /oauth/authorize with only client_id and request_uri.

**Files:**
- Modify: `ext/phpbb/atproto/auth/oauth_client.php` (require PAR for all requests)
- Create: `tests/ext/phpbb/atproto/auth/OAuthClientParTest.php`

**Reference:** https://docs.bsky.app/docs/advanced-guides/oauth-client#par

**Depends on:** Task 12 (DPoP service - PAR requests require DPoP)

---

## Step 1: Write the failing test for PAR

```php
// tests/ext/phpbb/atproto/auth/OAuthClientParTest.php
<?php

namespace phpbb\atproto\tests\auth;

use phpbb\atproto\auth\dpop_service;
use phpbb\atproto\auth\oauth_client;
use phpbb\atproto\services\did_resolver;

class OAuthClientParTest extends \phpbb_test_case
{
    private oauth_client $client;
    private $mockDidResolver;
    private dpop_service $dpopService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDidResolver = $this->createMock(did_resolver::class);
        $this->mockDidResolver->method('isValidDid')->willReturn(false);
        $this->mockDidResolver->method('resolveHandle')->willReturn('did:plc:testuser123');
        $this->mockDidResolver->method('getPdsUrl')->willReturn('https://morel.us-east.host.bsky.network');

        $this->dpopService = new dpop_service();

        $this->client = new oauth_client(
            $this->mockDidResolver,
            $this->dpopService,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );
    }

    public function test_get_authorization_url_requires_par(): void
    {
        // Set metadata that includes PAR endpoint (required by AT Protocol)
        $this->client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
            'dpop_signing_alg_values_supported' => ['ES256'],
        ]);

        // This should throw because we can't actually make the PAR request in tests
        // But we can verify the method attempts to use PAR
        $this->expectException(\phpbb\atproto\auth\oauth_exception::class);
        $this->expectExceptionMessage('PAR request failed');

        $this->client->getAuthorizationUrl('alice.bsky.social', 'test-state');
    }

    public function test_throws_if_no_par_endpoint(): void
    {
        // AT Protocol requires PAR - missing endpoint should throw
        $this->client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            // No pushed_authorization_request_endpoint
        ]);

        $this->expectException(\phpbb\atproto\auth\oauth_exception::class);
        $this->expectExceptionMessage('PAR endpoint required');

        $this->client->getAuthorizationUrl('alice.bsky.social', 'test-state');
    }

    public function test_par_request_includes_dpop_header(): void
    {
        // We need to verify that PAR requests include DPoP
        // This is tested via mock/spy pattern

        $requestCapture = [];
        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $this->mockDidResolver,
                $this->dpopService,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeParRequest'])
            ->getMock();

        $client->expects($this->once())
            ->method('makeParRequest')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($headers) {
                    // Verify DPoP header is present
                    return isset($headers['DPoP']) && str_contains($headers['DPoP'], 'dpop+jwt');
                })
            )
            ->willReturn(['request_uri' => 'urn:ietf:params:oauth:request_uri:test']);

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('alice.bsky.social', 'test-state');

        // Result should be authorization URL with request_uri
        $this->assertStringContainsString('request_uri=', $result['url']);
        $this->assertStringContainsString('client_id=', $result['url']);
        $this->assertStringNotContainsString('code_challenge=', $result['url']);
    }

    public function test_authorization_url_only_contains_client_id_and_request_uri(): void
    {
        // After PAR, the authorization URL should only have client_id and request_uri
        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $this->mockDidResolver,
                $this->dpopService,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeParRequest'])
            ->getMock();

        $client->method('makeParRequest')->willReturn([
            'request_uri' => 'urn:ietf:params:oauth:request_uri:abc123',
            'expires_in' => 60,
        ]);

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('alice.bsky.social', 'test-state');
        $url = $result['url'];

        // Parse the URL
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        // Only client_id and request_uri should be in the URL
        $this->assertCount(2, $query);
        $this->assertArrayHasKey('client_id', $query);
        $this->assertArrayHasKey('request_uri', $query);
        $this->assertEquals('https://forum.example.com/client-metadata.json', $query['client_id']);
        $this->assertEquals('urn:ietf:params:oauth:request_uri:abc123', $query['request_uri']);
    }
}
```

---

## Step 2: Run test to verify it fails

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/auth/OAuthClientParTest.php`
Expected: FAIL (oauth_client doesn't accept dpop_service in constructor)

---

## Step 3: Update oauth_client.php to require PAR with DPoP

Replace the `getAuthorizationUrl` method and add PAR support:

```php
<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

use phpbb\atproto\services\did_resolver;

/**
 * OAuth client for AT Protocol authentication.
 *
 * Implements OAuth 2.0 with PKCE and DPoP for AT Protocol servers.
 * Uses Pushed Authorization Requests (PAR) as required by the protocol.
 */
class oauth_client implements oauth_client_interface
{
    private did_resolver $didResolver;
    private dpop_service_interface $dpopService;
    private string $clientId;
    private string $redirectUri;
    private ?array $oauthMetadata = null;

    public function __construct(
        did_resolver $didResolver,
        dpop_service_interface $dpopService,
        string $clientId,
        string $redirectUri
    ) {
        $this->didResolver = $didResolver;
        $this->dpopService = $dpopService;
        $this->clientId = $clientId;
        $this->redirectUri = $redirectUri;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizationUrl(string $handleOrDid, string $state): array
    {
        // Resolve handle to DID if needed
        $did = $this->resolveIdentifier($handleOrDid);

        // Get PDS URL and OAuth metadata
        $pdsUrl = $this->didResolver->getPdsUrl($did);
        $metadata = $this->getMetadataForPds($pdsUrl);

        // Verify PAR endpoint exists (required by AT Protocol)
        if (empty($metadata['pushed_authorization_request_endpoint'])) {
            throw new oauth_exception(
                'PAR endpoint required by AT Protocol',
                oauth_exception::CODE_CONFIG_ERROR
            );
        }

        // Generate PKCE parameters
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Build PAR request body
        $parParams = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => 'atproto transition:generic',
            'login_hint' => $handleOrDid,
        ];

        // Create DPoP proof for PAR endpoint
        $dpopProof = $this->dpopService->createProof(
            'POST',
            $metadata['pushed_authorization_request_endpoint']
        );

        // Make PAR request
        $parResponse = $this->makeParRequest(
            $metadata['pushed_authorization_request_endpoint'],
            $parParams,
            ['DPoP' => $dpopProof]
        );

        if (!isset($parResponse['request_uri'])) {
            throw new oauth_exception(
                'PAR response missing request_uri',
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            );
        }

        // Build authorization URL with only client_id and request_uri
        $authUrl = $metadata['authorization_endpoint'] . '?' . http_build_query([
            'client_id' => $this->clientId,
            'request_uri' => $parResponse['request_uri'],
        ]);

        return [
            'url' => $authUrl,
            'code_verifier' => $codeVerifier,
            'did' => $did,
            'dpop_nonce' => $parResponse['dpop_nonce'] ?? null,
        ];
    }

    /**
     * Make a Pushed Authorization Request.
     *
     * @param string $endpoint PAR endpoint URL
     * @param array  $params   Request parameters
     * @param array  $headers  Additional headers (including DPoP)
     *
     * @throws oauth_exception If request fails
     *
     * @return array PAR response with request_uri
     */
    public function makeParRequest(string $endpoint, array $params, array $headers): array
    {
        $headerLines = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 30,
                'header' => implode("\r\n", $headerLines),
                'content' => http_build_query($params),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                'PAR request failed: could not connect',
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            );
        }

        // Check for DPoP nonce in response headers
        $dpopNonce = null;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, 'DPoP-Nonce:') === 0) {
                    $dpopNonce = trim(substr($header, 11));
                    break;
                }
            }
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new oauth_exception(
                'PAR request failed: invalid response',
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            );
        }

        // Handle use_dpop_nonce error - retry with nonce
        if (isset($data['error']) && $data['error'] === 'use_dpop_nonce' && $dpopNonce !== null) {
            // Retry with nonce
            $dpopProof = $this->dpopService->createProofWithNonce(
                'POST',
                $endpoint,
                $dpopNonce
            );
            $headers['DPoP'] = $dpopProof;

            return $this->makeParRequest($endpoint, $params, $headers);
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error'];
            if (isset($data['error_description'])) {
                $errorMsg .= ': ' . $data['error_description'];
            }

            throw new oauth_exception(
                'PAR request failed: ' . $errorMsg,
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            );
        }

        if ($dpopNonce !== null) {
            $data['dpop_nonce'] = $dpopNonce;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array
    {
        $metadata = $this->oauthMetadata;
        if ($metadata === null) {
            throw new oauth_exception(
                'OAuth metadata not set',
                oauth_exception::CODE_CONFIG_ERROR
            );
        }

        $tokenEndpoint = $metadata['token_endpoint'];

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ];

        // Create DPoP proof for token endpoint
        $dpopProof = $this->dpopService->createProof('POST', $tokenEndpoint);

        try {
            $response = $this->makeTokenRequest($tokenEndpoint, $params, ['DPoP' => $dpopProof]);
        } catch (\Throwable $e) {
            throw new oauth_exception(
                'Token exchange failed: ' . $e->getMessage(),
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                $e
            );
        }

        $did = $response['sub'] ?? $this->extractDidFromToken($response['access_token'] ?? '');

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? '',
            'did' => $did,
            'expires_in' => $response['expires_in'] ?? 3600,
            'token_type' => $response['token_type'] ?? 'DPoP',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array
    {
        $metadata = $this->fetchOAuthMetadata($pdsUrl);
        $tokenEndpoint = $metadata['token_endpoint'];

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
        ];

        // Create DPoP proof for token endpoint
        $dpopProof = $this->dpopService->createProof('POST', $tokenEndpoint);

        try {
            $response = $this->makeTokenRequest($tokenEndpoint, $params, ['DPoP' => $dpopProof]);
        } catch (\Throwable $e) {
            throw new oauth_exception(
                'Token refresh failed: ' . $e->getMessage(),
                oauth_exception::CODE_REFRESH_FAILED,
                $e
            );
        }

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? '',
            'expires_in' => $response['expires_in'] ?? 3600,
            'token_type' => $response['token_type'] ?? 'DPoP',
        ];
    }

    /**
     * Make a token request with DPoP.
     *
     * @param string $endpoint Token endpoint URL
     * @param array  $params   Request parameters
     * @param array  $headers  Additional headers (including DPoP)
     *
     * @return array Token response
     */
    public function makeTokenRequest(string $endpoint, array $params, array $headers = []): array
    {
        $headerLines = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        foreach ($headers as $name => $value) {
            $headerLines[] = "$name: $value";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 30,
                'header' => implode("\r\n", $headerLines),
                'content' => http_build_query($params),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            throw new \RuntimeException('Token request failed');
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid token response');
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error'];
            if (isset($data['error_description'])) {
                $errorMsg .= ': ' . $data['error_description'];
            }

            throw new \RuntimeException($errorMsg);
        }

        return $data;
    }

    // ... rest of the existing methods remain the same ...
}
```

---

## Step 4: Update services.yml with dpop_service dependency

Modify `ext/phpbb/atproto/config/services.yml`:

```yaml
    phpbb.atproto.auth.oauth_client:
        class: phpbb\atproto\auth\oauth_client
        arguments:
            - '@phpbb.atproto.services.did_resolver'
            - '@phpbb.atproto.auth.dpop_service'
            - '%atproto.client_id%'
            - '%atproto.redirect_uri%'
```

---

## Step 5: Run test to verify it passes

Run: `./scripts/test.sh unit tests/ext/phpbb/atproto/auth/OAuthClientParTest.php`
Expected: All tests PASS

---

## Step 6: Commit

```bash
git add ext/phpbb/atproto/auth/oauth_client.php ext/phpbb/atproto/config/services.yml tests/ext/phpbb/atproto/auth/OAuthClientParTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): implement Pushed Authorization Requests (PAR)

AT Protocol OAuth requires PAR for all authorization requests:
- POST to /oauth/par with all params + DPoP proof
- Receive request_uri from authorization server
- Redirect to /oauth/authorize with only client_id + request_uri
- Handle use_dpop_nonce error with retry

This prevents authorization request tampering and is mandatory.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
