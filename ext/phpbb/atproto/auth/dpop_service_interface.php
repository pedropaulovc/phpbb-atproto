<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * Interface for DPoP (Demonstration of Proof-of-Possession) service.
 *
 * DPoP is required by AT Protocol OAuth for binding tokens to the client.
 * All token requests must include a DPoP proof header.
 */
interface dpop_service_interface
{
    /**
     * Get the ES256 keypair for DPoP proofs.
     *
     * @return array{private: string, public: string, jwk: array}
     */
    public function getKeypair(): array;

    /**
     * Create a DPoP proof JWT for a request.
     *
     * @param string      $method      HTTP method (GET, POST, etc.)
     * @param string      $url         Full URL of the request
     * @param string|null $accessToken Access token (include for resource requests)
     *
     * @return string The DPoP proof JWT
     */
    public function createProof(string $method, string $url, ?string $accessToken = null): string;

    /**
     * Get the JWK thumbprint of the public key.
     *
     * Used as the 'jkt' claim to bind tokens to this key.
     *
     * @return string Base64url-encoded SHA-256 thumbprint
     */
    public function getJwkThumbprint(): string;

    /**
     * Get the public key in JWK format.
     *
     * @return array The JWK
     */
    public function getPublicJwk(): array;
}
