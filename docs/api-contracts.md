# API Contracts

This document defines database schemas, PHP service interfaces, and API contracts for the phpBB AT Protocol integration.

---

## Database Schemas

### phpbb_atproto_users

Maps DIDs to phpBB user IDs and stores OAuth tokens.

```sql
CREATE TABLE phpbb_atproto_users (
    user_id         INT UNSIGNED NOT NULL,
    did             VARCHAR(255) NOT NULL,
    handle          VARCHAR(255) DEFAULT NULL,
    pds_url         VARCHAR(512) NOT NULL,
    access_token    TEXT DEFAULT NULL,
    refresh_token   TEXT DEFAULT NULL,
    token_expires_at INT UNSIGNED DEFAULT NULL,
    migration_status ENUM('none', 'pending', 'complete', 'failed') DEFAULT 'none',
    created_at      INT UNSIGNED NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,

    PRIMARY KEY (user_id),
    UNIQUE KEY idx_did (did),
    KEY idx_handle (handle),
    KEY idx_token_expires (token_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes**:
- `access_token` and `refresh_token` are encrypted with XChaCha20-Poly1305
- Token format: `base64(nonce || ciphertext || tag)`
- `migration_status` tracks historical post migration state

### phpbb_atproto_posts

Maps AT URIs to phpBB post IDs.

```sql
CREATE TABLE phpbb_atproto_posts (
    post_id         INT UNSIGNED NOT NULL,
    at_uri          VARCHAR(512) NOT NULL,
    at_cid          VARCHAR(64) NOT NULL,
    author_did      VARCHAR(255) NOT NULL,
    is_topic_starter TINYINT(1) DEFAULT 0,
    sync_status     ENUM('synced', 'pending', 'failed') DEFAULT 'synced',
    created_at      INT UNSIGNED NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,

    PRIMARY KEY (post_id),
    UNIQUE KEY idx_at_uri (at_uri),
    KEY idx_author_did (author_did),
    KEY idx_sync_status (sync_status),
    KEY idx_at_cid (at_cid),
    KEY idx_topic_starter (is_topic_starter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes**:
- `is_topic_starter = 1` indicates this post creates a topic (has `subject` field)
- Topic AT URI = first post's AT URI (no separate topic records)
- Topic title lives in the first post's `subject` field

### phpbb_atproto_forums

Maps AT URIs to phpBB forum IDs with slug-based routing.

```sql
CREATE TABLE phpbb_atproto_forums (
    forum_id        INT UNSIGNED NOT NULL,
    at_uri          VARCHAR(512) NOT NULL,
    at_cid          VARCHAR(64) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,

    PRIMARY KEY (forum_id),
    UNIQUE KEY idx_at_uri (at_uri),
    KEY idx_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes**:
- `slug` is mutable (updated when forum is renamed)
- AT URI uses TID key (immutable) for stable identity
- Slug enables human-readable URL routing in phpBB

### phpbb_atproto_labels

Caches moderation labels from the Ozone labeler.

```sql
CREATE TABLE phpbb_atproto_labels (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_uri     VARCHAR(512) NOT NULL,
    subject_cid     VARCHAR(64) DEFAULT NULL,
    label_value     VARCHAR(128) NOT NULL,
    label_src       VARCHAR(255) NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    negated         TINYINT(1) DEFAULT 0,
    negated_at      INT UNSIGNED DEFAULT NULL,
    expires_at      INT UNSIGNED DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_subject_uri (subject_uri),
    KEY idx_label_value (label_value),
    KEY idx_label_src (label_src),
    KEY idx_negated (negated),
    KEY idx_expires_at (expires_at),
    UNIQUE KEY idx_unique_label (subject_uri, label_value, label_src)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Label query pattern**:
```sql
-- Get active labels for a subject
SELECT label_value, label_src, created_at
FROM phpbb_atproto_labels
WHERE subject_uri = ?
  AND negated = 0
  AND (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP());
```

### phpbb_atproto_cursors

Tracks firehose cursor positions for resumable sync.

```sql
CREATE TABLE phpbb_atproto_cursors (
    service         VARCHAR(255) NOT NULL,
    cursor_value    BIGINT UNSIGNED NOT NULL,
    updated_at      INT UNSIGNED NOT NULL,

    PRIMARY KEY (service)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Services**:
- `firehose` - Main repo subscription cursor
- `labels` - Label subscription cursor

### phpbb_atproto_queue

Retry queue for failed PDS writes.

```sql
CREATE TABLE phpbb_atproto_queue (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation       ENUM('create', 'update', 'delete') NOT NULL,
    collection      VARCHAR(255) NOT NULL,
    rkey            VARCHAR(255) DEFAULT NULL,
    record_data     TEXT DEFAULT NULL,
    user_did        VARCHAR(255) NOT NULL,
    local_id        INT UNSIGNED DEFAULT NULL,
    attempts        INT UNSIGNED DEFAULT 0,
    max_attempts    INT UNSIGNED DEFAULT 5,
    last_error      TEXT DEFAULT NULL,
    next_retry_at   INT UNSIGNED NOT NULL,
    created_at      INT UNSIGNED NOT NULL,
    status          ENUM('pending', 'processing', 'dead_letter') DEFAULT 'pending',

    PRIMARY KEY (id),
    KEY idx_next_retry (next_retry_at, status),
    KEY idx_user_did (user_did),
    KEY idx_status (status),
    KEY idx_local_id (local_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Retry backoff schedule**:
| Attempt | Delay |
|---------|-------|
| 1 | 15 seconds |
| 2 | 30 seconds |
| 3 | 60 seconds |
| 4 | 5 minutes |
| 5 | 15 minutes |
| >5 | Move to dead_letter |

### phpbb_atproto_did_cache

Caches DID document resolutions.

```sql
CREATE TABLE phpbb_atproto_did_cache (
    did             VARCHAR(255) NOT NULL,
    handle          VARCHAR(255) DEFAULT NULL,
    pds_url         VARCHAR(512) DEFAULT NULL,
    signing_key     TEXT DEFAULT NULL,
    document_json   TEXT NOT NULL,
    cached_at       INT UNSIGNED NOT NULL,
    expires_at      INT UNSIGNED NOT NULL,

    PRIMARY KEY (did),
    KEY idx_handle (handle),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**TTL**: 1 hour (3600 seconds)

---

## Extension Service Interfaces (PHP)

### pds_client

Communicates with AT Protocol PDSes for record operations.

```php
<?php

namespace phpbb\atproto\services;

use phpbb\atproto\dto\BlobRef;
use phpbb\atproto\dto\CreateRecordResult;
use phpbb\atproto\dto\PutRecordResult;

interface PdsClientInterface
{
    /**
     * Create a new record in a user's repository.
     *
     * @param string $did User's DID
     * @param string $collection Lexicon collection (e.g., 'net.vza.forum.post')
     * @param array $record Record data matching lexicon schema
     * @param string|null $rkey Optional record key (auto-generated if null)
     * @return CreateRecordResult Contains uri and cid
     * @throws PdsUnavailableException When PDS is unreachable
     * @throws RateLimitedException When rate limited by PDS
     * @throws InvalidRecordException When record fails validation
     */
    public function createRecord(
        string $did,
        string $collection,
        array $record,
        ?string $rkey = null
    ): CreateRecordResult;

    /**
     * Update an existing record (or create if doesn't exist).
     *
     * @param string $did User's DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param array $record Updated record data
     * @param string|null $swapRecord Expected CID for conditional update
     * @return PutRecordResult Contains uri and cid
     * @throws CidMismatchException When swapRecord CID doesn't match
     */
    public function putRecord(
        string $did,
        string $collection,
        string $rkey,
        array $record,
        ?string $swapRecord = null
    ): PutRecordResult;

    /**
     * Delete a record from a user's repository.
     *
     * @param string $did User's DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param string|null $swapRecord Expected CID for conditional delete
     * @throws RecordNotFoundException When record doesn't exist
     */
    public function deleteRecord(
        string $did,
        string $collection,
        string $rkey,
        ?string $swapRecord = null
    ): void;

    /**
     * Upload a blob (attachment, image) to user's PDS.
     *
     * @param string $did User's DID
     * @param string $data Binary blob data
     * @param string $mimeType MIME type of the blob
     * @return BlobRef Reference to use in records
     * @throws BlobTooLargeException When blob exceeds PDS limit
     */
    public function uploadBlob(
        string $did,
        string $data,
        string $mimeType
    ): BlobRef;

    /**
     * Get a record from a repository.
     *
     * @param string $did Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @return array Record data with uri and cid
     * @throws RecordNotFoundException When record doesn't exist
     */
    public function getRecord(
        string $did,
        string $collection,
        string $rkey
    ): array;
}
```

### token_manager

Manages OAuth tokens for AT Protocol authentication.

```php
<?php

namespace phpbb\atproto\services;

interface TokenManagerInterface
{
    /**
     * Get a valid access token for a user, refreshing if necessary.
     *
     * @param int $userId phpBB user ID
     * @return string Valid access token (JWT)
     * @throws TokenNotFoundException When user has no tokens
     * @throws TokenRefreshFailedException When refresh fails
     */
    public function getAccessToken(int $userId): string;

    /**
     * Force refresh the access token using the refresh token.
     *
     * @param int $userId phpBB user ID
     * @return string New access token
     * @throws TokenRefreshFailedException When refresh fails
     */
    public function refreshToken(int $userId): string;

    /**
     * Store tokens for a user after OAuth flow.
     *
     * @param int $userId phpBB user ID
     * @param string $accessToken Access token (will be encrypted)
     * @param string $refreshToken Refresh token (will be encrypted)
     * @param int $expiresAt Unix timestamp when access token expires
     */
    public function storeTokens(
        int $userId,
        string $accessToken,
        string $refreshToken,
        int $expiresAt
    ): void;

    /**
     * Check if user has a valid (non-expired) token.
     *
     * @param int $userId phpBB user ID
     * @return bool True if token exists and isn't expired
     */
    public function isTokenValid(int $userId): bool;

    /**
     * Clear all tokens for a user (logout).
     *
     * @param int $userId phpBB user ID
     */
    public function clearTokens(int $userId): void;

    /**
     * Get the DID associated with a user's tokens.
     *
     * @param int $userId phpBB user ID
     * @return string|null User's DID or null if not linked
     */
    public function getUserDid(int $userId): ?string;
}
```

### uri_mapper

Maps between AT Protocol URIs and phpBB IDs.

```php
<?php

namespace phpbb\atproto\services;

interface UriMapperInterface
{
    /**
     * Get AT URI for a phpBB post.
     *
     * @param int $postId phpBB post ID
     * @return string|null AT URI or null if not mapped
     */
    public function getAtUri(int $postId): ?string;

    /**
     * Get phpBB post ID for an AT URI.
     *
     * @param string $atUri AT Protocol URI
     * @return int|null phpBB post ID or null if not mapped
     */
    public function getPostId(string $atUri): ?int;

    /**
     * Store a new post mapping.
     *
     * @param int $postId phpBB post ID
     * @param string $atUri AT Protocol URI
     * @param string $cid Content identifier
     * @param string $authorDid Author's DID
     */
    public function storeMapping(
        int $postId,
        string $atUri,
        string $cid,
        string $authorDid
    ): void;

    /**
     * Update the CID for an existing mapping.
     *
     * @param int $postId phpBB post ID
     * @param string $cid New content identifier
     */
    public function updateCid(int $postId, string $cid): void;

    /**
     * Get AT URI and CID for a phpBB post.
     *
     * @param int $postId phpBB post ID
     * @return array{uri: string, cid: string}|null
     */
    public function getStrongRef(int $postId): ?array;

    /**
     * Get forum AT URI for a phpBB forum.
     *
     * @param int $forumId phpBB forum ID
     * @return string|null AT URI or null if not mapped
     */
    public function getForumAtUri(int $forumId): ?string;

    /**
     * Get phpBB forum ID for an AT URI.
     *
     * @param string $atUri AT Protocol URI
     * @return int|null phpBB forum ID or null if not mapped
     */
    public function getForumId(string $atUri): ?int;
}
```

### forum_pds_client

Specialized client for forum PDS operations.

```php
<?php

namespace phpbb\atproto\services;

use phpbb\atproto\dto\BoardRecord;
use phpbb\atproto\dto\ConfigRecord;
use phpbb\atproto\dto\AclRecord;

interface ForumPdsClientInterface
{
    /**
     * Get global forum configuration.
     *
     * @return ConfigRecord Forum configuration
     */
    public function getConfig(): ConfigRecord;

    /**
     * Update global forum configuration.
     *
     * @param array $data Configuration data matching net.vza.forum.config schema
     */
    public function updateConfig(array $data): void;

    /**
     * Get a board/forum definition by AT URI.
     *
     * @param string $atUri Board AT URI (at://did/net.vza.forum.board/tid)
     * @return BoardRecord Board definition with uri and cid
     * @throws RecordNotFoundException When board doesn't exist
     */
    public function getBoard(string $atUri): BoardRecord;

    /**
     * Get a board/forum definition by slug.
     *
     * @param string $slug Board slug for routing lookup
     * @return BoardRecord Board definition with uri and cid
     * @throws RecordNotFoundException When board doesn't exist
     */
    public function getBoardBySlug(string $slug): BoardRecord;

    /**
     * Create a new board definition. Returns generated AT URI.
     *
     * @param array $data Board data matching net.vza.forum.board schema
     * @return array{uri: string, cid: string} Created record reference
     */
    public function createBoard(array $data): array;

    /**
     * Update a board definition with optimistic locking.
     *
     * @param string $atUri Board AT URI
     * @param array $data Board data matching net.vza.forum.board schema
     * @param string|null $expectedCid Expected CID for conflict detection (null to skip)
     * @return array{uri: string, cid: string} Updated record reference
     * @throws ConflictException When expectedCid doesn't match (concurrent modification)
     */
    public function updateBoard(string $atUri, array $data, ?string $expectedCid = null): array;

    /**
     * Delete a board.
     *
     * @param string $atUri Board AT URI
     */
    public function deleteBoard(string $atUri): void;

    /**
     * Get ACL/permissions record.
     *
     * @return AclRecord ACL configuration
     */
    public function getAcl(): AclRecord;

    /**
     * Update ACL/permissions.
     *
     * @param array $data ACL data matching net.vza.forum.acl schema
     */
    public function updateAcl(array $data): void;

    /**
     * Get forum PDS account DID.
     *
     * @return string Forum DID
     */
    public function getForumDid(): string;
}
```

### label_client

Emits and manages moderation labels via Ozone.

```php
<?php

namespace phpbb\atproto\services;

interface LabelClientInterface
{
    /**
     * Emit a label on a subject.
     *
     * @param string $subjectUri AT URI of the subject (post, user, etc.)
     * @param string $subjectCid CID of the subject record
     * @param array $labelVals Label values to apply (e.g., ['!hide'])
     * @param string|null $reason Optional reason for the label
     * @throws OzoneUnavailableException When Ozone is unreachable
     * @throws UnauthorizedLabelException When moderator lacks permission
     */
    public function emitLabel(
        string $subjectUri,
        string $subjectCid,
        array $labelVals,
        ?string $reason = null
    ): void;

    /**
     * Negate (remove) a label from a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @param array $labelVals Label values to negate
     */
    public function negateLabel(
        string $subjectUri,
        array $labelVals
    ): void;

    /**
     * Get all active labels for a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @return array Array of label records
     */
    public function getLabels(string $subjectUri): array;

    /**
     * Check if a subject has a specific label.
     *
     * @param string $subjectUri AT URI of the subject
     * @param string $labelVal Label value to check
     * @return bool True if label is active
     */
    public function hasLabel(string $subjectUri, string $labelVal): bool;
}
```

---

## Sync Service Interfaces (PHP)

### Firehose\Client

WebSocket client for AT Protocol firehose subscription.

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface ClientInterface
{
    /**
     * Connect to the firehose WebSocket.
     *
     * @param int|null $cursor Resume from this cursor position
     * @throws ConnectionFailedException When connection fails
     */
    public function connect(?int $cursor = null): void;

    /**
     * Disconnect from the firehose.
     */
    public function disconnect(): void;

    /**
     * Register a message handler callback.
     *
     * @param callable $callback Function(array $message): void
     */
    public function onMessage(callable $callback): void;

    /**
     * Register an error handler callback.
     *
     * @param callable $callback Function(\Throwable $error): void
     */
    public function onError(callable $callback): void;

    /**
     * Get the current cursor position.
     *
     * @return int|null Current cursor or null if not connected
     */
    public function getCursor(): ?int;

    /**
     * Check if connected to firehose.
     *
     * @return bool True if connected
     */
    public function isConnected(): bool;

    /**
     * Run the event loop (blocking).
     */
    public function run(): void;
}
```

### Firehose\Processor

Processes firehose commit messages.

```php
<?php

namespace phpbb\atproto\sync\Firehose;

interface ProcessorInterface
{
    /**
     * Process a repository commit event.
     *
     * @param array $commit Decoded commit message
     */
    public function processCommit(array $commit): void;

    /**
     * Handle a create operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param array $record Record data
     * @param string $cid Content identifier
     */
    public function handleCreate(
        string $repo,
        string $collection,
        string $rkey,
        array $record,
        string $cid
    ): void;

    /**
     * Handle an update operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     * @param array $record Updated record data
     * @param string $cid New content identifier
     */
    public function handleUpdate(
        string $repo,
        string $collection,
        string $rkey,
        array $record,
        string $cid
    ): void;

    /**
     * Handle a delete operation.
     *
     * @param string $repo Repository DID
     * @param string $collection Lexicon collection
     * @param string $rkey Record key
     */
    public function handleDelete(
        string $repo,
        string $collection,
        string $rkey
    ): void;

    /**
     * Check if a collection should be processed.
     *
     * @param string $collection Lexicon collection name
     * @return bool True if collection is relevant (net.vza.forum.*)
     */
    public function shouldProcess(string $collection): bool;
}
```

### Database\PostWriter

Writes posts to the local MySQL cache.

```php
<?php

namespace phpbb\atproto\sync\Database;

interface PostWriterInterface
{
    /**
     * Insert a new post from AT Protocol record.
     *
     * @param array $record net.vza.forum.post record data
     * @param string $authorDid Author's DID
     * @param string $atUri AT Protocol URI
     * @param string $cid Content identifier
     * @return int Created phpBB post ID
     * @throws UserResolutionException When author DID can't be resolved
     * @throws ForumResolutionException When forum reference is invalid
     */
    public function insertPost(
        array $record,
        string $authorDid,
        string $atUri,
        string $cid
    ): int;

    /**
     * Update an existing post.
     *
     * @param int $postId phpBB post ID
     * @param array $record Updated record data
     * @param string $cid New content identifier
     */
    public function updatePost(
        int $postId,
        array $record,
        string $cid
    ): void;

    /**
     * Delete a post (mark as deleted in cache).
     *
     * @param int $postId phpBB post ID
     */
    public function deletePost(int $postId): void;

    /**
     * Check if a post already exists by AT URI.
     *
     * @param string $atUri AT Protocol URI
     * @return bool True if post exists
     */
    public function postExists(string $atUri): bool;
}
```

### Labels\Subscriber

Subscribes to label events from Ozone.

```php
<?php

namespace phpbb\atproto\sync\Labels;

interface SubscriberInterface
{
    /**
     * Subscribe to a labeler's label stream.
     *
     * @param string $labelerDid DID of the labeler to subscribe to
     * @param int|null $cursor Resume from this cursor position
     */
    public function subscribe(string $labelerDid, ?int $cursor = null): void;

    /**
     * Register a label event handler.
     *
     * @param callable $callback Function(array $label): void
     */
    public function onLabel(callable $callback): void;

    /**
     * Unsubscribe from label stream.
     */
    public function unsubscribe(): void;

    /**
     * Get current subscription cursor.
     *
     * @return int|null Current cursor or null
     */
    public function getCursor(): ?int;
}
```

---

## Ozone Labeler API

### Emit Label Event

**Endpoint**: `POST /xrpc/tools.ozone.moderation.emitEvent`

**Request**:
```json
{
  "event": {
    "$type": "tools.ozone.moderation.defs#modEventLabel",
    "createLabelVals": ["!hide"],
    "negateLabelVals": []
  },
  "subject": {
    "$type": "com.atproto.repo.strongRef",
    "uri": "at://did:plc:abc123/net.vza.forum.post/3jui7kd2zoik2",
    "cid": "bafyreid..."
  },
  "createdBy": "did:plc:moderator",
  "subjectBlobCids": []
}
```

**Response** (200 OK):
```json
{
  "id": 12345,
  "event": {
    "$type": "tools.ozone.moderation.defs#modEventLabel",
    "createLabelVals": ["!hide"],
    "negateLabelVals": []
  },
  "subject": {
    "$type": "com.atproto.repo.strongRef",
    "uri": "at://did:plc:abc123/net.vza.forum.post/3jui7kd2zoik2",
    "cid": "bafyreid..."
  },
  "subjectBlobCids": [],
  "createdBy": "did:plc:moderator",
  "createdAt": "2024-01-15T10:30:00.000Z"
}
```

### Negate Label

Same endpoint, but with `negateLabelVals` populated:

```json
{
  "event": {
    "$type": "tools.ozone.moderation.defs#modEventLabel",
    "createLabelVals": [],
    "negateLabelVals": ["!hide"]
  },
  "subject": {
    "$type": "com.atproto.repo.strongRef",
    "uri": "at://did:plc:abc123/net.vza.forum.post/3jui7kd2zoik2",
    "cid": "bafyreid..."
  }
}
```

### Subscribe to Labels

**Endpoint**: `GET /xrpc/com.atproto.label.subscribeLabels` (WebSocket)

**Query Parameters**:
- `cursor`: Resume from position

**Message Format**:
```json
{
  "$type": "com.atproto.label.subscribeLabels#labels",
  "seq": 12345,
  "labels": [
    {
      "src": "did:plc:labeler",
      "uri": "at://did:plc:abc123/net.vza.forum.post/3jui7kd2zoik2",
      "cid": "bafyreid...",
      "val": "!hide",
      "neg": false,
      "cts": "2024-01-15T10:30:00.000Z"
    }
  ]
}
```

---

## Error Codes

### AT Protocol Errors

| Code | Constant | Description | Recovery |
|------|----------|-------------|----------|
| `ATPROTO_PDS_UNAVAILABLE` | Service unavailable | PDS is unreachable | Retry with backoff |
| `ATPROTO_TOKEN_EXPIRED` | Token expired | Access token expired | Refresh and retry |
| `ATPROTO_TOKEN_REVOKED` | Token revoked | Refresh token invalid | Re-authenticate |
| `ATPROTO_RATE_LIMITED` | Rate limited | Too many requests | Wait and retry |
| `ATPROTO_INVALID_RECORD` | Invalid record | Record fails schema validation | Fix record data |
| `ATPROTO_CID_MISMATCH` | CID mismatch | Concurrent modification | Refetch and retry |
| `ATPROTO_RECORD_NOT_FOUND` | Record not found | Record doesn't exist | Handle gracefully |
| `ATPROTO_REPO_NOT_FOUND` | Repo not found | DID has no repository | Handle gracefully |
| `ATPROTO_BLOB_TOO_LARGE` | Blob too large | File exceeds size limit | Reduce file size |

### Extension Errors

| Code | Constant | Description |
|------|----------|-------------|
| `EXT_USER_NOT_LINKED` | User not linked to DID | User must complete OAuth flow |
| `EXT_FORUM_NOT_MAPPED` | Forum not mapped to AT URI | Admin must sync forum structure |
| `EXT_WRITE_QUEUED` | Write queued for retry | PDS unavailable, queued |
| `EXT_MODERATION_DENIED` | Moderation action denied | User lacks Ozone permission |

### Sync Service Errors

| Code | Constant | Description |
|------|----------|-------------|
| `SYNC_CONNECTION_FAILED` | Firehose connection failed | Reconnecting |
| `SYNC_CURSOR_INVALID` | Invalid cursor | Will restart from latest |
| `SYNC_USER_RESOLUTION_FAILED` | Can't resolve user DID | User creation failed |
| `SYNC_FORUM_RESOLUTION_FAILED` | Can't resolve forum URI | Post rejected |

---

## Docker Configuration

### Sync Service Dockerfile

```dockerfile
FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    libsodium-dev \
    icu-dev \
    && docker-php-ext-install \
        pdo_mysql \
        sodium \
        intl \
        pcntl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first (for layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copy application code
COPY src/ src/
COPY bin/ bin/
COPY config/ config/

# Run post-install scripts
RUN composer dump-autoload --optimize

# Create non-root user
RUN adduser -D -u 1000 syncuser
USER syncuser

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD php /app/bin/healthcheck.php || exit 1

# Graceful shutdown timeout
STOPSIGNAL SIGTERM

# Run daemon
CMD ["php", "/app/bin/sync-daemon.php"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  phpbb:
    image: phpbb/phpbb:3.3
    container_name: phpbb-web
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - phpbb_data:/var/www/html
      - ./ext/atproto:/var/www/html/ext/phpbb/atproto
    environment:
      PHPBB_DB_HOST: mysql
      PHPBB_DB_NAME: phpbb
      PHPBB_DB_USER: phpbb
      PHPBB_DB_PASSWORD: ${MYSQL_PASSWORD}
    depends_on:
      mysql:
        condition: service_healthy
    networks:
      - phpbb_network
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    container_name: phpbb-mysql
    volumes:
      - mysql_data:/var/lib/mysql
      - ./init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: phpbb
      MYSQL_USER: phpbb
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    networks:
      - phpbb_network
    restart: unless-stopped

  sync-service:
    build:
      context: ./sync-service
      dockerfile: Dockerfile
    container_name: phpbb-sync
    environment:
      MYSQL_HOST: mysql
      MYSQL_DATABASE: phpbb
      MYSQL_USER: phpbb
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
      RELAY_URL: wss://bsky.network/xrpc/com.atproto.sync.subscribeRepos
      FORUM_DID: ${FORUM_DID}
      FORUM_PDS_URL: ${FORUM_PDS_URL}
      FORUM_PDS_ACCESS_TOKEN: ${FORUM_PDS_ACCESS_TOKEN}
      LABELER_DID: ${LABELER_DID:-${FORUM_DID}}
      TOKEN_ENCRYPTION_KEYS: ${TOKEN_ENCRYPTION_KEYS}
      TOKEN_ENCRYPTION_KEY_VERSION: ${TOKEN_ENCRYPTION_KEY_VERSION}
      LOG_LEVEL: ${LOG_LEVEL:-info}
    depends_on:
      mysql:
        condition: service_healthy
    networks:
      - phpbb_network
    restart: always
    stop_grace_period: 30s
    deploy:
      resources:
        limits:
          memory: 256M
          cpus: '0.5'
        reservations:
          memory: 128M
          cpus: '0.1'

networks:
  phpbb_network:
    driver: bridge

volumes:
  phpbb_data:
  mysql_data:
```

### Environment Variables (.env.example)

```bash
# Database
MYSQL_ROOT_PASSWORD=change_me_root_password
MYSQL_PASSWORD=change_me_phpbb_password

# Forum PDS
FORUM_DID=did:plc:your_forum_did
FORUM_PDS_URL=https://your-forum.bsky.social
FORUM_PDS_ACCESS_TOKEN=your_forum_pds_access_token

# Labeler (defaults to FORUM_DID if not set)
LABELER_DID=

# Security - Token Encryption with Key Rotation
# Generate keys with: php -r "echo base64_encode(random_bytes(32));"
# Format: JSON object mapping version to base64-encoded 32-byte keys
TOKEN_ENCRYPTION_KEYS='{"v1":"base64_encoded_32_byte_key"}'
TOKEN_ENCRYPTION_KEY_VERSION=v1
# To rotate: add new version to KEYS, update VERSION, old keys decrypt old tokens

# Logging
LOG_LEVEL=info
```

### Health Check Script

The health check validates multiple aspects of Sync Service health:
1. **Cursor freshness** - firehose is receiving messages
2. **Connection state** - WebSocket is connected
3. **State file freshness** - daemon process is alive

```php
<?php
// bin/healthcheck.php

declare(strict_types=1);

$requiredEnv = ['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'];
foreach ($requiredEnv as $var) {
    if (!getenv($var)) {
        fwrite(STDERR, "Missing environment variable: $var\n");
        exit(1);
    }
}

$healthy = true;
$reasons = [];
$stateFile = '/tmp/sync-service-state.json';

// Check 1: State file freshness (daemon is running and updating state)
if (file_exists($stateFile)) {
    $stateAge = time() - filemtime($stateFile);
    if ($stateAge > 60) {
        $healthy = false;
        $reasons[] = "State file stale ({$stateAge}s) - daemon may be dead";
    }

    $state = json_decode(file_get_contents($stateFile), true);
    if ($state) {
        // Check 2: WebSocket connection state
        if (!($state['websocket_connected'] ?? false)) {
            $healthy = false;
            $reasons[] = 'WebSocket disconnected';
        }

        // Check 3: Last message received
        $lastMsg = $state['last_message_at'] ?? 0;
        $msgAge = time() - $lastMsg;
        if ($msgAge > 300) {
            $healthy = false;
            $reasons[] = "No messages received in {$msgAge}s";
        }
    }
} else {
    // No state file yet - check database cursor as fallback
    echo "No state file found, checking database cursor\n";
}

// Check 4: Database cursor freshness (authoritative check)
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('MYSQL_HOST'),
            getenv('MYSQL_DATABASE')
        ),
        getenv('MYSQL_USER'),
        getenv('MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->query(
        "SELECT cursor_value, updated_at
         FROM phpbb_atproto_cursors
         WHERE service = 'firehose'"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // No cursor yet - might be first run, allow it
        echo "No cursor found (first run?)\n";
    } else {
        $staleness = time() - (int)$row['updated_at'];
        $maxStaleness = 300; // 5 minutes

        if ($staleness > $maxStaleness) {
            $healthy = false;
            $reasons[] = "Cursor stale: {$staleness}s (max: {$maxStaleness}s)";
        }
    }
} catch (PDOException $e) {
    $healthy = false;
    $reasons[] = "Database error: " . $e->getMessage();
}

if ($healthy) {
    echo "Healthy: all checks passed\n";
    exit(0);
} else {
    fwrite(STDERR, "Unhealthy: " . implode('; ', $reasons) . "\n");
    exit(1);
}
```

### Connection State Writer

The Sync Service daemon should write state periodically:

```php
<?php
// In sync daemon main loop

class ConnectionStateWriter
{
    private string $stateFile = '/tmp/sync-service-state.json';
    private int $lastWrite = 0;
    private int $writeInterval = 10; // seconds

    public function update(bool $connected, int $cursor, int $messagesProcessed): void
    {
        if (time() - $this->lastWrite < $this->writeInterval) {
            return;
        }

        $state = [
            'websocket_connected' => $connected,
            'connection_established_at' => $this->connectedAt ?? time(),
            'last_message_at' => time(),
            'cursor' => $cursor,
            'messages_processed' => $messagesProcessed,
            'pid' => getmypid(),
        ];

        file_put_contents($this->stateFile, json_encode($state));
        $this->lastWrite = time();
    }
}
```
