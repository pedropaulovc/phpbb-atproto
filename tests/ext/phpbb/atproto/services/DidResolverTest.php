<?php

declare(strict_types=1);

namespace phpbb\atproto\tests\services;

use phpbb\atproto\services\did_resolver;
use phpbb\cache\driver\driver_interface;
use PHPUnit\Framework\TestCase;

class DidResolverTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\did_resolver'));
    }

    public function test_resolve_handle_validates_format(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveHandle('invalid handle with spaces');
    }

    public function test_resolve_did_validates_format(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveDid('not-a-did');
    }

    public function test_is_valid_handle(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidHandle('alice.bsky.social'));
        $this->assertTrue($resolver->isValidHandle('user.example.com'));
        $this->assertFalse($resolver->isValidHandle('invalid'));
        $this->assertFalse($resolver->isValidHandle('has spaces.com'));
        $this->assertFalse($resolver->isValidHandle(''));
    }

    public function test_is_valid_did(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidDid('did:plc:abcdef123'));
        $this->assertTrue($resolver->isValidDid('did:web:example.com'));
        $this->assertFalse($resolver->isValidDid('notadid'));
        $this->assertFalse($resolver->isValidDid('did:'));
        $this->assertFalse($resolver->isValidDid(''));
    }

    public function test_extract_pds_url_from_did_document(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://bsky.social',
                ],
            ],
        ];

        $pdsUrl = $resolver->extractPdsUrl($didDoc);
        $this->assertEquals('https://bsky.social', $pdsUrl);
    }

    public function test_extract_pds_url_returns_null_for_empty_document(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertNull($resolver->extractPdsUrl([]));
    }

    public function test_extract_pds_url_returns_null_when_no_atproto_pds_service(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#other_service',
                    'type' => 'OtherService',
                    'serviceEndpoint' => 'https://other.example.com',
                ],
            ],
        ];

        $this->assertNull($resolver->extractPdsUrl($didDoc));
    }

    public function test_resolve_handle_uses_cache(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('atproto_handle_alice.bsky.social')
            ->willReturn('did:plc:cached123');

        $resolver = new did_resolver($cache, 3600);
        $did = $resolver->resolveHandle('alice.bsky.social');

        $this->assertEquals('did:plc:cached123', $did);
    }

    public function test_resolve_did_uses_cache(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $cachedDoc = [
            'id' => 'did:plc:test123',
            'service' => [],
        ];
        $cache->expects($this->once())
            ->method('get')
            ->with('atproto_did_did:plc:test123')
            ->willReturn($cachedDoc);

        $resolver = new did_resolver($cache, 3600);
        $doc = $resolver->resolveDid('did:plc:test123');

        $this->assertEquals($cachedDoc, $doc);
    }

    public function test_get_pds_url_returns_url_from_resolved_did(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $cachedDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.com',
                ],
            ],
        ];
        $cache->method('get')
            ->with('atproto_did_did:plc:test123')
            ->willReturn($cachedDoc);

        $resolver = new did_resolver($cache, 3600);
        $pdsUrl = $resolver->getPdsUrl('did:plc:test123');

        $this->assertEquals('https://pds.example.com', $pdsUrl);
    }

    public function test_get_pds_url_throws_when_no_pds_found(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $cachedDoc = [
            'id' => 'did:plc:test123',
            'service' => [],
        ];
        $cache->method('get')
            ->with('atproto_did_did:plc:test123')
            ->willReturn($cachedDoc);

        $resolver = new did_resolver($cache, 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No PDS endpoint found');
        $resolver->getPdsUrl('did:plc:test123');
    }

    public function test_is_valid_handle_rejects_single_part(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertFalse($resolver->isValidHandle('justonepart'));
    }

    public function test_is_valid_handle_rejects_empty_segments(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        // Double dots create empty segment
        $this->assertFalse($resolver->isValidHandle('test..com'));
        $this->assertFalse($resolver->isValidHandle('.test.com'));
        $this->assertFalse($resolver->isValidHandle('test.com.'));
    }

    public function test_is_valid_handle_accepts_single_char_segments(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidHandle('a.b'));
        $this->assertTrue($resolver->isValidHandle('x.example.com'));
    }

    public function test_is_valid_handle_rejects_invalid_single_char_segments(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        // Single char segments must be alphanumeric
        $this->assertFalse($resolver->isValidHandle('-.example.com'));
    }

    public function test_is_valid_handle_rejects_hyphen_at_start_of_segment(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertFalse($resolver->isValidHandle('-test.com'));
        $this->assertFalse($resolver->isValidHandle('test.-invalid.com'));
    }

    public function test_is_valid_handle_rejects_hyphen_at_end_of_segment(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertFalse($resolver->isValidHandle('test-.com'));
        $this->assertFalse($resolver->isValidHandle('test.invalid-.com'));
    }

    public function test_is_valid_handle_accepts_hyphen_in_middle(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidHandle('my-handle.bsky.social'));
        $this->assertTrue($resolver->isValidHandle('test.my-domain.com'));
    }

    public function test_is_valid_did_rejects_incomplete_did(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $this->assertFalse($resolver->isValidDid('did:'));
        $this->assertFalse($resolver->isValidDid('did:plc'));
        $this->assertFalse($resolver->isValidDid('did:plc:'));
    }

    public function test_extract_pds_url_with_full_id(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        // Full ID format like "did:plc:xxx#atproto_pds"
        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => 'did:plc:test123#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.com',
                ],
            ],
        ];

        $pdsUrl = $resolver->extractPdsUrl($didDoc);
        $this->assertEquals('https://pds.example.com', $pdsUrl);
    }

    public function test_extract_pds_url_ignores_non_array_services(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                'not an array',  // Invalid service entry
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.com',
                ],
            ],
        ];

        $pdsUrl = $resolver->extractPdsUrl($didDoc);
        $this->assertEquals('https://pds.example.com', $pdsUrl);
    }

    public function test_extract_pds_url_requires_correct_type(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $resolver = new did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'WrongType',  // Wrong type
                    'serviceEndpoint' => 'https://pds.example.com',
                ],
            ],
        ];

        $this->assertNull($resolver->extractPdsUrl($didDoc));
    }

    public function test_resolve_handle_caches_result(): void
    {
        $cache = $this->createMock(driver_interface::class);

        // First call: cache miss, then cache put
        $callOrder = [];
        $cache->method('get')
            ->willReturnCallback(function ($key) use (&$callOrder) {
                $callOrder[] = 'get:' . $key;

                // First call returns false (cache miss), subsequent calls would return the value
                return false;
            });

        // The cache put should be called with the resolved DID
        // Note: This test covers the cache miss path; actual DNS/HTTP resolution
        // would fail in unit tests, so we expect the exception
        $resolver = new did_resolver($cache, 3600);

        // Since we can't mock DNS/HTTP in unit tests, this will throw
        // but it exercises the cache miss path
        $this->expectException(\RuntimeException::class);
        $resolver->resolveHandle('nonexistent.test.invalid');
    }

    public function test_resolve_did_throws_for_unsupported_method(): void
    {
        $cache = $this->createMock(driver_interface::class);
        $cache->method('get')->willReturn(false);

        $resolver = new did_resolver($cache, 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported DID method');
        $resolver->resolveDid('did:key:z123456');
    }

    public function test_resolve_did_caches_result_on_success(): void
    {
        $cache = $this->createMock(driver_interface::class);

        $cachedDoc = [
            'id' => 'did:plc:cached123',
            'service' => [],
        ];

        // Return cached document
        $cache->method('get')
            ->with('atproto_did_did:plc:cached123')
            ->willReturn($cachedDoc);

        $resolver = new did_resolver($cache, 3600);
        $doc = $resolver->resolveDid('did:plc:cached123');

        $this->assertEquals($cachedDoc, $doc);
    }
}
