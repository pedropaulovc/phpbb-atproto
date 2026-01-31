<?php

declare(strict_types=1);

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\oauth_client_interface;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\token_manager_interface;
use phpbb\controller\helper;
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
    private helper $helper;
    private language $language;
    private oauth_client_interface $oauthClient;
    private request $request;
    private template $template;
    private token_manager_interface $tokenManager;
    private user $user;

    public function __construct(
        helper $helper,
        language $language,
        oauth_client_interface $oauthClient,
        request $request,
        template $template,
        token_manager_interface $tokenManager,
        user $user
    ) {
        $this->helper = $helper;
        $this->language = $language;
        $this->oauthClient = $oauthClient;
        $this->request = $request;
        $this->template = $template;
        $this->tokenManager = $tokenManager;
        $this->user = $user;
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

                // Store in session
                $this->user->data['atproto_oauth_state'] = $state;
                $this->user->data['atproto_code_verifier'] = $authResult['code_verifier'];
                $this->user->data['atproto_handle'] = $handle;
                $this->user->data['atproto_did'] = $authResult['did'];

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

        // Validate state parameter
        $expectedState = $this->user->data['atproto_oauth_state'] ?? '';
        if (empty($state) || empty($expectedState) || !hash_equals($expectedState, $state)) {
            return $this->showError(oauth_exception::CODE_STATE_MISMATCH);
        }

        // Retrieve session data
        $codeVerifier = $this->user->data['atproto_code_verifier'] ?? '';
        $handle = $this->user->data['atproto_handle'] ?? '';
        $did = $this->user->data['atproto_did'] ?? '';

        if (empty($codeVerifier)) {
            return $this->showError(oauth_exception::CODE_CONFIG_ERROR);
        }

        try {
            $tokens = $this->oauthClient->exchangeCode($code, $state, $codeVerifier);

            // Clear session vars
            unset(
                $this->user->data['atproto_oauth_state'],
                $this->user->data['atproto_code_verifier'],
                $this->user->data['atproto_handle'],
                $this->user->data['atproto_did']
            );

            $tokenDid = $tokens['did'] ?? $did;

            // Check if DID is already linked to a user
            $existingUserId = $this->tokenManager->findUserByDid($tokenDid);

            if ($existingUserId !== null) {
                // User exists - store tokens and log them in
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
                return $this->showSuccess($handle);
            }

            // If user is logged in, link the account
            if ($this->user->data['user_id'] != ANONYMOUS) {
                $this->tokenManager->storeTokens(
                    (int) $this->user->data['user_id'],
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
            // For now, just show an error that account needs to be created first
            $this->template->assign_vars([
                'ATPROTO_ERROR' => $this->language->lang('ATPROTO_ERROR_NO_ACCOUNT'),
            ]);

            return $this->helper->render('atproto_login.html', $this->language->lang('ATPROTO_LOGIN'));
        } catch (oauth_exception $e) {
            return $this->showError($e->getCode());
        } catch (\Exception $e) {
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
}
