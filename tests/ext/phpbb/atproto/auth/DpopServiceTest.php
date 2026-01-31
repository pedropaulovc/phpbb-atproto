<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\auth;

use phpbb\atproto\auth\dpop_service;
use phpbb\atproto\auth\dpop_service_interface;
use PHPUnit\Framework\TestCase;

class DpopServiceTest extends TestCase
{
    private dpop_service $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Create a service without database for testing
        $this->service = new dpop_service();
    }

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\auth\dpop_service'));
    }

    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists('\phpbb\atproto\auth\dpop_service_interface'));
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(dpop_service_interface::class, $this->service);
    }

    public function test_generates_es256_keypair(): void
    {
        $keypair = $this->service->getKeypair();

        $this->assertArrayHasKey('private', $keypair);
        $this->assertArrayHasKey('public', $keypair);
        $this->assertArrayHasKey('jwk', $keypair);

        // JWK should be ES256
        $this->assertEquals('EC', $keypair['jwk']['kty']);
        $this->assertEquals('P-256', $keypair['jwk']['crv']);
        $this->assertEquals('ES256', $keypair['jwk']['alg']);
    }

    public function test_creates_valid_dpop_proof(): void
    {
        $proof = $this->service->createProof(
            'POST',
            'https://bsky.social/oauth/token'
        );

        // DPoP proof is a JWT
        $parts = explode('.', $proof);
        $this->assertCount(3, $parts);

        // Decode header
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertEquals('dpop+jwt', $header['typ']);
        $this->assertEquals('ES256', $header['alg']);
        $this->assertArrayHasKey('jwk', $header);

        // Decode payload
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertEquals('POST', $payload['htm']);
        $this->assertEquals('https://bsky.social/oauth/token', $payload['htu']);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function test_proof_includes_ath_for_access_token(): void
    {
        $accessToken = 'test.access.token';
        $proof = $this->service->createProof(
            'GET',
            'https://bsky.social/xrpc/com.atproto.server.getSession',
            $accessToken
        );

        $parts = explode('.', $proof);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // ath = base64url(sha256(access_token))
        $this->assertArrayHasKey('ath', $payload);
        $expectedAth = rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
        $this->assertEquals($expectedAth, $payload['ath']);
    }

    public function test_keypair_persistence(): void
    {
        // Same service instance should return same keypair
        $keypair1 = $this->service->getKeypair();
        $keypair2 = $this->service->getKeypair();

        $this->assertEquals($keypair1['jwk']['x'], $keypair2['jwk']['x']);
        $this->assertEquals($keypair1['jwk']['y'], $keypair2['jwk']['y']);
    }

    public function test_jwk_thumbprint_calculation(): void
    {
        $thumbprint = $this->service->getJwkThumbprint();

        // JWK thumbprint is base64url encoded SHA-256
        $this->assertNotEmpty($thumbprint);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $thumbprint);
    }

    public function test_export_and_load_keypair(): void
    {
        // Generate keypair in first instance
        $keypair1 = $this->service->getKeypair();
        $exported = $this->service->exportKeypair();

        // Load into new instance via constructor's storedKeypair parameter
        $service2 = new dpop_service(null, null, 'phpbb_', $exported);
        $keypair2 = $service2->getKeypair();

        // Should be same keypair
        $this->assertEquals($keypair1['jwk']['x'], $keypair2['jwk']['x']);
        $this->assertEquals($keypair1['jwk']['y'], $keypair2['jwk']['y']);
    }

    public function test_get_public_jwk(): void
    {
        $jwk = $this->service->getPublicJwk();

        $this->assertArrayHasKey('kty', $jwk);
        $this->assertArrayHasKey('crv', $jwk);
        $this->assertArrayHasKey('x', $jwk);
        $this->assertArrayHasKey('y', $jwk);
        $this->assertEquals('EC', $jwk['kty']);
        $this->assertEquals('P-256', $jwk['crv']);
    }

    public function test_proof_method_uppercases_http_method(): void
    {
        $proof = $this->service->createProof(
            'get',
            'https://bsky.social/oauth/token'
        );

        $parts = explode('.', $proof);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertEquals('GET', $payload['htm']);
    }

    public function test_jti_is_unique_per_proof(): void
    {
        $proof1 = $this->service->createProof('POST', 'https://example.com');
        $proof2 = $this->service->createProof('POST', 'https://example.com');

        $parts1 = explode('.', $proof1);
        $parts2 = explode('.', $proof2);

        $payload1 = json_decode(base64_decode(strtr($parts1[1], '-_', '+/')), true);
        $payload2 = json_decode(base64_decode(strtr($parts2[1], '-_', '+/')), true);

        $this->assertNotEquals($payload1['jti'], $payload2['jti']);
    }

    public function test_invalid_keypair_json_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new dpop_service(null, null, 'phpbb_', 'not valid json');
    }

    public function test_incomplete_keypair_json_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new dpop_service(null, null, 'phpbb_', '{"private": "x"}');
    }

    public function test_create_proof_with_nonce(): void
    {
        $nonce = 'test-nonce-value';
        $proof = $this->service->createProofWithNonce(
            'POST',
            'https://bsky.social/oauth/token',
            $nonce
        );

        $parts = explode('.', $proof);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertArrayHasKey('nonce', $payload);
        $this->assertEquals($nonce, $payload['nonce']);
    }
}
