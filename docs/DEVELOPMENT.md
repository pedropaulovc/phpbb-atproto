# Development Environment

Complete guide to setting up and using the phpBB AT Protocol development environment.

## Quick Start Options

| Method | Best For | Setup Time |
|--------|----------|------------|
| [GitHub Codespaces](#github-codespaces) | Zero setup, cloud development | ~2 minutes |
| [VS Code Dev Container](#vs-code-dev-container) | Local development with VS Code | ~3 minutes |
| [Docker Compose](#docker-compose-local) | Local development, custom setup | ~5 minutes |

---

## GitHub Codespaces

**Recommended for quick start - no local setup required.**

1. Click the button below or go to the repository and click "Code" → "Codespaces" → "Create codespace on main"

   [![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/pedropaulovc/phpbb-atproto)

2. Wait for the environment to build (~2 minutes)
3. The phpBB forum will be available at the forwarded port 8080
4. Login: `admin` / `adminadmin`

**What's included:**
- PHP 8.2 with all required extensions
- MySQL 8.0 with phpBB database
- Apache web server
- Xdebug for debugging
- GitHub CLI

---

## VS Code Dev Container

**Recommended for local development with VS Code.**

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) 4.x+
- [VS Code](https://code.visualstudio.com/) with [Dev Containers extension](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers)

### Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/pedropaulovc/phpbb-atproto.git
   cd phpbb-atproto
   ```

2. Open in VS Code:
   ```bash
   code .
   ```

3. When prompted "Reopen in Container", click **Yes**
   - Or press `F1` → "Dev Containers: Reopen in Container"

4. Wait for the container to build and phpBB to install (~3 minutes first time)

5. Access phpBB at http://localhost:8080
   - Login: `admin` / `adminadmin`

### Dev Container Features

- **Hot reload**: Edit code in `ext/phpbb/atproto/`, changes are immediate
- **Debugging**: Xdebug pre-configured on port 9003
- **Extensions**: PHP Intelephense, Xdebug, EditorConfig pre-installed
- **Terminal**: Full bash access inside container

---

## Docker Compose (Local)

**For custom setups or when not using VS Code.**

### Prerequisites

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

### Quick Start

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

### Using the Test Script

```bash
# All tests (lint + analysis + unit + integration)
./scripts/test.sh all

# Individual test suites
./scripts/test.sh unit          # Unit tests only
./scripts/test.sh integration   # Integration tests only
./scripts/test.sh lint          # Code style check
./scripts/test.sh analyse       # Static analysis
```

### E2E Tests with Playwright

E2E tests run on Windows (not in Docker) and require the development environment to be running.

```bash
# Install dependencies (first time only)
cd tests/e2e
npm install
npx playwright install chromium

# Run tests
npm test                        # Headless
npm run test:headed            # With browser visible
npm run test:ui                # Interactive UI mode
npm run report                 # View last test report
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

Xdebug is pre-configured in the development Docker environment. To use it with VS Code:

1. Create `.vscode/launch.json`:

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
4. Trigger code via browser or tests

## Database Access

### phpMyAdmin

Open http://localhost:8081 in your browser.

- Server: db
- Username: root
- Password: rootpassword

### MySQL CLI

```bash
# Connect to development database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysql -u phpbb -pphpbbpassword phpbb

# Connect to test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u phpbb_test -ptest_password phpbb_test

# Connect as root
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysql -u root -prootpassword phpbb
```

### Export Schema

```bash
# Export schema only (no data)
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysqldump -u phpbb -pphpbbpassword --no-data phpbb > schema.sql

# Export with data
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysqldump -u phpbb -pphpbbpassword phpbb > backup.sql
```

## Troubleshooting

### Docker Issues

```bash
# View container status
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps

# View logs
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml logs -f phpbb
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml logs -f db

# Rebuild containers (after Dockerfile changes)
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml build --no-cache

# Reset everything (WARNING: destroys all data)
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml down -v
./scripts/dev-up.sh
```

### Permission Issues

```bash
# Fix extension permissions in container
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb chown -R www-data:www-data /var/www/html/ext

# Fix cache permissions
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb chmod -R 777 /var/www/html/cache
```

### Test Database Issues

```bash
# Reset test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u root -ptestroot -e "DROP DATABASE IF EXISTS phpbb_test; CREATE DATABASE phpbb_test;"

# Verify test database connection
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u phpbb_test -ptest_password -e "SHOW DATABASES;"
```

### Composer Issues

```bash
# Clear Composer cache
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer clear-cache

# Reinstall dependencies
rm -rf vendor
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer install
```

## Environment Variables

The development environment uses these environment variables (configured in `docker/docker-compose.dev.yml`):

| Variable | Default | Description |
|----------|---------|-------------|
| `ATPROTO_TOKEN_ENCRYPTION_KEYS` | Test key | JSON object mapping key versions to base64-encoded 32-byte keys |
| `ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION` | `v1` | Current key version to use for encryption |
| `XDEBUG_MODE` | `debug` | Xdebug mode: `off`, `debug`, `coverage`, `profile` |
| `XDEBUG_CONFIG` | `client_host=host.docker.internal` | Xdebug client configuration |

### Token Encryption Keys

For local development, test keys are pre-configured. For production, generate secure keys:

```bash
# Generate a random 32-byte key (base64 encoded)
openssl rand -base64 32
```

Store keys in this format:
```json
{"v1":"base64key1==","v2":"base64key2=="}
```

## CI/CD

GitHub Actions runs on every push and pull request:

1. **Lint check** - PHP-CS-Fixer verifies code style
2. **Static analysis** - PHPStan analyzes code at level 6
3. **Unit tests** - PHPUnit runs isolated unit tests
4. **Integration tests** - PHPUnit runs database-dependent tests
5. **E2E tests** - Playwright runs browser tests

Configuration files:
- `.github/workflows/` - GitHub Actions workflow definitions
- `phpunit.xml` - Unit test configuration
- `phpunit.integration.xml` - Integration test configuration
- `phpstan.neon` - Static analysis configuration
- `.php-cs-fixer.php` - Code style rules
- `tests/e2e/playwright.config.ts` - E2E test configuration

## Project Structure

```
phpbb-atproto/
├── docker/                    # Docker configuration
│   ├── docker-compose.yml     # Base services
│   ├── docker-compose.dev.yml # Development overrides
│   ├── Dockerfile             # PHP/Apache image
│   └── install-config.yml     # phpBB auto-install config
├── ext/phpbb/atproto/         # phpBB extension source
├── tests/
│   ├── unit/                  # PHPUnit unit tests
│   ├── integration/           # PHPUnit integration tests
│   ├── fixtures/              # Test data fixtures
│   └── e2e/                   # Playwright E2E tests
├── scripts/
│   ├── dev-up.sh              # Start development environment
│   ├── dev-down.sh            # Stop development environment
│   └── test.sh                # Run tests
├── docs/                      # Documentation
├── composer.json              # PHP dependencies
├── phpunit.xml                # Unit test config
├── phpunit.integration.xml    # Integration test config
├── phpstan.neon               # Static analysis config
└── .php-cs-fixer.php          # Code style config
```
