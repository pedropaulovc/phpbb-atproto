# Foundation Phase Implementation Plan

## Objective
Implement the foundational infrastructure for phpBB AT Protocol integration:
- phpBB extension skeleton with proper structure
- Database migrations (6 tables)
- Working AT Protocol OAuth authentication flow

## Prerequisites
- Docker environment running (`docker/` setup)
- Lexicons already defined (10 files in `lexicons/`)
- Specifications complete in `specs/components/`

---

## Implementation Phases

### Phase F1: Extension Skeleton

**Goal**: Create the phpBB extension structure with service definitions.

**Files to create**:
```
ext/phpbb/atproto/
├── composer.json              # Extension metadata, dependencies
├── ext.php                    # Extension enable/disable hooks
├── config/
│   ├── services.yml           # Service definitions
│   └── routing.yml            # Route definitions (OAuth callback)
├── language/
│   └── en/
│       └── common.php         # Language strings
└── styles/
    └── prosilver/
        └── template/
            └── event/         # Template events (login button)
```

**Key tasks**:
1. Create `composer.json` with phpBB extension metadata
2. Create `ext.php` with enable/disable hooks
3. Create `config/services.yml` with service definitions for:
   - `phpbb.atproto.token_manager`
   - `phpbb.atproto.token_encryption`
   - `phpbb.atproto.oauth_client`
   - `phpbb.atproto.pds_client`
   - `phpbb.atproto.uri_mapper`
   - `phpbb.atproto.queue_manager`
   - `phpbb.atproto.record_builder`
4. Create `config/routing.yml` with OAuth callback route
5. Create language file with error messages and UI strings

---

### Phase F2: Database Migrations

**Goal**: Create the 6 mapping/state tables.

**File to create**:
```
ext/phpbb/atproto/
└── migrations/
    └── v1/
        └── m1_initial_schema.php
```

**Tables** (from `specs/components/phpbb-extension/migrations.md`):
| Table | Purpose |
|-------|---------|
| `phpbb_atproto_users` | DID-to-user mapping, encrypted OAuth tokens |
| `phpbb_atproto_posts` | AT URI-to-post_id mapping |
| `phpbb_atproto_forums` | AT URI-to-forum_id mapping |
| `phpbb_atproto_labels` | Cached moderation labels |
| `phpbb_atproto_cursors` | Firehose cursor positions |
| `phpbb_atproto_queue` | Retry queue for failed PDS writes |

**Key indexes**:
- `idx_did` (unique) on `atproto_users`
- `idx_at_uri` (unique) on `atproto_posts`, `atproto_forums`
- `idx_subject_uri` on `atproto_labels`
- Composite `(next_retry_at, status)` on `atproto_queue`

---

### Phase F3: Token Encryption

**Goal**: Implement XChaCha20-Poly1305 token encryption with key rotation.

**File to create**:
```
ext/phpbb/atproto/
└── auth/
    └── token_encryption.php
```

**Key features**:
- Environment variable configuration: `ATPROTO_TOKEN_ENCRYPTION_KEYS`, `ATPROTO_TOKEN_ENCRYPTION_KEY_VERSION`
- Format: `version:base64(nonce || ciphertext)`
- Key rotation: decrypt with any version, encrypt with current
- `sodium_crypto_aead_xchacha20poly1305_ietf_*` functions

---

### Phase F4: DID Resolution

**Goal**: Resolve handles to DIDs and DIDs to PDS URLs.

**File to create**:
```
ext/phpbb/atproto/
└── services/
    └── did_resolver.php
```

**Key features**:
- Handle resolution via DNS TXT (`_atproto.handle`) or HTTP (`/.well-known/atproto-did`)
- DID document resolution:
  - `did:plc:*` → `https://plc.directory/{did}`
  - `did:web:*` → `https://{domain}/.well-known/did.json`
- Extract PDS service endpoint from DID document
- Caching (1 hour TTL)

---

### Phase F5: OAuth Client

**Goal**: Implement AT Protocol OAuth flow.

**Files to create**:
```
ext/phpbb/atproto/
├── auth/
│   ├── oauth_client.php       # OAuth flow implementation
│   └── provider.php           # phpBB auth provider integration
├── controller/
│   └── oauth_controller.php   # OAuth callback handler
└── event/
    └── auth_listener.php      # Event hooks
```

**OAuth flow**:
1. User enters handle on login page
2. Resolve handle → DID → PDS URL
3. Fetch PDS OAuth metadata (`/.well-known/oauth-authorization-server`)
4. Redirect to `{pds}/oauth/authorize`
5. User approves, redirected back with code
6. Exchange code for tokens at `{pds}/oauth/token`
7. Store encrypted tokens, create/update phpBB user
8. Create phpBB session

**Key interfaces** (from spec):
- `OAuthClientInterface::getAuthorizationUrl(handle, state)`
- `OAuthClientInterface::exchangeCode(code, state)`
- `OAuthClientInterface::refreshAccessToken(refreshToken)`

---

### Phase F6: Token Manager

**Goal**: Manage token storage, retrieval, and refresh.

**File to create**:
```
ext/phpbb/atproto/
└── services/
    └── token_manager.php
```

**Key features**:
- `getAccessToken(userId)` - returns valid token, refreshes if needed
- `storeTokens(userId, accessToken, refreshToken, expiresAt)` - encrypts and stores
- `refreshToken(userId)` - force refresh with race condition prevention
- `clearTokens(userId)` - logout
- Row-level locking during refresh to prevent race conditions
- Refresh 5 minutes before expiry (configurable buffer)

---

### Phase F7: Login UI Integration

**Goal**: Add "Login with AT Protocol" button to phpBB login page.

**Files to create**:
```
ext/phpbb/atproto/
├── styles/
│   └── prosilver/
│       └── template/
│           └── event/
│               └── overall_header_navigation_prepend.html
└── language/
    └── en/
        └── common.php
```

**UI elements**:
- Login form with handle input field
- "Login with AT Protocol" button
- Error message display
- Link to connect existing phpBB account to AT Protocol

---

## File Summary

**Total files to create**: ~15 files

| Path | Purpose |
|------|---------|
| `ext/phpbb/atproto/composer.json` | Extension metadata |
| `ext/phpbb/atproto/ext.php` | Enable/disable hooks |
| `ext/phpbb/atproto/config/services.yml` | Service definitions |
| `ext/phpbb/atproto/config/routing.yml` | Route definitions |
| `ext/phpbb/atproto/migrations/v1/m1_initial_schema.php` | Database schema |
| `ext/phpbb/atproto/auth/token_encryption.php` | Token encryption |
| `ext/phpbb/atproto/auth/oauth_client.php` | OAuth flow |
| `ext/phpbb/atproto/auth/provider.php` | Auth provider |
| `ext/phpbb/atproto/services/did_resolver.php` | DID resolution |
| `ext/phpbb/atproto/services/token_manager.php` | Token management |
| `ext/phpbb/atproto/controller/oauth_controller.php` | OAuth callback |
| `ext/phpbb/atproto/event/auth_listener.php` | Auth events |
| `ext/phpbb/atproto/language/en/common.php` | Language strings |
| `ext/phpbb/atproto/styles/prosilver/template/event/*.html` | Login UI |

---

## Dependencies

```
F1 (Skeleton) ──┬──> F2 (Migrations)
                │
                └──> F3 (Encryption) ──> F6 (Token Manager)
                │                              │
                └──> F4 (DID Resolver) ────────┼──> F5 (OAuth Client)
                                               │           │
                                               └───────────┴──> F7 (Login UI)
```

**Critical path**: F1 → F2 → F3 → F6 → F5 → F7

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

## Verification

**Test the implementation**:

1. **Migration test**:
   - Enable extension in ACP
   - Verify all 6 tables exist: `SHOW TABLES LIKE 'phpbb_atproto_%'`
   - Verify indexes: `SHOW INDEX FROM phpbb_atproto_users`

2. **Encryption test**:
   - Encrypt/decrypt round-trip with test token
   - Verify key rotation decrypts old tokens

3. **DID resolution test**:
   - Resolve `@handle.bsky.social` → DID
   - Resolve DID → PDS URL

4. **OAuth flow test**:
   - Click "Login with AT Protocol"
   - Enter valid handle
   - Complete OAuth flow on PDS
   - Verify session created
   - Verify tokens stored (encrypted) in database

5. **Token refresh test**:
   - Manually expire token in database
   - Make authenticated request
   - Verify token auto-refreshed

---

## Reference Files

- `specs/components/phpbb-extension/migrations.md` - Full migration spec
- `specs/components/phpbb-extension/auth-provider.md` - Auth flow spec
- `specs/components/phpbb-extension/write-interceptor.md` - Write path (next phase)
- `docs/api-contracts.md` - Interface definitions
