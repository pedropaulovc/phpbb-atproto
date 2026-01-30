# phpBB AT Protocol Architecture

## System Overview

phpBB operates as a **Hybrid AppView** on the AT Protocol network. The MySQL database serves as a local cache while all canonical data lives on AT Protocol Personal Data Servers (PDSes).

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
│  │ - votes      │  │ - votes      │  │ - votes      │          │
│  │ - bookmarks  │  │ - bookmarks  │  │ - bookmarks  │          │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘          │
│         │                 │                 │                   │
│         └─────────────────┼─────────────────┘                   │
│                           │                                     │
│  ┌────────────────────────┴────────────────────────┐           │
│  │           FORUM-MANAGED PDS                      │           │
│  │  - board definitions (net.vza.forum.board)       │           │
│  │  - forum config (net.vza.forum.config)           │           │
│  │  - ACL/permissions (net.vza.forum.acl)           │           │
│  │  - group memberships (net.vza.forum.membership)  │           │
│  │  - ban list (net.vza.forum.ban)                  │           │
│  │  - labeler identity (Ozone moderation)           │           │
│  └────────────────────────┬────────────────────────┘           │
│                           │                                     │
│                           ▼                                     │
│              PUBLIC RELAY (bsky.network)                        │
│              Firehose: com.atproto.sync.subscribeRepos          │
│              Format: CBOR-encoded DAG blocks                    │
└─────────────────────────────────────────────────────────────────┘
                           │
                           │ WebSocket + CBOR
                           │ Filter: net.vza.forum.*
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                    SYNC SERVICE (PHP Docker Container)          │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────┐    ┌─────────────────┐                    │
│  │ Firehose Client │───▶│ Event Processor │                    │
│  │ (WebSocket+CBOR)│    │ (filter, decode)│                    │
│  └─────────────────┘    └────────┬────────┘                    │
│                                  │                              │
│                                  ▼                              │
│  ┌─────────────────┐    ┌─────────────────┐                    │
│  │ Label Subscriber│───▶│ Database Writer │                    │
│  │ (Ozone labels)  │    │ (MySQL ops)     │                    │
│  └─────────────────┘    └─────────────────┘                    │
│                                                                 │
│  ┌─────────────────┐    ┌─────────────────┐                    │
│  │ Write Queue     │    │ Config Sync     │                    │
│  │ (retry failed)  │    │ (forum PDS)     │                    │
│  └─────────────────┘    └─────────────────┘                    │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
                           │
                           │ MySQL connection
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                    phpBB APPLICATION                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  phpBB Core (unmodified)                                        │
│  ├── viewforum.php, viewtopic.php, posting.php                 │
│  ├── ACP (Admin Control Panel)                                 │
│  └── MCP (Moderator Control Panel)                             │
│                                                                 │
│  AT Proto Extension                                             │
│  ├── Auth Provider (DID login via OAuth)                       │
│  ├── Write Interceptor (post → user's PDS)                     │
│  ├── Config Interceptor (admin → forum PDS)                    │
│  ├── Label Display (visibility filtering)                      │
│  └── Token Manager (OAuth refresh)                             │
│                                                                 │
│  MySQL Database                                                 │
│  ├── phpbb_* tables (standard phpBB - local cache)             │
│  └── phpbb_atproto_* tables (mapping + state)                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Components

### phpBB Extension

The extension integrates AT Protocol into phpBB without modifying core files.

#### Auth Provider

Handles DID-based authentication using AT Protocol OAuth.

**Responsibilities**:
- OAuth flow initiation and callback handling
- DID resolution and verification
- Token storage (encrypted) and refresh
- Session binding to DID

**Events hooked**:
- `core.user_setup_after` - Check token validity
- `core.session_create_after` - Bind session to DID
- `core.logout_after` - Clear tokens

#### Write Interceptor

Intercepts phpBB write operations and forwards to user's PDS.

**Responsibilities**:
- Convert phpBB post data to `net.vza.forum.post` records
- Create records on user's PDS via `com.atproto.repo.createRecord`
- Handle update and delete operations
- Queue failed writes for retry
- Store URI mappings

**Events hooked**:
- `core.posting_modify_submit_post_before` - Intercept new posts
- `core.posting_modify_submit_post_after` - Store URI mapping
- `core.delete_post_after` - Delete from PDS

#### Config Interceptor

Forwards admin configuration changes to the forum PDS.

**Responsibilities**:
- Convert ACP changes to forum PDS records
- Update board definitions
- Sync ACL changes
- Manage forum config record

**Events hooked**:
- `core.acp_board_config_edit_add` - Board settings
- `core.acp_manage_forums_request_data` - Forum structure
- `core.acp_permissions_submit_after` - ACL updates

#### Label Display

Filters content based on moderation labels from Ozone.

**Responsibilities**:
- Join queries with label cache table
- Hide posts with `!hide` label
- Display warnings for `!warn` label
- Respect user label preferences

**Events hooked**:
- `core.viewtopic_modify_post_row` - Apply post visibility
- `core.viewforum_modify_topicrow` - Apply topic visibility

### Sync Service

Long-running PHP daemon that bridges AT Protocol network to local MySQL cache.

#### Firehose Client

Maintains WebSocket connection to the public relay firehose.

**Responsibilities**:
- Connect to `wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos`
- Handle CBOR-encoded messages
- Automatic reconnection with exponential backoff
- Cursor persistence for resumable sync

**Technology**:
- `amphp/websocket-client` for async WebSocket
- `clue/cbor-php` for CBOR decoding
- Cursor stored in `phpbb_atproto_cursors`

#### Event Processor

Filters and processes firehose events for forum-relevant data.

**Responsibilities**:
- Filter for `net.vza.forum.*` collections
- Decode AT Protocol records
- Route to appropriate handlers (create, update, delete)
- Validate record structure

**Processing flow**:
```
Firehose Message
    │
    ▼
Decode CBOR commit
    │
    ▼
For each operation:
    ├── collection = net.vza.forum.post? → PostWriter.handlePost()
    ├── collection = net.vza.forum.board? → ConfigSync.handleBoard()
    └── collection = net.vza.forum.acl? → ConfigSync.handleAcl()
```

#### Database Writer

Translates AT Protocol records to MySQL operations.

**Responsibilities**:
- Map DIDs to phpBB user IDs (create user if new)
- Insert/update/delete posts in `phpbb_posts`
- Maintain URI mappings in `phpbb_atproto_posts`
- Update derived statistics

**User resolution**:
1. Check `phpbb_atproto_users` for DID
2. If not found, resolve DID to get handle
3. Create phpBB user with handle as username
4. Store mapping

#### Label Subscriber

Receives moderation labels from Ozone labeler.

**Responsibilities**:
- Subscribe to `com.atproto.label.subscribeLabels`
- Filter labels for forum content (matching URIs)
- Store/negate labels in `phpbb_atproto_labels`
- Both direct subscription and firehose redundancy

**Label processing**:
```
Label Event
    │
    ▼
Match subject_uri to phpbb_atproto_posts?
    │
    ├── Yes: Store in phpbb_atproto_labels
    │        Update post visibility cache
    │
    └── No: Ignore (not our content)
```

### Forum PDS

A dedicated PDS account controlled by forum operators.

**Stores**:
- `net.vza.forum.board` - Board/subforum definitions
- `net.vza.forum.config/self` - Global forum configuration
- `net.vza.forum.acl/self` - Permission templates and assignments
- `net.vza.forum.membership` - Group membership records
- `net.vza.forum.ban` - Ban list

**Acts as**:
- Ozone labeler identity for moderation
- Source of truth for forum structure
- Configuration authority for multi-instance deployments

### External Dependencies

#### Public Relay (bsky.network)

**Purpose**: Aggregates and distributes AT Protocol events across the network.

**Interface**:
- Firehose: `wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos`
- Format: CBOR-encoded DAG-CBOR blocks

**Considerations**:
- Rate limiting may apply
- Connection may drop; cursor enables resumption
- For high-volume deployments, consider self-hosted relay

#### User PDSes

**Purpose**: Store user-owned data (posts, settings, votes).

**Providers**: Various (Bluesky, self-hosted, third-party)

**Interface**: Standard AT Protocol XRPC endpoints
- `com.atproto.repo.createRecord`
- `com.atproto.repo.putRecord`
- `com.atproto.repo.deleteRecord`
- `com.atproto.repo.uploadBlob`

---

## Data Flows

### User Creates Post

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. User submits post via phpBB                                  │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Extension intercepts (core.posting_modify_submit_post_before)│
│    - Build net.vza.forum.post record from form data             │
│    - Get user's access token from phpbb_atproto_users           │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Write to user's PDS                                          │
│    POST /xrpc/com.atproto.repo.createRecord                     │
│    {                                                            │
│      repo: user-did,                                            │
│      collection: "net.vza.forum.post",                          │
│      record: { text, forum, reply, createdAt, ... }             │
│    }                                                            │
└─────────────────────────────────────────────────────────────────┘
                           │
              ┌────────────┴────────────┐
              │                         │
          Success                    Failure
              │                         │
              ▼                         ▼
┌─────────────────────────┐  ┌─────────────────────────┐
│ 4a. Store URI mapping   │  │ 4b. Queue for retry     │
│ in phpbb_atproto_posts  │  │ Save locally as pending │
│ Allow phpBB to save     │  │ Show "syncing..." UI    │
│ locally (cache)         │  │                         │
└─────────────────────────┘  └─────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Post propagates via relay firehose                           │
│    Sync Service receives and confirms local cache is correct    │
└─────────────────────────────────────────────────────────────────┘
```

### Admin Changes Forum Config

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Admin updates forum structure in ACP                         │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Extension intercepts (core.acp_manage_forums_request_data)   │
│    - Build net.vza.forum.board record                           │
│    - Get forum PDS credentials                                  │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Write to forum PDS (with optimistic locking)                 │
│    POST /xrpc/com.atproto.repo.putRecord                        │
│    {                                                            │
│      repo: forum-did,                                           │
│      collection: "net.vza.forum.board",                         │
│      rkey: <tid>,  // TID key, not slug                         │
│      record: { name, slug, description, settings, ... },        │
│      swapRecord: <expected-cid>  // Conflict detection          │
│    }                                                            │
│                                                                 │
│    On CID mismatch: present conflict UI to admin                │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Update local cache, store URI in phpbb_atproto_forums        │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Change propagates via firehose to other instances            │
│    (if multi-instance deployment)                               │
└─────────────────────────────────────────────────────────────────┘
```

### External Post Arrives via Firehose

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. User posts via another client (e.g., custom AT Proto app)   │
│    Post appears in public relay firehose                        │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Sync Service receives via WebSocket                          │
│    Decode CBOR commit message                                   │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Filter: collection = net.vza.forum.post?                     │
│    Yes → continue processing                                    │
│    No → ignore                                                  │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Resolve author DID to phpBB user                             │
│    - Check phpbb_atproto_users                                  │
│    - If not found: resolve DID, create user, store mapping      │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Resolve forum reference to phpBB forum_id                    │
│    - Check phpbb_atproto_forums for forum.uri                   │
│    - If not found: reject or queue for later                    │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. Insert into local cache                                      │
│    - INSERT into phpbb_posts                                    │
│    - INSERT into phpbb_atproto_posts (URI mapping)              │
│    - Update topic if reply, or create topic if new              │
│    - Update derived counts (user_posts, forum_posts, etc.)      │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 7. Persist cursor position for resumability                     │
└─────────────────────────────────────────────────────────────────┘
```

### Moderator Applies Label

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Moderator clicks "Disapprove" in MCP                         │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. Extension intercepts (core.mcp_post_approve)                 │
│    - Lookup post's AT URI and CID from phpbb_atproto_posts      │
│    - Verify moderator has Ozone team membership                 │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. Call Ozone labeler API                                       │
│    POST /xrpc/tools.ozone.moderation.emitEvent                  │
│    {                                                            │
│      event: {                                                   │
│        $type: "tools.ozone.moderation.defs#modEventLabel",      │
│        createLabelVals: ["!hide"]                               │
│      },                                                         │
│      subject: {                                                 │
│        $type: "com.atproto.repo.strongRef",                     │
│        uri: "at://did:plc:author/net.vza.forum.post/...",       │
│        cid: "bafyreid..."                                       │
│      },                                                         │
│      createdBy: moderator-did                                   │
│    }                                                            │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. Ozone creates label record, publishes to its repo            │
│    Label propagates via relay firehose                          │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Sync Service receives label (dual path: direct + firehose)   │
│    - Match subject_uri to local post                            │
│    - Store in phpbb_atproto_labels                              │
└─────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│ 6. phpBB queries exclude posts with !hide label                 │
│    SELECT ... LEFT JOIN phpbb_atproto_labels ...                │
│    WHERE label.id IS NULL OR label.value != '!hide'             │
└─────────────────────────────────────────────────────────────────┘
```

---

## Deployment Topology

### Single-Instance Deployment

Simplest deployment for small to medium forums.

```
┌─────────────────────────────────────────────────────────┐
│                    Docker Host                          │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │            docker-compose.yml                    │   │
│  ├─────────────────────────────────────────────────┤   │
│  │                                                  │   │
│  │  ┌─────────────┐  ┌─────────────┐              │   │
│  │  │   phpbb     │  │   mysql     │              │   │
│  │  │  (Apache)   │◀─┤  (MySQL 8)  │              │   │
│  │  │  Port 80    │  │  Port 3306  │              │   │
│  │  └─────────────┘  └─────────────┘              │   │
│  │         │                │                      │   │
│  │         │                │                      │   │
│  │  ┌─────────────┐         │                      │   │
│  │  │sync-service │─────────┘                      │   │
│  │  │  (PHP CLI)  │                                │   │
│  │  │  Daemon     │◀──── WebSocket to relay       │   │
│  │  └─────────────┘                                │   │
│  │                                                  │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

**Components**:
- phpBB container: Apache + PHP-FPM serving web UI
- MySQL container: Database storage
- Sync Service container: PHP CLI daemon

**Network**:
- Internal Docker network for MySQL access
- External port 80/443 for web traffic
- Outbound WebSocket to bsky.network

### Multi-Instance Deployment

For high availability or geographically distributed forums.

```
┌─────────────────────────────────────────────────────────────────┐
│                         SHARED FORUM PDS                         │
│                    (Source of truth for config)                  │
└───────────────────────────────┬─────────────────────────────────┘
                                │
           ┌────────────────────┼────────────────────┐
           │                    │                    │
           ▼                    ▼                    ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│   Instance A     │  │   Instance B     │  │   Instance C     │
│   (US-East)      │  │   (US-West)      │  │   (EU)           │
├──────────────────┤  ├──────────────────┤  ├──────────────────┤
│ phpBB + MySQL    │  │ phpBB + MySQL    │  │ phpBB + MySQL    │
│ Sync Service     │  │ Sync Service     │  │ Sync Service     │
│                  │  │                  │  │                  │
│ Local cache      │  │ Local cache      │  │ Local cache      │
│ (replicated via  │  │ (replicated via  │  │ (replicated via  │
│  firehose)       │  │  firehose)       │  │  firehose)       │
└──────────────────┘  └──────────────────┘  └──────────────────┘
           │                    │                    │
           └────────────────────┼────────────────────┘
                                │
                                ▼
                    ┌──────────────────────┐
                    │    PUBLIC RELAY      │
                    │   (bsky.network)     │
                    │                      │
                    │ Firehose broadcasts  │
                    │ all net.vza.forum.*  │
                    │ events to all        │
                    │ instances            │
                    └──────────────────────┘
```

**Characteristics**:
- All instances sync from same forum PDS for config
- Posts from any instance appear on all via firehose
- Each instance has independent MySQL (local cache)
- No direct instance-to-instance communication needed
- Load balancer can route users to nearest instance

**Consistency model**:
- Eventual consistency across instances
- Writes go to user's PDS (any instance)
- Firehose propagates to all instances
- Forum config changes from any admin propagate to all

**Conflict Resolution**:
- Admin config changes use `swapRecord` with expected CID
- On CID mismatch (concurrent edit), admin sees conflict UI
- Must manually resolve by reviewing both versions
- Prevents silent overwrites in multi-admin scenarios

---

## Technology Stack

### phpBB Extension

| Component | Technology | Version |
|-----------|------------|---------|
| Runtime | PHP | 8.4+ |
| Framework | phpBB Extension | 3.3.x |
| HTTP Client | Guzzle | 7.x |
| OAuth | Custom AT Protocol OAuth | - |
| Encryption | libsodium | PHP bundled |

### Sync Service

| Component | Technology | Version |
|-----------|------------|---------|
| Runtime | PHP | 8.4+ |
| Async Framework | amphp | 3.x |
| WebSocket | amphp/websocket-client | 2.x |
| CBOR | clue/cbor-php | 0.4+ |
| Database | PDO MySQL | PHP bundled |
| Process Manager | Supervisor or Docker | - |

### Database

| Component | Technology | Version |
|-----------|------------|---------|
| RDBMS | MySQL | 8.0+ |
| Connection | PDO | PHP bundled |

### Infrastructure

| Component | Technology |
|-----------|------------|
| Containerization | Docker |
| Orchestration | Docker Compose / Kubernetes |
| Web Server | Apache (phpBB container) |
| PDS Hosting | Bluesky or self-hosted |

---

## Docker Deployment

### Sync Service Container

**Base image**: `php:8.4-cli-alpine`

**Dockerfile** (`sync-service/Dockerfile`):
```dockerfile
FROM php:8.4-cli-alpine

# Install dependencies
RUN apk add --no-cache \
    libsodium-dev \
    && docker-php-ext-install pdo_mysql sodium

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy application code
COPY src/ src/
COPY bin/ bin/

# Health check
HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
    CMD php /app/bin/healthcheck.php || exit 1

# Run daemon
CMD ["php", "/app/bin/sync-daemon.php"]
```

**docker-compose.yml additions**:
```yaml
services:
  phpbb:
    image: phpbb/phpbb:latest
    ports:
      - "80:80"
    volumes:
      - phpbb_data:/var/www/html
    environment:
      - PHPBB_DB_HOST=mysql
      - PHPBB_DB_NAME=phpbb
      - PHPBB_DB_USER=phpbb
      - PHPBB_DB_PASSWORD=${MYSQL_PASSWORD}
    depends_on:
      - mysql

  mysql:
    image: mysql:8.0
    volumes:
      - mysql_data:/var/lib/mysql
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - MYSQL_DATABASE=phpbb
      - MYSQL_USER=phpbb
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}

  sync-service:
    build:
      context: ./sync-service
      dockerfile: Dockerfile
    restart: always
    stop_grace_period: 30s
    environment:
      - MYSQL_HOST=mysql
      - MYSQL_DATABASE=phpbb
      - MYSQL_USER=phpbb
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
      - RELAY_URL=wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos
      - FORUM_DID=${FORUM_DID}
      - FORUM_PDS_URL=${FORUM_PDS_URL}
      - TOKEN_ENCRYPTION_KEYS=${TOKEN_ENCRYPTION_KEYS}
      - TOKEN_ENCRYPTION_KEY_VERSION=${TOKEN_ENCRYPTION_KEY_VERSION}
    depends_on:
      - mysql
    deploy:
      resources:
        limits:
          memory: 256M
          cpus: '0.5'
        reservations:
          memory: 128M

volumes:
  phpbb_data:
  mysql_data:
```

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `MYSQL_HOST` | MySQL hostname | Yes |
| `MYSQL_DATABASE` | Database name | Yes |
| `MYSQL_USER` | Database username | Yes |
| `MYSQL_PASSWORD` | Database password | Yes |
| `RELAY_URL` | Firehose WebSocket URL | Yes |
| `FORUM_DID` | Forum PDS account DID | Yes |
| `FORUM_PDS_URL` | Forum PDS XRPC endpoint | Yes |
| `TOKEN_ENCRYPTION_KEYS` | JSON object of versioned encryption keys | Yes |
| `TOKEN_ENCRYPTION_KEY_VERSION` | Current key version to use | Yes |
| `LABELER_DID` | Ozone labeler DID (if different from forum) | No |
| `LOG_LEVEL` | Logging verbosity (debug, info, warn, error) | No |

### Health Check Endpoint

The Sync Service health check validates multiple aspects:

1. **State file freshness** - daemon is alive and updating state
2. **WebSocket connection** - firehose is connected
3. **Message recency** - messages are being received
4. **Cursor freshness** - database cursor is updating

The daemon writes state to `/tmp/sync-service-state.json` periodically:

```php
// Daemon writes state every 10 seconds
$state = [
    'websocket_connected' => $client->isConnected(),
    'last_message_at' => time(),
    'cursor' => $cursor->getValue(),
    'messages_processed' => $stats->getCount(),
    'pid' => getmypid(),
];
file_put_contents('/tmp/sync-service-state.json', json_encode($state));
```

Health check reads state file + validates database cursor:

```php
// bin/healthcheck.php
// Check 1: State file freshness (daemon alive)
// Check 2: WebSocket connected (from state)
// Check 3: Messages received recently (from state)
// Check 4: Database cursor freshness (authoritative)
// See api-contracts.md for full implementation
```

This multi-layered approach catches:
- Daemon crashes (state file stale)
- Connection drops (websocket_connected = false)
- Firehose stalls (no messages)
- Database issues (cursor not updating)

### Graceful Shutdown

The Sync Service handles SIGTERM for graceful shutdown:

```php
// Handle shutdown signals
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use ($client, $cursor) {
    $cursor->persist();
    $client->disconnect();
    exit(0);
});
```
