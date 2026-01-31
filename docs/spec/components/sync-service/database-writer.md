# Component: Database Writer

## Overview
- **Purpose**: Translate AT Protocol records from the firehose into MySQL operations for the phpBB local cache
- **Location**: `sync-service/src/Database/`
- **Dependencies**: event-processor, migrations
- **Dependents**: forum-config-sync (uses same patterns)

## Acceptance Criteria
- [ ] AC-1: Inserts new posts into `phpbb_posts` and `phpbb_atproto_posts`
- [ ] AC-2: Resolves author DID to phpBB user_id (creates user if new)
- [ ] AC-3: Resolves forum AT URI to phpBB forum_id
- [ ] AC-4: Updates existing posts on update operations
- [ ] AC-5: Marks posts as deleted on delete operations
- [ ] AC-6: Uses idempotent insert/update to handle race conditions
- [ ] AC-7: Updates derived statistics (post counts, topic counts)
- [ ] AC-8: Creates topics for posts with `subject` field (topic starters)

## File Structure
```
sync-service/
└── src/
    └── Database/
        ├── PostWriter.php        # Post insert/update/delete
        ├── UserResolver.php      # DID to user_id resolution
        ├── ForumResolver.php     # Forum URI to forum_id resolution
        ├── UriMapper.php         # AT URI mapping operations
        └── StatsUpdater.php      # Derived statistics updates
```

## Interface Definitions

### PostWriterInterface

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
     * Delete a post (soft delete in phpBB).
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

    /**
     * Get post ID by AT URI.
     *
     * @param string $atUri AT Protocol URI
     * @return int|null Post ID or null if not found
     */
    public function getPostIdByUri(string $atUri): ?int;
}
```

### UserResolverInterface

```php
<?php

namespace phpbb\atproto\sync\Database;

interface UserResolverInterface
{
    /**
     * Get or create phpBB user for a DID.
     *
     * @param string $did User's DID
     * @return int phpBB user_id
     * @throws UserResolutionException When DID cannot be resolved
     */
    public function resolveUser(string $did): int;

    /**
     * Get phpBB user ID for a DID without creating.
     *
     * @param string $did User's DID
     * @return int|null User ID or null if not found
     */
    public function getUserId(string $did): ?int;

    /**
     * Update user's handle if changed.
     *
     * @param string $did User's DID
     * @param string $handle New handle
     */
    public function updateHandle(string $did, string $handle): void;
}
```

### ForumResolverInterface

```php
<?php

namespace phpbb\atproto\sync\Database;

interface ForumResolverInterface
{
    /**
     * Get phpBB forum ID for an AT URI.
     *
     * @param string $atUri Forum AT URI
     * @return int|null Forum ID or null if not found
     */
    public function getForumId(string $atUri): ?int;

    /**
     * Get AT URI for a phpBB forum.
     *
     * @param int $forumId phpBB forum ID
     * @return string|null AT URI or null if not mapped
     */
    public function getForumUri(int $forumId): ?string;
}
```

### StatsUpdaterInterface

```php
<?php

namespace phpbb\atproto\sync\Database;

interface StatsUpdaterInterface
{
    /**
     * Increment post count for a user.
     *
     * @param int $userId phpBB user ID
     */
    public function incrementUserPosts(int $userId): void;

    /**
     * Decrement post count for a user.
     *
     * @param int $userId phpBB user ID
     */
    public function decrementUserPosts(int $userId): void;

    /**
     * Update forum statistics (post count, topic count, last post).
     *
     * @param int $forumId phpBB forum ID
     */
    public function updateForumStats(int $forumId): void;

    /**
     * Update topic statistics (reply count, last post).
     *
     * @param int $topicId phpBB topic ID
     */
    public function updateTopicStats(int $topicId): void;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `onPostInserted` | After successful post insert | Post ID, AT URI |
| `onPostUpdated` | After successful post update | Post ID |
| `onPostDeleted` | After successful post delete | Post ID |
| `onUserCreated` | After creating user from DID | User ID, DID |

## Database Interactions

### Tables Written
- `phpbb_posts` - Post content and metadata
- `phpbb_topics` - Topic metadata (for first posts)
- `phpbb_atproto_posts` - AT URI mapping
- `phpbb_atproto_users` - User DID mapping (via UserResolver)
- `phpbb_users` - User profile (for new users)
- `phpbb_forums` - Forum statistics updates

### Key Queries

```php
// Insert post (idempotent)
$sql = 'INSERT INTO phpbb_posts
        (forum_id, topic_id, poster_id, post_time, post_text, post_subject,
         bbcode_bitfield, bbcode_uid, post_visibility)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

// Insert mapping (idempotent with ON DUPLICATE KEY)
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_posts
        (post_id, at_uri, at_cid, author_did, is_topic_starter, sync_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            at_cid = VALUES(at_cid),
            sync_status = "synced",
            updated_at = VALUES(updated_at)';

// Check if post exists by URI
$sql = 'SELECT post_id FROM ' . $this->table_prefix . 'atproto_posts
        WHERE at_uri = ?';

// Resolve user DID
$sql = 'SELECT user_id FROM ' . $this->table_prefix . 'atproto_users
        WHERE did = ?';

// Create user for new DID
$sql = 'INSERT INTO phpbb_users
        (username, username_clean, user_type, user_regdate, user_email)
        VALUES (?, ?, ?, ?, ?)';

// Resolve forum URI
$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'atproto_forums
        WHERE at_uri = ?';

// Create topic for first post
$sql = 'INSERT INTO phpbb_topics
        (forum_id, topic_poster, topic_title, topic_time, topic_visibility,
         topic_first_poster_name, topic_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)';

// Update topic reply count
$sql = 'UPDATE phpbb_topics
        SET topic_replies_real = topic_replies_real + 1,
            topic_last_post_id = ?,
            topic_last_poster_id = ?,
            topic_last_post_time = ?
        WHERE topic_id = ?';
```

## Processing Flow

```
net.vza.forum.post Record Received
    │
    ▼
┌─────────────────────────────────────┐
│ Parse record fields:                 │
│ - text: Post content                 │
│ - createdAt: Timestamp               │
│ - forum: Forum strongRef             │
│ - subject: Topic title (if present)  │
│ - reply: Reply refs (if present)     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Resolve author DID → user_id         │
│                                      │
│ Exists? Use existing user_id         │
│ No?     Resolve DID, create user     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Resolve forum.uri → forum_id         │
│                                      │
│ Found?  Continue                     │
│ No?     Reject post (log warning)    │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Has subject field? (Topic starter)   │
│                                      │
│ Yes ──► Create topic in phpbb_topics │
│ No  ──► Resolve reply.root → topic_id│
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Insert into phpbb_posts              │
│ Insert into phpbb_atproto_posts      │
│ (idempotent with ON DUPLICATE KEY)   │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Update statistics:                   │
│ - User post count                    │
│ - Forum post/topic count             │
│ - Topic reply count                  │
│ - Last post info                     │
└─────────────────────────────────────┘
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| User DID resolution failed | `SYNC_USER_RESOLUTION_FAILED` | Log error, skip post |
| Forum URI not found | `SYNC_FORUM_RESOLUTION_FAILED` | Log error, skip post |
| Topic root not found | `SYNC_TOPIC_NOT_FOUND` | Queue for later retry |
| Database error | `SYNC_DB_ERROR` | Log error, retry |
| Duplicate post (race) | N/A | Idempotent update, continue |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `AUTO_CREATE_USERS` | bool | true | Create users for unknown DIDs |
| `DEFAULT_USER_GROUP` | int | 2 | Group ID for new users |
| `REJECT_UNKNOWN_FORUMS` | bool | true | Reject posts to unmapped forums |
| `DID_CACHE_TTL` | int | 3600 | DID document cache seconds |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Insert new post | Post in phpbb_posts, mapping in phpbb_atproto_posts |
| Insert topic starter (has subject) | Topic created, post linked |
| Insert reply | Post linked to existing topic |
| Insert post from unknown DID | User created, post inserted |
| Insert post to unknown forum | Post rejected, warning logged |
| Insert duplicate post (race) | Idempotent update, no error |
| Update post | Post content updated, CID updated |
| Delete post | Post marked as soft-deleted |

## Implementation Notes

### User Resolution

```php
class UserResolver implements UserResolverInterface
{
    public function resolveUser(string $did): int
    {
        // Check cache first
        $userId = $this->getUserId($did);
        if ($userId !== null) {
            return $userId;
        }

        // Resolve DID to get handle
        $didDoc = $this->didResolver->resolve($did);
        $handle = $didDoc['handle'] ?? $this->generateHandleFromDid($did);
        $pdsUrl = $didDoc['pds_endpoint'] ?? '';

        // Create phpBB user
        $userId = $this->createUser($handle);

        // Store DID mapping
        $this->storeDIDMapping($userId, $did, $handle, $pdsUrl);

        return $userId;
    }

    private function createUser(string $handle): int
    {
        // Generate unique username (handle may conflict)
        $username = $this->generateUniqueUsername($handle);

        $sql = 'INSERT INTO ' . USERS_TABLE . '
                (username, username_clean, user_type, user_regdate, user_email, group_id)
                VALUES (?, ?, ?, ?, ?, ?)';

        $this->db->sql_query($sql, [
            $username,
            strtolower($username),
            USER_NORMAL,
            time(),
            '', // No email for AT Proto users
            $this->config['default_user_group'],
        ]);

        return $this->db->sql_nextid();
    }
}
```

### Idempotent Post Insert

```php
class PostWriter implements PostWriterInterface
{
    public function insertPost(
        array $record,
        string $authorDid,
        string $atUri,
        string $cid
    ): int {
        // Check if already exists (race with extension)
        $existingId = $this->getPostIdByUri($atUri);
        if ($existingId !== null) {
            // Update to mark as synced
            $this->markSynced($existingId, $cid);
            return $existingId;
        }

        // Resolve dependencies
        $userId = $this->userResolver->resolveUser($authorDid);
        $forumId = $this->resolveForumId($record['forum']);

        // Determine topic
        $topicId = $this->resolveOrCreateTopic($record, $userId, $forumId, $atUri);

        // Insert post
        $postId = $this->insertPhpbbPost($record, $userId, $forumId, $topicId);

        // Insert mapping (idempotent)
        $this->insertMapping($postId, $atUri, $cid, $authorDid, isset($record['subject']));

        // Update stats
        $this->statsUpdater->incrementUserPosts($userId);
        $this->statsUpdater->updateForumStats($forumId);
        $this->statsUpdater->updateTopicStats($topicId);

        return $postId;
    }
}
```

### Topic Resolution

Topics are identified by the first post's AT URI:

```php
private function resolveOrCreateTopic(
    array $record,
    int $userId,
    int $forumId,
    string $atUri
): int {
    // If has subject, this IS the topic starter
    if (isset($record['subject'])) {
        return $this->createTopic($record, $userId, $forumId);
    }

    // Otherwise, find topic from reply reference
    $rootUri = $record['reply']['root']['uri'] ?? null;
    if (!$rootUri) {
        throw new \RuntimeException('Reply post missing root reference');
    }

    // Get topic ID from root post
    $rootPostId = $this->getPostIdByUri($rootUri);
    if (!$rootPostId) {
        throw new TopicNotFoundException("Root post not found: $rootUri");
    }

    return $this->getTopicIdForPost($rootPostId);
}
```

### Security Considerations
- Validate all input from firehose (untrusted data)
- Sanitize post content before storage
- Verify DID format before resolution
- Rate limit user creation to prevent abuse

### Performance Considerations
- Batch inserts where possible
- Cache user and forum resolutions
- Use ON DUPLICATE KEY UPDATE for idempotency
- Transaction per post (not per batch) for isolation

## References
- [phpBB Database Layer](https://area51.phpbb.com/docs/dev/3.3.x/db/dbal.html)
- [net.vza.forum.post.json](../../lexicons/net.vza.forum.post.json)
- [api-contracts.md](../../api-contracts.md) - PostWriterInterface
- [risks.md](../../risks.md) - D2a: Post Creation Race Condition
