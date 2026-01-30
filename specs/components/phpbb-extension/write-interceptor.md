# Component: Write Interceptor

## Overview
- **Purpose**: Intercept phpBB write operations and forward posts to user's PDS before local storage
- **Location**: `ext/phpbb/atproto/event/`
- **Dependencies**: auth-provider (for tokens), migrations
- **Dependents**: None (end of write path)
- **Task**: phpbb-042

## Acceptance Criteria
- [ ] AC-1: Intercepts post creation via `core.posting_modify_submit_post_before`
- [ ] AC-2: Converts phpBB post data to `net.vza.forum.post` record format
- [ ] AC-3: Writes record to user's PDS via `com.atproto.repo.createRecord`
- [ ] AC-4: Stores AT URI mapping after successful PDS write
- [ ] AC-5: Queues failed writes for retry with exponential backoff
- [ ] AC-6: Handles post updates by updating PDS record
- [ ] AC-7: Handles post deletions by deleting from PDS
- [ ] AC-8: Shows "syncing..." indicator for pending posts

## File Structure
```
ext/phpbb/atproto/
├── event/
│   └── write_listener.php      # Event hooks for posting
├── services/
│   ├── pds_client.php          # PDS API communication
│   ├── record_builder.php      # Build AT Proto records
│   └── queue_manager.php       # Retry queue operations
└── includes/
    └── posting_helper.php      # Posting utilities
```

## Interface Definitions

### PdsClientInterface

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
}
```

### RecordBuilderInterface

```php
<?php

namespace phpbb\atproto\services;

interface RecordBuilderInterface
{
    /**
     * Build a net.vza.forum.post record from phpBB post data.
     *
     * @param array $postData phpBB post data from posting form
     * @param int $forumId Target forum ID
     * @param int|null $topicId Topic ID (null for new topic)
     * @param int|null $replyToPostId Post being replied to
     * @return array Record matching lexicon schema
     */
    public function buildPostRecord(
        array $postData,
        int $forumId,
        ?int $topicId = null,
        ?int $replyToPostId = null
    ): array;

    /**
     * Build attachment blob references for a post.
     *
     * @param array $attachments phpBB attachment data
     * @param string $userDid User's DID for blob upload
     * @return array Array of attachment objects for record
     */
    public function buildAttachments(array $attachments, string $userDid): array;
}
```

### QueueManagerInterface

```php
<?php

namespace phpbb\atproto\services;

interface QueueManagerInterface
{
    /**
     * Queue a failed write operation for retry.
     *
     * @param string $operation 'create', 'update', or 'delete'
     * @param string $collection Lexicon collection
     * @param string $userDid User's DID
     * @param array $recordData Record data (null for delete)
     * @param int|null $localId Local post ID
     * @param string $error Error message
     * @return int Queue item ID
     */
    public function queue(
        string $operation,
        string $collection,
        string $userDid,
        ?array $recordData,
        ?int $localId,
        string $error
    ): int;

    /**
     * Get pending items ready for retry.
     *
     * @param int $limit Maximum items to return
     * @return array Array of queue items
     */
    public function getPendingItems(int $limit = 10): array;

    /**
     * Mark an item as successfully processed.
     *
     * @param int $itemId Queue item ID
     */
    public function markComplete(int $itemId): void;

    /**
     * Record a retry failure.
     *
     * @param int $itemId Queue item ID
     * @param string $error Error message
     */
    public function recordFailure(int $itemId, string $error): void;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `core.posting_modify_submit_post_before` | Intercept new/edit posts | `$event['data']`, `$event['mode']` |
| `core.posting_modify_submit_post_after` | Store URI mapping | `$event['data']`, `$event['post_data']` |
| `core.delete_post_after` | Delete from PDS | `$event['post_id']`, `$event['topic_id']` |
| `core.submit_post_end` | Handle post completion | `$event['data']`, `$event['mode']` |

## Database Interactions

### Tables Used
- `phpbb_atproto_users` - Get user's DID and tokens
- `phpbb_atproto_posts` - Store/update URI mappings
- `phpbb_atproto_forums` - Get forum AT URI
- `phpbb_atproto_queue` - Retry queue

### Key Queries

```php
// Get user's DID and PDS URL
$sql = 'SELECT did, pds_url FROM ' . $this->table_prefix . 'atproto_users
        WHERE user_id = ?';

// Get forum AT URI
$sql = 'SELECT at_uri, at_cid FROM ' . $this->table_prefix . 'atproto_forums
        WHERE forum_id = ?';

// Get post AT URI for reply references
$sql = 'SELECT at_uri, at_cid FROM ' . $this->table_prefix . 'atproto_posts
        WHERE post_id = ?';

// Get topic's root post URI
$sql = 'SELECT ap.at_uri, ap.at_cid
        FROM ' . $this->table_prefix . 'atproto_posts ap
        JOIN phpbb_posts p ON ap.post_id = p.post_id
        WHERE p.topic_id = ? AND ap.is_topic_starter = 1';

// Store post mapping
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_posts
        (post_id, at_uri, at_cid, author_did, is_topic_starter, sync_status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

// Update post mapping (after edit)
$sql = 'UPDATE ' . $this->table_prefix . 'atproto_posts
        SET at_cid = ?, sync_status = ?, updated_at = ?
        WHERE post_id = ?';

// Queue failed write
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_queue
        (operation, collection, rkey, record_data, user_did, local_id, attempts, next_retry_at, created_at, status)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, "pending")';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `com.atproto.repo.createRecord` | POST | Create new post on PDS |
| `com.atproto.repo.putRecord` | POST | Update existing post |
| `com.atproto.repo.deleteRecord` | POST | Delete post from PDS |
| `com.atproto.repo.uploadBlob` | POST | Upload attachments |

### Create Record Request

```json
POST /xrpc/com.atproto.repo.createRecord
Authorization: Bearer {access_token}

{
  "repo": "did:plc:user123",
  "collection": "net.vza.forum.post",
  "record": {
    "$type": "net.vza.forum.post",
    "text": "Post content here",
    "createdAt": "2024-01-15T10:30:00.000Z",
    "forum": {
      "uri": "at://did:plc:forum/net.vza.forum.board/abc123",
      "cid": "bafyreid..."
    },
    "subject": "Topic title (only for first post)",
    "reply": {
      "root": {
        "uri": "at://did:plc:author/net.vza.forum.post/xyz789",
        "cid": "bafyreid..."
      },
      "parent": {
        "uri": "at://did:plc:author/net.vza.forum.post/xyz789",
        "cid": "bafyreid..."
      }
    }
  }
}
```

### Create Record Response

```json
{
  "uri": "at://did:plc:user123/net.vza.forum.post/3jui7kd2zoik2",
  "cid": "bafyreidabc123..."
}
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| User not linked to DID | `EXT_USER_NOT_LINKED` | Prompt user to link AT account |
| Forum not mapped | `EXT_FORUM_NOT_MAPPED` | Block post, admin must sync |
| PDS unavailable | `ATPROTO_PDS_UNAVAILABLE` | Queue for retry, save locally |
| Token expired | `ATPROTO_TOKEN_EXPIRED` | Refresh and retry (automatic) |
| Rate limited | `ATPROTO_RATE_LIMITED` | Queue for retry with backoff |
| Invalid record | `ATPROTO_INVALID_RECORD` | Log error, show user message |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `atproto_write_enabled` | bool | true | Enable PDS writes |
| `atproto_allow_pending` | bool | true | Allow local save if PDS fails |
| `atproto_max_retry_attempts` | int | 5 | Maximum retry attempts |
| `atproto_attachment_max_size` | int | 10485760 | Max attachment size (10MB) |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Create new topic post | PDS record created, URI stored, topic created |
| Create reply post | PDS record with reply refs, URI stored |
| Edit existing post | PDS record updated, new CID stored |
| Delete post | PDS record deleted, local marked deleted |
| PDS unavailable on create | Post queued, saved locally with pending status |
| User not linked | Error shown, post blocked |
| Token expired during write | Token refreshed, retry succeeds |
| Attachment upload | Blob uploaded, ref included in record |

## Implementation Notes

### Write Listener Implementation

```php
<?php

namespace phpbb\atproto\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class WriteListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'core.posting_modify_submit_post_before' => 'onPostSubmitBefore',
            'core.posting_modify_submit_post_after' => 'onPostSubmitAfter',
            'core.delete_post_after' => 'onPostDelete',
        ];
    }

    public function onPostSubmitBefore($event)
    {
        $mode = $event['mode'];
        $data = $event['data'];

        // Skip if AT Proto writes disabled
        if (!$this->config['atproto_write_enabled']) {
            return;
        }

        // Get user's DID
        $userDid = $this->tokenManager->getUserDid($this->user->data['user_id']);
        if (!$userDid) {
            if ($mode === 'post' || $mode === 'reply') {
                throw new \phpbb\exception\runtime_exception('ATPROTO_USER_NOT_LINKED');
            }
            return;
        }

        // Get forum AT URI
        $forumRef = $this->uriMapper->getForumStrongRef($data['forum_id']);
        if (!$forumRef) {
            throw new \phpbb\exception\runtime_exception('ATPROTO_FORUM_NOT_MAPPED');
        }

        // Build record
        $record = $this->recordBuilder->buildPostRecord(
            $data,
            $data['forum_id'],
            $data['topic_id'] ?? null,
            $data['post_id'] ?? null
        );

        try {
            // Write to PDS
            $accessToken = $this->tokenManager->getAccessToken($this->user->data['user_id']);
            $result = $this->pdsClient->createRecord(
                $userDid,
                'net.vza.forum.post',
                $record
            );

            // Store result for after hook
            $this->pendingWrite = [
                'uri' => $result->uri,
                'cid' => $result->cid,
                'did' => $userDid,
                'is_topic_starter' => !isset($data['topic_id']),
            ];

        } catch (PdsUnavailableException $e) {
            if ($this->config['atproto_allow_pending']) {
                // Queue for retry, allow local save
                $this->queueManager->queue(
                    'create',
                    'net.vza.forum.post',
                    $userDid,
                    $record,
                    null, // Local ID not known yet
                    $e->getMessage()
                );
                $this->pendingWrite = ['status' => 'pending'];
            } else {
                throw $e;
            }
        }
    }

    public function onPostSubmitAfter($event)
    {
        if (!$this->pendingWrite) {
            return;
        }

        $postId = $event['data']['post_id'];

        if (isset($this->pendingWrite['uri'])) {
            // Successful PDS write - store mapping
            $this->uriMapper->storeMapping(
                $postId,
                $this->pendingWrite['uri'],
                $this->pendingWrite['cid'],
                $this->pendingWrite['did'],
                $this->pendingWrite['is_topic_starter']
            );
        } else {
            // Pending - store with pending status
            $this->uriMapper->storePending($postId, $this->pendingWrite['did']);

            // Update queue with local ID
            $this->queueManager->updateLocalId($postId);
        }

        $this->pendingWrite = null;
    }

    public function onPostDelete($event)
    {
        $postId = $event['post_id'];

        // Get AT URI and CID
        $ref = $this->uriMapper->getStrongRef($postId);
        if (!$ref) {
            return; // Not an AT Proto post
        }

        // Parse URI to get rkey
        $rkey = $this->parseRkeyFromUri($ref['uri']);
        $userDid = $this->parseDidFromUri($ref['uri']);

        try {
            $this->pdsClient->deleteRecord(
                $userDid,
                'net.vza.forum.post',
                $rkey
            );
        } catch (\Exception $e) {
            // Log but don't block local delete
            $this->logger->error('Failed to delete from PDS', [
                'uri' => $ref['uri'],
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### Record Builder Implementation

```php
class RecordBuilder implements RecordBuilderInterface
{
    public function buildPostRecord(
        array $postData,
        int $forumId,
        ?int $topicId = null,
        ?int $replyToPostId = null
    ): array {
        $record = [
            '$type' => 'net.vza.forum.post',
            'text' => $postData['message'],
            'createdAt' => gmdate('c'),
            'forum' => $this->uriMapper->getForumStrongRef($forumId),
            'enableBbcode' => (bool)($postData['enable_bbcode'] ?? true),
            'enableSmilies' => (bool)($postData['enable_smilies'] ?? true),
            'enableSignature' => (bool)($postData['enable_sig'] ?? true),
        ];

        // Topic starter gets subject
        if (!$topicId && isset($postData['subject'])) {
            $record['subject'] = $postData['subject'];

            if (isset($postData['topic_type'])) {
                $record['topicType'] = $this->mapTopicType($postData['topic_type']);
            }
        }

        // Replies get reply references
        if ($topicId) {
            $rootRef = $this->uriMapper->getTopicRootRef($topicId);
            $parentRef = $replyToPostId
                ? $this->uriMapper->getStrongRef($replyToPostId)
                : $rootRef;

            $record['reply'] = [
                'root' => $rootRef,
                'parent' => $parentRef,
            ];
        }

        return $record;
    }
}
```

### Security Considerations
- Validate user owns the token before write
- Sanitize post content (phpBB handles this)
- Don't expose PDS errors to end users
- Rate limit PDS calls per user

### Performance Considerations
- Async PDS writes where possible
- Batch attachment uploads
- Connection pooling for PDS clients
- Retry queue processed in background

## References
- [AT Protocol Repo Operations](https://atproto.com/specs/repo)
- [phpBB Event System](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_events.html)
- [lexicons/net.vza.forum.post.json](../../../lexicons/net.vza.forum.post.json)
- [docs/api-contracts.md](../../../docs/api-contracts.md) - PdsClientInterface
- [docs/architecture.md](../../../docs/architecture.md) - User Creates Post flow
