# Component: MCP Integration

## Overview
- **Purpose**: Integrate AT Protocol label-based moderation into phpBB's Moderator Control Panel (MCP)
- **Location**: `ext/phpbb/atproto/mcp/`
- **Dependencies**: label-display, ozone-setup, auth-provider
- **Dependents**: None (end of moderation path)

## Acceptance Criteria
- [ ] AC-1: Moderators can emit `!hide` label via standard "Disapprove" action
- [ ] AC-2: Moderators can emit `!warn` label via "Add Warning" action
- [ ] AC-3: Moderators can negate labels to restore posts
- [ ] AC-4: Moderator must have both phpBB ACL and Ozone team membership
- [ ] AC-5: All moderation actions are audit logged
- [ ] AC-6: Failed Ozone calls are queued for retry
- [ ] AC-7: MCP shows label status on posts

## File Structure
```
ext/phpbb/atproto/
├── mcp/
│   ├── main_module.php         # MCP module registration
│   └── atproto_info.php        # Module info
├── event/
│   └── mcp_listener.php        # MCP event hooks
├── services/
│   └── ozone_client.php        # Ozone API client
├── includes/
│   └── moderation_helper.php   # Moderation utilities
└── adm/style/
    └── mcp_atproto_info.html   # Label info panel
```

## Interface Definitions

### OzoneClientInterface

```php
<?php

namespace phpbb\atproto\services;

interface OzoneClientInterface
{
    /**
     * Emit a label on a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @param string $subjectCid CID of the subject
     * @param array $createLabels Labels to create (e.g., ['!hide'])
     * @param array $negateLabels Labels to negate (e.g., [])
     * @param string|null $reason Optional reason for the action
     * @return int Event ID from Ozone
     * @throws OzoneUnavailableException When Ozone is unreachable
     * @throws UnauthorizedLabelException When user lacks permission
     */
    public function emitLabel(
        string $subjectUri,
        string $subjectCid,
        array $createLabels,
        array $negateLabels = [],
        ?string $reason = null
    ): int;

    /**
     * Check if a user is an Ozone team member.
     *
     * @param string $userDid User's DID
     * @return bool True if user is team member
     */
    public function isTeamMember(string $userDid): bool;

    /**
     * Add a user to the Ozone team.
     *
     * @param string $userDid User's DID
     * @param string $role Role (admin, moderator, triage)
     */
    public function addTeamMember(string $userDid, string $role): void;

    /**
     * Remove a user from the Ozone team.
     *
     * @param string $userDid User's DID
     */
    public function removeTeamMember(string $userDid): void;

    /**
     * Get moderation history for a subject.
     *
     * @param string $subjectUri AT URI of the subject
     * @return array Array of moderation events
     */
    public function getSubjectHistory(string $subjectUri): array;
}
```

### ModerationHelperInterface

```php
<?php

namespace phpbb\atproto\includes;

interface ModerationHelperInterface
{
    /**
     * Check if user can moderate a post via AT Protocol.
     *
     * @param int $userId phpBB user ID
     * @param int $forumId Forum ID
     * @return bool True if user can moderate
     */
    public function canModerate(int $userId, int $forumId): bool;

    /**
     * Hide a post (apply !hide label).
     *
     * @param int $postId phpBB post ID
     * @param string|null $reason Reason for hiding
     * @return bool True on success
     */
    public function hidePost(int $postId, ?string $reason = null): bool;

    /**
     * Warn on a post (apply !warn label).
     *
     * @param int $postId phpBB post ID
     * @param string|null $reason Warning reason
     * @return bool True on success
     */
    public function warnPost(int $postId, ?string $reason = null): bool;

    /**
     * Restore a post (negate !hide label).
     *
     * @param int $postId phpBB post ID
     * @return bool True on success
     */
    public function restorePost(int $postId): bool;

    /**
     * Apply a custom label to a post.
     *
     * @param int $postId phpBB post ID
     * @param string $label Label value
     * @param string|null $reason Reason
     * @return bool True on success
     */
    public function applyLabel(int $postId, string $label, ?string $reason = null): bool;

    /**
     * Remove a label from a post.
     *
     * @param int $postId phpBB post ID
     * @param string $label Label value to remove
     * @return bool True on success
     */
    public function removeLabel(int $postId, string $label): bool;
}
```

## Event Hooks

| Event | Purpose | Data |
|-------|---------|------|
| `core.mcp_post_approve` | Intercept approve/disapprove | `$event['action']`, `$event['post_ids']` |
| `core.mcp_front_view_queue_row` | Show label info in queue | `$event['row']` |
| `core.mcp_view_post_info` | Add label panel to post view | `$event['post_info']` |
| `core.mcp_reports_report_before` | Handle report submission | `$event['report_data']` |

## Database Interactions

### Tables Read
- `phpbb_atproto_posts` - Get AT URI/CID for posts
- `phpbb_atproto_users` - Get moderator DID
- `phpbb_atproto_labels` - Current labels on post

### Key Queries

```php
// Get post AT reference for moderation
$sql = 'SELECT ap.at_uri, ap.at_cid, ap.author_did
        FROM ' . $this->table_prefix . 'atproto_posts ap
        WHERE ap.post_id = ?';

// Get moderator's DID
$sql = 'SELECT did FROM ' . $this->table_prefix . 'atproto_users
        WHERE user_id = ?';

// Get current labels on post
$sql = 'SELECT label_value, label_src, created_at
        FROM ' . $this->table_prefix . 'atproto_labels
        WHERE subject_uri = ?
          AND negated = 0
          AND (expires_at IS NULL OR expires_at > ?)';

// Audit log
$sql = 'INSERT INTO phpbb_log
        (log_type, user_id, log_ip, log_time, forum_id, topic_id, log_operation, log_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
```

## External API Calls

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `tools.ozone.moderation.emitEvent` | POST | Emit label |
| `tools.ozone.moderation.getRecord` | GET | Get subject history |
| `tools.ozone.team.listMembers` | GET | Verify team membership |

### Emit Label Request

```json
POST /xrpc/tools.ozone.moderation.emitEvent
Authorization: Bearer {moderator_token}

{
  "event": {
    "$type": "tools.ozone.moderation.defs#modEventLabel",
    "createLabelVals": ["!hide"],
    "negateLabelVals": []
  },
  "subject": {
    "$type": "com.atproto.repo.strongRef",
    "uri": "at://did:plc:author/net.vza.forum.post/3jui7kd2zoik2",
    "cid": "bafyreid..."
  },
  "createdBy": "did:plc:moderator",
  "subjectBlobCids": []
}
```

### Emit Label Response

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
    "uri": "at://did:plc:author/net.vza.forum.post/3jui7kd2zoik2",
    "cid": "bafyreid..."
  },
  "createdBy": "did:plc:moderator",
  "createdAt": "2024-01-15T10:30:00.000Z"
}
```

## Moderation Action Flow

```
Moderator clicks "Disapprove" in MCP
    │
    ▼
┌─────────────────────────────────────┐
│ Check permissions:                   │
│ - phpBB ACL: m_approve              │
│ - User has linked DID               │
│ - User is Ozone team member         │
│                                      │
│ Any failed? → Show permission error │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Get post AT reference:               │
│ - at_uri from phpbb_atproto_posts   │
│ - at_cid from phpbb_atproto_posts   │
│                                      │
│ Not found? → Show "legacy post" msg │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Get moderator DID:                   │
│ - did from phpbb_atproto_users      │
│ - access_token via TokenManager     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Call Ozone API:                      │
│ POST tools.ozone.moderation.emitEvent│
│ {                                    │
│   createLabelVals: ["!hide"],        │
│   subject: { uri, cid },             │
│   createdBy: moderator_did           │
│ }                                    │
└──────────────┬──────────────────────┘
               │
          ┌────┴────┐
          │         │
      Success    Failure
          │         │
          ▼         ▼
┌──────────────┐  ┌──────────────┐
│ Log action   │  │ Queue retry  │
│ Show success │  │ Show warning │
│ message      │  │ message      │
└──────────────┘  └──────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│ Label propagates via firehose       │
│ Sync Service updates local cache    │
│ Post becomes hidden on next view    │
└─────────────────────────────────────┘
```

## Error Handling

| Condition | Code | Recovery |
|-----------|------|----------|
| User not linked | `MOD_USER_NOT_LINKED` | Prompt to link AT account |
| Not Ozone team member | `MOD_NOT_TEAM_MEMBER` | Admin must add to team |
| Post not mapped | `MOD_POST_NOT_MAPPED` | Show legacy moderation UI |
| Ozone unavailable | `MOD_OZONE_UNAVAILABLE` | Queue action for retry |
| Permission denied | `MOD_PERMISSION_DENIED` | Show error message |

## Configuration

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `atproto_mod_enabled` | bool | true | Enable AT Proto moderation |
| `atproto_mod_queue_retry` | bool | true | Queue failed mod actions |
| `atproto_mod_audit_log` | bool | true | Log all mod actions |

## Test Scenarios

| Test | Expected Result |
|------|-----------------|
| Disapprove post | !hide label emitted, post hidden |
| Approve post | !hide label negated, post visible |
| Add warning | !warn label emitted, warning shown |
| Remove warning | !warn label negated, warning removed |
| Moderator not in Ozone team | Permission denied error |
| Legacy post (no AT URI) | Standard phpBB moderation |
| Ozone API down | Action queued, warning shown |
| Bulk disapprove | Labels emitted for each post |

## Implementation Notes

### MCP Listener Implementation

```php
<?php

namespace phpbb\atproto\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class McpListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'core.mcp_post_approve' => 'onPostApprove',
            'core.mcp_view_post_info' => 'onViewPostInfo',
        ];
    }

    public function onPostApprove($event)
    {
        if (!$this->config['atproto_mod_enabled']) {
            return;
        }

        $action = $event['action'];
        $postIds = $event['post_ids'];

        // Check moderator permissions
        $modDid = $this->tokenManager->getUserDid($this->user->data['user_id']);
        if (!$modDid) {
            // User not linked - allow standard phpBB moderation
            return;
        }

        if (!$this->ozoneClient->isTeamMember($modDid)) {
            trigger_error($this->language->lang('ATPROTO_NOT_TEAM_MEMBER'), E_USER_WARNING);
            return;
        }

        // Process each post
        foreach ($postIds as $postId) {
            $this->processModAction($postId, $action, $modDid);
        }
    }

    private function processModAction(int $postId, string $action, string $modDid): void
    {
        // Get post AT reference
        $ref = $this->uriMapper->getStrongRef($postId);
        if (!$ref) {
            // Legacy post - skip AT Proto handling
            return;
        }

        $createLabels = [];
        $negateLabels = [];

        switch ($action) {
            case 'disapprove':
            case 'delete':
                $createLabels = ['!hide'];
                break;

            case 'approve':
            case 'restore':
                $negateLabels = ['!hide'];
                break;
        }

        if (empty($createLabels) && empty($negateLabels)) {
            return;
        }

        try {
            $this->ozoneClient->emitLabel(
                $ref['uri'],
                $ref['cid'],
                $createLabels,
                $negateLabels,
                $this->request->variable('reason', '', true)
            );

            // Audit log
            $this->logAction($postId, $action, $modDid, $createLabels, $negateLabels);

        } catch (OzoneUnavailableException $e) {
            if ($this->config['atproto_mod_queue_retry']) {
                $this->queueModAction($postId, $action, $modDid);
                $this->template->assign_var('S_ATPROTO_QUEUED', true);
            } else {
                throw $e;
            }
        }
    }

    public function onViewPostInfo($event)
    {
        $postInfo = $event['post_info'];
        $postId = $postInfo['post_id'];

        // Get current labels
        $ref = $this->uriMapper->getStrongRef($postId);
        if ($ref) {
            $labels = $this->labelChecker->getLabels($ref['uri']);
            $postInfo['ATPROTO_LABELS'] = $labels;
            $postInfo['ATPROTO_URI'] = $ref['uri'];
        }

        $event['post_info'] = $postInfo;
    }

    private function logAction(
        int $postId,
        string $action,
        string $modDid,
        array $createLabels,
        array $negateLabels
    ): void {
        $logData = serialize([
            'post_id' => $postId,
            'action' => $action,
            'mod_did' => $modDid,
            'create_labels' => $createLabels,
            'negate_labels' => $negateLabels,
        ]);

        add_log('mod', $this->user->data['user_id'], $this->user->ip, 'LOG_ATPROTO_MOD', false, [$logData]);
    }
}
```

### Ozone Client Implementation

```php
class OzoneClient implements OzoneClientInterface
{
    public function emitLabel(
        string $subjectUri,
        string $subjectCid,
        array $createLabels,
        array $negateLabels = [],
        ?string $reason = null
    ): int {
        $accessToken = $this->getModeratorToken();

        $payload = [
            'event' => [
                '$type' => 'tools.ozone.moderation.defs#modEventLabel',
                'createLabelVals' => $createLabels,
                'negateLabelVals' => $negateLabels,
            ],
            'subject' => [
                '$type' => 'com.atproto.repo.strongRef',
                'uri' => $subjectUri,
                'cid' => $subjectCid,
            ],
            'createdBy' => $this->getModeratorDid(),
            'subjectBlobCids' => [],
        ];

        $response = $this->httpClient->post(
            $this->labelerUrl . '/xrpc/tools.ozone.moderation.emitEvent',
            [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'json' => $payload,
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new OzoneUnavailableException('Ozone API error: ' . $response->getBody());
        }

        $result = json_decode($response->getBody(), true);
        return $result['id'];
    }
}
```

### Security Considerations
- Verify both phpBB ACL AND Ozone membership
- Don't expose Ozone errors to end users
- Audit log all moderation actions
- Rate limit moderation actions

### Performance Considerations
- Cache Ozone team membership checks
- Batch label emissions where possible
- Async Ozone calls if supported

## References
- [phpBB MCP Module Development](https://area51.phpbb.com/docs/dev/3.3.x/)
- [Ozone Moderation API](https://github.com/bluesky-social/ozone)
- [docs/moderation-flow.md](../../../docs/moderation-flow.md) - Complete moderation flow
- [docs/api-contracts.md](../../../docs/api-contracts.md) - LabelClientInterface
