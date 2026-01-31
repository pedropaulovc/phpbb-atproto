<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * Interface for AT Protocol OAuth client.
 *
 * Defines the contract for OAuth 2.0 authentication with AT Protocol servers.
 * Supports PKCE (Proof Key for Code Exchange) for enhanced security.
 */
interface oauth_client_interface
{
    /**
     * Get the authorization URL for OAuth flow.
     *
     * Resolves the handle to a DID, fetches the PDS OAuth metadata,
     * generates PKCE parameters, and constructs the authorization URL.
     *
     * @param string $handleOrDid The user's handle (e.g., alice.bsky.social) or DID
     * @param string $state       Random state parameter for CSRF protection
     *
     * @throws oauth_exception If handle is invalid or resolution fails
     *
     * @return array{url: string, code_verifier: string, did: string} Authorization URL, PKCE verifier, and resolved DID
     */
    public function getAuthorizationUrl(string $handleOrDid, string $state): array;

    /**
     * Exchange authorization code for access tokens.
     *
     * @param string $code         The authorization code from the callback
     * @param string $state        The state parameter for verification
     * @param string $codeVerifier The PKCE code verifier from getAuthorizationUrl
     *
     * @throws oauth_exception If token exchange fails
     *
     * @return array{access_token: string, refresh_token: string, did: string, expires_in: int, token_type: string} Token response
     */
    public function exchangeCode(string $code, string $state, string $codeVerifier): array;

    /**
     * Refresh an access token using a refresh token.
     *
     * @param string $refreshToken The refresh token
     * @param string $pdsUrl       The PDS URL for the user
     *
     * @throws oauth_exception If token refresh fails
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} New token response
     */
    public function refreshAccessToken(string $refreshToken, string $pdsUrl): array;

    /**
     * Get the client ID (metadata URL).
     *
     * @return string The client metadata URL
     */
    public function getClientId(): string;

    /**
     * Get the redirect URI.
     *
     * @return string The callback URL
     */
    public function getRedirectUri(): string;
}
