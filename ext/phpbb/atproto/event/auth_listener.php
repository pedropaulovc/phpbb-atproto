<?php

declare(strict_types=1);

namespace phpbb\atproto\event;

use phpbb\atproto\services\token_manager_interface;
use Symfony\Component\EventDispatcher\Event;
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
            'core.ucp_logout_after' => 'onLogoutAfter',
        ];
    }

    /**
     * Check token validity after user setup.
     *
     * @param Event $event Event object containing user data
     */
    public function onUserSetupAfter(Event $event): void
    {
        // User setup event doesn't give us direct access to user_id in a useful way for now
        // This is a placeholder for future token validation logic
    }

    /**
     * Bind session to DID after creation.
     *
     * @param Event $event Event object containing session data
     */
    public function onSessionCreateAfter(Event $event): void
    {
        // Session create event - placeholder for future implementation
    }

    /**
     * Clear AT Protocol tokens on logout.
     *
     * @param Event $event Event object containing logout data
     */
    public function onLogoutAfter(Event $event): void
    {
        $userId = $event['user_id'] ?? null;
        if ($userId === null || $userId == 1) { // ANONYMOUS = 1
            return;
        }

        // Clear tokens
        $this->tokenManager->clearTokens((int) $userId);
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
