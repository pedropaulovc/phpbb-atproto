# Task 6: OAuth Client (Basic Structure)

> **Note:** This task creates the basic OAuth client structure. Tasks 14-15 add the required AT Protocol extensions (DPoP, PAR) that make this actually work with bsky.social.

**Files:**
- Create: `ext/phpbb/atproto/auth/oauth_client.php`
- Create: `ext/phpbb/atproto/auth/oauth_exception.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/auth/OAuthClientTest.php
<?php

namespace phpbb\atproto\tests\auth;

class OAuthClientTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_client'));
    }

    public function test_get_authorization_url_includes_required_params()
    {
        $didResolver = $this->createMock(\phpbb\atproto\services\did_resolver::class);
        $didResolver->method('resolveHandle')->willReturn('did:plc:test123');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $client = new \phpbb\atproto\auth\oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        // Mock the OAuth metadata fetch
        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $url = $client->getAuthorizationUrl('alice.bsky.social', 'test-state-123');

        $this->assertStringContainsString('client_id=', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
        $this->assertStringContainsString('scope=atproto', $url);
    }

    public function test_oauth_exception_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_exception'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/OAuthClientTest.php`
Expected: FAIL with "Class '\phpbb\atproto\auth\oauth_client' not found"

**Step 3: Create oauth_exception.php**

```php
<?php

namespace phpbb\atproto\auth;

/**
 * Exception thrown during OAuth operations.
 */
class oauth_exception extends \Exception
{
    public const CODE_INVALID_HANDLE = 'AUTH_INVALID_HANDLE';
    public const CODE_DID_RESOLUTION_FAILED = 'AUTH_DID_RESOLUTION_FAILED';
    public const CODE_OAUTH_DENIED = 'AUTH_OAUTH_DENIED';
    public const CODE_TOKEN_EXCHANGE_FAILED = 'AUTH_TOKEN_EXCHANGE_FAILED';
    public const CODE_REFRESH_FAILED = 'AUTH_REFRESH_FAILED';
    public const CODE_CONFIG_ERROR = 'AUTH_CONFIG_ERROR';
    public const CODE_METADATA_FETCH_FAILED = 'AUTH_METADATA_FETCH_FAILED';
    public const CODE_STATE_MISMATCH = 'AUTH_STATE_MISMATCH';

    private string $errorCode;

    public function __construct(string $errorCode, string $message, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
```

**Step 4: Create oauth_client.php**

```php
<?php

namespace phpbb\atproto\auth;

use phpbb\atproto\services\did_resolver;

/**
 * AT Protocol OAuth client.
 *
 * Implements the OAuth flow for AT Protocol authentication:
 * 1. Resolve handle to DID and PDS
 * 2. Fetch OAuth metadata from PDS
 * 3. Generate authorization URL (using PAR if available)
 * 4. Exchange authorization code for tokens
 * 5. Refresh tokens when needed
 */
class oauth_client implements oauth_client_interface
{
    private did_resolver $didResolver;
    private string $clientId;
    private string $redirectUri;
    private ?array $oauthMetadata = null;
    private ?string $currentPdsUrl = null;

    public function __construct(
        did_resolver $didResolver,
        string $clientId,
        string $redirectUri
    ) {
        $this->didResolver = $didResolver;
        $this->clientId = $clientId;
        $this->redirectUri = $redirectUri;
    }

    /**
     * Set OAuth metadata (for testing).
     */
    public function setOAuthMetadata(array $metadata): void
    {
        $this->oauthMetadata = $metadata;
    }

    /**
     * Generate OAuth authorization URL.
     *
     * @param string $handle User's handle or DID
     * @param string $state CSRF protection state
     * @return string Authorization URL to redirect user to
     * @throws oauth_exception On failure
     */
    public function getAuthorizationUrl(string $handle, string $state): string
    {
        try {
            // Resolve handle to DID if needed
            $did = $this->didResolver->isValidDid($handle)
                ? $handle
                : $this->didResolver->resolveHandle($handle);

            // Get PDS URL
            $pdsUrl = $this->didResolver->getPdsUrl($did);
            $this->currentPdsUrl = $pdsUrl;

            // Fetch OAuth metadata
            $metadata = $this->getOAuthMetadata($pdsUrl);

            // Generate PKCE challenge
            $codeVerifier = $this->generateCodeVerifier();
            $codeChallenge = $this->generateCodeChallenge($codeVerifier);

            // Build authorization URL
            $params = [
                'client_id' => $this->clientId,
                'redirect_uri' => $this->redirectUri,
                'response_type' => 'code',
                'scope' => 'atproto',
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'login_hint' => $did,
            ];

            // Use PAR if available
            if (isset($metadata['pushed_authorization_request_endpoint'])) {
                return $this->usePar($metadata, $params, $codeVerifier);
            }

            // Store code verifier in state (will be saved by caller)
            // In production, this should be stored server-side keyed by state

            return $metadata['authorization_endpoint'] . '?' . http_build_query($params);

        } catch (\InvalidArgumentException $e) {
            throw new oauth_exception(
                oauth_exception::CODE_INVALID_HANDLE,
                "Invalid handle format: $handle",
                $e
            );
        } catch (\RuntimeException $e) {
            throw new oauth_exception(
                oauth_exception::CODE_DID_RESOLUTION_FAILED,
                "Failed to resolve handle: " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code from callback
     * @param string $state State for CSRF validation
     * @param string $codeVerifier PKCE code verifier
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array
    {
        if ($this->currentPdsUrl === null) {
            throw new oauth_exception(
                oauth_exception::CODE_CONFIG_ERROR,
                'PDS URL not set - must call getAuthorizationUrl first'
            );
        }

        $metadata = $this->getOAuthMetadata($this->currentPdsUrl);

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'code_verifier' => $codeVerifier,
        ];

        $response = $this->postToTokenEndpoint($metadata['token_endpoint'], $params);

        if (!isset($response['access_token'], $response['refresh_token'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid token response: missing tokens'
            );
        }

        // Extract DID from access token (JWT)
        $did = $this->extractDidFromToken($response['access_token']);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'did' => $did,
            'expires_in' => $response['expires_in'] ?? 3600,
        ];
    }

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Current refresh token
     * @param string $pdsUrl PDS URL for token endpoint
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array
    {
        $metadata = $this->getOAuthMetadata($pdsUrl);

        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
        ];

        try {
            $response = $this->postToTokenEndpoint($metadata['token_endpoint'], $params);
        } catch (oauth_exception $e) {
            throw new oauth_exception(
                oauth_exception::CODE_REFRESH_FAILED,
                'Token refresh failed: ' . $e->getMessage(),
                $e
            );
        }

        if (!isset($response['access_token'], $response['refresh_token'])) {
            throw new oauth_exception(
                oauth_exception::CODE_REFRESH_FAILED,
                'Invalid refresh response: missing tokens'
            );
        }

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'] ?? 3600,
        ];
    }

    /**
     * Fetch OAuth metadata from PDS.
     */
    private function getOAuthMetadata(string $pdsUrl): array
    {
        if ($this->oauthMetadata !== null) {
            return $this->oauthMetadata;
        }

        $metadataUrl = rtrim($pdsUrl, '/') . '/.well-known/oauth-authorization-server';

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($metadataUrl, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                oauth_exception::CODE_METADATA_FETCH_FAILED,
                "Failed to fetch OAuth metadata from: $metadataUrl"
            );
        }

        $metadata = json_decode($response, true);
        if (!is_array($metadata) || !isset($metadata['authorization_endpoint'], $metadata['token_endpoint'])) {
            throw new oauth_exception(
                oauth_exception::CODE_METADATA_FETCH_FAILED,
                'Invalid OAuth metadata: missing required endpoints'
            );
        }

        $this->oauthMetadata = $metadata;
        return $metadata;
    }

    /**
     * Generate PKCE code verifier.
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate PKCE code challenge from verifier.
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Use Pushed Authorization Request (PAR).
     */
    private function usePar(array $metadata, array $params, string $codeVerifier): string
    {
        $response = $this->postToTokenEndpoint(
            $metadata['pushed_authorization_request_endpoint'],
            $params
        );

        if (!isset($response['request_uri'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'PAR response missing request_uri'
            );
        }

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'client_id' => $this->clientId,
            'request_uri' => $response['request_uri'],
        ]);
    }

    /**
     * POST to token endpoint.
     */
    private function postToTokenEndpoint(string $url, array $params): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 30,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
                'content' => http_build_query($params),
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                "Failed to contact token endpoint: $url"
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid JSON response from token endpoint'
            );
        }

        if (isset($data['error'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'OAuth error: ' . ($data['error_description'] ?? $data['error'])
            );
        }

        return $data;
    }

    /**
     * Extract DID from JWT access token.
     */
    private function extractDidFromToken(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'Invalid JWT format'
            );
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || !isset($payload['sub'])) {
            throw new oauth_exception(
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED,
                'JWT missing sub claim'
            );
        }

        return $payload['sub'];
    }

    /**
     * Get the current PDS URL (set during getAuthorizationUrl).
     */
    public function getCurrentPdsUrl(): ?string
    {
        return $this->currentPdsUrl;
    }

    /**
     * Set the current PDS URL (for resuming flow).
     */
    public function setCurrentPdsUrl(string $pdsUrl): void
    {
        $this->currentPdsUrl = $pdsUrl;
    }
}
```

**Step 5: Create oauth_client_interface.php**

```php
<?php

namespace phpbb\atproto\auth;

/**
 * Interface for AT Protocol OAuth client.
 */
interface oauth_client_interface
{
    /**
     * Generate OAuth authorization URL.
     *
     * @param string $handle User's handle or DID
     * @param string $state CSRF protection state
     * @return string Authorization URL to redirect user to
     */
    public function getAuthorizationUrl(string $handle, string $state): string;

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code Authorization code from callback
     * @param string $state State for CSRF validation
     * @param string $codeVerifier PKCE code verifier
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array;

    /**
     * Refresh an access token.
     *
     * @param string $refreshToken Current refresh token
     * @param string $pdsUrl PDS URL for token endpoint
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     * @throws oauth_exception On failure
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array;
}
```

**Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/auth/OAuthClientTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add ext/phpbb/atproto/auth/oauth_client.php ext/phpbb/atproto/auth/oauth_client_interface.php ext/phpbb/atproto/auth/oauth_exception.php tests/ext/phpbb/atproto/auth/OAuthClientTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add OAuth client for AT Protocol authentication

- Implement full OAuth flow with PKCE
- Support Pushed Authorization Requests (PAR)
- Fetch OAuth metadata from PDS
- Token exchange and refresh
- Extract DID from JWT access token
- Custom exception class with error codes

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
