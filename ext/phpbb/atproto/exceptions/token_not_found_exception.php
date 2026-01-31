<?php

declare(strict_types=1);

namespace phpbb\atproto\exceptions;

/**
 * Exception thrown when no AT Protocol tokens are found for a user.
 *
 * This typically occurs when:
 * - User has not linked their AT Protocol account
 * - User's tokens have been cleared (logged out)
 * - User ID does not exist in the system
 */
class token_not_found_exception extends \Exception
{
    private int $userId;

    /**
     * Constructor.
     *
     * @param int $userId The phpBB user ID for which tokens were not found
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
        parent::__construct("No AT Protocol tokens found for user ID: $userId");
    }

    /**
     * Get the user ID that was not found.
     *
     * @return int The phpBB user ID
     */
    public function getUserId(): int
    {
        return $this->userId;
    }
}
