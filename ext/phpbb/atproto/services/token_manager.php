<?php

declare(strict_types=1);

namespace phpbb\atproto\services;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\token_encryption;
use phpbb\atproto\exceptions\token_not_found_exception;
use phpbb\atproto\exceptions\token_refresh_failed_exception;
use phpbb\db\driver\driver_interface;

/**
 * Token manager for AT Protocol OAuth tokens.
 *
 * Handles token storage, retrieval, and automatic refresh.
 * Uses row-level locking to prevent race conditions during refresh.
 *
 * Tokens are encrypted at rest using XChaCha20-Poly1305 via the
 * token_encryption service to protect against database compromise.
 */
class token_manager implements token_manager_interface
{
    /** @var driver_interface Database connection */
    private driver_interface $db;

    /** @var token_encryption Encryption service for tokens */
    private token_encryption $encryption;

    /** @var oauth_client_interface OAuth client for token refresh */
    private oauth_client_interface $oauthClient;

    /** @var string Database table prefix */
    private string $tablePrefix;

    /** @var int Seconds before expiry to trigger refresh */
    private int $refreshBuffer;

    /**
     * Constructor.
     *
     * @param driver_interface       $db            Database connection
     * @param token_encryption       $encryption    Token encryption service
     * @param oauth_client_interface $oauthClient   OAuth client for refresh
     * @param string                 $tablePrefix   Database table prefix
     * @param int                    $refreshBuffer Seconds before expiry to refresh (default: 300)
     */
    public function __construct(
        driver_interface $db,
        token_encryption $encryption,
        oauth_client_interface $oauthClient,
        string $tablePrefix,
        int $refreshBuffer = 300
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->oauthClient = $oauthClient;
        $this->tablePrefix = $tablePrefix;
        $this->refreshBuffer = $refreshBuffer;
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(int $userId): string
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            throw new token_not_found_exception($userId);
        }

        // Check if token needs refresh (within buffer period)
        $expiresAt = (int) $row['token_expires_at'];
        if ($expiresAt <= time() + $this->refreshBuffer) {
            return $this->refreshToken($userId);
        }

        // Decrypt and return access token
        return $this->encryption->decrypt($row['access_token']);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken(int $userId): string
    {
        // Start transaction for row-level locking
        $this->db->sql_transaction('begin');

        try {
            // Lock the row with FOR UPDATE
            $sql = 'SELECT access_token, refresh_token, token_expires_at, pds_url
                    FROM ' . $this->tablePrefix . 'atproto_users
                    WHERE user_id = ' . (int) $userId . '
                    FOR UPDATE';
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if ($row === false) {
                $this->db->sql_transaction('rollback');

                throw new token_not_found_exception($userId);
            }

            // Double-check: maybe another request already refreshed
            // Use a smaller buffer (60s) to avoid unnecessary refresh
            $expiresAt = (int) $row['token_expires_at'];
            if ($expiresAt > time() + 60) {
                // Token was refreshed by another request, use it
                $this->db->sql_transaction('commit');

                return $this->encryption->decrypt($row['access_token']);
            }

            // Perform the actual token refresh
            $refreshToken = $this->encryption->decrypt($row['refresh_token']);
            $pdsUrl = $row['pds_url'];

            try {
                $tokens = $this->oauthClient->refreshAccessToken($refreshToken, $pdsUrl);
            } catch (\Exception $e) {
                $this->db->sql_transaction('rollback');

                throw new token_refresh_failed_exception($e->getMessage(), $e);
            }

            // Store new tokens
            $newExpiresAt = time() + $tokens['expires_in'];
            $encryptedAccess = $this->encryption->encrypt($tokens['access_token']);
            $encryptedRefresh = $this->encryption->encrypt($tokens['refresh_token']);

            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET access_token = \'' . $this->db->sql_escape($encryptedAccess) . '\',
                        refresh_token = \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                        token_expires_at = ' . $newExpiresAt . ',
                        updated_at = ' . time() . '
                    WHERE user_id = ' . (int) $userId;
            $this->db->sql_query($sql);

            $this->db->sql_transaction('commit');

            return $tokens['access_token'];
        } catch (token_not_found_exception|token_refresh_failed_exception $e) {
            // Already handled, re-throw
            throw $e;
        } catch (\Exception $e) {
            $this->db->sql_transaction('rollback');

            throw new token_refresh_failed_exception($e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeTokens(
        int $userId,
        string $did,
        string $handle,
        string $pdsUrl,
        string $accessToken,
        string $refreshToken,
        int $expiresIn
    ): void {
        $now = time();
        $expiresAt = $now + $expiresIn;

        $encryptedAccess = $this->encryption->encrypt($accessToken);
        $encryptedRefresh = $this->encryption->encrypt($refreshToken);

        // Check if user already has a row
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result) !== false;
        $this->db->sql_freeresult($result);

        if ($exists) {
            // Update existing row
            $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                    SET did = \'' . $this->db->sql_escape($did) . '\',
                        handle = \'' . $this->db->sql_escape($handle) . '\',
                        pds_url = \'' . $this->db->sql_escape($pdsUrl) . '\',
                        access_token = \'' . $this->db->sql_escape($encryptedAccess) . '\',
                        refresh_token = \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                        token_expires_at = ' . $expiresAt . ',
                        updated_at = ' . $now . '
                    WHERE user_id = ' . (int) $userId;
        } else {
            // Insert new row
            $sql = 'INSERT INTO ' . $this->tablePrefix . 'atproto_users
                    (user_id, did, handle, pds_url, access_token, refresh_token, token_expires_at, migration_status, created_at, updated_at)
                    VALUES (' . (int) $userId . ',
                            \'' . $this->db->sql_escape($did) . '\',
                            \'' . $this->db->sql_escape($handle) . '\',
                            \'' . $this->db->sql_escape($pdsUrl) . '\',
                            \'' . $this->db->sql_escape($encryptedAccess) . '\',
                            \'' . $this->db->sql_escape($encryptedRefresh) . '\',
                            ' . $expiresAt . ',
                            \'none\',
                            ' . $now . ',
                            ' . $now . ')';
        }

        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenValid(int $userId): bool
    {
        $row = $this->getTokenRow($userId);

        if ($row === null) {
            return false;
        }

        // Token is valid if it exists and hasn't expired
        return $row['access_token'] !== null
            && (int) $row['token_expires_at'] > time();
    }

    /**
     * {@inheritdoc}
     */
    public function clearTokens(int $userId): void
    {
        $sql = 'UPDATE ' . $this->tablePrefix . 'atproto_users
                SET access_token = NULL,
                    refresh_token = NULL,
                    token_expires_at = NULL,
                    updated_at = ' . time() . '
                WHERE user_id = ' . (int) $userId;
        $this->db->sql_query($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserDid(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);

        return $row !== null ? $row['did'] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserPdsUrl(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);

        return $row !== null ? $row['pds_url'] : null;
    }

    /**
     * Find phpBB user ID by DID.
     *
     * @param string $did The AT Protocol DID to search for
     *
     * @return int|null The phpBB user ID or null if not found
     */
    public function findUserByDid(string $did): ?int
    {
        $sql = 'SELECT user_id FROM ' . $this->tablePrefix . 'atproto_users
                WHERE did = \'' . $this->db->sql_escape($did) . '\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row !== false ? (int) $row['user_id'] : null;
    }

    /**
     * Get the handle associated with a user's tokens.
     *
     * @param int $userId phpBB user ID
     *
     * @return string|null User's handle or null if not linked
     */
    public function getUserHandle(int $userId): ?string
    {
        $row = $this->getTokenRow($userId);

        return $row !== null ? $row['handle'] : null;
    }

    /**
     * Get token row for a user from the database.
     *
     * @param int $userId phpBB user ID
     *
     * @return array|null Token row data or null if not found
     */
    private function getTokenRow(int $userId): ?array
    {
        $sql = 'SELECT did, handle, pds_url, access_token, refresh_token, token_expires_at
                FROM ' . $this->tablePrefix . 'atproto_users
                WHERE user_id = ' . (int) $userId;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row !== false ? $row : null;
    }
}
