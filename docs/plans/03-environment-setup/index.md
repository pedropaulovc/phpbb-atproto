# Environment Setup Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Establish complete development environment with all tooling for Windows + Docker hybrid workflow.

**Architecture:** Local Windows machine runs IDE, Git, and Node.js tooling. Docker runs phpBB, MySQL, and PHP execution. Tests run in both environments depending on type.

**Tech Stack:** PHP 8.2+, MySQL 8.0, Docker, Composer, PHPUnit, PHP-CS-Fixer, Playwright, Node.js

---

## Environment Philosophy

| Layer | Runs On | Rationale |
|-------|---------|-----------|
| IDE, Git, Node.js | Windows | Fast feedback, native tooling |
| PHP runtime, MySQL | Docker | Consistent environment, matches production |
| Unit tests | Docker | Access to PHP extensions |
| Integration tests | Docker | Requires MySQL |
| E2E tests | Windows (Node) | Playwright controls browser |
| Linting | Both | Pre-commit on Windows, CI in Docker |

---

## Task 1: Docker Environment Enhancement

**Files:**
- Modify: `docker/Dockerfile`
- Modify: `docker/docker-compose.yml`
- Create: `docker/docker-compose.dev.yml`

**Step 1: Create development docker-compose override**

```yaml
# docker/docker-compose.dev.yml
services:
  phpbb:
    build:
      context: .
      dockerfile: Dockerfile
      target: dev
    volumes:
      - ../ext:/var/www/html/ext/phpbb/atproto:cached
      - ../tests:/var/www/html/tests:cached
      - ../vendor:/var/www/html/vendor:cached
    environment:
      XDEBUG_MODE: debug
      XDEBUG_CONFIG: client_host=host.docker.internal
      ATPROTO_TOKEN_ENCRYPTION_KEYS: '{"v1":"dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q="}'
      ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION: v1

  db:
    ports:
      - "3306:3306"

  # Test database for integration tests
  db-test:
    image: mysql:8.0
    container_name: phpbb-mysql-test
    environment:
      MYSQL_ROOT_PASSWORD: testroot
      MYSQL_DATABASE: phpbb_test
      MYSQL_USER: phpbb_test
      MYSQL_PASSWORD: test_password
    command: --default-authentication-plugin=mysql_native_password
    tmpfs:
      - /var/lib/mysql
```

**Step 2: Update Dockerfile with development target**

```dockerfile
# docker/Dockerfile - Add after existing content

# Development stage with Xdebug and Composer
FROM php:8.2-apache as dev

# Install PHP extensions required by phpBB
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libsodium-dev \
    unzip \
    curl \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo_mysql zip opcache sodium \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable Apache modules
RUN a2enmod rewrite

# Set PHP configuration for development
RUN { \
    echo 'upload_max_filesize = 10M'; \
    echo 'post_max_size = 10M'; \
    echo 'memory_limit = 512M'; \
    echo 'max_execution_time = 300'; \
    echo 'display_errors = On'; \
    echo 'error_reporting = E_ALL'; \
} > /usr/local/etc/php/conf.d/phpbb-dev.ini

# Download and extract phpBB
ARG PHPBB_VERSION=3.3.14
RUN curl -L "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.zip" -o /tmp/phpbb.zip \
    && unzip /tmp/phpbb.zip -d /tmp \
    && rm -rf /var/www/html/* \
    && mv /tmp/phpBB3/* /var/www/html/ \
    && rm -rf /tmp/phpbb.zip /tmp/phpBB3 \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

WORKDIR /var/www/html

EXPOSE 80
```

**Step 3: Verify Docker environment**

Run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml build`
Expected: Build completes successfully

**Step 4: Commit**

```bash
git add docker/docker-compose.dev.yml docker/Dockerfile
git commit -m "$(cat <<'EOF'
feat(docker): add development environment with Xdebug and test DB

- Add docker-compose.dev.yml for development overrides
- Add dev stage to Dockerfile with Xdebug, Composer, sodium
- Mount extension and test directories as volumes
- Add separate test database service

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Composer Setup

**Files:**
- Create: `composer.json` (root)
- Create: `ext/phpbb/atproto/composer.json`

**Step 1: Create root composer.json**

```json
{
    "name": "pedropaulovc/phpbb-atproto-dev",
    "description": "Development environment for phpBB AT Protocol integration",
    "type": "project",
    "license": "GPL-2.0-only",
    "require": {
        "php": ">=8.2"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "friendsofphp/php-cs-fixer": "^3.64",
        "phpstan/phpstan": "^1.12",
        "vimeo/psalm": "^5.26"
    },
    "autoload": {
        "psr-4": {
            "phpbb\\atproto\\": "ext/phpbb/atproto/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "phpbb\\atproto\\tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "platform": {
            "php": "8.2"
        }
    },
    "scripts": {
        "test": "phpunit",
        "test:unit": "phpunit --testsuite unit",
        "test:integration": "phpunit -c phpunit.integration.xml",
        "lint": "php-cs-fixer fix --dry-run --diff",
        "lint:fix": "php-cs-fixer fix",
        "analyse": "phpstan analyse",
        "check": [
            "@lint",
            "@analyse",
            "@test"
        ]
    }
}
```

**Step 2: Create extension composer.json**

Create directory and file:

```json
{
    "name": "phpbb/atproto",
    "type": "phpbb-extension",
    "description": "AT Protocol integration for phpBB - DID authentication and decentralized data",
    "homepage": "https://github.com/pedropaulovc/phpbb-atproto",
    "license": "GPL-2.0-only",
    "authors": [
        {
            "name": "Pedro Paulo Vezza Campos",
            "email": "pedro@vza.net"
        }
    ],
    "require": {
        "php": ">=8.2",
        "ext-sodium": "*",
        "guzzlehttp/guzzle": "^7.0"
    },
    "extra": {
        "display-name": "AT Protocol Integration",
        "soft-require": {
            "phpbb/phpbb": ">=3.3.0,<4.0"
        }
    }
}
```

**Step 3: Install dependencies in Docker**

Run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer install`
Expected: Dependencies installed successfully

**Step 4: Commit**

```bash
git add composer.json ext/phpbb/atproto/composer.json
git commit -m "$(cat <<'EOF'
feat(composer): add Composer configuration for development

- Add root composer.json with dev dependencies
- Add extension composer.json with runtime dependencies
- Configure PHPUnit, PHP-CS-Fixer, PHPStan
- Add convenience scripts for testing and linting

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: PHPUnit Configuration

**Files:**
- Create: `phpunit.xml`
- Create: `phpunit.integration.xml`
- Create: `tests/bootstrap.php`

**Step 1: Create unit test configuration**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         verbose="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">ext/phpbb/atproto</directory>
        </include>
        <exclude>
            <directory>ext/phpbb/atproto/migrations</directory>
        </exclude>
        <report>
            <html outputDirectory="tests/coverage"/>
            <text outputFile="php://stdout"/>
        </report>
    </coverage>
    <php>
        <env name="ATPROTO_TOKEN_ENCRYPTION_KEYS" value='{"v1":"dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q="}'/>
        <env name="ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION" value="v1"/>
    </php>
</phpunit>
```

**Step 2: Create integration test configuration**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.integration.php"
         colors="true">
    <testsuites>
        <testsuite name="integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="TEST_DB_HOST" value="db-test"/>
        <env name="TEST_DB_NAME" value="phpbb_test"/>
        <env name="TEST_DB_USER" value="phpbb_test"/>
        <env name="TEST_DB_PASS" value="test_password"/>
        <env name="ATPROTO_TOKEN_ENCRYPTION_KEYS" value='{"v1":"dGVzdGtleXRlc3RrZXl0ZXN0a2V5dGVzdGtleXRlc3Q="}'/>
        <env name="ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION" value="v1"/>
    </php>
</phpunit>
```

**Step 3: Create test bootstrap**

```php
<?php
// tests/bootstrap.php

require_once __DIR__ . '/../vendor/autoload.php';

// Mock phpBB globals for unit tests
define('IN_PHPBB', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
define('PHP_EXT', 'php');
```

**Step 4: Create directory structure**

```bash
mkdir -p tests/unit tests/integration tests/fixtures
```

**Step 5: Verify PHPUnit**

Run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpunit --version`
Expected: PHPUnit 9.x version displayed

**Step 6: Commit**

```bash
git add phpunit.xml phpunit.integration.xml tests/bootstrap.php
git commit -m "$(cat <<'EOF'
feat(testing): add PHPUnit configuration

- Add phpunit.xml for unit tests
- Add phpunit.integration.xml for integration tests
- Add test bootstrap with phpBB globals
- Create test directory structure

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: PHP-CS-Fixer Configuration

**Files:**
- Create: `.php-cs-fixer.php`

**Step 1: Create PHP-CS-Fixer configuration**

```php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/ext/phpbb/atproto')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try'],
        ],
        'cast_spaces' => true,
        'class_attributes_separation' => [
            'elements' => ['method' => 'one'],
        ],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'function_typehint_space' => true,
        'include' => true,
        'lowercase_cast' => true,
        'native_function_casing' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => true,
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_mixed_echo_print' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_spaces_around_offset' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unused_imports' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'normalize_index_brace' => true,
        'object_operator_without_whitespace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_package' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'return_type_declaration' => true,
        'self_accessor' => true,
        'short_scalar_cast' => true,
        'single_blank_line_before_namespace' => true,
        'single_quote' => true,
        'space_after_semicolon' => true,
        'standardize_not_equals' => true,
        'ternary_operator_spaces' => true,
        'trailing_comma_in_multiline' => true,
        'trim_array_spaces' => true,
        'unary_operator_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
```

**Step 2: Verify linting**

Run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/php-cs-fixer fix --dry-run`
Expected: No errors (or list of files to fix)

**Step 3: Commit**

```bash
git add .php-cs-fixer.php
git commit -m "$(cat <<'EOF'
feat(linting): add PHP-CS-Fixer configuration

- Configure PSR-12 base rules
- Add PHP 8.2 migration rules
- Enable strict types declaration
- Configure import ordering and spacing

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: PHPStan Configuration

**Files:**
- Create: `phpstan.neon`

**Step 1: Create PHPStan configuration**

```neon
parameters:
    level: 6
    paths:
        - ext/phpbb/atproto
    excludePaths:
        - ext/phpbb/atproto/migrations
    checkMissingIterableValueType: false
    reportUnmatchedIgnoredErrors: false
    ignoreErrors:
        - '#Access to undefined constant IN_PHPBB#'
        - '#Access to undefined constant PHPBB_ROOT_PATH#'
        - '#Access to undefined constant PHP_EXT#'
```

**Step 2: Verify static analysis**

Run: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpstan analyse`
Expected: Analysis completes (may show 0 errors if no code exists yet)

**Step 3: Commit**

```bash
git add phpstan.neon
git commit -m "$(cat <<'EOF'
feat(static-analysis): add PHPStan configuration

- Set analysis level 6
- Exclude migrations from analysis
- Ignore phpBB global constants

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: E2E Testing Setup (Node.js/Playwright)

**Files:**
- Create: `tests/e2e/package.json`
- Create: `tests/e2e/playwright.config.ts`
- Create: `tests/e2e/tsconfig.json`

**Step 1: Create package.json for E2E tests**

```json
{
    "name": "phpbb-atproto-e2e",
    "version": "1.0.0",
    "private": true,
    "scripts": {
        "test": "playwright test",
        "test:ui": "playwright test --ui",
        "test:headed": "playwright test --headed",
        "report": "playwright show-report"
    },
    "devDependencies": {
        "@playwright/test": "^1.48",
        "@types/node": "^22",
        "typescript": "^5.6"
    }
}
```

**Step 2: Create Playwright configuration**

```typescript
// tests/e2e/playwright.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: '.',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:8080',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: 'echo "Docker environment expected to be running"',
        url: 'http://localhost:8080',
        reuseExistingServer: true,
    },
});
```

**Step 3: Create TypeScript configuration**

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "module": "commonjs",
        "strict": true,
        "esModuleInterop": true,
        "skipLibCheck": true,
        "forceConsistentCasingInFileNames": true,
        "outDir": "./dist",
        "rootDir": "."
    },
    "include": ["**/*.ts"],
    "exclude": ["node_modules", "dist"]
}
```

**Step 4: Install E2E dependencies (Windows)**

Run: `cd tests/e2e && npm install && npx playwright install chromium`
Expected: Dependencies installed, Chromium downloaded

**Step 5: Commit**

```bash
git add tests/e2e/package.json tests/e2e/playwright.config.ts tests/e2e/tsconfig.json
git commit -m "$(cat <<'EOF'
feat(e2e): add Playwright configuration for end-to-end tests

- Add package.json with Playwright dependencies
- Configure Playwright for Docker environment
- Add TypeScript configuration

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Environment Scripts

**Files:**
- Create: `scripts/dev-up.sh`
- Create: `scripts/dev-down.sh`
- Create: `scripts/test.sh`

**Step 1: Create development startup script**

```bash
#!/bin/bash
# scripts/dev-up.sh
# Start development environment

set -e

cd "$(dirname "$0")/.."

echo "Starting development environment..."

# Build and start Docker services
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml up -d --build

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T db mysqladmin ping -h localhost -u root -prootpassword --silent; do
    sleep 1
done

# Install Composer dependencies if needed
if [ ! -d "vendor" ]; then
    echo "Installing Composer dependencies..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb composer install
fi

echo ""
echo "Development environment ready!"
echo "  phpBB:      http://localhost:8080"
echo "  phpMyAdmin: http://localhost:8081"
echo "  MySQL:      localhost:3306"
echo ""
echo "First time? Run: docker cp docker/install-config.yml phpbb-app:/var/www/html/install/install-config.yml"
echo "Then: docker exec phpbb-app bash -c 'cd /var/www/html && php install/phpbbcli.php install install/install-config.yml'"
```

**Step 2: Create development shutdown script**

```bash
#!/bin/bash
# scripts/dev-down.sh
# Stop development environment

set -e

cd "$(dirname "$0")/.."

echo "Stopping development environment..."
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml down

echo "Development environment stopped."
```

**Step 3: Create test runner script**

```bash
#!/bin/bash
# scripts/test.sh
# Run all tests

set -e

cd "$(dirname "$0")/.."

SUITE="${1:-all}"

run_unit_tests() {
    echo "Running unit tests..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb vendor/bin/phpunit --testsuite unit
}

run_integration_tests() {
    echo "Running integration tests..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb vendor/bin/phpunit -c phpunit.integration.xml
}

run_e2e_tests() {
    echo "Running E2E tests..."
    cd tests/e2e && npm test
}

run_lint() {
    echo "Running linter..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb vendor/bin/php-cs-fixer fix --dry-run --diff
}

run_static_analysis() {
    echo "Running static analysis..."
    docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec -T phpbb vendor/bin/phpstan analyse
}

case "$SUITE" in
    unit)
        run_unit_tests
        ;;
    integration)
        run_integration_tests
        ;;
    e2e)
        run_e2e_tests
        ;;
    lint)
        run_lint
        ;;
    analyse)
        run_static_analysis
        ;;
    all)
        run_lint
        run_static_analysis
        run_unit_tests
        run_integration_tests
        ;;
    *)
        echo "Usage: $0 {unit|integration|e2e|lint|analyse|all}"
        exit 1
        ;;
esac

echo "Done!"
```

**Step 4: Make scripts executable**

Run: `chmod +x scripts/*.sh`
(Note: On Windows, Git will handle executable bits)

**Step 5: Commit**

```bash
git add scripts/
git commit -m "$(cat <<'EOF'
feat(scripts): add development convenience scripts

- Add dev-up.sh for starting environment
- Add dev-down.sh for stopping environment
- Add test.sh for running all test types

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Update .gitignore

**Files:**
- Modify: `.gitignore`

**Step 1: Update .gitignore with all development artifacts**

```gitignore
# Dependencies
/vendor/
/node_modules/
tests/e2e/node_modules/

# Build outputs
/dist/
*.phar

# Test artifacts
/tests/coverage/
/tests/e2e/test-results/
/tests/e2e/playwright-report/
/.phpunit.result.cache

# IDE
/.idea/
/.vscode/
*.sublime-*

# OS
.DS_Store
Thumbs.db

# Environment
.env
.env.local
*.local.php

# PHP-CS-Fixer
/.php-cs-fixer.cache

# PHPStan
/phpstan-baseline.neon

# Docker volumes (local)
/docker/data/

# Worktrees
/.worktrees/
```

**Step 2: Commit**

```bash
git add .gitignore
git commit -m "$(cat <<'EOF'
chore(gitignore): update for development tooling

- Add vendor and node_modules
- Add test coverage and artifacts
- Add IDE and OS files
- Add linting caches

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Update AGENTS.md

**Files:**
- Modify: `AGENTS.md`

**Step 1: Add environment section to AGENTS.md**

Add after the existing content:

```markdown

## Environment Setup

**All Claude sessions MUST verify environment before coding work.**

### Quick Verification

```bash
# Verify Docker is running
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps

# Verify PHP dependencies
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer show | head -5

# Verify test configuration
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpunit --version
```

### Environment Architecture

| Component | Location | How to Run |
|-----------|----------|------------|
| PHP runtime | Docker `phpbb` service | `docker compose exec phpbb php ...` |
| MySQL | Docker `db` service | `docker compose exec db mysql ...` |
| Unit tests | Docker | `./scripts/test.sh unit` |
| Integration tests | Docker | `./scripts/test.sh integration` |
| E2E tests | Windows Node.js | `cd tests/e2e && npm test` |
| Linting | Docker | `./scripts/test.sh lint` |

### Starting Fresh Session

1. Start environment: `./scripts/dev-up.sh`
2. Verify services: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps`
3. Run quick test: `./scripts/test.sh lint`

### PHP Tooling Reference

| Tool | Purpose | Command |
|------|---------|---------|
| PHPUnit | Unit/integration tests | `vendor/bin/phpunit` |
| PHP-CS-Fixer | Code style | `vendor/bin/php-cs-fixer fix` |
| PHPStan | Static analysis | `vendor/bin/phpstan analyse` |
| Composer | Dependencies | `composer install/update/require` |

### Windows-Specific Notes

- Use bash scripts via Git Bash or WSL
- Node.js runs natively on Windows for E2E tests
- Docker Desktop must be running
- File paths in JS/TS use Windows style: `C:\\path\\to\\file`
```

**Step 2: Commit**

```bash
git add AGENTS.md
git commit -m "$(cat <<'EOF'
docs(agents): add environment setup section

- Add quick verification commands
- Document environment architecture
- Add session startup checklist
- Include Windows-specific notes

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Create Environment Documentation

**Files:**
- Create: `docs/DEVELOPMENT.md`

**Step 1: Create comprehensive development documentation**

```markdown
# Development Environment

Complete guide to setting up and using the phpBB AT Protocol development environment.

## Prerequisites

### Windows (Host Machine)

- **Docker Desktop** 4.x+ with WSL2 backend
- **Git** 2.x+ with Git Bash
- **Node.js** 20.x+ LTS
- **VS Code** (recommended) with extensions:
  - PHP Intelephense
  - Docker
  - GitLens
  - Playwright Test for VSCode

### Installed via Docker

- PHP 8.2+ with extensions: sodium, pdo_mysql, gd, zip, opcache, xdebug
- MySQL 8.0
- Composer 2.x
- PHPUnit 9.x
- PHP-CS-Fixer 3.x
- PHPStan 1.x

## Quick Start

```bash
# Clone repository
git clone https://github.com/pedropaulovc/phpbb-atproto.git
cd phpbb-atproto

# Start development environment
./scripts/dev-up.sh

# First-time phpBB installation
docker cp docker/install-config.yml phpbb-app:/var/www/html/install/install-config.yml
docker exec phpbb-app bash -c 'cd /var/www/html && php install/phpbbcli.php install install/install-config.yml'

# Access services
# phpBB:      http://localhost:8080 (admin / adminpassword)
# phpMyAdmin: http://localhost:8081 (root / rootpassword)
```

## Running Tests

```bash
# All tests (lint + analysis + unit + integration)
./scripts/test.sh all

# Individual test suites
./scripts/test.sh unit          # Unit tests only
./scripts/test.sh integration   # Integration tests only
./scripts/test.sh lint          # Code style check
./scripts/test.sh analyse       # Static analysis

# E2E tests (requires running Docker environment)
cd tests/e2e
npm install
npm test                        # Headless
npm run test:headed            # With browser visible
npm run test:ui                # Interactive UI mode
```

## Development Workflow

### Making Changes

1. Start environment: `./scripts/dev-up.sh`
2. Edit code in `ext/phpbb/atproto/`
3. Changes are immediately reflected (volume mount)
4. Run tests: `./scripts/test.sh unit`
5. Check style: `./scripts/test.sh lint`
6. Commit when tests pass

### Code Style

Run fixer before committing:

```bash
# Check for issues
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/php-cs-fixer fix --dry-run --diff

# Auto-fix issues
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/php-cs-fixer fix
```

### Debugging with Xdebug

1. Configure VS Code launch.json:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003,
            "pathMappings": {
                "/var/www/html/ext/phpbb/atproto": "${workspaceFolder}/ext/phpbb/atproto"
            }
        }
    ]
}
```

2. Start debugging in VS Code (F5)
3. Set breakpoints in code
4. Trigger code via browser/tests

## Database Access

### phpMyAdmin

Open http://localhost:8081 in browser.

### MySQL CLI

```bash
# Connect to development database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysql -u phpbb -pphpbbpassword phpbb

# Connect to test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u phpbb_test -ptest_password phpbb_test
```

### Export Schema

```bash
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysqldump -u phpbb -pphpbbpassword --no-data phpbb > schema.sql
```

## Troubleshooting

### Docker Issues

```bash
# Rebuild containers
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml build --no-cache

# View logs
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml logs -f phpbb

# Reset everything
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml down -v
./scripts/dev-up.sh
```

### Permission Issues

```bash
# Fix extension permissions in container
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb chown -R www-data:www-data /var/www/html/ext
```

### Test Database Issues

```bash
# Reset test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u root -ptestroot -e "DROP DATABASE phpbb_test; CREATE DATABASE phpbb_test;"
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ATPROTO_TOKEN_ENCRYPTION_KEYS` | (required) | JSON object of key versions |
| `ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION` | (required) | Current key version |
| `XDEBUG_MODE` | `debug` | Xdebug mode (off, debug, coverage) |

## CI/CD

GitHub Actions runs on every push/PR:

1. Lint check (PHP-CS-Fixer)
2. Static analysis (PHPStan)
3. Unit tests (PHPUnit)
4. Integration tests (PHPUnit + MySQL)
5. E2E tests (Playwright)

See `.github/workflows/` for configuration.
```

**Step 2: Commit**

```bash
git add docs/DEVELOPMENT.md
git commit -m "$(cat <<'EOF'
docs: add comprehensive development environment guide

- Document prerequisites and quick start
- Add test running instructions
- Include debugging setup
- Add troubleshooting section

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Final Verification

**Step 1: Verify all components work**

```bash
# Start environment
./scripts/dev-up.sh

# Verify PHP tools
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb php -v
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer show | head -5

# Verify test tools
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpunit --version
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/php-cs-fixer --version
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpstan --version

# Verify E2E setup
cd tests/e2e && npm --version && npx playwright --version
```

**Step 2: Run all quality gates**

```bash
./scripts/test.sh all
```

Expected: All checks pass (or report "no tests" if none exist yet)

**Step 3: Final commit**

```bash
git add -A
git status  # Verify nothing unexpected
git commit -m "$(cat <<'EOF'
chore: finalize environment setup

- Verify all tooling works
- Environment ready for development

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)" || echo "Nothing to commit"
```

---

## Summary

After completing this plan, the development environment includes:

| Category | Tools |
|----------|-------|
| **Runtime** | PHP 8.2, MySQL 8.0, Docker |
| **Testing** | PHPUnit 9.x, Playwright |
| **Linting** | PHP-CS-Fixer 3.x |
| **Analysis** | PHPStan level 6 |
| **Dependencies** | Composer 2.x, npm |
| **Debugging** | Xdebug 3.x |
| **Database** | phpMyAdmin, MySQL CLI |

**Local (Windows):** Git, Node.js, IDE, E2E tests
**Docker:** PHP execution, database, PHP tests, linting
