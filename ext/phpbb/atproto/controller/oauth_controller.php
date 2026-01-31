<?php

declare(strict_types=1);

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\token_manager_interface;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth controller for AT Protocol authentication flow.
 *
 * Handles the OAuth login flow including:
 * - Displaying the login form
 * - Initiating authorization with the user's PDS
 * - Processing the OAuth callback
 * - Creating/linking phpBB accounts
 */
class oauth_controller
{
    private oauth_client_interface $oauthClient;
    private token_manager_interface $tokenManager;

    public function __construct(
        oauth_client_interface $oauthClient,
        token_manager_interface $tokenManager
    ) {
        $this->oauthClient = $oauthClient;
        $this->tokenManager = $tokenManager;
    }

    /**
     * Start OAuth flow - user enters handle.
     *
     * If no handle is provided, displays the login form.
     * If a handle is provided, redirects to the authorization server.
     *
     * @param string $handle    The user's AT Protocol handle (optional)
     * @param array  $session   Session data storage (for state/verifier)
     * @param int    $errorCode Error code to display (optional, 0 = no error)
     *
     * @return array Response data including redirect URL or template vars
     */
    public function start(string $handle = '', array &$session = [], int $errorCode = 0): array
    {
        if (empty($handle)) {
            return [
                'type' => 'form',
                'error' => $errorCode !== 0 ? $this->getErrorMessage($errorCode) : '',
            ];
        }

        try {
            $state = bin2hex(random_bytes(16));
            $authResult = $this->oauthClient->getAuthorizationUrl($handle, $state);

            $session['atproto_oauth_state'] = $state;
            $session['atproto_code_verifier'] = $authResult['code_verifier'];
            $session['atproto_handle'] = $handle;
            $session['atproto_did'] = $authResult['did'];

            return [
                'type' => 'redirect',
                'url' => $authResult['url'],
            ];
        } catch (oauth_exception $e) {
            return [
                'type' => 'form',
                'error' => $this->getErrorMessage($e->getCode()),
            ];
        }
    }

    /**
     * OAuth callback - exchange code for tokens.
     *
     * Processes the callback from the authorization server:
     * - Validates state parameter
     * - Exchanges authorization code for tokens
     * - Stores tokens and links/creates user account
     *
     * @param string $code    Authorization code from callback
     * @param string $state   State parameter from callback
     * @param string $error   Error parameter from callback (if auth failed)
     * @param array  $session Session data with stored state/verifier
     * @param int    $userId  User ID if already logged in (optional)
     *
     * @return array Response data including success/error info
     */
    public function callback(
        string $code,
        string $state,
        string $error,
        array &$session,
        ?int $userId = null
    ): array {
        // Handle authorization error
        if (!empty($error)) {
            $this->clearSessionVars($session);

            return [
                'type' => 'error',
                'error_code' => oauth_exception::CODE_OAUTH_DENIED,
                'error_message' => $this->getErrorMessage(oauth_exception::CODE_OAUTH_DENIED),
            ];
        }

        // Validate state parameter
        $expectedState = $session['atproto_oauth_state'] ?? '';
        if (empty($state) || $state !== $expectedState) {
            $this->clearSessionVars($session);

            return [
                'type' => 'error',
                'error_code' => oauth_exception::CODE_STATE_MISMATCH,
                'error_message' => $this->getErrorMessage(oauth_exception::CODE_STATE_MISMATCH),
            ];
        }

        // Retrieve session data
        $codeVerifier = $session['atproto_code_verifier'] ?? '';
        $handle = $session['atproto_handle'] ?? '';
        $did = $session['atproto_did'] ?? '';

        if (empty($codeVerifier)) {
            $this->clearSessionVars($session);

            return [
                'type' => 'error',
                'error_code' => oauth_exception::CODE_CONFIG_ERROR,
                'error_message' => 'Session data missing - please try again',
            ];
        }

        try {
            $tokens = $this->oauthClient->exchangeCode($code, $state, $codeVerifier);

            $this->clearSessionVars($session);

            // Use the DID from token exchange (more reliable)
            $tokenDid = $tokens['did'] ?? $did;

            // If we have a user ID, store tokens directly
            // Otherwise, return data for the caller to handle user creation/lookup
            if ($userId !== null) {
                $this->tokenManager->storeTokens(
                    $userId,
                    $tokenDid,
                    $handle,
                    '', // PDS URL would need to be obtained from DID resolver
                    $tokens['access_token'],
                    $tokens['refresh_token'],
                    $tokens['expires_in']
                );

                return [
                    'type' => 'success',
                    'user_id' => $userId,
                    'did' => $tokenDid,
                    'handle' => $handle,
                ];
            }

            // Check if DID is already linked to a user
            $existingUserId = $this->tokenManager->findUserByDid($tokenDid);

            return [
                'type' => 'success',
                'existing_user_id' => $existingUserId,
                'did' => $tokenDid,
                'handle' => $handle,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
            ];
        } catch (oauth_exception $e) {
            $this->clearSessionVars($session);

            return [
                'type' => 'error',
                'error_code' => $e->getCode(),
                'error_message' => $this->getErrorMessage($e->getCode()),
            ];
        }
    }

    /**
     * Clear OAuth-related session variables.
     *
     * @param array $session Session data storage
     */
    private function clearSessionVars(array &$session): void
    {
        unset(
            $session['atproto_oauth_state'],
            $session['atproto_code_verifier'],
            $session['atproto_handle'],
            $session['atproto_did']
        );
    }

    /**
     * Get human-readable error message for an error code.
     *
     * @param int $code Error code from oauth_exception
     *
     * @return string Human-readable error message
     */
    private function getErrorMessage(int $code): string
    {
        $messages = [
            oauth_exception::CODE_INVALID_HANDLE => 'Invalid handle format',
            oauth_exception::CODE_DID_RESOLUTION_FAILED => 'Could not resolve handle - please check it is correct',
            oauth_exception::CODE_OAUTH_DENIED => 'Authorization was denied',
            oauth_exception::CODE_TOKEN_EXCHANGE_FAILED => 'Token exchange failed - please try again',
            oauth_exception::CODE_REFRESH_FAILED => 'Token refresh failed',
            oauth_exception::CODE_CONFIG_ERROR => 'Configuration error',
            oauth_exception::CODE_METADATA_FETCH_FAILED => 'Could not connect to authorization server',
            oauth_exception::CODE_STATE_MISMATCH => 'Invalid state parameter - possible CSRF attempt',
        ];

        return $messages[$code] ?? 'An unknown error occurred';
    }
}
