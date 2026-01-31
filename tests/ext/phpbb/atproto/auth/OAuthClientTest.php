<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\auth;

use phpbb\atproto\auth\oauth_client;
use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\did_resolver;
use PHPUnit\Framework\TestCase;

class OAuthClientTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_client'));
    }

    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists('\phpbb\atproto\auth\oauth_client_interface'));
    }

    public function test_oauth_exception_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\oauth_exception'));
    }

    public function test_implements_interface(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->assertInstanceOf(oauth_client_interface::class, $client);
    }

    public function test_oauth_exception_error_codes(): void
    {
        $this->assertEquals(1, oauth_exception::CODE_INVALID_HANDLE);
        $this->assertEquals(2, oauth_exception::CODE_DID_RESOLUTION_FAILED);
        $this->assertEquals(3, oauth_exception::CODE_OAUTH_DENIED);
        $this->assertEquals(4, oauth_exception::CODE_TOKEN_EXCHANGE_FAILED);
        $this->assertEquals(5, oauth_exception::CODE_REFRESH_FAILED);
        $this->assertEquals(6, oauth_exception::CODE_CONFIG_ERROR);
        $this->assertEquals(7, oauth_exception::CODE_METADATA_FETCH_FAILED);
        $this->assertEquals(8, oauth_exception::CODE_STATE_MISMATCH);
    }

    public function test_oauth_exception_with_code(): void
    {
        $exception = new oauth_exception(
            'Invalid handle format',
            oauth_exception::CODE_INVALID_HANDLE
        );

        $this->assertEquals('Invalid handle format', $exception->getMessage());
        $this->assertEquals(oauth_exception::CODE_INVALID_HANDLE, $exception->getCode());
    }

    public function test_get_authorization_url_includes_required_params(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('resolveHandle')->willReturn('did:plc:test123');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');
        $didResolver->method('isValidDid')->willReturn(false);

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        // Set OAuth metadata to avoid network calls
        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('alice.bsky.social', 'test-state-123');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('code_verifier', $result);
        $this->assertArrayHasKey('did', $result);

        $url = $result['url'];
        $this->assertStringContainsString('client_id=', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertEquals('did:plc:test123', $result['did']);
    }

    public function test_get_authorization_url_resolves_handle_to_did(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->expects($this->once())
            ->method('resolveHandle')
            ->with('alice.bsky.social')
            ->willReturn('did:plc:resolved123');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');
        $didResolver->method('isValidDid')->willReturn(false);

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
        ]);

        $result = $client->getAuthorizationUrl('alice.bsky.social', 'state123');

        $this->assertEquals('did:plc:resolved123', $result['did']);
    }

    public function test_get_authorization_url_accepts_did_directly(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(true);
        $didResolver->expects($this->never())->method('resolveHandle');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:direct123', 'state123');

        $this->assertEquals('did:plc:direct123', $result['did']);
    }

    public function test_get_authorization_url_throws_on_invalid_handle(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(false);
        $didResolver->method('resolveHandle')
            ->willThrowException(new \InvalidArgumentException('Invalid handle'));

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_INVALID_HANDLE);

        $client->getAuthorizationUrl('invalid handle', 'state123');
    }

    public function test_get_authorization_url_throws_on_resolution_failure(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(false);
        $didResolver->method('resolveHandle')
            ->willThrowException(new \RuntimeException('Resolution failed'));

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_DID_RESOLUTION_FAILED);

        $client->getAuthorizationUrl('alice.bsky.social', 'state123');
    }

    public function test_code_verifier_has_valid_length(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(true);
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'state123');

        // PKCE code verifier should be between 43 and 128 characters
        $this->assertGreaterThanOrEqual(43, strlen($result['code_verifier']));
        $this->assertLessThanOrEqual(128, strlen($result['code_verifier']));
        // Should only contain unreserved characters
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $result['code_verifier']);
    }

    public function test_exchange_code_returns_tokens(): void
    {
        $didResolver = $this->createMock(did_resolver::class);

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeTokenRequest'])
            ->getMock();

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
        ]);

        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => 'at_test123',
                'refresh_token' => 'rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
                'sub' => 'did:plc:user123',
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertArrayHasKey('did', $result);
        $this->assertEquals('at_test123', $result['access_token']);
        $this->assertEquals('rt_test123', $result['refresh_token']);
        $this->assertEquals('did:plc:user123', $result['did']);
    }

    public function test_exchange_code_throws_on_failure(): void
    {
        $didResolver = $this->createMock(did_resolver::class);

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeTokenRequest'])
            ->getMock();

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
        ]);

        $client->method('makeTokenRequest')
            ->willThrowException(new \RuntimeException('Token exchange failed'));

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_TOKEN_EXCHANGE_FAILED);

        $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');
    }

    public function test_refresh_access_token_returns_new_tokens(): void
    {
        $didResolver = $this->createMock(did_resolver::class);

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeTokenRequest', 'fetchOAuthMetadata'])
            ->getMock();

        $client->method('fetchOAuthMetadata')
            ->willReturn([
                'authorization_endpoint' => 'https://pds.example.com/oauth/authorize',
                'token_endpoint' => 'https://pds.example.com/oauth/token',
            ]);

        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => 'new_at_test123',
                'refresh_token' => 'new_rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
                'sub' => 'did:plc:user123',
            ]);

        $result = $client->refreshAccessToken('old_rt_test123', 'https://pds.example.com');

        $this->assertArrayHasKey('access_token', $result);
        $this->assertArrayHasKey('refresh_token', $result);
        $this->assertEquals('new_at_test123', $result['access_token']);
        $this->assertEquals('new_rt_test123', $result['refresh_token']);
    }

    public function test_refresh_access_token_throws_on_failure(): void
    {
        $didResolver = $this->createMock(did_resolver::class);

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeTokenRequest', 'fetchOAuthMetadata'])
            ->getMock();

        $client->method('fetchOAuthMetadata')
            ->willReturn([
                'authorization_endpoint' => 'https://pds.example.com/oauth/authorize',
                'token_endpoint' => 'https://pds.example.com/oauth/token',
            ]);

        $client->method('makeTokenRequest')
            ->willThrowException(new \RuntimeException('Refresh failed'));

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_REFRESH_FAILED);

        $client->refreshAccessToken('old_rt_test123', 'https://pds.example.com');
    }

    public function test_set_oauth_metadata(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(true);
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $metadata = [
            'authorization_endpoint' => 'https://custom.auth.example.com/authorize',
            'token_endpoint' => 'https://custom.auth.example.com/token',
        ];

        $client->setOAuthMetadata($metadata);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'state123');
        $this->assertStringContainsString('custom.auth.example.com', $result['url']);
    }

    public function test_get_client_id(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->assertEquals(
            'https://forum.example.com/client-metadata.json',
            $client->getClientId()
        );
    }

    public function test_get_redirect_uri(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $client = new oauth_client(
            $didResolver,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->assertEquals(
            'https://forum.example.com/atproto/callback',
            $client->getRedirectUri()
        );
    }
}
