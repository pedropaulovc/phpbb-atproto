<?php

declare(strict_types=1);

namespace phpbb\atproto\auth;

/**
 * Exception class for OAuth-related errors.
 *
 * Provides specific error codes for different types of OAuth failures
 * during the AT Protocol authentication process.
 */
class oauth_exception extends \Exception
{
    /** @var int Handle format is invalid */
    public const CODE_INVALID_HANDLE = 1;

    /** @var int DID resolution failed */
    public const CODE_DID_RESOLUTION_FAILED = 2;

    /** @var int OAuth authorization was denied */
    public const CODE_OAUTH_DENIED = 3;

    /** @var int Token exchange failed */
    public const CODE_TOKEN_EXCHANGE_FAILED = 4;

    /** @var int Token refresh failed */
    public const CODE_REFRESH_FAILED = 5;

    /** @var int Configuration error */
    public const CODE_CONFIG_ERROR = 6;

    /** @var int OAuth metadata fetch failed */
    public const CODE_METADATA_FETCH_FAILED = 7;

    /** @var int State parameter mismatch */
    public const CODE_STATE_MISMATCH = 8;

    /**
     * Constructor.
     *
     * @param string          $message  The exception message
     * @param int             $code     One of the CODE_* constants
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
