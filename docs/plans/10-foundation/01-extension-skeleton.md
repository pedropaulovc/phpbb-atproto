# Task 1: Extension Skeleton Files

**Files:**
- Create: `ext/phpbb/atproto/composer.json`
- Create: `ext/phpbb/atproto/ext.php`

**Step 1: Write the failing test**

Create a simple test that checks the extension can be loaded.

```php
// tests/ext/phpbb/atproto/ExtensionTest.php
<?php

namespace phpbb\atproto\tests;

class ExtensionTest extends \phpbb_test_case
{
    public function test_extension_class_exists()
    {
        $this->assertTrue(class_exists('\phpbb\atproto\ext'));
    }

    public function test_extension_is_enableable()
    {
        $ext = new \phpbb\atproto\ext();
        $this->assertTrue($ext->is_enableable());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ExtensionTest.php`
Expected: FAIL with "Class '\phpbb\atproto\ext' not found"

**Step 3: Create composer.json**

```json
{
    "name": "phpbb/atproto",
    "type": "phpbb-extension",
    "description": "AT Protocol integration for phpBB - DID authentication and decentralized data",
    "homepage": "https://github.com/pedropaulovc/phpbb-atproto",
    "license": "GPL-2.0-only",
    "authors": [
        {
            "name": "Pedro Carvalho",
            "email": "pedro@vza.net"
        }
    ],
    "require": {
        "php": ">=8.4"
    },
    "extra": {
        "display-name": "AT Protocol Integration",
        "soft-require": {
            "phpbb/phpbb": ">=3.3.0,<4.0"
        },
        "version-check": {
            "host": "github.com",
            "directory": "/pedropaulovc/phpbb-atproto",
            "filename": "version.json"
        }
    }
}
```

**Step 4: Create ext.php**

```php
<?php

namespace phpbb\atproto;

class ext extends \phpbb\extension\base
{
    /**
     * Check if extension is enableable.
     * Requires sodium extension for token encryption.
     */
    public function is_enableable()
    {
        return extension_loaded('sodium') && PHP_VERSION_ID >= 80400;
    }

    /**
     * Enable step - run migrations.
     */
    public function enable_step($old_state)
    {
        return parent::enable_step($old_state);
    }

    /**
     * Disable step.
     */
    public function disable_step($old_state)
    {
        return parent::disable_step($old_state);
    }

    /**
     * Purge step - clean up data.
     */
    public function purge_step($old_state)
    {
        return parent::purge_step($old_state);
    }
}
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ExtensionTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/composer.json ext/phpbb/atproto/ext.php tests/ext/phpbb/atproto/ExtensionTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add extension skeleton with composer.json and ext.php

- Create phpbb/atproto extension structure
- Require PHP 8.4+ and sodium extension for token encryption
- Add basic extension test

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
