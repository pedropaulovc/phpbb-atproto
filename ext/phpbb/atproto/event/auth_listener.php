<?php

declare(strict_types=1);

namespace phpbb\atproto\event;

use phpbb\atproto\services\token_manager_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener for AT Protocol authentication events.
 */
class auth_listener implements EventSubscriberInterface
{
    private token_manager_interface $tokenManager;

    public function __construct(token_manager_interface $tokenManager)
    {
        $this->tokenManager = $tokenManager;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'core.user_setup_after' => 'onUserSetupAfter',
            'core.session_create_after' => 'onSessionCreateAfter',
            'core.logout_after' => 'onLogoutAfter',
        ];
    }

    /**
     * Check token validity after user setup.
     *
     * @param array<string, mixed> $userData User data from the event
     * @param array<string, mixed> $session  Session data (passed by reference)
     *
     * @return bool|null True if valid, false if needs reauth, null if not applicable
     */
    public function onUserSetupAfter(array $userData, array &$session): ?bool
    {
        $userId = $userData['user_id'] ?? null;
        if ($userId === null || $userId == 1) { // ANONYMOUS = 1
            return null;
        }

        // Check if user has AT Protocol tokens
        $did = $this->tokenManager->getUserDid((int) $userId);
        if ($did === null) {
            return null;
        }

        // Check if token is still valid
        if (!$this->tokenManager->isTokenValid((int) $userId)) {
            // Token expired and can't be refreshed - mark for re-auth
            $session['atproto_needs_reauth'] = true;

            return false;
        }

        return true;
    }

    /**
     * Bind session to DID after creation.
     *
     * @param array<string, mixed> $userData User data from the event
     * @param array<string, mixed> $session  Session data (passed by reference)
     */
    public function onSessionCreateAfter(array $userData, array &$session): void
    {
        $userId = $userData['user_id'] ?? null;
        if ($userId === null || $userId == 1) { // ANONYMOUS = 1
            return;
        }

        $did = $this->tokenManager->getUserDid((int) $userId);
        if ($did !== null) {
            // Store DID in session for quick access
            $session['atproto_did'] = $did;
        }
    }

    /**
     * Clear AT Protocol tokens on logout.
     *
     * @param int                  $userId  The user ID
     * @param array<string, mixed> $session Session data (passed by reference)
     */
    public function onLogoutAfter(int $userId, array &$session): void
    {
        if ($userId == 1) { // ANONYMOUS = 1
            return;
        }

        // Clear tokens
        $this->tokenManager->clearTokens($userId);

        // Clear session variables
        unset($session['atproto_did'], $session['atproto_needs_reauth']);
    }

    /**
     * Check if user needs re-authentication.
     *
     * @param array<string, mixed> $session Session data
     *
     * @return bool True if re-authentication is needed
     */
    public function needsReauth(array $session): bool
    {
        return !empty($session['atproto_needs_reauth']);
    }

    /**
     * Get current user's DID from session.
     *
     * @param array<string, mixed> $session Session data
     *
     * @return string|null The DID or null if not set
     */
    public function getCurrentDid(array $session): ?string
    {
        return $session['atproto_did'] ?? null;
    }
}
