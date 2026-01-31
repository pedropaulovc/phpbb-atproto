# Component: Forum Config Sync

## Overview
- **Purpose**: Sync forum configuration (boards, ACL, settings) from forum PDS to local MySQL cache
- **Location**: `sync-service/src/Config/`
- **Dependencies**: database-writer, event-processor, config-interceptor
- **Dependents**: None (end of config sync path)

## Acceptance Criteria
- [ ] AC-1: Processes `net.vza.forum.board` events from firehose
- [ ] AC-2: Creates/updates/deletes forums in phpBB based on board records
- [ ] AC-3: Processes `net.vza.forum.acl` events for permission sync
- [ ] AC-4: Processes `net.vza.forum.config` events for global settings
- [ ] AC-5: Maintains forum hierarchy (parent relationships)
- [ ] AC-6: Updates slug mappings when boards are renamed
- [ ] AC-7: Handles initial bootstrap sync from forum PDS

## File Structure
```
sync-service/
└── src/
    └── Config/
        ├── ForumSync.php         # Board sync operations
        ├── AclSync.php           # ACL sync operations
        ├── ConfigSync.php        # Global config sync
        └── BootstrapSync.php     # Initial sync from PDS
```

## Interface Definitions

### ForumSyncInterface

```php
<?php

namespace phpbb\atproto\sync\Config;

interface ForumSyncInterface
{
    /**
     * Sync a board record from the firehose.
     *
     * @param string $operation 'create', 'update', or 'delete'
     * @param string $atUri Board AT URI
     * @param array|null $record Board record data (null for delete)
     * @param string|null $cid Record CID
     */
    public function syncBoard(
        string $operation,
        string $atUri,
        ?array $record,
        ?string $cid
    ): void;

    /**
     * Get forum ID by AT URI.
     *
     * @param string $atUri Board AT URI
     * @return int|null Forum ID or null if not found
     */
    public function getForumId(string $atUri): ?int;

    /**
     * Get forum ID by slug.
     *
     * @param string $slug Board slug
     * @return int|null Forum ID or null if not found
     */
    public function getForumIdBySlug(string $slug): ?int;
}
```

### AclSyncInterface

```php
<?php

namespace phpbb\atproto\sync\Config;

interface AclSyncInterface
{
    /**
     * Sync ACL record from the firehose.
     *
     * @param array $record ACL record data
     * @param string $cid Record CID
     */
    public function syncAcl(array $record, string $cid): void;

    /**
     * Sync group definitions.
     *
     * @param array $groups Array of group definitions
     */
    public function syncGroups(array $groups): void;

    /**
     * Sync role definitions.
     *
     * @param array $roles Array of role definitions
     */
    public function syncRoles(array $roles): void;

    /**
     * Sync forum permissions.
     *
     * @param array $permissions Array of forum permission assignments
     */
    public function syncForumPermissions(array $permissions): void;
}
```

### ConfigSyncInterface

```php
<?php

namespace phpbb\atproto\sync\Config;

interface ConfigSyncInterface
{
    /**
     * Sync global config record from the firehose.
     *
     * @param array $record Config record data
     * @param string $cid Record CID
     */
    public function syncConfig(array $record, string $cid): void;
}
```

### BootstrapSyncInterface

```php
<?php

namespace phpbb\atproto\sync\Config;

interface BootstrapSyncInterface
{
    /**
     * Perform initial sync of all forum configuration from PDS.
     *
     * @param string $forumDid Forum PDS DID
     * @return int Number of records synced
     */
    public function bootstrap(string $forumDid): int;

    /**
     * Check if bootstrap sync has been completed.
     *
     * @return bool True if bootstrapped
     */
    public function isBootstrapped(): bool;

    /**
     * Mark bootstrap as complete.
     */
    public function markBootstrapped(): void;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `onBoardCreate` | Create forum from board record | Record, AT URI |
| `onBoardUpdate` | Update forum from board record | Record, AT URI, CID |
| `onBoardDelete` | Delete forum | AT URI |
| `onAclUpdate` | Sync permissions | ACL record |
| `onConfigUpdate` | Sync global config | Config record |

## Database Interactions

### Tables Written
- `phpbb_forums` - Forum metadata
- `phpbb_atproto_forums` - AT URI mapping
- `phpbb_groups` - Group definitions
- `phpbb_acl_groups` - Group permissions
- `phpbb_acl_roles` - Role definitions
- `phpbb_acl_roles_data` - Role permissions
- `phpbb_config` - Global configuration

### Key Queries

```php
// Get forum by AT URI
$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'atproto_forums
        WHERE at_uri = ?';

// Get forum by slug
$sql = 'SELECT forum_id FROM ' . $this->table_prefix . 'atproto_forums
        WHERE slug = ?';

// Create forum
$sql = 'INSERT INTO phpbb_forums
        (forum_name, forum_desc, parent_id, forum_type, forum_status,
         forum_rules, left_id, right_id, forum_parents)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

// Update forum
$sql = 'UPDATE phpbb_forums
        SET forum_name = ?, forum_desc = ?, parent_id = ?,
            forum_type = ?, forum_status = ?, forum_rules = ?
        WHERE forum_id = ?';

// Store forum mapping
$sql = 'INSERT INTO ' . $this->table_prefix . 'atproto_forums
        (forum_id, at_uri, at_cid, slug, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            at_cid = VALUES(at_cid),
            slug = VALUES(slug),
            updated_at = VALUES(updated_at)';

// Update phpBB config
$sql = 'UPDATE phpbb_config SET config_value = ? WHERE config_name = ?';
```

## Processing Flow

### Board Sync Flow

```
net.vza.forum.board Event
    │
    ▼
┌─────────────────────────────────────┐
│ Operation type?                      │
│                                      │
│ create ──► Create forum              │
│ update ──► Update forum              │
│ delete ──► Delete forum              │
└──────────────┬──────────────────────┘
               │
               ▼ (for create/update)
┌─────────────────────────────────────┐
│ Parse board record:                  │
│ - name: Forum name                   │
│ - slug: URL slug                     │
│ - description: Forum description     │
│ - boardType: category/forum/link     │
│ - order: Display order               │
│ - parent: Parent board reference     │
│ - settings: Board settings           │
│ - status: open/locked                │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Resolve parent forum (if present)    │
│ - Get parent AT URI                  │
│ - Lookup phpBB forum_id              │
│ - Queue if parent not yet synced     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Map boardType to phpBB forum_type    │
│ - category → FORUM_CAT               │
│ - forum → FORUM_POST                 │
│ - link → FORUM_LINK                  │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Create/Update phpbb_forums row       │
│ Update phpbb_atproto_forums mapping  │
│ Recalculate tree (left_id/right_id)  │
└─────────────────────────────────────┘
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| Parent forum not found | `CONFIG_PARENT_NOT_FOUND` | Queue for later |
| Invalid board type | `CONFIG_INVALID_TYPE` | Log warning, skip |
| Duplicate slug | `CONFIG_SLUG_CONFLICT` | Append suffix |
| Tree corruption | `CONFIG_TREE_ERROR` | Rebuild tree |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `FORUM_DID` | string | (required) | Forum PDS DID |
| `AUTO_BOOTSTRAP` | bool | true | Auto-sync on first run |
| `SYNC_ACL` | bool | true | Sync ACL from PDS |
| `SYNC_CONFIG` | bool | true | Sync global config |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Create board (category) | Category created in phpBB |
| Create board (forum) | Forum created under parent |
| Update board name | Forum renamed, slug updated |
| Delete board | Forum deleted from phpBB |
| Update board with new parent | Forum moved in hierarchy |
| Bootstrap sync | All boards from PDS synced |
| Board with unknown parent | Queued until parent synced |

## Implementation Notes

### Forum Sync Implementation

```php
<?php

namespace phpbb\atproto\sync\Config;

class ForumSync implements ForumSyncInterface
{
    public function syncBoard(
        string $operation,
        string $atUri,
        ?array $record,
        ?string $cid
    ): void {
        switch ($operation) {
            case 'create':
                $this->createForum($atUri, $record, $cid);
                break;
            case 'update':
                $this->updateForum($atUri, $record, $cid);
                break;
            case 'delete':
                $this->deleteForum($atUri);
                break;
        }
    }

    private function createForum(string $atUri, array $record, string $cid): void
    {
        // Check if already exists (idempotent)
        $existing = $this->getForumId($atUri);
        if ($existing) {
            $this->updateForum($atUri, $record, $cid);
            return;
        }

        // Resolve parent
        $parentId = 0;
        if (isset($record['parent'])) {
            $parentId = $this->getForumId($record['parent']['uri']);
            if (!$parentId) {
                // Queue for later processing
                $this->queueForLater($atUri, $record, $cid);
                return;
            }
        }

        // Map board type
        $forumType = $this->mapBoardType($record['boardType']);

        // Calculate tree position
        [$leftId, $rightId] = $this->calculateTreePosition($parentId, $record['order'] ?? 0);

        // Insert forum
        $forumId = $this->insertForum([
            'forum_name' => $record['name'],
            'forum_desc' => $record['description'] ?? '',
            'parent_id' => $parentId,
            'forum_type' => $forumType,
            'forum_status' => $record['status'] === 'locked' ? ITEM_LOCKED : ITEM_UNLOCKED,
            'forum_rules' => $record['rules'] ?? '',
            'left_id' => $leftId,
            'right_id' => $rightId,
        ]);

        // Store mapping
        $this->storeMapping($forumId, $atUri, $cid, $record['slug']);

        // Process queued children
        $this->processQueuedChildren($atUri);
    }

    private function updateForum(string $atUri, array $record, string $cid): void
    {
        $forumId = $this->getForumId($atUri);
        if (!$forumId) {
            // Not found - create it
            $this->createForum($atUri, $record, $cid);
            return;
        }

        // Check if parent changed
        $newParentId = 0;
        if (isset($record['parent'])) {
            $newParentId = $this->getForumId($record['parent']['uri']) ?? 0;
        }

        // Update forum
        $this->db->sql_query(
            'UPDATE phpbb_forums SET
                forum_name = ?,
                forum_desc = ?,
                parent_id = ?,
                forum_status = ?,
                forum_rules = ?
            WHERE forum_id = ?',
            [
                $record['name'],
                $record['description'] ?? '',
                $newParentId,
                $record['status'] === 'locked' ? ITEM_LOCKED : ITEM_UNLOCKED,
                $record['rules'] ?? '',
                $forumId,
            ]
        );

        // Update mapping
        $this->updateMapping($forumId, $cid, $record['slug']);
    }

    private function deleteForum(string $atUri): void
    {
        $forumId = $this->getForumId($atUri);
        if (!$forumId) {
            return;
        }

        // phpBB forum deletion (move content to parent or delete)
        $this->forumAdmin->delete_forum($forumId, 'move');

        // Remove mapping
        $this->deleteMapping($atUri);
    }

    private function mapBoardType(string $boardType): int
    {
        return match ($boardType) {
            'category' => FORUM_CAT,
            'link' => FORUM_LINK,
            default => FORUM_POST,
        };
    }
}
```

### ACL Sync

```php
class AclSync implements AclSyncInterface
{
    public function syncAcl(array $record, string $cid): void
    {
        // Sync in order: groups, roles, then permissions
        if (isset($record['groups'])) {
            $this->syncGroups($record['groups']);
        }

        if (isset($record['roles'])) {
            $this->syncRoles($record['roles']);
        }

        if (isset($record['globalPermissions'])) {
            $this->syncGlobalPermissions($record['globalPermissions']);
        }

        if (isset($record['forumPermissions'])) {
            $this->syncForumPermissions($record['forumPermissions']);
        }
    }

    public function syncGroups(array $groups): void
    {
        foreach ($groups as $group) {
            $existingId = $this->getGroupIdByExternalId($group['id']);

            $groupData = [
                'group_name' => $group['name'],
                'group_desc' => $group['description'] ?? '',
                'group_type' => $this->mapGroupType($group['type']),
                'group_colour' => $group['color'] ?? '',
            ];

            if ($existingId) {
                $this->updateGroup($existingId, $groupData);
            } else {
                $newId = $this->createGroup($groupData);
                $this->storeGroupMapping($newId, $group['id']);
            }
        }
    }
}
```

### Bootstrap Sync

```php
class BootstrapSync implements BootstrapSyncInterface
{
    public function bootstrap(string $forumDid): int
    {
        $count = 0;

        // Fetch all boards from forum PDS
        $records = $this->pdsClient->listRecords($forumDid, 'net.vza.forum.board');
        foreach ($records as $record) {
            $this->forumSync->syncBoard('create', $record['uri'], $record['value'], $record['cid']);
            $count++;
        }

        // Fetch ACL
        $acl = $this->pdsClient->getRecord($forumDid, 'net.vza.forum.acl', 'self');
        if ($acl) {
            $this->aclSync->syncAcl($acl['value'], $acl['cid']);
            $count++;
        }

        // Fetch config
        $config = $this->pdsClient->getRecord($forumDid, 'net.vza.forum.config', 'self');
        if ($config) {
            $this->configSync->syncConfig($config['value'], $config['cid']);
            $count++;
        }

        $this->markBootstrapped();
        return $count;
    }
}
```

### Security Considerations
- Only accept config from forum DID (not user DIDs)
- Validate board records match expected schema
- Don't allow arbitrary config keys to be set

### Performance Considerations
- Batch forum tree updates
- Cache forum ID lookups
- Process queued items periodically

## References
- [phpBB Forum Management](https://area51.phpbb.com/docs/dev/3.3.x/)
- [net.vza.forum.board.json](../../lexicons/net.vza.forum.board.json)
- [net.vza.forum.acl.json](../../lexicons/net.vza.forum.acl.json)
- [architecture.md](../../architecture.md) - External Post Arrives flow
