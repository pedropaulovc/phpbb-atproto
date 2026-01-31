<?php

declare(strict_types=1);

namespace phpbb\atproto\services;

use phpbb\cache\driver\driver_interface;

/**
 * DID Resolver for AT Protocol.
 *
 * Resolves handles to DIDs and fetches DID documents.
 * Supports did:plc (via plc.directory) and did:web methods.
 */
class did_resolver
{
    private const PLC_DIRECTORY_URL = 'https://plc.directory';
    private const CACHE_PREFIX_HANDLE = 'atproto_handle_';
    private const CACHE_PREFIX_DID = 'atproto_did_';

    private driver_interface $cache;
    private int $cache_ttl;

    public function __construct(driver_interface $cache, int $cache_ttl = 3600)
    {
        $this->cache = $cache;
        $this->cache_ttl = $cache_ttl;
    }

    /**
     * Resolve a handle to a DID.
     *
     * Tries DNS TXT record first (_atproto.handle), then HTTP well-known endpoint.
     *
     * @param string $handle The handle to resolve (e.g., alice.bsky.social)
     *
     * @throws \InvalidArgumentException If handle format is invalid
     * @throws \RuntimeException         If resolution fails
     *
     * @return string The resolved DID
     */
    public function resolveHandle(string $handle): string
    {
        if (!$this->isValidHandle($handle)) {
            throw new \InvalidArgumentException("Invalid handle format: $handle");
        }

        // Check cache first
        $cacheKey = self::CACHE_PREFIX_HANDLE . $handle;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // Try DNS TXT record first
        $did = $this->resolveHandleViaDns($handle);

        // Fall back to HTTP well-known endpoint
        if ($did === null) {
            $did = $this->resolveHandleViaHttp($handle);
        }

        if ($did === null) {
            throw new \RuntimeException("Failed to resolve handle: $handle");
        }

        // Cache the result
        $this->cache->put($cacheKey, $did, $this->cache_ttl);

        return $did;
    }

    /**
     * Resolve a DID to its document.
     *
     * @param string $did The DID to resolve
     *
     * @throws \InvalidArgumentException If DID format is invalid
     * @throws \RuntimeException         If resolution fails
     *
     * @return array The DID document
     */
    public function resolveDid(string $did): array
    {
        if (!$this->isValidDid($did)) {
            throw new \InvalidArgumentException("Invalid DID format: $did");
        }

        // Check cache first
        $cacheKey = self::CACHE_PREFIX_DID . $did;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // Determine resolution method based on DID type
        $document = match (true) {
            str_starts_with($did, 'did:plc:') => $this->resolveDidPlc($did),
            str_starts_with($did, 'did:web:') => $this->resolveDidWeb($did),
            default => throw new \RuntimeException("Unsupported DID method: $did")
        };

        // Cache the result
        $this->cache->put($cacheKey, $document, $this->cache_ttl);

        return $document;
    }

    /**
     * Get the PDS URL for a DID.
     *
     * @param string $did The DID to look up
     *
     * @throws \RuntimeException If no PDS endpoint is found
     *
     * @return string The PDS URL
     */
    public function getPdsUrl(string $did): string
    {
        $document = $this->resolveDid($did);
        $pdsUrl = $this->extractPdsUrl($document);

        if ($pdsUrl === null) {
            throw new \RuntimeException("No PDS endpoint found for DID: $did");
        }

        return $pdsUrl;
    }

    /**
     * Validate a handle format.
     *
     * Valid handles are domain-like strings with at least two segments.
     *
     * @param string $handle The handle to validate
     *
     * @return bool True if valid
     */
    public function isValidHandle(string $handle): bool
    {
        if (empty($handle)) {
            return false;
        }

        // Handle must be a valid domain-like string
        // Must have at least two parts separated by dots
        // Each part must be alphanumeric with hyphens allowed (but not at start/end)
        // No spaces or special characters except dots and hyphens
        if (preg_match('/\s/', $handle)) {
            return false;
        }

        $parts = explode('.', $handle);
        if (count($parts) < 2) {
            return false;
        }

        foreach ($parts as $part) {
            if (empty($part)) {
                return false;
            }
            // Single character segments must be alphanumeric
            if (strlen($part) === 1) {
                if (!preg_match('/^[a-zA-Z0-9]$/', $part)) {
                    return false;
                }
                continue;
            }
            // Multi-character segments must match DNS label rules
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a DID format.
     *
     * @param string $did The DID to validate
     *
     * @return bool True if valid
     */
    public function isValidDid(string $did): bool
    {
        if (empty($did)) {
            return false;
        }

        // DID format: did:<method>:<method-specific-identifier>
        if (!preg_match('/^did:[a-z]+:.+$/', $did)) {
            return false;
        }

        return true;
    }

    /**
     * Extract the PDS URL from a DID document.
     *
     * @param array $document The DID document
     *
     * @return string|null The PDS URL or null if not found
     */
    public function extractPdsUrl(array $document): ?string
    {
        if (!isset($document['service']) || !is_array($document['service'])) {
            return null;
        }

        foreach ($document['service'] as $service) {
            if (!is_array($service)) {
                continue;
            }

            // Look for AtprotoPersonalDataServer service
            $id = $service['id'] ?? '';
            $type = $service['type'] ?? '';

            if (($id === '#atproto_pds' || str_ends_with($id, '#atproto_pds')) &&
                $type === 'AtprotoPersonalDataServer' &&
                isset($service['serviceEndpoint'])) {
                return $service['serviceEndpoint'];
            }
        }

        return null;
    }

    /**
     * Resolve handle via DNS TXT record.
     *
     * @param string $handle The handle to resolve
     *
     * @return string|null The DID or null if not found
     */
    private function resolveHandleViaDns(string $handle): ?string
    {
        $dnsName = "_atproto.$handle";

        // Get TXT records
        $records = @dns_get_record($dnsName, DNS_TXT);
        if ($records === false || empty($records)) {
            return null;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if (str_starts_with($txt, 'did=')) {
                $did = substr($txt, 4);
                if ($this->isValidDid($did)) {
                    return $did;
                }
            }
        }

        return null;
    }

    /**
     * Resolve handle via HTTP well-known endpoint.
     *
     * @param string $handle The handle to resolve
     *
     * @return string|null The DID or null if not found
     */
    private function resolveHandleViaHttp(string $handle): ?string
    {
        $url = "https://$handle/.well-known/atproto-did";

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
        if ($this->isValidDid($did)) {
            return $did;
        }

        return null;
    }

    /**
     * Resolve a did:plc DID via plc.directory.
     *
     * @param string $did The DID to resolve
     *
     * @throws \RuntimeException If resolution fails
     *
     * @return array The DID document
     */
    private function resolveDidPlc(string $did): array
    {
        $url = self::PLC_DIRECTORY_URL . '/' . $did;

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve DID: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document for: $did");
        }

        return $document;
    }

    /**
     * Resolve a did:web DID.
     *
     * @param string $did The DID to resolve
     *
     * @throws \RuntimeException If resolution fails
     *
     * @return array The DID document
     */
    private function resolveDidWeb(string $did): array
    {
        // Extract domain from did:web:<domain>
        $domain = substr($did, 8); // Remove 'did:web:'

        // URL-decode the domain (colons become slashes for paths)
        $domain = str_replace(':', '/', $domain);
        $domain = rawurldecode($domain);

        // Construct the URL
        if (str_contains($domain, '/')) {
            $url = "https://$domain/did.json";
        } else {
            $url = "https://$domain/.well-known/did.json";
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new \RuntimeException("Failed to resolve DID: $did");
        }

        $document = json_decode($response, true);
        if (!is_array($document)) {
            throw new \RuntimeException("Invalid DID document for: $did");
        }

        return $document;
    }
}
