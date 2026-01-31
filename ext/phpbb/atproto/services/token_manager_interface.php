<?php

declare(strict_types=1);

namespace phpbb\atproto\services;

/**
 * Interface for AT Protocol token management.
 *
 * Defines the contract for storing, retrieving, and refreshing OAuth tokens
 * used for AT Protocol authentication.
 */
interface token_manager_interface
{
    /**
     * Get a valid access token for a user, refreshing if necessary.
     *
     * @param int $userId phpBB user ID
     *
     * @throws \phpbb\atproto\exceptions\token_not_found_exception      When user has no tokens
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     *
     * @return string Valid access token (JWT)
     */
    public function getAccessToken(int $userId): string;

    /**
     * Force refresh the access token using the refresh token.
     *
     * @param int $userId phpBB user ID
     *
     * @throws \phpbb\atproto\exceptions\token_not_found_exception      When user has no tokens
     * @throws \phpbb\atproto\exceptions\token_refresh_failed_exception When refresh fails
     *
     * @return string New access token
     */
    public function refreshToken(int $userId): string;

    /**
     * Store tokens for a user after OAuth flow.
     *
     * @param int    $userId       phpBB user ID
     * @param string $did          User's DID
     * @param string $handle       User's handle
     * @param string $pdsUrl       User's PDS URL
     * @param string $accessToken  Access token (will be encrypted)
     * @param string $refreshToken Refresh token (will be encrypted)
     * @param int    $expiresIn    Seconds until access token expires
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void;

    /**
     * Check if user has a valid (non-expired) token.
     *
     * @param int $userId phpBB user ID
     *
     * @return bool True if token exists and isn't expired
     */
    public function isTokenValid(int $userId): bool;

    /**
     * Clear all tokens for a user (logout).
     *
     * @param int $userId phpBB user ID
     */
    public function clearTokens(int $userId): void;

    /**
     * Get the DID associated with a user's tokens.
     *
     * @param int $userId phpBB user ID
     *
     * @return string|null User's DID or null if not linked
     */
    public function getUserDid(int $userId): ?string;

    /**
     * Get the PDS URL for a user.
     *
     * @param int $userId phpBB user ID
     *
     * @return string|null User's PDS URL or null if not linked
     */
    public function getUserPdsUrl(int $userId): ?string;
}
