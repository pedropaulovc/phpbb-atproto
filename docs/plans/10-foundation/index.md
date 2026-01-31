# Foundation Phase Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the phpBB AT Protocol extension skeleton with database migrations and working OAuth authentication flow.

**Architecture:** A phpBB extension (`ext/phpbb/atproto/`) that provides DID-based authentication via AT Protocol OAuth. Tokens are encrypted at rest using XChaCha20-Poly1305 with key rotation support. The extension hooks into phpBB's authentication events and provides services for token management, DID resolution, and PDS communication.

**Tech Stack:** PHP 8.4+, phpBB 3.3.x extension framework, Sodium for encryption, HTTP client for OAuth/DID resolution.

---

## Prerequisites

- Docker environment running (`docker/` setup)
- Lexicons already defined (10 files in `lexicons/`)
- Specifications complete in `docs/spec/components/`

---

## Task Dependencies

```
Task 1 (Skeleton) ──┬──> Task 2 (Services Config)
                    │
                    └──> Task 3 (Migrations)
                    │
                    └──> Task 4 (Encryption) ──> Task 7 (Token Manager)
                    │                                   │
                    └──> Task 5 (DID Resolver) ─────────┼──> Task 6 (OAuth Client)
                                                        │           │
                                                        └───────────┴──> Task 8 (Controller)
                                                                                │
                                                                                └──> Task 9 (Event Listener)
                                                                                        │
                                                                                        └──> Task 10-11 (Language/Templates)
                                                                                                │
                                                                                                └──> Task 12-13 (Integration/Verification)
```

**Critical path:** Tasks 1 → 3 → 4 → 7 → 6 → 8 → 9 → 12

---

## Environment Configuration

Required environment variables:
```bash
ATPROTO_TOKEN_ENCRYPTION_KEYS='{"v1":"base64-encoded-32-byte-key"}'
ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION='v1'
ATPROTO_CLIENT_ID='https://your-forum.com/client-metadata.json'
```

Optional:
```bash
ATPROTO_TOKEN_REFRESH_BUFFER=300  # seconds before expiry to refresh
ATPROTO_DID_CACHE_TTL=3600        # DID document cache TTL
```

---

## Tasks

| # | Task | File |
|---|------|------|
| 1 | Extension Skeleton Files | [01-extension-skeleton.md](01-extension-skeleton.md) |
| 2 | Service Container Configuration | [02-service-container.md](02-service-container.md) |
| 3 | Database Migration | [03-database-migration.md](03-database-migration.md) |
| 4 | Token Encryption Service | [04-token-encryption.md](04-token-encryption.md) |
| 5 | DID Resolver Service | [05-did-resolver.md](05-did-resolver.md) |
| 6 | OAuth Client | [06-oauth-client.md](06-oauth-client.md) |
| 7 | Token Manager Service | [07-token-manager.md](07-token-manager.md) |
| 8 | OAuth Controller | [08-oauth-controller.md](08-oauth-controller.md) |
| 9 | Auth Event Listener | [09-auth-event-listener.md](09-auth-event-listener.md) |
| 10 | Language Strings | [10-language-strings.md](10-language-strings.md) |
| 11 | Login Template | [11-login-template.md](11-login-template.md) |
| 12 | Integration Test | [12-integration-test.md](12-integration-test.md) |
| 13 | Final Verification | [13-final-verification.md](13-final-verification.md) |

---

## Verification Checklist

After completing all tasks:

- [ ] Extension can be enabled in phpBB ACP
- [ ] All 6 database tables created
- [ ] Token encryption works (round-trip test)
- [ ] DID resolution works for valid handles
- [ ] OAuth login button appears on login page
- [ ] OAuth flow redirects to PDS
- [ ] Callback creates phpBB session
- [ ] Logout clears AT Protocol tokens
- [ ] All unit tests pass
- [ ] All integration tests pass

---

## File Summary

| Task | Files Created |
|------|---------------|
| 1 | `ext.php`, `composer.json` |
| 2 | `config/services.yml`, `config/routing.yml` |
| 3 | `migrations/v1/m1_initial_schema.php` |
| 4 | `auth/token_encryption.php` |
| 5 | `services/did_resolver.php` |
| 6 | `auth/oauth_client.php`, `auth/oauth_client_interface.php`, `auth/oauth_exception.php` |
| 7 | `services/token_manager.php`, `services/token_manager_interface.php`, `exceptions/*.php` |
| 8 | `controller/oauth_controller.php` |
| 9 | `event/auth_listener.php` |
| 10 | `language/en/common.php` |
| 11 | `styles/prosilver/template/*.html` |
| 12 | `tests/integration/AuthFlowTest.php` |

**Total: ~20 source files + ~12 test files**

---

## Reference Files

- `docs/spec/components/phpbb-extension/migrations.md` - Full migration spec with table schemas
- `docs/spec/components/phpbb-extension/auth-provider.md` - Auth flow spec with interfaces
- `docs/spec/components/phpbb-extension/write-interceptor.md` - Write path (next phase)
- `docs/api-contracts.md` - Interface definitions
- `docs/spec/lexicons/` - AT Protocol lexicon definitions (10 files)
