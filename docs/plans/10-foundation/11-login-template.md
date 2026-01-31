# Task 11: Login Template

**Files:**
- Create: `ext/phpbb/atproto/styles/prosilver/template/atproto_login.html`
- Create: `ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/styles/TemplateTest.php
<?php

namespace phpbb\atproto\tests\styles;

class TemplateTest extends \phpbb_test_case
{
    public function test_login_template_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $this->assertFileExists($path);
    }

    public function test_nav_event_template_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html';
        $this->assertFileExists($path);
    }

    public function test_login_template_has_form()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/styles/prosilver/template/atproto_login.html';
        $content = file_get_contents($path);

        $this->assertStringContainsString('<form', $content);
        $this->assertStringContainsString('handle', $content);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/styles/TemplateTest.php`
Expected: FAIL with "Failed asserting that file exists"

**Step 3: Create atproto_login.html**

```html
{% INCLUDE 'overall_header.html' %}

<div class="panel">
    <div class="inner">
        <h2 class="panel-title">{L_ATPROTO_LOGIN}</h2>

        {% if ATPROTO_LOGIN_ERROR %}
        <div class="error">
            {ATPROTO_LOGIN_ERROR}
        </div>
        {% endif %}

        <form method="post" action="{U_ATPROTO_LOGIN}" class="atproto-login-form">
            <fieldset>
                <dl>
                    <dt>
                        <label for="atproto_handle">{L_ATPROTO_LOGIN_HANDLE}:</label>
                        <br><span class="explain">{L_ATPROTO_LOGIN_HANDLE_EXPLAIN}</span>
                    </dt>
                    <dd>
                        <input type="text"
                               name="handle"
                               id="atproto_handle"
                               class="inputbox autowidth"
                               placeholder="alice.bsky.social"
                               autocomplete="username"
                               required>
                    </dd>
                </dl>
            </fieldset>

            <fieldset class="submit-buttons">
                <input type="submit"
                       name="login"
                       value="{L_ATPROTO_LOGIN_BUTTON}"
                       class="button1">
            </fieldset>
        </form>

        <hr>

        <p class="atproto-alt-login">
            {L_ATPROTO_LOGIN_OR}
            <a href="{U_LOGIN}">{L_LOGIN}</a>
        </p>
    </div>
</div>

{% INCLUDE 'overall_footer.html' %}
```

**Step 4: Create overall_header_navigation_prepend.html**

```html
{% if S_USER_LOGGED_IN and ATPROTO_DID %}
<li class="atproto-status" title="{L_ATPROTO_LINKED_AS}: {ATPROTO_HANDLE}">
    <span class="atproto-icon">🔗</span>
</li>
{% endif %}

{% if not S_USER_LOGGED_IN %}
<li class="atproto-login-link">
    <a href="{U_ATPROTO_LOGIN}" title="{L_ATPROTO_LOGIN}">
        {L_ATPROTO_LOGIN}
    </a>
</li>
{% endif %}
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/styles/TemplateTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/styles/prosilver/template/atproto_login.html ext/phpbb/atproto/styles/prosilver/template/event/overall_header_navigation_prepend.html tests/ext/phpbb/atproto/styles/TemplateTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add login templates for prosilver theme

- AT Protocol login form with handle input
- Navigation bar integration (login link, linked status)
- Error message display
- Fallback to traditional login

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
