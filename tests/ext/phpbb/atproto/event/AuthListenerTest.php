<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\event;

use phpbb\atproto\event\auth_listener;
use phpbb\atproto\services\token_manager_interface;
use PHPUnit\Framework\TestCase;
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
        $this->assertArrayHasKey('core.logout_after', $events);
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

    public function test_on_logout_after_clears_tokens(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->once())
            ->method('clearTokens')
            ->with(42);

        $listener = new auth_listener($tokenManager);

        $session = ['atproto_did' => 'did:plc:test123', 'atproto_needs_reauth' => true];
        $listener->onLogoutAfter(42, $session);

        $this->assertArrayNotHasKey('atproto_did', $session);
        $this->assertArrayNotHasKey('atproto_needs_reauth', $session);
    }

    public function test_on_logout_after_skips_anonymous_user(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->never())
            ->method('clearTokens');

        $listener = new auth_listener($tokenManager);

        $session = [];
        $listener->onLogoutAfter(1, $session); // ANONYMOUS = 1
    }

    public function test_on_user_setup_after_returns_null_for_anonymous(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $listener = new auth_listener($tokenManager);

        $session = [];
        $result = $listener->onUserSetupAfter(['user_id' => 1], $session);

        $this->assertNull($result);
    }

    public function test_on_user_setup_after_returns_null_for_user_without_did(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->method('getUserDid')
            ->with(42)
            ->willReturn(null);

        $listener = new auth_listener($tokenManager);

        $session = [];
        $result = $listener->onUserSetupAfter(['user_id' => 42], $session);

        $this->assertNull($result);
    }

    public function test_on_user_setup_after_returns_true_for_valid_token(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->method('getUserDid')
            ->with(42)
            ->willReturn('did:plc:test123');
        $tokenManager->method('isTokenValid')
            ->with(42)
            ->willReturn(true);

        $listener = new auth_listener($tokenManager);

        $session = [];
        $result = $listener->onUserSetupAfter(['user_id' => 42], $session);

        $this->assertTrue($result);
    }

    public function test_on_user_setup_after_sets_reauth_flag_for_invalid_token(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->method('getUserDid')
            ->with(42)
            ->willReturn('did:plc:test123');
        $tokenManager->method('isTokenValid')
            ->with(42)
            ->willReturn(false);

        $listener = new auth_listener($tokenManager);

        $session = [];
        $result = $listener->onUserSetupAfter(['user_id' => 42], $session);

        $this->assertFalse($result);
        $this->assertTrue($session['atproto_needs_reauth']);
    }

    public function test_on_session_create_after_stores_did_in_session(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->method('getUserDid')
            ->with(42)
            ->willReturn('did:plc:test123');

        $listener = new auth_listener($tokenManager);

        $session = [];
        $listener->onSessionCreateAfter(['user_id' => 42], $session);

        $this->assertEquals('did:plc:test123', $session['atproto_did']);
    }

    public function test_on_session_create_after_skips_anonymous(): void
    {
        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->never())
            ->method('getUserDid');

        $listener = new auth_listener($tokenManager);

        $session = [];
        $listener->onSessionCreateAfter(['user_id' => 1], $session);

        $this->assertArrayNotHasKey('atproto_did', $session);
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
