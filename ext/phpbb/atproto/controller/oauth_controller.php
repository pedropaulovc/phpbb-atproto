<?php

declare(strict_types=1);

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\token_manager_interface;
use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\request\request;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth controller for AT Protocol authentication flow.
 */
class oauth_controller
{
    private driver_interface $db;
    private helper $helper;
    private language $language;
    private oauth_client_interface $oauthClient;
    private request $request;
    private template $template;
    private token_manager_interface $tokenManager;
    private user $user;
    private string $tablePrefix;

    public function __construct(
        driver_interface $db,
        helper $helper,
        language $language,
        oauth_client_interface $oauthClient,
        request $request,
        template $template,
        token_manager_interface $tokenManager,
        user $user,
        string $tablePrefix
    ) {
        $this->db = $db;
        $this->helper = $helper;
        $this->language = $language;
        $this->oauthClient = $oauthClient;
        $this->request = $request;
        $this->template = $template;
        $this->tokenManager = $tokenManager;
        $this->user = $user;
        $this->tablePrefix = $tablePrefix;
    }

    /**
     * Start OAuth flow - display login form or redirect to authorization.
     *
     * @return Response
     */
    public function start(): Response
    {
        $this->language->add_lang('common', 'phpbb/atproto');

        $handle = $this->request->variable('handle', '', true);
        $error = '';

        if ($this->request->is_set_post('login') && !empty($handle)) {
            try {
                $state = bin2hex(random_bytes(16));
                $authResult = $this->oauthClient->getAuthorizationUrl($handle, $state);

                // Store OAuth state in database (keyed by state for retrieval in callback)
                $this->storeOAuthState($state, [
                    'code_verifier' => $authResult['code_verifier'],
                    'handle' => $handle,
                    'did' => $authResult['did'],
                    'pds_url' => $authResult['pds_url'],
                    'created' => time(),
                ]);

                return new RedirectResponse($authResult['url']);
            } catch (oauth_exception $e) {
                $error = $this->getErrorMessage($e->getCode());
            } catch (\Exception $e) {
                $error = $this->language->lang('ATPROTO_ERROR_UNKNOWN');
            }
        }

        $this->template->assign_vars([
            'U_ATPROTO_LOGIN' => $this->helper->route('phpbb_atproto_oauth_start'),
            'ATPROTO_LOGIN_ERROR' => $error,
            'S_ATPROTO_HANDLE' => $handle,
        ]);

        return $this->helper->render('atproto_login.html', $this->language->lang('ATPROTO_LOGIN'));
    }

    /**
     * OAuth callback - exchange code for tokens.
     *
     * @return Response
     */
    public function callback(): Response
    {
        $this->language->add_lang('common', 'phpbb/atproto');

        $code = $this->request->variable('code', '');
        $state = $this->request->variable('state', '');
        $error = $this->request->variable('error', '');

        // Handle authorization error
        if (!empty($error)) {
            return $this->showError(oauth_exception::CODE_OAUTH_DENIED);
        }

        // Retrieve and validate OAuth state from database
        if (empty($state)) {
            return $this->showError(oauth_exception::CODE_STATE_MISMATCH);
        }

        $stateData = $this->retrieveOAuthState($state);
        if ($stateData === null) {
            return $this->showError(oauth_exception::CODE_STATE_MISMATCH);
        }

        // Delete state after retrieval (one-time use)
        $this->deleteOAuthState($state);

        // Extract session data
        $codeVerifier = $stateData['code_verifier'] ?? '';
        $handle = $stateData['handle'] ?? '';
        $did = $stateData['did'] ?? '';
        $pdsUrl = $stateData['pds_url'] ?? '';

        if (empty($codeVerifier) || empty($pdsUrl)) {
            return $this->showError(oauth_exception::CODE_CONFIG_ERROR);
        }

        try {
            // Re-fetch OAuth metadata for the token exchange (new request = new instance)
            error_log("[ATPROTO] Fetching OAuth metadata for PDS: $pdsUrl");
            $metadata = $this->oauthClient->fetchOAuthMetadata($pdsUrl);
            $this->oauthClient->setOAuthMetadata($metadata);
            error_log("[ATPROTO] OAuth metadata fetched successfully");

            error_log("[ATPROTO] Exchanging code for tokens...");
            $tokens = $this->oauthClient->exchangeCode($code, $state, $codeVerifier);
            error_log("[ATPROTO] Token exchange successful, DID: " . ($tokens['did'] ?? 'unknown'));

            $tokenDid = $tokens['did'] ?? $did;

            // Check if DID is already linked to a user
            error_log("[ATPROTO] Looking up user by DID: $tokenDid");
            $existingUserId = $this->tokenManager->findUserByDid($tokenDid);
            error_log("[ATPROTO] Existing user ID: " . ($existingUserId ?? 'null'));

            if ($existingUserId !== null) {
                // User exists - store tokens and log them in
                error_log("[ATPROTO] Storing tokens for existing user $existingUserId");
                $this->tokenManager->storeTokens(
                    $existingUserId,
                    $tokenDid,
                    $handle,
                    '',
                    $tokens['access_token'],
                    $tokens['refresh_token'],
                    $tokens['expires_in']
                );

                // TODO: Create phpBB session for this user
                error_log("[ATPROTO] Login successful for user $existingUserId");
                return $this->showSuccess($handle);
            }

            // If user is logged in, link the account
            $currentUserId = $this->user->data['user_id'];
            error_log("[ATPROTO] Current user ID: $currentUserId (ANONYMOUS=" . ANONYMOUS . ")");

            if ($currentUserId != ANONYMOUS) {
                error_log("[ATPROTO] Linking AT Protocol account to logged-in user $currentUserId");
                $this->tokenManager->storeTokens(
                    (int) $currentUserId,
                    $tokenDid,
                    $handle,
                    '',
                    $tokens['access_token'],
                    $tokens['refresh_token'],
                    $tokens['expires_in']
                );

                return $this->showSuccess($handle);
            }

            // No existing user and not logged in - show account creation/linking options
            error_log("[ATPROTO] No existing user and not logged in - showing error");
            $this->template->assign_vars([
                'U_ATPROTO_LOGIN' => $this->helper->route('phpbb_atproto_oauth_start'),
                'ATPROTO_LOGIN_ERROR' => $this->language->lang('ATPROTO_ERROR_NO_ACCOUNT'),
            ]);

            return $this->helper->render('atproto_login.html', $this->language->lang('ATPROTO_LOGIN'));
        } catch (oauth_exception $e) {
            error_log("[ATPROTO] OAuth exception: " . $e->getMessage() . " (code: " . $e->getCode() . ")");
            return $this->showError($e->getCode());
        } catch (\Exception $e) {
            error_log("[ATPROTO] Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->showError(0);
        }
    }

    /**
     * Show error page.
     */
    private function showError(int $code): Response
    {
        $this->template->assign_vars([
            'U_ATPROTO_LOGIN' => $this->helper->route('phpbb_atproto_oauth_start'),
            'ATPROTO_LOGIN_ERROR' => $this->getErrorMessage($code),
        ]);

        return $this->helper->render('atproto_login.html', $this->language->lang('ATPROTO_LOGIN'));
    }

    /**
     * Show success page.
     */
    private function showSuccess(string $handle): Response
    {
        $this->template->assign_vars([
            'ATPROTO_SUCCESS_MESSAGE' => $this->language->lang('ATPROTO_LOGIN_SUCCESS'),
            'U_INDEX' => append_sid("{$this->helper->get_phpbb_root_path()}index.php"),
        ]);

        return $this->helper->render('atproto_success.html', $this->language->lang('ATPROTO_LOGIN_SUCCESS'));
    }

    /**
     * Get human-readable error message.
     */
    private function getErrorMessage(int $code): string
    {
        $messages = [
            oauth_exception::CODE_INVALID_HANDLE => 'ATPROTO_ERROR_INVALID_HANDLE',
            oauth_exception::CODE_DID_RESOLUTION_FAILED => 'ATPROTO_ERROR_DID_RESOLUTION',
            oauth_exception::CODE_OAUTH_DENIED => 'ATPROTO_ERROR_OAUTH_DENIED',
            oauth_exception::CODE_TOKEN_EXCHANGE_FAILED => 'ATPROTO_ERROR_TOKEN_EXCHANGE',
            oauth_exception::CODE_REFRESH_FAILED => 'ATPROTO_ERROR_REFRESH_FAILED',
            oauth_exception::CODE_CONFIG_ERROR => 'ATPROTO_ERROR_CONFIG',
            oauth_exception::CODE_METADATA_FETCH_FAILED => 'ATPROTO_ERROR_CONFIG',
            oauth_exception::CODE_STATE_MISMATCH => 'ATPROTO_ERROR_STATE_MISMATCH',
        ];

        $langKey = $messages[$code] ?? 'ATPROTO_ERROR_UNKNOWN';

        return $this->language->lang($langKey);
    }

    /**
     * Store OAuth state in database.
     *
     * @param string $state The state parameter
     * @param array  $data  Associated data (code_verifier, handle, did)
     */
    private function storeOAuthState(string $state, array $data): void
    {
        $table = $this->tablePrefix . 'atproto_config';
        $configName = 'oauth_state_' . $state;
        $configValue = json_encode($data);

        // Use phpBB's sql_build_array for proper escaping
        $sql_ary = [
            'config_name' => $configName,
            'config_value' => $configValue,
        ];

        $sql = "INSERT INTO $table " . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);
    }

    /**
     * Retrieve OAuth state from database.
     *
     * @param string $state The state parameter
     *
     * @return array|null The state data or null if not found/expired
     */
    private function retrieveOAuthState(string $state): ?array
    {
        $table = $this->tablePrefix . 'atproto_config';
        $configName = 'oauth_state_' . $state;

        $sql = "SELECT config_value FROM $table WHERE config_name = '" . $this->db->sql_escape($configName) . "'";
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row) {
            return null;
        }

        $data = json_decode($row['config_value'], true);
        if (!is_array($data)) {
            return null;
        }

        // Check if expired (10 minute window)
        if (isset($data['created']) && $data['created'] < time() - 600) {
            $this->deleteOAuthState($state);
            return null;
        }

        return $data;
    }

    /**
     * Delete OAuth state from database.
     *
     * @param string $state The state parameter
     */
    private function deleteOAuthState(string $state): void
    {
        $table = $this->tablePrefix . 'atproto_config';
        $configName = 'oauth_state_' . $state;

        $sql = "DELETE FROM $table WHERE config_name = '" . $this->db->sql_escape($configName) . "'";
        $this->db->sql_query($sql);
    }
}
