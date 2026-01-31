<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\controller;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\controller\oauth_controller;
use phpbb\atproto\services\token_manager_interface;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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

    private function createController(
        ?oauth_client_interface $oauthClient = null,
        ?token_manager_interface $tokenManager = null,
        ?request $requestObj = null,
        ?user $userObj = null,
        ?template $templateObj = null,
        ?helper $helperObj = null,
        ?language $languageObj = null
    ): oauth_controller {
        $oauthClient = $oauthClient ?? $this->createMock(oauth_client_interface::class);
        $tokenManager = $tokenManager ?? $this->createMock(token_manager_interface::class);
        $requestObj = $requestObj ?? new request();
        $userObj = $userObj ?? new user();
        $templateObj = $templateObj ?? new template();
        $helperObj = $helperObj ?? new helper();
        $languageObj = $languageObj ?? new language([
            'ATPROTO_LOGIN' => 'AT Protocol Login',
            'ATPROTO_ERROR_INVALID_HANDLE' => 'Invalid handle format',
            'ATPROTO_ERROR_DID_RESOLUTION' => 'Could not resolve DID',
            'ATPROTO_ERROR_OAUTH_DENIED' => 'Authorization denied',
            'ATPROTO_ERROR_TOKEN_EXCHANGE' => 'Token exchange failed',
            'ATPROTO_ERROR_REFRESH_FAILED' => 'Token refresh failed',
            'ATPROTO_ERROR_CONFIG' => 'Configuration error',
            'ATPROTO_ERROR_STATE_MISMATCH' => 'State mismatch',
            'ATPROTO_ERROR_UNKNOWN' => 'Unknown error',
            'ATPROTO_ERROR_NO_ACCOUNT' => 'No linked account',
            'ATPROTO_LOGIN_SUCCESS' => 'Login successful',
            'RETURN_INDEX' => 'Return to %sindex%s',
        ]);

        return new oauth_controller(
            $helperObj,
            $languageObj,
            $oauthClient,
            $requestObj,
            $templateObj,
            $tokenManager,
            $userObj
        );
    }

    public function test_start_returns_response_when_no_handle(): void
    {
        $request = new request(['handle' => '']);
        $request->setPostActive(false);

        $controller = $this->createController(requestObj: $request);
        $result = $controller->start();

        $this->assertInstanceOf(Response::class, $result);
    }

    public function test_start_redirects_with_valid_handle(): void
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

        $request = new request(['handle' => 'alice.bsky.social', 'login' => '1']);
        $request->setPostActive(true);

        $user = new user();

        $controller = $this->createController(
            oauthClient: $oauthClient,
            requestObj: $request,
            userObj: $user
        );

        $result = $controller->start();

        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('bsky.social/oauth/authorize', $result->getTargetUrl());

        // Verify session was populated
        $this->assertEquals('test-verifier-123', $user->data['atproto_code_verifier']);
        $this->assertEquals('alice.bsky.social', $user->data['atproto_handle']);
        $this->assertEquals('did:plc:alice123', $user->data['atproto_did']);
        $this->assertNotEmpty($user->data['atproto_oauth_state']);
    }

    public function test_start_shows_error_on_invalid_handle(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('getAuthorizationUrl')
            ->willThrowException(new oauth_exception(
                'Invalid handle',
                oauth_exception::CODE_INVALID_HANDLE
            ));

        $request = new request(['handle' => 'invalid handle', 'login' => '1']);
        $request->setPostActive(true);

        $template = new template();

        $controller = $this->createController(
            oauthClient: $oauthClient,
            requestObj: $request,
            templateObj: $template
        );

        $result = $controller->start();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
    }

    public function test_start_shows_error_on_did_resolution_failure(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('getAuthorizationUrl')
            ->willThrowException(new oauth_exception(
                'DID resolution failed',
                oauth_exception::CODE_DID_RESOLUTION_FAILED
            ));

        $request = new request(['handle' => 'nonexistent.handle.com', 'login' => '1']);
        $request->setPostActive(true);

        $template = new template();

        $controller = $this->createController(
            oauthClient: $oauthClient,
            requestObj: $request,
            templateObj: $template
        );

        $result = $controller->start();

        $this->assertInstanceOf(Response::class, $result);
        $error = $template->getVar('ATPROTO_LOGIN_ERROR');
        $this->assertNotEmpty($error);
        $this->assertStringContainsString('resolve', strtolower($error));
    }

    public function test_callback_shows_error_when_authorization_denied(): void
    {
        $request = new request(['error' => 'access_denied', 'code' => '', 'state' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
        ]);

        $template = new template();

        $controller = $this->createController(
            requestObj: $request,
            userObj: $user,
            templateObj: $template
        );

        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
    }

    public function test_callback_shows_error_on_state_mismatch(): void
    {
        $request = new request(['code' => 'auth-code', 'state' => 'wrong-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'expected-state',
            'atproto_code_verifier' => 'test-verifier',
        ]);

        $template = new template();

        $controller = $this->createController(
            requestObj: $request,
            userObj: $user,
            templateObj: $template
        );

        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
    }

    public function test_callback_shows_error_on_empty_state(): void
    {
        $request = new request(['code' => 'auth-code', 'state' => '', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'expected-state',
            'atproto_code_verifier' => 'test-verifier',
        ]);

        $template = new template();

        $controller = $this->createController(
            requestObj: $request,
            userObj: $user,
            templateObj: $template
        );

        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
    }

    public function test_callback_shows_error_on_missing_code_verifier(): void
    {
        $request = new request(['code' => 'auth-code', 'state' => 'test-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            // Missing code_verifier
        ]);

        $template = new template();

        $controller = $this->createController(
            requestObj: $request,
            userObj: $user,
            templateObj: $template
        );

        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
    }

    public function test_callback_exchanges_code_successfully(): void
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

        $request = new request(['code' => 'auth-code-123', 'state' => 'test-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:user123',
        ]);

        $controller = $this->createController(
            oauthClient: $oauthClient,
            tokenManager: $tokenManager,
            requestObj: $request,
            userObj: $user
        );

        // This will trigger an error which is the expected flow for no linked account
        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        // Session should be cleared
        $this->assertArrayNotHasKey('atproto_oauth_state', $user->data);
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
        $tokenManager->expects($this->once())
            ->method('storeTokens')
            ->with(42, 'did:plc:existing', 'existing.user.com', '', 'at_test123', 'rt_test123', 3600);

        $request = new request(['code' => 'auth-code', 'state' => 'test-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'existing.user.com',
            'atproto_did' => 'did:plc:existing',
        ]);

        $controller = $this->createController(
            oauthClient: $oauthClient,
            tokenManager: $tokenManager,
            requestObj: $request,
            userObj: $user
        );

        // Note: This will call trigger_error which we can't easily test
        // The important thing is the tokenManager->storeTokens was called
        try {
            $controller->callback();
        } catch (\Exception $e) {
            // Expected - trigger_error throws
        }
    }

    public function test_callback_shows_error_on_token_exchange_failure(): void
    {
        $oauthClient = $this->createMock(oauth_client_interface::class);
        $oauthClient->expects($this->once())
            ->method('exchangeCode')
            ->willThrowException(new oauth_exception(
                'Token exchange failed',
                oauth_exception::CODE_TOKEN_EXCHANGE_FAILED
            ));

        $request = new request(['code' => 'auth-code', 'state' => 'test-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:alice123',
        ]);

        $template = new template();

        $controller = $this->createController(
            oauthClient: $oauthClient,
            requestObj: $request,
            userObj: $user,
            templateObj: $template
        );

        $result = $controller->callback();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertNotEmpty($template->getVar('ATPROTO_LOGIN_ERROR'));
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
        // Verify it looks up the DID from token exchange, not session
        $tokenManager->expects($this->once())
            ->method('findUserByDid')
            ->with('did:plc:from-exchange')
            ->willReturn(null);

        $request = new request(['code' => 'auth-code', 'state' => 'test-state', 'error' => '']);
        $user = new user([
            'atproto_oauth_state' => 'test-state',
            'atproto_code_verifier' => 'test-verifier',
            'atproto_handle' => 'alice.bsky.social',
            'atproto_did' => 'did:plc:from-session',
        ]);

        $controller = $this->createController(
            oauthClient: $oauthClient,
            tokenManager: $tokenManager,
            requestObj: $request,
            userObj: $user
        );

        $controller->callback();
    }
}
