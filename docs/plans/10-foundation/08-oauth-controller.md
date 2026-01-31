# Task 8: OAuth Controller

**Files:**
- Create: `ext/phpbb/atproto/controller/oauth_controller.php`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/controller/OAuthControllerTest.php
<?php

namespace phpbb\atproto\tests\controller;

class OAuthControllerTest extends \phpbb_test_case
{
    public function test_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\controller\oauth_controller'));
    }

    public function test_has_start_method()
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'start'));
    }

    public function test_has_callback_method()
    {
        $this->assertTrue(method_exists('\phpbb\atproto\controller\oauth_controller', 'callback'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/controller/OAuthControllerTest.php`
Expected: FAIL with "Class '\phpbb\atproto\controller\oauth_controller' not found"

**Step 3: Create oauth_controller.php**

```php
<?php

namespace phpbb\atproto\controller;

use phpbb\atproto\auth\oauth_client;
use phpbb\atproto\auth\oauth_exception;
use phpbb\atproto\services\token_manager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth controller for AT Protocol authentication flow.
 */
class oauth_controller
{
    private oauth_client $oauthClient;
    private token_manager $tokenManager;
    private \phpbb\user $user;
    private \phpbb\auth\auth $auth;
    private \phpbb\request\request_interface $request;
    private \phpbb\template\template $template;
    private \phpbb\config\config $config;
    private string $phpbbRootPath;
    private string $phpEx;

    public function __construct(
        oauth_client $oauthClient,
        token_manager $tokenManager,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\request\request_interface $request,
        \phpbb\template\template $template,
        \phpbb\config\config $config,
        string $phpbbRootPath,
        string $phpEx
    ) {
        $this->oauthClient = $oauthClient;
        $this->tokenManager = $tokenManager;
        $this->user = $user;
        $this->auth = $auth;
        $this->request = $request;
        $this->template = $template;
        $this->config = $config;
        $this->phpbbRootPath = $phpbbRootPath;
        $this->phpEx = $phpEx;
    }

    /**
     * Start OAuth flow - user enters handle.
     */
    public function start(): Response
    {
        $handle = $this->request->variable('handle', '');

        if (empty($handle)) {
            // Show login form
            $this->template->assign_vars([
                'ATPROTO_LOGIN_ERROR' => '',
                'S_ATPROTO_LOGIN' => true,
            ]);

            return $this->renderPage('atproto_login');
        }

        try {
            // Generate state for CSRF protection
            $state = bin2hex(random_bytes(16));
            $codeVerifier = bin2hex(random_bytes(32));

            // Store in session
            $this->user->session_setvar('atproto_oauth_state', $state);
            $this->user->session_setvar('atproto_code_verifier', $codeVerifier);
            $this->user->session_setvar('atproto_handle', $handle);

            // Get authorization URL
            $authUrl = $this->oauthClient->getAuthorizationUrl($handle, $state);

            // Store PDS URL for callback
            $this->user->session_setvar('atproto_pds_url', $this->oauthClient->getCurrentPdsUrl());

            return new RedirectResponse($authUrl);

        } catch (oauth_exception $e) {
            $this->template->assign_vars([
                'ATPROTO_LOGIN_ERROR' => $this->getErrorMessage($e->getErrorCode()),
                'S_ATPROTO_LOGIN' => true,
            ]);

            return $this->renderPage('atproto_login');
        }
    }

    /**
     * OAuth callback - exchange code for tokens.
     */
    public function callback(): Response
    {
        $code = $this->request->variable('code', '');
        $state = $this->request->variable('state', '');
        $error = $this->request->variable('error', '');

        // Check for OAuth error
        if (!empty($error)) {
            return $this->handleError(
                oauth_exception::CODE_OAUTH_DENIED,
                $this->request->variable('error_description', 'Authorization denied')
            );
        }

        // Validate state
        $expectedState = $this->user->session_getvar('atproto_oauth_state');
        if (empty($state) || $state !== $expectedState) {
            return $this->handleError(
                oauth_exception::CODE_STATE_MISMATCH,
                'Invalid state parameter'
            );
        }

        // Get stored values
        $codeVerifier = $this->user->session_getvar('atproto_code_verifier');
        $pdsUrl = $this->user->session_getvar('atproto_pds_url');
        $handle = $this->user->session_getvar('atproto_handle');

        if (empty($codeVerifier) || empty($pdsUrl)) {
            return $this->handleError(
                oauth_exception::CODE_CONFIG_ERROR,
                'Session data missing - please try again'
            );
        }

        try {
            // Set PDS URL for token exchange
            $this->oauthClient->setCurrentPdsUrl($pdsUrl);

            // Exchange code for tokens
            $tokens = $this->oauthClient->exchangeCode($code, $state, $codeVerifier);

            // Clear OAuth session data
            $this->user->session_setvar('atproto_oauth_state', null);
            $this->user->session_setvar('atproto_code_verifier', null);
            $this->user->session_setvar('atproto_pds_url', null);
            $this->user->session_setvar('atproto_handle', null);

            // Find or create phpBB user
            $userId = $this->findOrCreateUser($tokens['did'], $handle);

            // Store tokens
            $this->tokenManager->storeTokens(
                $userId,
                $tokens['did'],
                $handle,
                $pdsUrl,
                $tokens['access_token'],
                $tokens['refresh_token'],
                $tokens['expires_in']
            );

            // Create phpBB session
            $this->auth->login($handle, '', false, true, true);

            // Redirect to forum index
            return new RedirectResponse(
                append_sid($this->phpbbRootPath . 'index.' . $this->phpEx)
            );

        } catch (oauth_exception $e) {
            return $this->handleError($e->getErrorCode(), $e->getMessage());
        }
    }

    /**
     * Find existing phpBB user by DID or create new one.
     */
    private function findOrCreateUser(string $did, string $handle): int
    {
        // Check if user already linked
        $existingUserId = $this->tokenManager->findUserByDid($did);
        if ($existingUserId !== null) {
            return $existingUserId;
        }

        // Check if user exists by handle (as username)
        $cleanHandle = $this->sanitizeUsername($handle);
        $sql = 'SELECT user_id FROM ' . USERS_TABLE . '
                WHERE username_clean = \'' . $this->db->sql_escape(utf8_clean_string($cleanHandle)) . '\'';
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row) {
            return (int) $row['user_id'];
        }

        // Create new user
        return $this->createUser($cleanHandle, $did);
    }

    /**
     * Create a new phpBB user.
     */
    private function createUser(string $username, string $did): int
    {
        if (!function_exists('user_add')) {
            include($this->phpbbRootPath . 'includes/functions_user.' . $this->phpEx);
        }

        $userData = [
            'username' => $username,
            'user_password' => '', // No password - OAuth only
            'user_email' => '', // Will be fetched from profile later
            'group_id' => (int) $this->config['default_usergroup'],
            'user_type' => USER_NORMAL,
            'user_regdate' => time(),
        ];

        $userId = user_add($userData);

        return $userId;
    }

    /**
     * Sanitize AT Protocol handle for use as phpBB username.
     */
    private function sanitizeUsername(string $handle): string
    {
        // Remove TLD (e.g., .bsky.social) to make shorter username
        $parts = explode('.', $handle);
        if (count($parts) > 2) {
            return $parts[0];
        }

        // Fallback: replace dots with underscores
        return str_replace('.', '_', $handle);
    }

    /**
     * Handle OAuth error.
     */
    private function handleError(string $code, string $message): Response
    {
        $this->template->assign_vars([
            'ATPROTO_LOGIN_ERROR' => $this->getErrorMessage($code) . ': ' . $message,
            'S_ATPROTO_LOGIN' => true,
        ]);

        return $this->renderPage('atproto_login');
    }

    /**
     * Get user-friendly error message.
     */
    private function getErrorMessage(string $code): string
    {
        $messages = [
            oauth_exception::CODE_INVALID_HANDLE => $this->user->lang('ATPROTO_ERROR_INVALID_HANDLE'),
            oauth_exception::CODE_DID_RESOLUTION_FAILED => $this->user->lang('ATPROTO_ERROR_DID_RESOLUTION'),
            oauth_exception::CODE_OAUTH_DENIED => $this->user->lang('ATPROTO_ERROR_OAUTH_DENIED'),
            oauth_exception::CODE_TOKEN_EXCHANGE_FAILED => $this->user->lang('ATPROTO_ERROR_TOKEN_EXCHANGE'),
            oauth_exception::CODE_STATE_MISMATCH => $this->user->lang('ATPROTO_ERROR_STATE_MISMATCH'),
            oauth_exception::CODE_CONFIG_ERROR => $this->user->lang('ATPROTO_ERROR_CONFIG'),
        ];

        return $messages[$code] ?? $this->user->lang('ATPROTO_ERROR_UNKNOWN');
    }

    /**
     * Render a template page.
     */
    private function renderPage(string $template): Response
    {
        page_header($this->user->lang('LOGIN'));

        $this->template->set_filenames([
            'body' => '@phpbb_atproto/' . $template . '.html',
        ]);

        page_footer();

        // phpBB's page_footer() calls exit, so we won't reach here
        // This is for type safety
        return new Response();
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/controller/OAuthControllerTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add ext/phpbb/atproto/controller/oauth_controller.php tests/ext/phpbb/atproto/controller/OAuthControllerTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add OAuth controller for login flow

- Start endpoint: handle input, generate state, redirect to PDS
- Callback endpoint: validate state, exchange code, create session
- Find or create phpBB user by DID
- User-friendly error messages
- CSRF protection via state parameter

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
