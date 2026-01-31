# Task 5: DID Resolver Service

**Files:**
- Create: `ext/phpbb/atproto/services/did_resolver.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/services/DidResolverTest.php
<?php

namespace phpbb\atproto\tests\services;

class DidResolverTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\services\did_resolver'));
    }

    public function test_resolve_handle_validates_format()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveHandle('invalid handle with spaces');
    }

    public function test_resolve_did_validates_format()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveDid('not-a-did');
    }

    public function test_is_valid_handle()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidHandle('alice.bsky.social'));
        $this->assertTrue($resolver->isValidHandle('user.example.com'));
        $this->assertFalse($resolver->isValidHandle('invalid'));
        $this->assertFalse($resolver->isValidHandle('has spaces.com'));
        $this->assertFalse($resolver->isValidHandle(''));
    }

    public function test_is_valid_did()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $this->assertTrue($resolver->isValidDid('did:plc:abcdef123'));
        $this->assertTrue($resolver->isValidDid('did:web:example.com'));
        $this->assertFalse($resolver->isValidDid('notadid'));
        $this->assertFalse($resolver->isValidDid('did:'));
        $this->assertFalse($resolver->isValidDid(''));
    }

    public function test_extract_pds_url_from_did_document()
    {
        $cache = $this->createMock(\phpbb\cache\driver\driver_interface::class);
        $resolver = new \phpbb\atproto\services\did_resolver($cache, 3600);

        $didDoc = [
            'id' => 'did:plc:test123',
            'service' => [
                [
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://bsky.social'
                ]
            ]
        ];

        $pdsUrl = $resolver->extractPdsUrl($didDoc);
        $this->assertEquals('https://bsky.social', $pdsUrl);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/DidResolverTest.php`
Expected: FAIL with "Class '\phpbb\atproto\services\did_resolver' not found"

**Step 3: Create did_resolver.php**

```php
<?php

namespace phpbb\atproto\services;

/**
 * DID Resolution service for AT Protocol.
 *
 * Resolves handles to DIDs and DIDs to PDS URLs.
 * Supports did:plc and did:web methods.
 */
class did_resolver
{
    private const PLC_DIRECTORY = 'https://plc.directory';

    /** @var \phpbb\cache\driver\driver_interface */
    private $cache;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl;

    public function __construct(\phpbb\cache\driver\driver_interface $cache, int $cacheTtl = 3600)
    {
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Resolve a handle to a DID.
     *
     * @param string $handle AT Protocol handle (e.g., "alice.bsky.social")
     * @return string DID (e.g., "did:plc:...")
     * @throws \InvalidArgumentException If handle format is invalid
     * @throws \RuntimeException If resolution fails
     */
    public function resolveHandle(string $handle): string
    {
        // Strip leading @ if present
        $handle = ltrim($handle, '@');

        if (!$this->isValidHandle($handle)) {
            throw new \InvalidArgumentException("Invalid handle format: $handle");
        }

        $cacheKey = 'atproto_handle_' . md5($handle);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // Try DNS TXT record first
        $did = $this->resolveHandleViaDns($handle);

        // Fall back to HTTP well-known
        if ($did === null) {
            $did = $this->resolveHandleViaHttp($handle);
        }

        if ($did === null) {
            throw new \RuntimeException("Failed to resolve handle: $handle");
        }

        $this->cache->put($cacheKey, $did, $this->cacheTtl);
        return $did;
    }

    /**
     * Resolve a DID to its DID document.
     *
     * @param string $did DID (did:plc:... or did:web:...)
     * @return array DID document
     * @throws \InvalidArgumentException If DID format is invalid
     * @throws \RuntimeException If resolution fails
     */
    public function resolveDid(string $did): array
    {
        if (!$this->isValidDid($did)) {
            throw new \InvalidArgumentException("Invalid DID format: $did");
        }

        $cacheKey = 'atproto_did_' . md5($did);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        if (strpos($did, 'did:plc:') === 0) {
            $document = $this->resolvePlcDid($did);
        } elseif (strpos($did, 'did:web:') === 0) {
            $document = $this->resolveWebDid($did);
        } else {
            throw new \InvalidArgumentException("Unsupported DID method: $did");
        }

        $this->cache->put($cacheKey, $document, $this->cacheTtl);
        return $document;
    }

    /**
     * Get the PDS URL for a DID.
     *
     * @param string $did DID to resolve
     * @return string PDS service endpoint URL
     * @throws \RuntimeException If PDS URL not found
     */
    public function getPdsUrl(string $did): string
    {
        $document = $this->resolveDid($did);
        $pdsUrl = $this->extractPdsUrl($document);

        if ($pdsUrl === null) {
            throw new \RuntimeException("No PDS service found in DID document: $did");
        }

        return $pdsUrl;
    }

    /**
     * Check if a string is a valid AT Protocol handle.
     */
    public function isValidHandle(string $handle): bool
    {
        // Handle must be a valid domain name with at least 2 segments
        if (empty($handle) || strlen($handle) > 253) {
            return false;
        }

        // Must contain at least one dot
        if (strpos($handle, '.') === false) {
            return false;
        }

        // Each segment: alphanumeric and hyphens, not starting/ending with hyphen
        $segments = explode('.', $handle);
        foreach ($segments as $segment) {
            if (empty($segment) || strlen($segment) > 63) {
                return false;
            }
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $segment)) {
                // Allow single-char segments
                if (!preg_match('/^[a-zA-Z0-9]$/', $segment)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if a string is a valid DID.
     */
    public function isValidDid(string $did): bool
    {
        if (empty($did)) {
            return false;
        }

        // Basic DID format: did:method:identifier
        return (bool) preg_match('/^did:[a-z]+:[a-zA-Z0-9._:%-]+$/', $did);
    }

    /**
     * Extract PDS URL from a DID document.
     *
     * @param array $document DID document
     * @return string|null PDS URL or null if not found
     */
    public function extractPdsUrl(array $document): ?string
    {
        if (!isset($document['service']) || !is_array($document['service'])) {
            return null;
        }

        foreach ($document['service'] as $service) {
            if (isset($service['type']) && $service['type'] === 'AtprotoPersonalDataServer') {
                return $service['serviceEndpoint'] ?? null;
            }
            // Also check by ID for compatibility
            if (isset($service['id']) && $service['id'] === '#atproto_pds') {
                return $service['serviceEndpoint'] ?? null;
            }
        }

        return null;
    }

    /**
     * Resolve handle via DNS TXT record.
     */
    private function resolveHandleViaDns(string $handle): ?string
    {
        $dnsName = '_atproto.' . $handle;

        try {
            $records = dns_get_record($dnsName, DNS_TXT);
            if ($records === false || empty($records)) {
                return null;
            }

            foreach ($records as $record) {
                if (isset($record['txt']) && strpos($record['txt'], 'did=') === 0) {
                    return substr($record['txt'], 4);
                }
            }
        } catch (\Exception $e) {
            // DNS resolution failed, fall back to HTTP
        }

        return null;
    }

    /**
     * Resolve handle via HTTP well-known endpoint.
     */
    private function resolveHandleViaHttp(string $handle): ?string
    {
        $url = "https://{$handle}/.well-known/atproto-did";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $did = trim($response);
        return $this->isValidDid($did) ? $did : null;
    }

    /**
     * Resolve a did:plc DID via PLC directory.
     */
    private function resolvePlcDid(string $did): array
    {
        $url = self::PLC_DIRECTORY . '/' . urlencode($did);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve DID from PLC directory: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document from PLC directory: $did");
        }

        return $document;
    }

    /**
     * Resolve a did:web DID.
     */
    private function resolveWebDid(string $did): array
    {
        // Extract domain from did:web:domain
        $domain = substr($did, 8); // Remove 'did:web:'
        $domain = str_replace(':', '/', $domain); // Handle path segments
        $domain = urldecode($domain);

        $url = "https://{$domain}/.well-known/did.json";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve did:web: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document for did:web: $did");
        }

        return $document;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/services/DidResolverTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/services/did_resolver.php tests/ext/phpbb/atproto/services/DidResolverTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add DID resolution service

- Resolve handles via DNS TXT or HTTP well-known
- Support did:plc (PLC directory) and did:web methods
- Extract PDS URL from DID documents
- Cache resolved DIDs for performance
- Validate handle and DID formats

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
