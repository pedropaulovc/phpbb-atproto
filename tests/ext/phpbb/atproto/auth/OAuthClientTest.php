<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\auth;

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

    public function test_exchange_code_extracts_did_from_jwt_when_sub_missing(): void
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

        // Create a JWT-like access token with 'sub' in the payload
        $header = base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode(['sub' => 'did:plc:fromjwt123', 'iat' => time()]));
        $signature = base64_encode('fake-signature');
        $jwtToken = rtrim(strtr($header, '+/', '-_'), '=') . '.' .
                    rtrim(strtr($payload, '+/', '-_'), '=') . '.' .
                    rtrim(strtr($signature, '+/', '-_'), '=');

        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => $jwtToken,
                'refresh_token' => 'rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
                // No 'sub' in the response - should be extracted from JWT
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        $this->assertEquals('did:plc:fromjwt123', $result['did']);
    }

    public function test_exchange_code_handles_invalid_jwt_gracefully(): void
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
                'access_token' => 'not-a-jwt-token',  // Invalid JWT format
                'refresh_token' => 'rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        // Should return empty string for DID when extraction fails
        $this->assertEquals('', $result['did']);
    }

    public function test_exchange_code_handles_jwt_with_invalid_payload(): void
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

        // JWT with invalid base64 in payload
        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => 'header.!!!invalid-base64!!!.signature',
                'refresh_token' => 'rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        $this->assertEquals('', $result['did']);
    }

    public function test_exchange_code_handles_jwt_with_non_array_payload(): void
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

        // JWT with string payload instead of JSON object
        $header = rtrim(strtr(base64_encode('{"alg":"ES256"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('"just a string"'), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('sig'), '+/', '-_'), '=');

        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => "$header.$payload.$signature",
                'refresh_token' => 'rt_test123',
                'token_type' => 'DPoP',
                'expires_in' => 3600,
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        $this->assertEquals('', $result['did']);
    }

    public function test_exchange_code_throws_without_metadata(): void
    {
        $didResolver = $this->createMock(did_resolver::class);
        $dpopService = $this->createDpopServiceMock();

        $client = new oauth_client(
            $didResolver,
            $dpopService,
            'https://forum.example.com/client-metadata.json',
            'https://forum.example.com/atproto/callback'
        );

        // Don't set OAuth metadata

        $this->expectException(oauth_exception::class);
        $this->expectExceptionCode(oauth_exception::CODE_CONFIG_ERROR);

        $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');
    }

    public function test_exchange_code_uses_defaults_when_optional_fields_missing(): void
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

        // Return minimal response without optional fields
        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => 'at_test123',
                'sub' => 'did:plc:user123',
                // No refresh_token, expires_in, or token_type
            ]);

        $result = $client->exchangeCode('auth_code_123', 'state123', 'code_verifier_123');

        $this->assertEquals('at_test123', $result['access_token']);
        $this->assertEquals('', $result['refresh_token']);
        $this->assertEquals(3600, $result['expires_in']);
        $this->assertEquals('DPoP', $result['token_type']);
    }

    public function test_refresh_access_token_uses_defaults_when_optional_fields_missing(): void
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

        // Return minimal response without optional fields
        $client->method('makeTokenRequest')
            ->willReturn([
                'access_token' => 'new_at_test123',
                // No refresh_token, expires_in, or token_type
            ]);

        $result = $client->refreshAccessToken('old_rt_test123', 'https://pds.example.com');

        $this->assertEquals('new_at_test123', $result['access_token']);
        $this->assertEquals('', $result['refresh_token']);
        $this->assertEquals(3600, $result['expires_in']);
        $this->assertEquals('DPoP', $result['token_type']);
    }

    public function test_get_authorization_url_uses_cached_metadata(): void
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

        // Set metadata before call
        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'test-state');

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('code_verifier', $result);
        $this->assertArrayHasKey('did', $result);
        $this->assertEquals('did:plc:test123', $result['did']);

        // Verify code_verifier is a valid PKCE verifier (43-128 characters, base64url)
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $result['code_verifier']);
    }

    public function test_get_authorization_url_returns_dpop_nonce_when_present(): void
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
            'dpop_nonce' => 'server-provided-nonce',
        ]);

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'test-state');

        $this->assertEquals('server-provided-nonce', $result['dpop_nonce']);
    }

    public function test_get_authorization_url_returns_null_dpop_nonce_when_missing(): void
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
            // No dpop_nonce
        ]);

        $client->setOAuthMetadata([
            'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
            'token_endpoint' => 'https://bsky.social/oauth/token',
            'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
        ]);

        $result = $client->getAuthorizationUrl('did:plc:test123', 'test-state');

        $this->assertNull($result['dpop_nonce']);
    }
}
