# Component: Config Interceptor

## Overview
- **Purpose**: Forward admin configuration changes to the forum PDS for multi-instance synchronization
- **Location**: `ext/phpbb/atproto/event/`
- **Dependencies**: auth-provider (for forum PDS credentials), migrations
- **Dependents**: forum-config-sync (receives synced config)
- **Task**: phpbb-1qw

## Acceptance Criteria
- [ ] AC-1: Intercepts board/forum creation, update, and deletion
- [ ] AC-2: Converts phpBB forum data to `net.vza.forum.board` records
- [ ] AC-3: Uses optimistic locking (`swapRecord`) to detect concurrent edits
- [ ] AC-4: Presents conflict resolution UI when CID mismatch occurs
- [ ] AC-5: Stores AT URI mapping for forums
- [ ] AC-6: Syncs ACL changes to `net.vza.forum.acl` record
- [ ] AC-7: Syncs global config to `net.vza.forum.config` record

## File Structure
```
ext/phpbb/atproto/
├── event/
│   └── config_listener.php     # ACP event hooks
├── services/
│   └── forum_pds_client.php    # Forum PDS operations
├── acp/
│   └── conflict_module.php     # Conflict resolution UI
└── adm/style/
    └── conflict_resolution.html # Conflict UI template
```

## Interface Definitions

### ForumPdsClientInterface

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
     * @param string|null $expectedCid Expected CID for conflict detection
     * @return array{uri: string, cid: string} Updated record reference
     * @throws ConflictException When expectedCid doesn't match
     */
    public function updateConfig(array $data, ?string $expectedCid = null): array;

    /**
     * Get a board/forum definition by AT URI.
     *
     * @param string $atUri Board AT URI
     * @return BoardRecord Board definition with uri and cid
     * @throws RecordNotFoundException When board doesn't exist
     */
    public function getBoard(string $atUri): BoardRecord;

    /**
     * Create a new board definition.
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
     * @param string|null $expectedCid Expected CID for conflict detection
     * @return array{uri: string, cid: string} Updated record reference
     * @throws ConflictException When expectedCid doesn't match
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
     * Update ACL/permissions with optimistic locking.
     *
     * @param array $data ACL data matching net.vza.forum.acl schema
     * @param string|null $expectedCid Expected CID for conflict detection
     * @return array{uri: string, cid: string} Updated record reference
     * @throws ConflictException When expectedCid doesn't match
     */
    public function updateAcl(array $data, ?string $expectedCid = null): array;

    /**
     * Get forum PDS account DID.
     *
     * @return string Forum DID
     */
    public function getForumDid(): string;
}
```

### BoardRecordBuilderInterface

```php
<?php

namespace phpbb\atproto\services;

interface BoardRecordBuilderInterface
{
    /**
     * Build a net.vza.forum.board record from phpBB forum data.
     *
     * @param array $forumData phpBB forum row data
     * @return array Record matching lexicon schema
     */
    public function buildBoardRecord(array $forumData): array;

    /**
     * Build net.vza.forum.acl record from phpBB ACL tables.
     *
     * @return array Record matching lexicon schema
     */
    public function buildAclRecord(): array;

    /**
     * Build net.vza.forum.config record from phpBB config.
     *
     * @return array Record matching lexicon schema
     */
    public function buildConfigRecord(): array;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `core.acp_manage_forums_request_data` | Intercept forum create/edit | `$event['forum_data']` |
| `core.acp_manage_forums_move_content` | Forum move operations | `$event['forum_data']` |
| `core.acp_manage_forums_delete_forum` | Forum deletion | `$event['forum_id']` |
| `core.acp_board_config_edit_add` | Global board config | `$event['display_vars']` |
| `core.acp_permissions_submit_after` | ACL changes | `$event['permission_type']` |

## Database Interactions

### Tables Used
- `phpbb_atproto_forums` - Forum AT URI mapping
- `phpbb_forums` - phpBB forum data
- `phpbb_config` - phpBB configuration
- `phpbb_acl_*` - Permission tables

### Key Queries

```php
// Get forum AT URI and CID
$sql = 'SELECT at_uri, at_cid, slug
        FROM ' . $this->table_prefix . 'atproto_forums
        WHERE forum_id = ?';

// Store new forum mapping
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_forums
        (forum_id, at_uri, at_cid, slug, updated_at)
        VALUES (?, ?, ?, ?, ?)';

// Update forum mapping (after edit)
$sql = 'UPDATE ' . $this->table_prefix . 'atproto_forums
        SET at_cid = ?, slug = ?, updated_at = ?
        WHERE forum_id = ?';

// Delete forum mapping
$sql = 'DELETE FROM ' . $this->table_prefix . 'atproto_forums
        WHERE forum_id = ?';

// Get parent forum AT URI
$sql = 'SELECT at_uri, at_cid
        FROM ' . $this->table_prefix . 'atproto_forums
        WHERE forum_id = ?';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `com.atproto.repo.createRecord` | POST | Create new board |
| `com.atproto.repo.putRecord` | POST | Update board with swapRecord |
| `com.atproto.repo.deleteRecord` | POST | Delete board |
| `com.atproto.repo.getRecord` | GET | Fetch current board state |

### Create Board Request

```json
POST /xrpc/com.atproto.repo.createRecord
Authorization: Bearer {forum_access_token}

{
  "repo": "did:plc:forum",
  "collection": "net.vza.forum.board",
  "record": {
    "$type": "net.vza.forum.board",
    "name": "General Discussion",
    "slug": "general",
    "description": "Talk about anything",
    "boardType": "forum",
    "order": 1,
    "parent": {
      "uri": "at://did:plc:forum/net.vza.forum.board/category1",
      "cid": "bafyreid..."
    },
    "settings": {
      "allowPolls": true,
      "requireApproval": false
    },
    "status": "open"
  }
}
```

### Update Board with Optimistic Locking

```json
POST /xrpc/com.atproto.repo.putRecord
Authorization: Bearer {forum_access_token}

{
  "repo": "did:plc:forum",
  "collection": "net.vza.forum.board",
  "rkey": "3jui7kd2zoik2",
  "swapRecord": "bafyreid_expected_cid...",
  "record": {
    "$type": "net.vza.forum.board",
    "name": "Updated Forum Name",
    "slug": "updated-slug",
    ...
  }
}
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Forum PDS unavailable | `CONFIG_PDS_UNAVAILABLE` | Show error, admin retries |
| CID mismatch (conflict) | `CONFIG_CONFLICT` | Show conflict resolution UI |
| Invalid record | `CONFIG_INVALID_RECORD` | Show validation errors |
| Parent forum not found | `CONFIG_PARENT_NOT_FOUND` | Block operation |

### Conflict Resolution Flow

```
Admin saves forum changes
    │
    ▼
┌─────────────────────────────────────┐
│ Get current CID from mapping        │
│ Call putRecord with swapRecord      │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Response: InvalidSwapException?      │
│                                      │
│ No  ──► Update mapping, success     │
│ Yes │                                │
└─────┼───────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────┐
│ Fetch latest state from PDS         │
│ Show conflict resolution UI:        │
│                                      │
│ ┌─────────────┐  ┌─────────────┐   │
│ │ Your Changes│  │ Other Admin │   │
│ │             │  │   Changes   │   │
│ └─────────────┘  └─────────────┘   │
│                                      │
│ Options:                             │
│ - Keep mine (overwrite)              │
│ - Keep theirs (discard mine)         │
│ - Merge manually                     │
└─────────────────────────────────────┘
```

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `atproto_config_sync_enabled` | bool | true | Enable config sync |
| `atproto_forum_did` | string | (required) | Forum PDS DID |
| `atproto_forum_pds_url` | string | (required) | Forum PDS URL |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Create new forum | Board record created on PDS, URI stored |
| Edit existing forum | Board record updated, new CID stored |
| Delete forum | Board record deleted from PDS |
| Concurrent edit (same forum) | Conflict UI shown with both versions |
| Edit forum hierarchy | Parent references updated correctly |
| Update ACL permissions | ACL record updated on PDS |
| Update global config | Config record updated on PDS |

## Implementation Notes

### Config Listener Implementation

```php
<?php

namespace phpbb\atproto\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb\atproto\exception\ConflictException;

class ConfigListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'core.acp_manage_forums_request_data' => 'onForumSave',
            'core.acp_manage_forums_delete_forum' => 'onForumDelete',
            'core.acp_permissions_submit_after' => 'onAclUpdate',
        ];
    }

    public function onForumSave($event)
    {
        $forumData = $event['forum_data'];
        $forumId = $forumData['forum_id'] ?? null;

        // Build board record
        $record = $this->recordBuilder->buildBoardRecord($forumData);

        if ($forumId) {
            // Update existing forum
            $this->updateBoard($forumId, $record);
        } else {
            // Create new forum - store result for after hook
            $this->pendingCreate = $record;
        }
    }

    private function updateBoard(int $forumId, array $record): void
    {
        // Get current mapping
        $mapping = $this->uriMapper->getForumMapping($forumId);
        if (!$mapping) {
            // Not synced yet - create
            $result = $this->forumPds->createBoard($record);
            $this->uriMapper->storeForumMapping(
                $forumId,
                $result['uri'],
                $result['cid'],
                $record['slug']
            );
            return;
        }

        try {
            // Update with optimistic locking
            $result = $this->forumPds->updateBoard(
                $mapping['at_uri'],
                $record,
                $mapping['at_cid'] // Expected CID
            );

            // Update local mapping
            $this->uriMapper->updateForumCid($forumId, $result['cid'], $record['slug']);

        } catch (ConflictException $e) {
            // Conflict detected - redirect to resolution UI
            $this->showConflictResolution($forumId, $record, $e->getLatest());
        }
    }

    private function showConflictResolution(
        int $forumId,
        array $attempted,
        array $latest
    ): void {
        // Store conflict state in session
        $this->request->getSession()->set('atproto_conflict', [
            'forum_id' => $forumId,
            'attempted' => $attempted,
            'latest' => $latest,
            'latest_cid' => $latest['cid'],
        ]);

        // Redirect to conflict resolution page
        throw new \phpbb\exception\http_exception(
            303,
            'ATPROTO_CONFLICT_DETECTED',
            [],
            new \Symfony\Component\HttpFoundation\RedirectResponse(
                $this->helper->route('phpbb_atproto_conflict')
            )
        );
    }

    public function onForumDelete($event)
    {
        $forumId = $event['forum_id'];

        // Get AT URI
        $mapping = $this->uriMapper->getForumMapping($forumId);
        if (!$mapping) {
            return;
        }

        try {
            $this->forumPds->deleteBoard($mapping['at_uri']);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete board from PDS', [
                'forum_id' => $forumId,
                'error' => $e->getMessage(),
            ]);
        }

        // Always delete local mapping
        $this->uriMapper->deleteForumMapping($forumId);
    }

    public function onAclUpdate($event)
    {
        // Build ACL record from current state
        $record = $this->recordBuilder->buildAclRecord();

        try {
            $result = $this->forumPds->updateAcl($record);
        } catch (ConflictException $e) {
            // For ACL, just retry with latest CID
            $this->forumPds->updateAcl($record, null);
        }
    }
}
```

### Board Record Builder

```php
class BoardRecordBuilder implements BoardRecordBuilderInterface
{
    public function buildBoardRecord(array $forumData): array
    {
        $record = [
            '$type' => 'net.vza.forum.board',
            'name' => $forumData['forum_name'],
            'slug' => $this->generateSlug($forumData['forum_name']),
            'boardType' => $this->mapForumType($forumData['forum_type']),
            'order' => (int)$forumData['left_id'],
            'status' => $forumData['forum_status'] == 0 ? 'open' : 'locked',
        ];

        if (!empty($forumData['forum_desc'])) {
            $record['description'] = $forumData['forum_desc'];
        }

        if ($forumData['parent_id'] > 0) {
            $parentMapping = $this->uriMapper->getForumMapping($forumData['parent_id']);
            if ($parentMapping) {
                $record['parent'] = [
                    'uri' => $parentMapping['at_uri'],
                    'cid' => $parentMapping['at_cid'],
                ];
            }
        }

        // Board settings
        $record['settings'] = [
            'topicsPerPage' => (int)($forumData['forum_topics_per_page'] ?: 25),
            'displayOnIndex' => $forumData['display_on_index'] != 0,
            'enableIndexing' => $forumData['enable_indexing'] != 0,
            'allowPolls' => true, // phpBB doesn't have per-forum poll setting
            'requireApproval' => false, // Would need permission check
        ];

        if (!empty($forumData['forum_rules'])) {
            $record['rules'] = $forumData['forum_rules'];
        }

        return $record;
    }

    private function mapForumType(int $phpbbType): string
    {
        return match ($phpbbType) {
            FORUM_CAT => 'category',
            FORUM_LINK => 'link',
            default => 'forum',
        };
    }
}
```

### Security Considerations
- Forum PDS credentials are admin-only
- Validate admin permissions before sync
- Audit log all config changes
- Don't expose PDS errors to non-admins

### Performance Considerations
- Batch ACL updates (don't sync on every change)
- Cache forum mappings
- Debounce rapid config changes

## References
- [phpBB ACP Events](https://wiki.phpbb.com/Event_List)
- [lexicons/net.vza.forum.board.json](../../../lexicons/net.vza.forum.board.json)
- [lexicons/net.vza.forum.acl.json](../../../lexicons/net.vza.forum.acl.json)
- [docs/api-contracts.md](../../../docs/api-contracts.md) - ForumPdsClientInterface
- [docs/risks.md](../../../docs/risks.md) - D3a: Multi-Instance Config Conflicts
