# Task 2: Service Container Configuration

**Files:**
- Create: `ext/phpbb/atproto/config/services.yml`
- Create: `ext/phpbb/atproto/config/routing.yml`

**Step 1: Write the failing test**

```php
// tests/ext/phpbb/atproto/ConfigTest.php
<?php

namespace phpbb\atproto\tests;

class ConfigTest extends \phpbb_test_case
{
    public function test_services_yaml_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/services.yml';
        $this->assertFileExists($path);
    }

    public function test_routing_yaml_exists()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/routing.yml';
        $this->assertFileExists($path);
    }

    public function test_services_yaml_is_valid()
    {
        $path = __DIR__ . '/../../../../ext/phpbb/atproto/config/services.yml';
        $content = file_get_contents($path);
        $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
        $this->assertArrayHasKey('services', $parsed);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ConfigTest.php`
Expected: FAIL with "Failed asserting that file exists"

**Step 3: Create services.yml**

```yaml
services:
    # Token encryption service
    phpbb.atproto.token_encryption:
        class: phpbb\atproto\auth\token_encryption

    # DID resolution service
    phpbb.atproto.did_resolver:
        class: phpbb\atproto\services\did_resolver
        arguments:
            - '@cache.driver'
            - '%atproto.did_cache_ttl%'

    # OAuth client for AT Protocol
    phpbb.atproto.oauth_client:
        class: phpbb\atproto\auth\oauth_client
        arguments:
            - '@phpbb.atproto.did_resolver'
            - '%atproto.client_id%'
            - '%atproto.redirect_uri%'

    # Token manager for storage and refresh
    phpbb.atproto.token_manager:
        class: phpbb\atproto\services\token_manager
        arguments:
            - '@dbal.conn'
            - '@phpbb.atproto.token_encryption'
            - '@phpbb.atproto.oauth_client'
            - '%core.table_prefix%'
            - '%atproto.token_refresh_buffer%'

    # PDS client for AT Protocol API calls
    phpbb.atproto.pds_client:
        class: phpbb\atproto\services\pds_client
        arguments:
            - '@phpbb.atproto.token_manager'
            - '@phpbb.atproto.did_resolver'

    # URI mapper for AT URI <-> phpBB ID
    phpbb.atproto.uri_mapper:
        class: phpbb\atproto\services\uri_mapper
        arguments:
            - '@dbal.conn'
            - '%core.table_prefix%'

    # Queue manager for retry failed writes
    phpbb.atproto.queue_manager:
        class: phpbb\atproto\services\queue_manager
        arguments:
            - '@dbal.conn'
            - '%core.table_prefix%'

    # Record builder for AT Protocol records
    phpbb.atproto.record_builder:
        class: phpbb\atproto\services\record_builder
        arguments:
            - '@phpbb.atproto.uri_mapper'

    # OAuth callback controller
    phpbb.atproto.controller.oauth:
        class: phpbb\atproto\controller\oauth_controller
        arguments:
            - '@phpbb.atproto.oauth_client'
            - '@phpbb.atproto.token_manager'
            - '@user'
            - '@auth'
            - '@request'
            - '@template'
            - '@config'

    # Auth event listener
    phpbb.atproto.event.auth_listener:
        class: phpbb\atproto\event\auth_listener
        arguments:
            - '@phpbb.atproto.token_manager'
            - '@user'
            - '@dbal.conn'
            - '%core.table_prefix%'
        tags:
            - { name: event.listener }

parameters:
    atproto.client_id: '%env(ATPROTO_CLIENT_ID)%'
    atproto.redirect_uri: ~
    atproto.token_refresh_buffer: 300
    atproto.did_cache_ttl: 3600
```

**Step 4: Create routing.yml**

```yaml
phpbb_atproto_oauth_callback:
    path: /atproto/callback
    defaults:
        _controller: phpbb.atproto.controller.oauth:callback

phpbb_atproto_oauth_start:
    path: /atproto/login
    defaults:
        _controller: phpbb.atproto.controller.oauth:start
```

**Step 5: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/ext/phpbb/atproto/ConfigTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add ext/phpbb/atproto/config/services.yml ext/phpbb/atproto/config/routing.yml tests/ext/phpbb/atproto/ConfigTest.php
git commit -m "$(cat <<'EOF'
feat(atproto): add service container and routing configuration

- Define all core services: token_encryption, did_resolver, oauth_client,
  token_manager, pds_client, uri_mapper, queue_manager, record_builder
- Add OAuth callback and login routes
- Configure environment-based parameters

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```
