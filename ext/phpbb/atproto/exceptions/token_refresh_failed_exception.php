<?php

declare(strict_types=1);

namespace phpbb\atproto\exceptions;

/**
 * Exception thrown when token refresh fails.
 *
 * This can occur when:
 * - Refresh token has expired
 * - Refresh token has been revoked
 * - PDS is unreachable
 * - OAuth server returns an error
 */
class token_refresh_failed_exception extends \Exception
{
    /**
     * Constructor.
     *
     * @param string          $message  Description of what went wrong
     * @param \Throwable|null $previous The previous exception (if any)
     */
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct("Token refresh failed: $message", 0, $previous);
    }
}
