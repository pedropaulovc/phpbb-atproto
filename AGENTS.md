# Agent Instructions

## The Rule

**Invoke relevant skills BEFORE any response or action.** Even a 1% chance a skill might apply means you should invoke it.

## Red Flags

These thoughts mean STOP - you're rationalizing:

| Thought | Reality |
|---------|---------|
| "This is just a simple question" | Questions are tasks. Check for skills. |
| "I need more context first" | Skill check comes BEFORE clarifying questions. |
| "Let me explore the codebase first" | Skills tell you HOW to explore. Check first. |
| "This doesn't need a formal skill" | If a skill exists, use it. |
| "I'll just do this one thing first" | Check BEFORE doing anything. |

## Skill Priority

When multiple skills could apply:

1. **Process skills first** (brainstorming, debugging) - these determine HOW to approach the task
2. **Implementation skills second** (frontend-design, feature-dev) - these guide execution

Examples:
- "Let's build X" → brainstorming first, then implementation skills
- "Fix this bug" → systematic-debugging first, then domain-specific skills

## Key Skills

| Skill | When to Use |
|-------|-------------|
| `brainstorming` | Before ANY creative work - features, components, modifications |
| `systematic-debugging` | Before fixing ANY bug or unexpected behavior |
| `test-driven-development` | Before implementing ANY feature or bugfix |
| `writing-plans` | When you have specs/requirements for multi-step work |
| `executing-plans` | When implementing from a written plan |
| `verification-before-completion` | Before claiming work is done |
| `playwright-cli` | For demos, manual validation, or accessing pages forbidden via WebFetch |

## Playwright CLI Usage

**Always use `--headed` flag** when invoking playwright-cli to allow visual observation.

Use playwright-cli when:
- WebFetch fails due to forbidden/authenticated pages
- Demonstrating UI flows visually
- Manual validation requiring browser interaction

## Session Completion

**Work is NOT complete until `git push` succeeds.**

1. Run quality gates (tests, linters) if code changed
2. Commit all changes
3. Push to remote:
   ```bash
   git pull --rebase && git push
   git status  # MUST show "up to date with origin"
   ```
4. Verify - all changes committed AND pushed

**Never say "ready to push when you are" - YOU must push.**

## Environment Setup

**All Claude sessions MUST verify environment before coding work.**

### Environment Options

| Method | When to Use |
|--------|-------------|
| **Docker Compose** | Default for local development |
| **Dev Container** | When working in VS Code or Codespaces |

### Quick Verification (Docker Compose)

```bash
# Verify Docker is running
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps

# Verify PHP dependencies
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb composer show | head -5

# Verify test configuration
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb vendor/bin/phpunit --version
```

### Quick Verification (Dev Container)

```bash
# Check if running inside Dev Container
echo $REMOTE_CONTAINERS  # Should be "true" if in container

# Verify phpBB is installed
curl -s http://localhost:8080/ | grep -o "AT Protocol Test Forum"

# Verify MySQL is running
sudo service mysql status
```

### Environment Architecture

| Component | Location | How to Run |
|-----------|----------|------------|
| PHP runtime | Docker `phpbb` service | `docker compose exec phpbb php ...` |
| MySQL | Docker `db` service | `docker compose exec db mysql ...` |
| MySQL (test) | Docker `db-test` service | `docker compose exec db-test mysql ...` |
| Unit tests | Docker | `./scripts/test.sh unit` |
| Integration tests | Docker | `./scripts/test.sh integration` |
| E2E tests | Windows Node.js | `cd tests/e2e && npm test` |
| Linting | Docker | `./scripts/test.sh lint` |
| Static analysis | Docker | `./scripts/test.sh analyse` |

### Starting Fresh Session

**Option A: Docker Compose (default)**
1. Start environment: `./scripts/dev-up.sh`
2. Verify services: `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps`
3. Run quick test: `./scripts/test.sh lint`

**Option B: Dev Container / Codespaces**
1. Open in VS Code and reopen in container, or use GitHub Codespaces
2. Environment auto-starts (phpBB at http://localhost:8080)
3. Run quick test: `cd /workspaces/phpbb-core/phpBB && php vendor/bin/phpunit`

### Test Commands

```bash
# All tests (lint + analysis + unit + integration)
./scripts/test.sh all

# Individual test suites
./scripts/test.sh unit          # Unit tests only
./scripts/test.sh integration   # Integration tests only
./scripts/test.sh lint          # Code style check
./scripts/test.sh analyse       # Static analysis

# E2E tests (run from Windows, not Docker)
cd tests/e2e && npm test        # Headless
cd tests/e2e && npm run test:headed   # With browser visible
```

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

### Troubleshooting

```bash
# View container status
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml ps

# View logs
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml logs -f phpbb

# Reset test database
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec db-test mysql -u root -ptestroot -e "DROP DATABASE IF EXISTS phpbb_test; CREATE DATABASE phpbb_test;"

# Fix permissions
docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec phpbb chown -R www-data:www-data /var/www/html/ext
```
