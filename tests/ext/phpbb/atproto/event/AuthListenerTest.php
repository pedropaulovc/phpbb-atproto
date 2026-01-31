<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\event;

use phpbb\atproto\event\auth_listener;
use phpbb\atproto\services\token_manager_interface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AuthListenerTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\event\auth_listener'));
    }

    public function test_implements_event_subscriber(): void
    {
        $reflection = new \ReflectionClass('\phpbb\atproto\event\auth_listener');
        $this->assertTrue($reflection->implementsInterface(EventSubscriberInterface::class));
    }

    public function test_subscribes_to_logout_event(): void
    {
        $events = auth_listener::getSubscribedEvents();
        $this->assertArrayHasKey('core.ucp_logout_after', $events);
    }

    public function test_subscribes_to_user_setup_after_event(): void
    {
        $events = auth_listener::getSubscribedEvents();
        $this->assertArrayHasKey('core.user_setup_after', $events);
    }

    public function test_subscribes_to_session_create_after_event(): void
    {
        $events = auth_listener::getSubscribedEvents();
        $this->assertArrayHasKey('core.session_create_after', $events);
    }

    private function createEventMock(array $data = []): Event
    {
        $event = $this->createMock(Event::class);

        // Use anonymous class to implement ArrayAccess behavior
        return new class ($data) extends Event implements \ArrayAccess {
            private array $data;

            public function __construct(array $data)
            {
                $this->data = $data;
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->data[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->data[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->data[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->data[$offset]);
            }
        };
    }

    public function test_on_logout_after_clears_tokens(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->once())
            ->method('clearTokens')
            ->with(42);

        $listener = new auth_listener($tokenManager);

        $event = $this->createEventMock(['user_id' => 42]);
        $listener->onLogoutAfter($event);
    }

    public function test_on_logout_after_skips_anonymous_user(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->never())
            ->method('clearTokens');

        $listener = new auth_listener($tokenManager);

        $event = $this->createEventMock(['user_id' => 1]); // ANONYMOUS = 1
        $listener->onLogoutAfter($event);
    }

    public function test_on_user_setup_after_does_not_throw(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $event = $this->createEventMock(['user_id' => 1]);
        // Should not throw
        $listener->onUserSetupAfter($event);
        $this->assertTrue(true);
    }

    public function test_on_session_create_after_does_not_throw(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $event = $this->createEventMock(['user_id' => 42]);
        // Should not throw
        $listener->onSessionCreateAfter($event);
        $this->assertTrue(true);
    }

    public function test_needs_reauth_returns_true_when_flag_set(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $session = ['atproto_needs_reauth' => true];
        $this->assertTrue($listener->needsReauth($session));
    }

    public function test_needs_reauth_returns_false_when_flag_not_set(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $session = [];
        $this->assertFalse($listener->needsReauth($session));
    }

    public function test_get_current_did_returns_did_from_session(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $session = ['atproto_did' => 'did:plc:test123'];
        $this->assertEquals('did:plc:test123', $listener->getCurrentDid($session));
    }

    public function test_get_current_did_returns_null_when_not_set(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $session = [];
        $this->assertNull($listener->getCurrentDid($session));
    }
}
