# phpBB on AT Protocol - Exploration Plan

## Project Overview

Port phpBB to use AT Protocol as its data backend using a **Hybrid AppView** approach:
- phpBB's MySQL database serves as a **local cache** (not source of truth)
- All canonical data lives on **AT Protocol PDSes**:
  - User content (posts, profile, settings) → **User's PDS**
  - Forum structure (categories, config, ACL) → **Forum-managed PDS**
- A **Sync Service** (PHP) bridges AT Protocol ↔ phpBB MySQL
- Forum reads from **public relays** (Bluesky's firehose)
- **Labels-only moderation** (AT Protocol philosophy)

**Repository**: https://github.com/pedropaulovc/phpbb-atproto
**Lexicon namespace**: `net.vza.forum.*`

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    AT PROTOCOL NETWORK                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ User A's PDS │  │ User B's PDS │  │ User C's PDS │          │
│  │ - posts      │  │ - posts      │  │ - posts      │          │
│  │ - profile    │  │ - profile    │  │ - profile    │          │
│  │ - settings   │  │ - settings   │  │ - settings   │          │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘          │
│         │                 │                 │                   │
│         └─────────────────┼─────────────────┘                   │
│                           │                                     │
│  ┌────────────────────────┴────────────────────────┐           │
│  │           FORUM-MANAGED PDS                      │           │
│  │  - forum structure (categories, subforums)       │           │
│  │  - forum config (themes, settings)               │           │
│  │  - permissions/ACL templates                     │           │
│  │  - labeler identity (moderation)                 │           │
│  └────────────────────────┬────────────────────────┘           │
│                           │                                     │
│                           ▼                                     │
│              PUBLIC RELAY (bsky.network)                        │
│              Firehose: com.atproto.sync.subscribeRepos          │
└─────────────────────────────────────────────────────────────────┘
                        │
                        ▼ WebSocket (filter: net.vza.forum.*)
┌─────────────────────────────────────────────────────────────────┐
│                    SYNC SERVICE (PHP)                           │
├─────────────────────────────────────────────────────────────────┤
│  Firehose Client → Event Processor → Database Writer            │
│  Label Subscriber → Label Processor → Post Visibility           │
│  Write Queue (retry failed PDS writes)                          │
│  Config Sync (forum PDS ↔ local cache)                          │
└─────────────────────────────────────────────────────────────────┘
                        │
                        ▼ MySQL connection (cache layer)
┌─────────────────────────────────────────────────────────────────┐
│                    phpBB APPLICATION                            │
├─────────────────────────────────────────────────────────────────┤
│  phpBB Core (unmodified)                                        │
│  AT Proto Extension:                                            │
│    - Auth Provider (DID login)                                  │
│    - Write Interceptor (post → user's PDS)                      │
│    - Config Interceptor (admin changes → forum PDS)             │
│    - Label Display (hide/warn posts based on Sync Service)      │
│  MySQL: phpbb_* tables (local cache of AT Protocol data)        │
└─────────────────────────────────────────────────────────────────┘
```

### Data Ownership Model

| Data Type | Primary Location | Synced To |
|-----------|------------------|-----------|
| Posts, topics | User's PDS | Local MySQL cache |
| User profile (name, avatar, bio) | User's PDS | Local cache |
| User settings (notifications, preferences) | User's PDS | Local cache |
| Forum structure (categories, subforums) | Forum PDS | Local cache |
| Forum config (themes, global settings) | Forum PDS | Local cache |
| Permissions/ACL | Forum PDS | Local cache (enforced at AppView) |
| Moderation labels | Forum PDS (labeler) | Local cache |
| Private messages | User's PDS | Local cache (future E2EE) |

### Key Data Flows

**Write Path - User Post**:
1. User submits post via phpBB UI
2. Extension intercepts `core.posting_modify_submit_post_before`
3. Extension writes to **user's PDS** via `com.atproto.repo.createRecord`
4. On success, phpBB saves to local cache + stores URI mapping
5. On failure, queue for retry (post appears locally with "syncing..." status)

**Write Path - Admin Config**:
1. Admin changes forum structure in ACP
2. Extension intercepts config change event
3. Extension writes to **forum PDS**
4. Local cache updated

**Read Path - New Post from Network**:
1. Sync Service receives event from relay firehose
2. Filters for `net.vza.forum.*` collections
3. Maps author DID → phpBB user_id (creates user if new)
4. Inserts into `phpbb_posts` + `phpbb_atproto_posts`

**Read Path - Forum Config**:
1. Sync Service subscribes to forum PDS repo
2. Config changes propagate to local cache
3. Multiple forum instances can sync from same forum PDS

---

## Phase 1: phpBB Data Model Exploration ✓

### Objective
Document phpBB's schema and classify data by ownership pattern for AT Protocol mapping.

### Tasks
- [x] Deploy phpBB + MySQL via Docker
- [x] Document all tables and relationships
- [x] Classify data ownership:
  - **User-owned** → lives on user's PDS
  - **Forum-owned** → lives on forum-managed PDS
  - **Cache-only** → derived data in local MySQL

### Key Tables (from research)

| Category | Tables | AT Proto Location |
|----------|--------|-------------------|
| Posts | `phpbb_posts`, `phpbb_topics` | User's PDS |
| User profile | `phpbb_users`, `phpbb_profile_fields_data` | User's PDS |
| User settings | notification prefs, display options | User's PDS |
| Forums | `phpbb_forums`, `phpbb_categories` | Forum PDS |
| Config | `phpbb_config`, `phpbb_config_text` | Forum PDS |
| Permissions | `phpbb_acl_*`, `phpbb_forums_access` | Forum PDS |
| Private Messages | `phpbb_privmsgs` | User's PDS (future E2EE) |
| Derived data | post counts, last visit, search index | Local cache only |

### Deliverables
- `docs/schema-analysis.md` - Complete schema documentation ✓
- `docs/data-ownership.md` - Ownership classification ✓
- `docker/` - Docker Compose setup for exploration ✓

---

## Phase 2: AT Protocol Mapping

### Objective
Design lexicons and specify how phpBB data maps to AT Protocol primitives.

### Custom Lexicons

**Namespace**: `net.vza.forum.*`

```
# User PDS collections
net.vza.forum.post       - Forum posts (text, reply refs, embeds)
net.vza.forum.topic      - Topic metadata (title, forum ref, first post ref)
net.vza.forum.reaction   - Likes/reactions to posts
net.vza.forum.settings   - User's forum preferences

# Forum PDS collections
net.vza.forum.category   - Forum categories
net.vza.forum.board      - Individual forums/subforums
net.vza.forum.config     - Global forum configuration
net.vza.forum.acl        - Permission templates
net.vza.forum.rank       - User rank definitions
```

### Post Record Structure

```json
{
  "$type": "net.vza.forum.post",
  "text": "Post content with BBCode",
  "createdAt": "2024-01-15T10:30:00Z",
  "forum": { "uri": "at://forum-did/net.vza.forum.board/general", "cid": "..." },
  "subject": "Topic title (for first post only)",
  "reply": {
    "root": { "uri": "at://author-did/net.vza.forum.post/...", "cid": "..." },
    "parent": { "uri": "at://author-did/net.vza.forum.post/...", "cid": "..." }
  }
}
```

### Forum Config Record Structure

```json
{
  "$type": "net.vza.forum.board",
  "name": "General Discussion",
  "description": "Talk about anything",
  "slug": "general",
  "order": 1,
  "parent": { "uri": "at://forum-did/net.vza.forum.category/main", "cid": "..." },
  "settings": {
    "allowPolls": true,
    "requireApproval": false
  }
}
```

### Key Mapping Decisions

| phpBB Concept | AT Protocol Approach |
|---------------|---------------------|
| Post editing | User updates record on their PDS; Sync Service updates local cache |
| Post deletion | User deletes from PDS; Sync Service marks local as deleted |
| Forum creation | Admin creates on forum PDS; Sync Service creates local |
| Permission change | Admin updates ACL on forum PDS; Sync Service syncs |
| Post counts | Derived in local DB (not on AT Protocol) |
| Search | Local index built from firehose data |

### Moderation Flow (Detailed)

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODERATION ARCHITECTURE                       │
└─────────────────────────────────────────────────────────────────┘

SCENARIO: Moderator disapproves a post

1. PHPBB MCP (Moderator Control Panel)
   ┌────────────────────────────────────┐
   │ Moderator clicks "Disapprove Post" │
   │ in standard phpBB MCP interface    │
   └──────────────┬─────────────────────┘
                  │
                  ▼
2. EXTENSION INTERCEPTS
   ┌────────────────────────────────────┐
   │ Hook: core.mcp_post_approve        │
   │ - Lookup post's AT URI             │
   │ - Call Ozone labeler API           │
   └──────────────┬─────────────────────┘
                  │
                  ▼
3. OZONE LABELER (on Forum PDS)
   ┌────────────────────────────────────┐
   │ POST /xrpc/tools.ozone.moderation. │
   │       emitEvent                    │
   │ {                                  │
   │   subject: { uri, cid },           │
   │   createLabelVals: ["!hide"],      │
   │   createdBy: moderator-did         │
   │ }                                  │
   └──────────────┬─────────────────────┘
                  │
                  ▼
4. LABEL PROPAGATES VIA RELAY
   ┌────────────────────────────────────┐
   │ Ozone publishes label to repo      │
   │ Relay includes in firehose         │
   │ Label event: { subject, val }      │
   └──────────────┬─────────────────────┘
                  │
                  ▼
5. SYNC SERVICE RECEIVES LABEL
   ┌────────────────────────────────────┐
   │ Filter: com.atproto.label.*        │
   │ Match subject URI → post_id        │
   │ Store in phpbb_atproto_labels      │
   │ Update post visibility in cache    │
   └──────────────┬─────────────────────┘
                  │
                  ▼
6. PHPBB DISPLAYS FILTERED VIEW
   ┌────────────────────────────────────┐
   │ viewtopic.php queries posts        │
   │ Extension joins with labels table  │
   │ Posts with !hide label excluded    │
   │ Posts with !warn show warning      │
   └────────────────────────────────────┘

LABEL TYPES:
  !hide     - Post hidden from all users
  !warn     - Post shown with content warning
  spam      - Marked as spam (filterable)
  nsfw      - Adult content (user preference)
  spoiler   - Spoiler content (expandable)

MODERATOR ACTIONS MAPPING:
  phpBB Action          → AT Protocol Label
  ─────────────────────────────────────────
  Disapprove post       → !hide
  Soft delete           → !hide
  Mark as spam          → spam
  Lock topic            → (forum PDS config update)
  Move topic            → (user re-publishes with new forum ref)
  Edit post (as mod)    → (not possible - user owns data)
```

### Deliverables
- [ ] `lexicons/net.vza.forum.post.json`
- [ ] `lexicons/net.vza.forum.board.json`
- [ ] `lexicons/net.vza.forum.config.json`
- [ ] `docs/data-mapping.md`
- [ ] `docs/moderation-flow.md`

---

## Phase 3: Implementation Effort Analysis

### Objective
Break down components and estimate complexity.

### Components Required

| Component | Language | Complexity | Purpose |
|-----------|----------|------------|---------|
| phpBB Extension | PHP | Medium | Auth, write intercept, label display |
| Sync Service | PHP | High | Firehose subscription, DB sync |
| Ozone Setup | Config | Medium | Labeler for moderation |
| Database Migrations | SQL | Low | Mapping tables |
| Forum PDS | Hosted PDS | Low | Config storage |

### Sync Service (PHP)

The Sync Service runs as a PHP daemon alongside phpBB, using the same codebase patterns.

**Firehose client options**:
- Use `amphp/websocket-client` for async WebSocket
- Or Jetstream JSON endpoint: `https://jetstream2.us-east.bsky.network/subscribe`
- Cursor persistence in `phpbb_atproto_cursors`

**Structure**:
```
sync-service/
├── bin/
│   └── sync-daemon.php      # Entry point (long-running)
├── src/
│   ├── Firehose/
│   │   ├── Client.php       # WebSocket connection
│   │   ├── Processor.php    # Event processing
│   │   └── Filter.php       # Collection filtering
│   ├── Database/
│   │   ├── PostWriter.php   # Insert/update posts
│   │   └── UriMapper.php    # AT URI ↔ phpBB ID
│   ├── Labels/
│   │   ├── Subscriber.php   # Label firehose
│   │   └── Processor.php    # Apply to posts
│   └── Config/
│       └── ForumSync.php    # Forum PDS sync
└── composer.json
```

### phpBB Extension Scope

**Events to hook**:
- `core.posting_modify_submit_post_before` - Intercept writes → user PDS
- `core.posting_modify_submit_post_after` - Store URI mapping
- `core.viewtopic_modify_post_row` - Apply label visibility
- `core.mcp_post_approve` - Forward mod actions to Ozone
- `core.acp_board_config_edit_add` - Config changes → forum PDS
- `core.user_setup_after` - Check token validity

**Custom services**:
- `pds_client` - PDS API communication
- `token_manager` - JWT token refresh
- `uri_mapper` - AT URI ↔ phpBB ID mapping
- `forum_pds_client` - Forum PDS specific operations

**Custom tables**:
```sql
phpbb_atproto_users   -- DID ↔ user_id mapping + tokens
phpbb_atproto_posts   -- AT URI ↔ post_id mapping
phpbb_atproto_labels  -- Cached moderation labels
phpbb_atproto_cursors -- Firehose cursor tracking
phpbb_atproto_queue   -- Retry queue for failed writes
```

### Infrastructure Requirements
- MySQL (existing phpBB)
- PHP CLI for Sync Service daemon
- Forum PDS (Bluesky hosting or self-hosted)
- Supervisor/systemd to manage Sync Service

### Deliverables
- [ ] `docs/architecture.md` - Component diagrams
- [ ] `docs/api-contracts.md` - Interface definitions
- [ ] `docs/risks.md` - Risk assessment

---

## Phase 4: Full Specification for Agent Swarm

### Objective
Produce specs detailed enough for multi-agent implementation with minimal human intervention.

### Specification Structure

```
specs/
├── plan.md                    # This plan document
├── lexicons/
│   ├── net.vza.forum.post.json
│   ├── net.vza.forum.topic.json
│   ├── net.vza.forum.board.json
│   ├── net.vza.forum.config.json
│   └── net.vza.forum.acl.json
├── components/
│   ├── phpbb-extension/
│   │   ├── auth-provider.md
│   │   ├── write-interceptor.md
│   │   ├── config-interceptor.md
│   │   ├── label-display.md
│   │   └── migrations.md
│   ├── sync-service/
│   │   ├── firehose-client.md
│   │   ├── event-processor.md
│   │   ├── database-writer.md
│   │   ├── label-subscriber.md
│   │   └── forum-config-sync.md
│   └── moderation/
│       ├── ozone-setup.md
│       └── mcp-integration.md
├── testing/
│   ├── unit-tests.md
│   ├── integration-tests.md
│   └── e2e-scenarios.md
└── deployment/
    ├── docker-compose.yml
    └── infrastructure.md
```

### Agent Task Breakdown

1. **Lexicon agents** - Define and validate JSON schemas
2. **Extension agents** - One per phpBB extension component
3. **Sync Service agents** - One per module (PHP)
4. **Moderation agents** - Ozone setup and MCP integration
5. **Testing agents** - Generate test cases
6. **Documentation agents** - User/developer docs

### Deliverables
- [ ] Complete specification documents
- [ ] Agent task definitions with dependencies
- [ ] Acceptance criteria per component
- [ ] Test scenarios

---

## Implementation Roadmap

| Phase | Focus | Deliverables |
|-------|-------|-------------|
| **1. Foundation** | Lexicons, extension skeleton, migrations | Working auth flow |
| **2. Write Path** | Post creation → user PDS, token management | Posts appear on PDS |
| **3. Forum PDS** | Forum config on AT Protocol, admin sync | Config on AT Protocol |
| **4. Sync Service** | Firehose client, event processing (PHP) | External posts appear |
| **5. Moderation** | Ozone setup, label subscriber, MCP integration | Moderation working |
| **6. Polish** | Admin UI, error handling, docs | Production-ready |

---

## Open Questions (Resolved)

| Question | Decision |
|----------|----------|
| Implementation approach | **Hybrid AppView** - phpBB UI + Sync Service |
| Moderation model | **Labels only** - via Ozone, filtered by Sync Service |
| Relay infrastructure | **Public relays** - Use Bluesky's |
| Offline PDS handling | **Cache in AppView** - Posts remain available |
| Forum config location | **Forum-managed PDS** - Synced like user data |
| User settings location | **User's PDS** - Primary copy, cached locally |
| Lexicon namespace | **net.vza.forum.*** |
| Sync Service language | **PHP** - Match phpBB stack |

---

## Next Steps

1. ✓ **Phase 1 execution**: Set up Docker environment, explore phpBB schema hands-on
2. [ ] **Create lexicon drafts**: Define `net.vza.forum.*` schemas
3. [ ] **Prototype auth flow**: Test AT Protocol authentication with phpBB
4. [ ] **Set up forum PDS**: Create PDS for forum config storage
5. [ ] **Document findings**: Commit exploration results to repo

---

## References

### phpBB
- [phpBB 3.3.x DBAL Documentation](https://area51.phpbb.com/docs/dev/3.3.x/db/dbal.html)
- [phpBB Extension Development](https://area51.phpbb.com/docs/dev/3.3.x/extensions/)
- [phpBB Authentication Tutorial](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_authentication.html)

### AT Protocol
- [AT Protocol Specs](https://atproto.com/specs)
- [Bluesky Federation Architecture](https://docs.bsky.app/docs/advanced-guides/federation-architecture)
- [Bluesky Firehose](https://docs.bsky.app/docs/advanced-guides/firehose)
- [Custom Lexicons](https://docs.bsky.app/docs/advanced-guides/custom-schemas)
- [Ozone Moderation](https://github.com/bluesky-social/ozone)

### Related Projects
- [Tinychat](https://github.com/callmephilip/tinychat-at-proto) - Group chat on atproto
- [Roomy](https://blog.muni.town/roomy-deep-dive/) - P2P messaging with CRDTs
