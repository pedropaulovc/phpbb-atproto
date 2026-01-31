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
