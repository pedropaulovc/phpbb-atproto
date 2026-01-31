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

## AT Protocol OAuth Requirements

> **IMPORTANT:** AT Protocol OAuth has stricter requirements than standard OAuth 2.0. The authorization server will reject requests that don't follow these requirements.

| Requirement | Description | Status |
|-------------|-------------|--------|
| **DPoP** | All token requests must include DPoP proofs (RFC 9449) | Task 14 |
| **PAR** | Must use Pushed Authorization Requests | Task 15 |
| **Client Metadata** | `client_id` must be URL serving metadata JSON | Task 16 |
| **Keypair Persistence** | DPoP keypair must persist (tokens bound to key) | Task 17 |

**Reference:** https://docs.bsky.app/docs/advanced-guides/oauth-client

---

## Task Dependencies

```
Task 1 (Skeleton) ──┬──> Task 2 (Services Config)
                    │
                    └──> Task 3 (Migrations) ──> Task 13 (DPoP Persistence)
                    │                                       │
                    └──> Task 4 (Encryption) ──> Task 7 (Token Manager)
                    │                                   │
                    └──> Task 5 (DID Resolver) ─────────┼──> Task 12 (DPoP Service)
                                                        │           │
                                                        │           └──> Task 14 (PAR)
                                                        │                   │
                                                        │                   └──> Task 6 (OAuth Client)
                                                        │                           │
                                                        └───────────────────────────┼──> Task 15 (Client Metadata)
                                                                                    │           │
                                                                                    └───────────┴──> Task 8 (Controller)
                                                                                                            │
                                                                                                            └──> Task 9 (Event Listener)
                                                                                                                    │
                                                                                                                    └──> Task 10-11 (Language/Templates)
                                                                                                                            │
                                                                                                                            └──> Task 16-17 (Integration/Verification)
```

**Critical path:** Tasks 1 → 3 → 13 → 12 → 14 → 6 → 15 → 8 → 9 → 16

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

| # | Task | File | Status |
|---|------|------|--------|
| 1 | Extension Skeleton Files | [01-extension-skeleton.md](01-extension-skeleton.md) | ✅ |
| 2 | Service Container Configuration | [02-service-container.md](02-service-container.md) | ✅ |
| 3 | Database Migration | [03-database-migration.md](03-database-migration.md) | ✅ |
| 4 | Token Encryption Service | [04-token-encryption.md](04-token-encryption.md) | ✅ |
| 5 | DID Resolver Service | [05-did-resolver.md](05-did-resolver.md) | ✅ |
| 6 | OAuth Client (basic) | [06-oauth-client.md](06-oauth-client.md) | ✅ |
| 7 | Token Manager Service | [07-token-manager.md](07-token-manager.md) | ✅ |
| 8 | OAuth Controller | [08-oauth-controller.md](08-oauth-controller.md) | ✅ |
| 9 | Auth Event Listener | [09-auth-event-listener.md](09-auth-event-listener.md) | ✅ |
| 10 | Language Strings | [10-language-strings.md](10-language-strings.md) | ✅ |
| 11 | Login Template | [11-login-template.md](11-login-template.md) | ✅ |
| **12** | **DPoP Service** | [12-dpop-service.md](12-dpop-service.md) | 🆕 |
| **13** | **DPoP Keypair Persistence** | [13-dpop-keypair-persistence.md](13-dpop-keypair-persistence.md) | 🆕 |
| **14** | **Pushed Authorization Request (PAR)** | [14-pushed-authorization-request.md](14-pushed-authorization-request.md) | 🆕 |
| **15** | **Client Metadata Endpoint** | [15-client-metadata-endpoint.md](15-client-metadata-endpoint.md) | 🆕 |
| 16 | Integration Test | [16-integration-test.md](16-integration-test.md) | ⏳ |
| 17 | Final Verification | [17-final-verification.md](17-final-verification.md) | ⏳ |

**Legend:** ✅ Complete | ⏳ Pending | 🆕 New (AT Protocol requirements)

---

## Verification Checklist

After completing all tasks:

- [ ] Extension can be enabled in phpBB ACP
- [ ] All 7 database tables created (including atproto_config for DPoP)
- [ ] Token encryption works (round-trip test)
- [ ] DID resolution works for valid handles
- [ ] OAuth login button appears on login page
- [ ] **DPoP proofs are generated correctly (ES256 signed JWTs)**
- [ ] **PAR request succeeds and returns request_uri**
- [ ] **Client metadata endpoint returns valid JSON**
- [ ] OAuth flow redirects to authorization server (via PAR)
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
| **12** | `auth/dpop_service.php`, `auth/dpop_service_interface.php` |
| **13** | `migrations/v1/m2_dpop_keypair.php`, (modifies `auth/dpop_service.php`) |
| **14** | (modifies `auth/oauth_client.php` for PAR) |
| **15** | `controller/client_metadata_controller.php` |
| 16 | `tests/integration/AuthFlowTest.php` |

**Total: ~24 source files + ~16 test files**

---

## Reference Files

- `docs/spec/components/phpbb-extension/migrations.md` - Full migration spec with table schemas
- `docs/spec/components/phpbb-extension/auth-provider.md` - Auth flow spec with interfaces
- `docs/spec/components/phpbb-extension/write-interceptor.md` - Write path (next phase)
- `docs/api-contracts.md` - Interface definitions
- `docs/spec/lexicons/` - AT Protocol lexicon definitions (10 files)

## External References

- [AT Protocol OAuth Client Guide](https://docs.bsky.app/docs/advanced-guides/oauth-client) - Official OAuth implementation guide
- [AT Protocol OAuth Blog Post](https://docs.bsky.app/blog/oauth-atproto) - OAuth announcement with technical details
- [RFC 9449 - DPoP](https://datatracker.ietf.org/doc/html/rfc9449) - DPoP specification
- [RFC 9126 - PAR](https://datatracker.ietf.org/doc/html/rfc9126) - Pushed Authorization Requests
