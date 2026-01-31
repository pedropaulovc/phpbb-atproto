# Task 9: Auth Event Listener

**Files:**
- Create: `ext/phpbb/atproto/event/auth_listener.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/event/AuthListenerTest.php
<?php

namespace phpbb\atproto\tests\event;

class AuthListenerTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\event\auth_listener'));
    }

    public function test_implements_event_subscriber()
    {
        $reflection = new \ReflectionClass('\phpbb\atproto\event\auth_listener');
        $this->assertTrue($reflection->implementsInterface(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class));
    }

    public function test_subscribes_to_logout_event()
    {
        $events = \phpbb\atproto\event\auth_listener::getSubscribedEvents();
        $this->assertArrayHasKey('core.logout_after', $events);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/event/AuthListenerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\event\auth_listener' not found"

**Step 3: Create auth_listener.php**

```php
<?php

namespace phpbb\atproto\event;

use phpbb\atproto\services\token_manager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener for AT Protocol authentication events.
 */
class auth_listener implements EventSubscriberInterface
{
    private token_manager $tokenManager;
    private \phpbb\user $user;
    private \phpbb\db\driver\driver_interface $db;
    private string $tablePrefix;

    public function __construct(
        token_manager $tokenManager,
        \phpbb\user $user,
        \phpbb\db\driver\driver_interface $db,
        string $tablePrefix
    ) {
        $this->tokenManager = $tokenManager;
        $this->user = $user;
        $this->db = $db;
        $this->tablePrefix = $tablePrefix;
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
     */
    public function onUserSetupAfter(\phpbb\event\data $event): void
    {
        if ($this->user->data['user_id'] == ANONYMOUS) {
            return;
        }

        $userId = (int) $this->user->data['user_id'];

        // Check if user has AT Protocol tokens
        $did = $this->tokenManager->getUserDid($userId);
        if ($did === null) {
            return;
        }

        // Check if token is still valid
        if (!$this->tokenManager->isTokenValid($userId)) {
            // Token expired and can't be refreshed - user needs to re-login
            // We don't force logout here, but mark for re-auth
            $this->user->session_setvar('atproto_needs_reauth', true);
        }
    }

    /**
     * Bind session to DID after creation.
     */
    public function onSessionCreateAfter(\phpbb\event\data $event): void
    {
        if ($this->user->data['user_id'] == ANONYMOUS) {
            return;
        }

        $userId = (int) $this->user->data['user_id'];
        $did = $this->tokenManager->getUserDid($userId);

        if ($did !== null) {
            // Store DID in session for quick access
            $this->user->session_setvar('atproto_did', $did);
        }
    }

    /**
     * Clear AT Protocol tokens on logout.
     */
    public function onLogoutAfter(\phpbb\event\data $event): void
    {
        $userId = $event['user_id'] ?? null;

        if ($userId === null || $userId == ANONYMOUS) {
            return;
        }

        // Clear tokens
        $this->tokenManager->clearTokens((int) $userId);

        // Clear session variables
        $this->user->session_setvar('atproto_did', null);
        $this->user->session_setvar('atproto_needs_reauth', null);
    }

    /**
     * Check if user needs re-authentication.
     */
    public function needsReauth(): bool
    {
        return (bool) $this->user->session_getvar('atproto_needs_reauth');
    }

    /**
     * Get current user's DID from session.
     */
    public function getCurrentDid(): ?string
    {
        return $this->user->session_getvar('atproto_did');
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/event/AuthListenerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/event/auth_listener.php tests/ext/phpbb/atproto/event/AuthListenerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add auth event listener

- Check token validity on user setup
- Bind session to DID after creation
- Clear tokens on logout
- Track re-authentication needs

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
