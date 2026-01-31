<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\controller;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\controller\oauth_controller;
use phpbb\atproto\services\token_manager_interface;
use PHPUnit\Framework\TestCase;

class OAuthControllerTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\controller\oauth_controller'));
    }

    public function test_has_start_method(): void
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'start'));
    }

    public function test_has_callback_method(): void
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'callback'));
    }

    public function test_start_returns_form_when_no_handle(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [];

        $result = $controller->start('', $session);

        $this->assertEquals('form', $result['type']);
        $this->assertEquals('', $result['error']);
    }

    public function test_start_returns_redirect_with_valid_handle(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('getAuthorizationUrl')
            ->with('alice.bsky.social', $this->isType('string'))
            ->willReturn([
                'url' => 'https://bsky.social/oauth/authorize?state=test123',
                'code_verifier' => 'test-verifier-123',
                'did' => 'did:plc:alice123',
            ]);

        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [];

        $result = $controller->start('alice.bsky.social', $session);

        $this->assertEquals('redirect', $result['type']);
        $this->assertStringContainsString('bsky.social/oauth/authorize', $result['url']);
        $this->assertEquals('test-verifier-123', $session['atproto_code_verifier']);
        $this->assertEquals('alice.bsky.social', $session['atproto_handle']);
        $this->assertEquals('did:plc:alice123', $session['atproto_did']);
        $this->assertNotEmpty($session['atproto_oauth_state']);
    }

    public function test_start_returns_error_on_invalid_handle(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('getAuthorizationUrl')
            ->willThrowException(new oauth_exception(
                'Invalid handle',
                oauth_exception::CODE_INVALID_HANDLE
            ));

        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [];

        $result = $controller->start('invalid handle', $session);

        $this->assertEquals('form', $result['type']);
        $this->assertNotEmpty($result['error']);
    }

    public function test_start_returns_error_on_did_resolution_failure(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('getAuthorizationUrl')
            ->willThrowException(new oauth_exception(
                'DID resolution failed',
                oauth_exception::CODE_DID_RESOLUTION_FAILED
            ));

        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [];

        $result = $controller->start('nonexistent.handle.com', $session);

        $this->assertEquals('form', $result['type']);
        $this->assertStringContainsString('resolve', strtolower($result['error']));
    }

    public function test_callback_returns_error_when_authorization_denied(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
        ];

        $result = $controller->callback('', '', 'access_denied', $session);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals(oauth_exception::CODE_OAUTH_DENIED, $result['error_code']);
        // Session should be cleared
        $this->assertArrayNotHasKey('atproto_oauth_state', $session);
    }

    public function test_callback_returns_error_on_state_mismatch(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'expected-state',
            'atproto_code_verifier' => 'test-verifier',
        ];

        $result = $controller->callback('auth-code', 'wrong-state', '', $session);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals(oauth_exception::CODE_STATE_MISMATCH, $result['error_code']);
    }

    public function test_callback_returns_error_on_empty_state(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'expected-state',
            'atproto_code_verifier' => 'test-verifier',
        ];

        $result = $controller->callback('auth-code', '', '', $session);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals(oauth_exception::CODE_STATE_MISMATCH, $result['error_code']);
    }

    public function test_callback_returns_error_on_missing_session_data(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            // Missing code_verifier
        ];

        $result = $controller->callback('auth-code', 'test-state', '', $session);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals(oauth_exception::CODE_CONFIG_ERROR, $result['error_code']);
    }

    public function test_callback_exchanges_code_and_returns_success(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->with('auth-code-123', 'test-state', 'test-verifier')
            ->willReturn([
                'access_token' => 'at_test123',
                'refresh_token' => 'rt_test123',
                'did' => 'did:plc:user123',
                'expires_in' => 3600,
                'token_type' => 'DPoP',
            ]);

        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->once())
            ->method('findUserByDid')
            ->with('did:plc:user123')
            ->willReturn(null);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:user123',
        ];

        $result = $controller->callback('auth-code-123', 'test-state', '', $session);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals('did:plc:user123', $result['did']);
        $this->assertEquals('alice.bsky.social', $result['handle']);
        $this->assertEquals('at_test123', $result['access_token']);
        $this->assertEquals('rt_test123', $result['refresh_token']);
        $this->assertEquals(3600, $result['expires_in']);
        $this->assertNull($result['existing_user_id']);
        // Session should be cleared
        $this->assertArrayNotHasKey('atproto_oauth_state', $session);
    }

    public function test_callback_finds_existing_user_by_did(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->willReturn([
                'access_token' => 'at_test123',
                'refresh_token' => 'rt_test123',
                'did' => 'did:plc:existing',
                'expires_in' => 3600,
                'token_type' => 'DPoP',
            ]);

        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->once())
            ->method('findUserByDid')
            ->with('did:plc:existing')
            ->willReturn(42);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'existing.user.com',
            'atproto_did' => 'did:plc:existing',
        ];

        $result = $controller->callback('auth-code', 'test-state', '', $session);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals(42, $result['existing_user_id']);
    }

    public function test_callback_stores_tokens_when_user_id_provided(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->willReturn([
                'access_token' => 'at_test123',
                'refresh_token' => 'rt_test123',
                'did' => 'did:plc:user123',
                'expires_in' => 3600,
                'token_type' => 'DPoP',
            ]);

        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->expects($this->once())
            ->method('storeTokens')
            ->with(
                123,
                'did:plc:user123',
                'alice.bsky.social',
                '',
                'at_test123',
                'rt_test123',
                3600
            );
        // findUserByDid should NOT be called when userId is provided
        $tokenManager->expects($this->never())
            ->method('findUserByDid');

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:user123',
        ];

        $result = $controller->callback('auth-code', 'test-state', '', $session, 123);

        $this->assertEquals('success', $result['type']);
        $this->assertEquals(123, $result['user_id']);
        $this->assertEquals('did:plc:user123', $result['did']);
        $this->assertArrayNotHasKey('access_token', $result);
    }

    public function test_callback_returns_error_on_token_exchange_failure(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->willThrowException(new oauth_exception(
                'Token exchange failed',
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            ));

        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:alice123',
        ];

        $result = $controller->callback('auth-code', 'test-state', '', $session);

        $this->assertEquals('error', $result['type']);
        $this->assertEquals(oauth_exception::CODE_TOKEN_EXCHANGE_FAILED, $result['error_code']);
        // Session should be cleared on error
        $this->assertArrayNotHasKey('atproto_oauth_state', $session);
    }

    public function test_start_with_error_code_displays_error(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $tokenManager = $this->createMock(token_manager_interface::class);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [];

        $result = $controller->start('', $session, oauth_exception::CODE_OAUTH_DENIED);

        $this->assertEquals('form', $result['type']);
        $this->assertNotEmpty($result['error']);
        $this->assertStringContainsString('denied', strtolower($result['error']));
    }

    public function test_callback_uses_did_from_token_exchange(): void
    {
        // Test that when token exchange returns a different DID than session,
        // we prefer the one from token exchange
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->willReturn([
                'access_token' => 'at_test123',
                'refresh_token' => 'rt_test123',
                'did' => 'did:plc:from-exchange',
                'expires_in' => 3600,
                'token_type' => 'DPoP',
            ]);

        $tokenManager = $this->createMock(token_manager_interface::class);
        $tokenManager->method('findUserByDid')
            ->with('did:plc:from-exchange')
            ->willReturn(null);

        $controller = new oauth_controller($oauthClient, $tokenManager);
        $session = [
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:from-session',
        ];

        $result = $controller->callback('auth-code', 'test-state', '', $session);

        // Should use DID from token exchange, not session
        $this->assertEquals('did:plc:from-exchange', $result['did']);
    }
}
