<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

use phpbb\atproto\services\did_resolver;

/**
 * OAuth client for AT Protocol authentication.
 *
 * Implements OAuth 2.0 with PKCE and DPoP for AT Protocol servers.
 * Uses Pushed Authorization Requests (PAR) as required by the protocol.
 *
 * @see https://docs.bsky.app/docs/advanced-guides/oauth-client
 */
class oauth_client implements oauth_client_interface
{
    private did_resolver $didResolver;
    private dpop_service_interface $dpopService;
    private string $clientId;
    private string $redirectUri;
    private ?array $oauthMetadata = null;

    /**
     * Constructor.
     *
     * @param did_resolver           $didResolver The DID resolver service
     * @param dpop_service_interface $dpopService The DPoP service for token binding
     * @param string                 $clientId    The client metadata URL (acts as client_id)
     * @param string                 $redirectUri The OAuth callback URL
     */
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
            'pds_url' => $pdsUrl,
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

        // Extract DID from the sub claim
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
     * {@inheritdoc}
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * {@inheritdoc}
     */
    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Set OAuth metadata manually (for testing or caching).
     *
     * @param array $metadata The OAuth server metadata
     */
    public function setOAuthMetadata(array $metadata): void
    {
        $this->oauthMetadata = $metadata;
    }

    /**
     * Fetch OAuth metadata for a PDS.
     *
     * First fetches the protected resource metadata to find the authorization server,
     * then fetches the authorization server metadata.
     *
     * @param string $pdsUrl The PDS URL
     *
     * @throws oauth_exception If metadata fetch fails
     *
     * @return array The OAuth metadata
     */
    public function fetchOAuthMetadata(string $pdsUrl): array
    {
        // Step 1: Fetch protected resource metadata from PDS to find auth server
        $resourceMetadataUrl = rtrim($pdsUrl, '/') . '/.well-known/oauth-protected-resource';

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($resourceMetadataUrl, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                "Failed to fetch protected resource metadata from: $resourceMetadataUrl",
                oauth_exception::CODE_METADATA_FETCH_FAILED
            );
        }

        $resourceMetadata = json_decode($response, true);
        if (!is_array($resourceMetadata) || empty($resourceMetadata['authorization_servers'])) {
            throw new oauth_exception(
                'Invalid protected resource metadata - no authorization servers',
                oauth_exception::CODE_METADATA_FETCH_FAILED
            );
        }

        // Step 2: Fetch authorization server metadata
        $authServer = $resourceMetadata['authorization_servers'][0];
        $authMetadataUrl = rtrim($authServer, '/') . '/.well-known/oauth-authorization-server';

        $response = @file_get_contents($authMetadataUrl, false, $context);
        if ($response === false) {
            throw new oauth_exception(
                "Failed to fetch OAuth metadata from: $authMetadataUrl",
                oauth_exception::CODE_METADATA_FETCH_FAILED
            );
        }

        $metadata = json_decode($response, true);
        if (!is_array($metadata)) {
            throw new oauth_exception(
                'Invalid OAuth metadata response',
                oauth_exception::CODE_METADATA_FETCH_FAILED
            );
        }

        // Store the auth server URL for later use
        $metadata['_auth_server'] = $authServer;

        return $metadata;
    }

    /**
     * Make a token request to the authorization server.
     *
     * @param string $endpoint The token endpoint URL
     * @param array  $params   Request parameters
     * @param array  $headers  Additional headers (including DPoP)
     *
     * @throws \RuntimeException If the request fails
     *
     * @return array The token response
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
            throw new \RuntimeException('Invalid token response');
        }

        // Handle use_dpop_nonce error - retry with nonce
        if (isset($data['error']) && $data['error'] === 'use_dpop_nonce' && $dpopNonce !== null) {
            $dpopProof = $this->dpopService->createProofWithNonce(
                'POST',
                $endpoint,
                $dpopNonce
            );
            $headers['DPoP'] = $dpopProof;

            return $this->makeTokenRequest($endpoint, $params, $headers);
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

    /**
     * Resolve a handle or DID to a DID.
     *
     * @param string $handleOrDid The handle or DID
     *
     * @throws oauth_exception If resolution fails
     *
     * @return string The resolved DID
     */
    private function resolveIdentifier(string $handleOrDid): string
    {
        // Check if already a DID
        if ($this->didResolver->isValidDid($handleOrDid)) {
            return $handleOrDid;
        }

        try {
            return $this->didResolver->resolveHandle($handleOrDid);
        } catch (\InvalidArgumentException $e) {
            throw new oauth_exception(
                'Invalid handle format: ' . $handleOrDid,
                oauth_exception::CODE_INVALID_HANDLE,
                $e
            );
        } catch (\RuntimeException $e) {
            throw new oauth_exception(
                'DID resolution failed: ' . $e->getMessage(),
                oauth_exception::CODE_DID_RESOLUTION_FAILED,
                $e
            );
        }
    }

    /**
     * Get OAuth metadata for a PDS.
     *
     * Uses cached metadata if available.
     *
     * @param string $pdsUrl The PDS URL
     *
     * @return array The OAuth metadata
     */
    private function getMetadataForPds(string $pdsUrl): array
    {
        // Use cached metadata if available
        if ($this->oauthMetadata !== null) {
            return $this->oauthMetadata;
        }

        $metadata = $this->fetchOAuthMetadata($pdsUrl);
        $this->oauthMetadata = $metadata;

        return $metadata;
    }

    /**
     * Generate a PKCE code verifier.
     *
     * Creates a cryptographically random string for PKCE.
     * Length: 64 characters (within the 43-128 character range required by spec).
     *
     * @return string The code verifier
     */
    private function generateCodeVerifier(): string
    {
        // Generate 48 random bytes and encode to base64url (64 characters)
        $bytes = random_bytes(48);

        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Generate a PKCE code challenge from a verifier.
     *
     * Creates the SHA-256 hash of the verifier, base64url encoded.
     *
     * @param string $verifier The code verifier
     *
     * @return string The code challenge
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Extract DID from a JWT access token.
     *
     * @param string $token The JWT access token
     *
     * @return string The DID or empty string if extraction fails
     */
    private function extractDidFromToken(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return '';
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        if ($payload === false) {
            return '';
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return '';
        }

        return $data['sub'] ?? '';
    }
}
