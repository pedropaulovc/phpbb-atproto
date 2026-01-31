<?php

declare(strict_types=1);
/**
 * AT Protocol Extension for phpBB
 * English Language File
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */
if (!defined('IN_PHPBB')) {
    exit;
}

if (empty($lang) || !is_array($lang)) {
    $lang = [];
}

$lang = array_merge($lang, [
    // Login form
    'ATPROTO_LOGIN' => 'Login with AT Protocol',
    'ATPROTO_LOGIN_HANDLE' => 'AT Protocol Handle',
    'ATPROTO_LOGIN_HANDLE_EXPLAIN' => 'Enter your handle (e.g., alice.bsky.social) or DID',
    'ATPROTO_LOGIN_BUTTON' => 'Login with AT Protocol',
    'ATPROTO_LOGIN_OR' => 'Or continue with traditional login',

    // Success messages
    'ATPROTO_LOGIN_SUCCESS' => 'Successfully logged in with AT Protocol',
    'ATPROTO_ACCOUNT_LINKED' => 'Your AT Protocol account has been linked',

    // Error messages
    'ATPROTO_ERROR_INVALID_HANDLE' => 'Invalid handle format',
    'ATPROTO_ERROR_DID_RESOLUTION' => 'Could not resolve your handle',
    'ATPROTO_ERROR_OAUTH_DENIED' => 'Authorization was denied',
    'ATPROTO_ERROR_TOKEN_EXCHANGE' => 'Failed to complete login',
    'ATPROTO_ERROR_STATE_MISMATCH' => 'Security validation failed - please try again',
    'ATPROTO_ERROR_CONFIG' => 'AT Protocol login is not properly configured',
    'ATPROTO_ERROR_UNKNOWN' => 'An unknown error occurred',
    'ATPROTO_ERROR_REFRESH_FAILED' => 'Your session has expired - please login again',
    'ATPROTO_ERROR_NO_ACCOUNT' => 'No account is linked to this AT Protocol identity. Please register or login first to link your account.',

    // Account linking
    'ATPROTO_LINK_ACCOUNT' => 'Link AT Protocol Account',
    'ATPROTO_LINK_EXPLAIN' => 'Connect your existing forum account with AT Protocol for decentralized login',
    'ATPROTO_UNLINK_ACCOUNT' => 'Unlink AT Protocol Account',
    'ATPROTO_UNLINK_CONFIRM' => 'Are you sure you want to unlink your AT Protocol account?',
    'ATPROTO_LINKED_AS' => 'Linked as: %s',

    // Status messages
    'ATPROTO_SYNC_PENDING' => 'Syncing to AT Protocol...',
    'ATPROTO_SYNC_FAILED' => 'Sync failed - will retry',
    'ATPROTO_SYNC_COMPLETE' => 'Synced to AT Protocol',

    // Profile
    'ATPROTO_DID' => 'AT Protocol DID',
    'ATPROTO_HANDLE' => 'AT Protocol Handle',
    'ATPROTO_PDS' => 'Personal Data Server',

    // Admin settings
    'ACP_ATPROTO_SETTINGS' => 'AT Protocol Settings',
    'ACP_ATPROTO_SETTINGS_EXPLAIN' => 'Configure AT Protocol integration for decentralized authentication and data storage',
    'ACP_ATPROTO_CLIENT_ID' => 'OAuth Client ID',
    'ACP_ATPROTO_CLIENT_ID_EXPLAIN' => 'URL to your client-metadata.json file',
    'ACP_ATPROTO_ENABLED' => 'Enable AT Protocol login',
    'ACP_ATPROTO_FORUM_DID' => 'Forum DID',
    'ACP_ATPROTO_FORUM_DID_EXPLAIN' => 'The DID for this forum\'s AT Protocol identity',
]);
