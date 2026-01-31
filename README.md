# phpBB on AT Protocol

A project to port phpBB bulletin board to use AT Protocol as its data backend.

## Architecture

This uses a **Hybrid AppView** approach:
- phpBB's MySQL database serves as a **local cache** (not source of truth)
- All canonical data lives on **AT Protocol PDSes**
- A **Sync Service** (PHP) bridges AT Protocol ↔ phpBB MySQL
- Forum reads from **public relays** (Bluesky's firehose)
- **Labels-only moderation** following AT Protocol philosophy

## Data Ownership

| Data Type | Location |
|-----------|----------|
| Posts, topics | User's PDS |
| User profile & settings | User's PDS |
| Forum structure | Forum PDS |
| Forum config & ACL | Forum PDS |
| Moderation labels | Forum PDS (Ozone) |

## Lexicon Namespace

All custom lexicons use the `net.vza.forum.*` namespace:

- `net.vza.forum.post` - Forum posts
- `net.vza.forum.board` - Forum/subforum definitions
- `net.vza.forum.category` - Forum categories
- `net.vza.forum.config` - Forum configuration
- `net.vza.forum.settings` - User forum preferences
- `net.vza.forum.acl` - Permission definitions

## Implementation Roadmap

| Phase | Focus | Status |
|-------|-------|--------|
| 1. Foundation | Extension skeleton, migrations, OAuth | 🔄 In Progress |
| 2. Write Path | Post creation → user PDS | ⏳ Pending |
| 3. Forum PDS | Forum config on AT Protocol | ⏳ Pending |
| 4. Sync Service | Firehose client, event processing | ⏳ Pending |
| 5. Moderation | Ozone labels, MCP integration | ⏳ Pending |
| 6. Polish | Admin UI, error handling, docs | ⏳ Pending |

See [docs/spec/](docs/spec/) for the complete functional specification.

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
├── docs/
│   ├── spec/                  # Functional specification
│   └── plans/                 # Implementation plans
├── lexicons/                  # AT Protocol lexicon definitions
├── composer.json              # PHP dependencies
├── phpunit.xml                # Unit test config
├── phpunit.integration.xml    # Integration test config
├── phpstan.neon               # Static analysis config
└── .php-cs-fixer.php          # Code style config
```

## Development Setup

### Prerequisites

**Windows (Host Machine):**
- Docker Desktop 4.x+ with WSL2 backend
- Git 2.x+ with Git Bash
- Node.js 20.x+ LTS

**Installed via Docker:**
- PHP 8.2+ with extensions: sodium, pdo_mysql, gd, zip, opcache, xdebug
- MySQL 8.0
- Composer 2.x, PHPUnit 9.x, PHP-CS-Fixer 3.x, PHPStan 1.x

### Quick Start

```bash
# Clone repository
git clone https://github.com/pedropaulovc/phpbb-atproto.git
cd phpbb-atproto

# Start development environment
./scripts/dev-up.sh

# Access services
# phpBB:      http://localhost:8080 (admin / adminpassword)
# phpMyAdmin: http://localhost:8081 (root / rootpassword)
```

### Running Tests

```bash
# All tests (lint + analysis + unit + integration)
./scripts/test.sh all

# Individual test suites
./scripts/test.sh unit          # Unit tests only
./scripts/test.sh integration   # Integration tests only
./scripts/test.sh lint          # Code style check
./scripts/test.sh analyse       # Static analysis

# E2E tests with Playwright (run from Windows, not Docker)
cd tests/e2e
npm install                     # First time only
npx playwright install chromium # First time only
npm test                        # Headless
npm run test:headed            # With browser visible
```

### Database Access

```bash
# Connect to development database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db mysql -u phpbb -pphpbbpassword phpbb

# Connect to test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u phpbb_test -ptest_password phpbb_test

# phpMyAdmin: http://localhost:8081 (root / rootpassword)
```

## Documentation

- [Functional Specification](docs/spec/README.md) - Complete system specification
- [Development Guide](docs/DEVELOPMENT.md) - Detailed development environment setup
- [Implementation Plans](docs/plans/README.md) - Task breakdowns for each phase

## License

GPL-2.0 - see [LICENSE](LICENSE) for details.
