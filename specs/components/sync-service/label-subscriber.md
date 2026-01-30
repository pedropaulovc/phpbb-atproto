# Component: Label Subscriber

## Overview
- **Purpose**: Subscribe to moderation labels from the forum's Ozone labeler and sync to local cache
- **Location**: `sync-service/src/Labels/`
- **Dependencies**: firehose-client, ozone-setup
- **Dependents**: database-writer (for label storage), label-display
- **Task**: phpbb-0jq

## Acceptance Criteria
- [ ] AC-1: Subscribes to `com.atproto.label.subscribeLabels` WebSocket endpoint
- [ ] AC-2: Persists label cursor separately from firehose cursor
- [ ] AC-3: Stores labels in `phpbb_atproto_labels` table
- [ ] AC-4: Handles label negation (removal) correctly
- [ ] AC-5: Filters labels to only those for forum content (matching subject URIs)
- [ ] AC-6: Resumes from persisted cursor on restart
- [ ] AC-7: Handles label expiration timestamps
- [ ] AC-8: Supports dual-path reception (direct subscription + firehose redundancy)

## File Structure
```
sync-service/
└── src/
    └── Labels/
        ├── Subscriber.php        # WebSocket subscription
        ├── Processor.php         # Label processing logic
        ├── LabelWriter.php       # Database operations
        └── SubjectMatcher.php    # Match labels to forum content
```

## Interface Definitions

### SubscriberInterface

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

    /**
     * Check if subscribed.
     *
     * @return bool True if actively subscribed
     */
    public function isSubscribed(): bool;
}
```

### LabelWriterInterface

```php
<?php

namespace phpbb\atproto\sync\Labels;

interface LabelWriterInterface
{
    /**
     * Store a label in the database.
     *
     * @param string $subjectUri AT URI of the labeled subject
     * @param string|null $subjectCid CID of the subject (informational)
     * @param string $labelValue Label identifier (e.g., '!hide')
     * @param string $labelSrc Source labeler DID
     * @param int $createdAt Unix timestamp
     * @param int|null $expiresAt Expiration timestamp (null = never)
     * @return bool True if inserted, false if duplicate
     */
    public function storeLabel(
        string $subjectUri,
        ?string $subjectCid,
        string $labelValue,
        string $labelSrc,
        int $createdAt,
        ?int $expiresAt = null
    ): bool;

    /**
     * Negate (logically remove) a label.
     *
     * @param string $subjectUri AT URI of the subject
     * @param string $labelValue Label to negate
     * @param string $labelSrc Source labeler DID
     * @param int $negatedAt Timestamp of negation
     * @return bool True if negated, false if not found
     */
    public function negateLabel(
        string $subjectUri,
        string $labelValue,
        string $labelSrc,
        int $negatedAt
    ): bool;

    /**
     * Check if a subject has a specific active label.
     *
     * @param string $subjectUri AT URI of the subject
     * @param string $labelValue Label to check
     * @return bool True if label is active
     */
    public function hasActiveLabel(string $subjectUri, string $labelValue): bool;

    /**
     * Get all active labels for a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @return array Array of label records
     */
    public function getLabels(string $subjectUri): array;

    /**
     * Clean up expired labels.
     *
     * @return int Number of labels cleaned up
     */
    public function cleanupExpired(): int;
}
```

### SubjectMatcherInterface

```php
<?php

namespace phpbb\atproto\sync\Labels;

interface SubjectMatcherInterface
{
    /**
     * Check if a subject URI belongs to this forum.
     *
     * @param string $subjectUri AT URI to check
     * @return bool True if subject is forum content
     */
    public function isForumContent(string $subjectUri): bool;

    /**
     * Get the phpBB post ID for a subject URI.
     *
     * @param string $subjectUri AT URI
     * @return int|null Post ID or null if not found
     */
    public function getPostId(string $subjectUri): ?int;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `onLabel` | Process incoming label event | Label data array |
| `onNegate` | Process label negation | Label to negate |
| `onError` | Handle subscription errors | Exception |
| `onReconnect` | Handle reconnection | Cursor position |

## Database Interactions

### Tables Used
- `phpbb_atproto_labels` - Label storage
- `phpbb_atproto_cursors` - Label cursor (service = 'labels')
- `phpbb_atproto_posts` - Match subject URIs to posts

### Key Queries

```php
// Store label (upsert)
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_labels
        (subject_uri, subject_cid, label_value, label_src, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            subject_cid = VALUES(subject_cid),
            created_at = VALUES(created_at),
            expires_at = VALUES(expires_at),
            negated = 0,
            negated_at = NULL';

// Negate label
$sql = 'UPDATE ' . $this->table_prefix . 'atproto_labels
        SET negated = 1, negated_at = ?
        WHERE subject_uri = ?
          AND label_value = ?
          AND label_src = ?
          AND negated = 0';

// Get active labels for subject (URI-only matching - sticky moderation)
$sql = 'SELECT label_value, label_src, created_at
        FROM ' . $this->table_prefix . 'atproto_labels
        WHERE subject_uri = ?
          AND negated = 0
          AND (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP())';

// Check if subject is forum content
$sql = 'SELECT post_id FROM ' . $this->table_prefix . 'atproto_posts
        WHERE at_uri = ?';

// Update label cursor
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_cursors
        (service, cursor_value, updated_at)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cursor_value = VALUES(cursor_value),
            updated_at = VALUES(updated_at)';

// Cleanup expired labels
$sql = 'DELETE FROM ' . $this->table_prefix . 'atproto_labels
        WHERE expires_at IS NOT NULL
          AND expires_at < UNIX_TIMESTAMP()';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `com.atproto.label.subscribeLabels` | WebSocket | Subscribe to label stream |

### WebSocket URL Format
```
wss://{labeler_pds}/xrpc/com.atproto.label.subscribeLabels?cursor={cursor}
```

### Label Event Format

```json
{
  "$type": "com.atproto.label.subscribeLabels#labels",
  "seq": 12345,
  "labels": [
    {
      "src": "did:plc:labeler",
      "uri": "at://did:plc:author/net.vza.forum.post/3jui7kd2zoik2",
      "cid": "bafyreid...",
      "val": "!hide",
      "neg": false,
      "cts": "2024-01-15T10:30:00.000Z",
      "exp": null
    }
  ]
}
```

### Label Fields

| Field | Type | Description |
|-------|------|-------------|
| `src` | string | Labeler DID |
| `uri` | string | Subject AT URI |
| `cid` | string | Subject CID (informational) |
| `val` | string | Label identifier |
| `neg` | boolean | True if this negates a previous label |
| `cts` | string | Creation timestamp (ISO 8601) |
| `exp` | string | Expiration timestamp (null = never) |

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Connection failed | `LABEL_CONNECTION_FAILED` | Exponential backoff reconnect |
| Invalid label format | `LABEL_INVALID_FORMAT` | Log warning, skip |
| Unknown subject URI | N/A | Ignore (not our content) |
| Database error | `LABEL_DB_ERROR` | Log error, retry |
| Cursor invalid | `LABEL_CURSOR_INVALID` | Start from latest |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `LABELER_DID` | string | `${FORUM_DID}` | Labeler to subscribe to |
| `LABELER_URL` | string | `${FORUM_PDS_URL}` | Labeler WebSocket endpoint |
| `LABEL_CURSOR_PERSIST_INTERVAL` | int | 10 | Persist cursor every N labels |
| `LABEL_CLEANUP_INTERVAL` | int | 3600 | Expired label cleanup interval (s) |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Receive `!hide` label | Label stored in database |
| Receive label negation | Existing label marked as negated |
| Receive label for non-forum content | Label ignored |
| Receive expired label | Label stored with expiration |
| Cleanup expired labels | Expired labels deleted |
| Resume from cursor | No duplicate labels processed |
| Connection drop | Reconnects with cursor |

## Implementation Notes

### Label Processing Flow

```
Label Event Received
    │
    ▼
┌─────────────────────────────────────┐
│ Parse label event                    │
│ - seq: cursor position               │
│ - labels: array of label objects     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ For each label in labels:            │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Is subject_uri forum content?        │
│ (matches phpbb_atproto_posts)        │
│                                      │
│ No  ────────────────────────► Skip   │
│ Yes │                                │
└─────┼───────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────┐
│ Is neg = true?                       │
│                                      │
│ Yes ──► Negate existing label        │
│ No  ──► Store new label              │
└─────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Update cursor position               │
└─────────────────────────────────────┘
```

### Sticky Moderation Implementation

Labels apply by URI only, not CID. This ensures moderation persists across edits:

```php
// When storing a label
public function storeLabel(
    string $subjectUri,
    ?string $subjectCid,  // Stored but not used for matching
    string $labelValue,
    string $labelSrc,
    int $createdAt,
    ?int $expiresAt = null
): bool {
    // subject_cid is informational only
    // Label lookups match on subject_uri only
}

// When checking labels (in label-display component)
public function hasActiveLabel(string $subjectUri, string $labelValue): bool
{
    // Query matches on URI only - CID not checked
    $sql = 'SELECT 1 FROM phpbb_atproto_labels
            WHERE subject_uri = ?
              AND label_value = ?
              AND negated = 0
              AND (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP())';
}
```

### Dual-Path Reception

Labels can be received through two paths for redundancy:

1. **Direct subscription**: `com.atproto.label.subscribeLabels` to labeler
2. **Firehose**: Labeler's label records appear in firehose

The subscriber should deduplicate labels using the unique constraint on `(subject_uri, label_value, label_src)`.

### Security Considerations
- Only trust labels from configured labeler DID
- Validate label value against known label types
- Don't expose raw label data to unprivileged users

### Performance Considerations
- Batch label storage for high-volume events
- Use unique constraint to prevent duplicates efficiently
- Periodic cleanup of expired labels
- Index on `subject_uri` for fast lookups

## References
- [AT Protocol Labels](https://atproto.com/specs/label)
- [Label Subscription Lexicon](https://github.com/bluesky-social/atproto/blob/main/lexicons/com/atproto/label/subscribeLabels.json)
- [docs/moderation-flow.md](../../../docs/moderation-flow.md) - Label processing details
- [docs/risks.md](../../../docs/risks.md) - D2: CID Mismatch / Sticky Moderation
