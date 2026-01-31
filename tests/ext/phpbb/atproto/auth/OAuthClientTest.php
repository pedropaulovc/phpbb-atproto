<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\auth;

use phpbb\atproto\auth\dpop_service;
use phpbb\atproto\auth\dpop_service_interface;
use phpbb\atproto\auth\oauth_client;
use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\did_resolver;
use PHPUnit\Framework\TestCase;

class OAuthClientTest extends TestCase
{
    private function createDpopServiceMock(): dpop_service_interface
    {
        $mock = $this->createMock(dpop_service_interface::class);
        $mock->method('createProof')->willReturn('mock.dpop.proof');
        $mock->method('createProofWithNonce')->willReturn('mock.dpop.proof.with.nonce');
        $mock->method('getPublicJwk')->willReturn([
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'test-x',
            'y' => 'test-y',
        ]);
        return $mock;
    }

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
        $dpopService = $this->createDpopServiceMock();
        $client = new oauth_client(
            $didResolver,
            $dpopService,
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

    public function test_get_authorization_url_throws_without_par_endpoint(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('resolveHandle')->willReturn('did:plc:test123');
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');
        $didResolver->method('isValidDid')->willReturn(false);

        $dpopService = $this->createDpopServiceMock();

        $client = new oauth_client(
            $didResolver,
            $dpopService,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        // Set OAuth metadata without PAR endpoint
        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            // No pushed_authorization_request_endpoint
        ]);

        $this->expectException(oauth_exception::class);
        $this->expectExceptionMessage('PAR endpoint required');

        $client->getAuthorizationUrl('alice.bsky.social', 'test-state-123');
    }

    public function test_get_authorization_url_throws_on_invalid_handle(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(false);
        $didResolver->method('resolveHandle')
            ->willThrowException(new \InvalidArgumentException('Invalid handle'));

        $dpopService = $this->createDpopServiceMock();

        $client = new oauth_client(
            $didResolver,
            $dpopService,
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

        $dpopService = $this->createDpopServiceMock();

        $client = new oauth_client(
            $didResolver,
            $dpopService,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_DID_RESOLUTION_FAILED);

        $client->getAuthorizationUrl('alice.bsky.social', 'state123');
    }

    public function test_exchange_code_returns_tokens(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $dpopService = $this->createDpopServiceMock();

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                $dpopService,
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
        $dpopService = $this->createDpopServiceMock();

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                $dpopService,
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
        $dpopService = $this->createDpopServiceMock();

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                $dpopService,
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
        $dpopService = $this->createDpopServiceMock();

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                $dpopService,
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

    public function test_get_client_id(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $dpopService = $this->createDpopServiceMock();
        $client = new oauth_client(
            $didResolver,
            $dpopService,
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
        $dpopService = $this->createDpopServiceMock();
        $client = new oauth_client(
            $didResolver,
            $dpopService,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        $this->assertEquals(
            'https://forum.example.com/atproto/callback',
            $client->getRedirectUri()
        );
    }

    public function test_par_url_only_contains_client_id_and_request_uri(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $didResolver->method('isValidDid')->willReturn(true);
        $didResolver->method('getPdsUrl')->willReturn('https://bsky.social');

        $dpopService = $this->createDpopServiceMock();

        $client = $this->getMockBuilder(oauth_client::class)
            ->setConstructorArgs([
                $didResolver,
                $dpopService,
                'https://forum.example.com/client-metadata.json',
                'https://forum.example.com/atproto/callback',
            ])
            ->onlyMethods(['makeParRequest'])
            ->getMock();

        $client->method('makeParRequest')->willReturn([
            'request_uri' => 'urn:ietf:params:oauth:request_uri:abc123',
            'expires_in' => 60,
        ]);

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'test-state');
        $url = $result['url'];

        // Parse the URL
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        // Only client_id and request_uri should be in the URL (PAR requirement)
        $this->assertCount(2, $query);
        $this->assertArrayHasKey('client_id', $query);
        $this->assertArrayHasKey('request_uri', $query);
        $this->assertEquals('https://forum.example.com/client-metadata.json', $query['client_id']);
        $this->assertEquals('urn:ietf:params:oauth:request_uri:abc123', $query['request_uri']);

        // Should NOT contain other OAuth params (they went to PAR)
        $this->assertStringNotContainsString('code_challenge=', $url);
        $this->assertStringNotContainsString('redirect_uri=', $url);
        $this->assertStringNotContainsString('scope=', $url);
    }
}
