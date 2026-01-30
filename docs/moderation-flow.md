# Moderation Flow for phpBB on AT Protocol

This document describes how phpBB moderation actions translate to AT Protocol's label-based moderation system.

## Philosophy

AT Protocol uses a **labels-only moderation** approach:
- **Users own their data** - moderators cannot edit or delete user content
- **Labels annotate content** - visibility is controlled by labels attached to content
- **AppViews filter** - each application decides how to display labeled content

This differs from traditional phpBB moderation where moderators can directly edit, move, or delete posts. In the AT Protocol model, the forum acts as an AppView that filters content based on labels.

---

## Label System Overview

### Label Types

| Label | Effect | phpBB Equivalent |
|-------|--------|-----------------|
| `!hide` | Content hidden from all users | Soft delete, disapprove |
| `!warn` | Content shown with warning | Content warning |
| `spam` | Marked as spam (filterable) | Spam flag |
| `nsfw` | Adult content | N/A (new feature) |
| `spoiler` | Spoiler content (expandable) | N/A (new feature) |
| `off-topic` | Off-topic post | N/A (informational) |

### Label Sources

- **Forum Ozone Labeler**: Primary labeler for forum moderation
- **External Labelers**: Optional third-party moderation services
- **User Preferences**: Users can subscribe to multiple labelers

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    MODERATION ARCHITECTURE                       │
└─────────────────────────────────────────────────────────────────┘

                    ┌──────────────────┐
                    │  phpBB MCP       │
                    │  (Moderator UI)  │
                    └────────┬─────────┘
                             │
                             ▼
                    ┌──────────────────┐
                    │  AT Proto        │
                    │  Extension       │
                    └────────┬─────────┘
                             │
              ┌──────────────┴──────────────┐
              │                             │
              ▼                             ▼
     ┌─────────────────┐           ┌─────────────────┐
     │  Ozone Labeler  │           │  Forum PDS      │
     │  (Label API)    │           │  (Config)       │
     └────────┬────────┘           └─────────────────┘
              │
              ▼
     ┌─────────────────┐
     │  Public Relay   │
     │  (Firehose)     │
     └────────┬────────┘
              │
              ▼
     ┌─────────────────┐
     │  Sync Service   │
     │  (Label Sub)    │
     └────────┬────────┘
              │
              ▼
     ┌─────────────────┐
     │  Local Cache    │
     │  (phpbb_atproto │
     │   _labels)      │
     └────────┬────────┘
              │
              ▼
     ┌─────────────────┐
     │  phpBB Display  │
     │  (Filtered)     │
     └─────────────────┘
```

---

## Moderator Actions Mapping

### Disapprove Post

**phpBB Flow:**
1. Moderator navigates to MCP → Queue → Unapproved posts
2. Selects post and clicks "Disapprove"

**AT Protocol Flow:**
1. Extension intercepts `core.mcp_post_approve` event
2. Looks up post's AT URI from `phpbb_atproto_posts`
3. Calls Ozone labeler API:
   ```json
   POST /xrpc/tools.ozone.moderation.emitEvent
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
     "createdBy": "did:plc:moderator"
   }
   ```
4. Ozone publishes label to its repo
5. Label propagates via relay firehose
6. Sync Service receives label, stores in `phpbb_atproto_labels`
7. phpBB queries exclude posts with `!hide` label

### Soft Delete Post

Same as disapprove - applies `!hide` label.

### Restore Post

**phpBB Flow:**
1. Moderator views deleted posts
2. Clicks "Restore"

**AT Protocol Flow:**
1. Extension calls Ozone to negate the `!hide` label:
   ```json
   POST /xrpc/tools.ozone.moderation.emitEvent
   {
     "event": {
       "$type": "tools.ozone.moderation.defs#modEventLabel",
       "createLabelVals": [],
       "negateLabelVals": ["!hide"]
     },
     "subject": {
       "uri": "at://did:plc:author/net.vza.forum.post/3jui7kd2zoik2",
       "cid": "bafyreid..."
     }
   }
   ```
2. Label negation propagates
3. Sync Service removes label from cache
4. Post becomes visible again

### Mark as Spam

**AT Protocol Flow:**
1. Apply `spam` label instead of `!hide`
2. Users can configure whether to show spam-labeled content
3. Admins can configure forum-wide spam filtering

### Add Content Warning

**AT Protocol Flow:**
1. Apply `!warn` label
2. Post displays with expandable warning
3. User must click to reveal content

### Lock Topic

Topic locking is handled differently - it's a forum configuration change, not a label:

1. Extension updates the topic's first post to indicate locked status
2. Or: Create a `net.vza.forum.topicStatus` record on forum PDS
3. phpBB checks lock status before allowing new replies

**Alternative approach:**
Since users own their posts, locking prevents the AppView from accepting new replies rather than preventing users from posting. The Sync Service rejects posts to locked topics.

### Move Topic

Moving topics is complex because users own their posts:

**Option A: User re-publishes**
1. Moderator requests move
2. System notifies topic author
3. Author updates their post with new forum reference
4. All replies maintain their references to root post

**Option B: Soft redirect**
1. Moderator creates redirect record on forum PDS
2. Original posts remain in original forum (on users' PDSes)
3. AppView displays posts as if moved
4. Less disruptive but creates indirection

**Recommended: Option B** for better UX, as it doesn't require user action.

### Edit Post (as Moderator)

**NOT POSSIBLE in AT Protocol** - users own their data.

**Alternatives:**
- Apply `!warn` label with reason
- Apply `!hide` label
- Contact user to request edit
- Add moderator note/reply

### Ban User

**AT Protocol Flow:**
1. Add user's DID to forum ban list (forum PDS record)
2. Sync Service rejects posts from banned DIDs
3. phpBB extension blocks login for banned DIDs
4. Existing posts remain (owned by user) but can be labeled

```json
// Ban list record on forum PDS
{
  "$type": "net.vza.forum.banlist",
  "bans": [
    {
      "userDid": "did:plc:banned-user",
      "reason": "Repeated violations",
      "bannedBy": "did:plc:moderator",
      "bannedAt": "2024-01-15T10:30:00.000Z",
      "expiresAt": "2024-02-15T10:30:00.000Z"
    }
  ]
}
```

---

## Detailed Flow: Disapprove Post

```
┌──────────────────────────────────────────────────────────────────┐
│ Step 1: Moderator Action                                          │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ MCP: Moderator clicks "Disapprove" │                          │
│  │ on post_id = 123                   │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ Step 2: Extension Intercepts                                      │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ Hook: core.mcp_post_approve        │                          │
│  │                                    │                          │
│  │ $post_id = $event['post_id'];      │                          │
│  │ $action = $event['action'];        │                          │
│  │ // action = 'disapprove'           │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
│  ┌────────────────────────────────────┐                          │
│  │ Lookup AT URI:                     │                          │
│  │ SELECT at_uri, at_cid              │                          │
│  │ FROM phpbb_atproto_posts           │                          │
│  │ WHERE post_id = 123                │                          │
│  │                                    │                          │
│  │ Result:                            │                          │
│  │ uri = at://did:plc:abc/net.vza.   │                          │
│  │       forum.post/3jui7kd2zoik2     │                          │
│  │ cid = bafyreid...                  │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ Step 3: Call Ozone Labeler                                        │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ POST /xrpc/tools.ozone.moderation  │                          │
│  │      .emitEvent                    │                          │
│  │                                    │                          │
│  │ Headers:                           │                          │
│  │   Authorization: Bearer <mod-jwt>  │                          │
│  │                                    │                          │
│  │ Body:                              │                          │
│  │ {                                  │                          │
│  │   "event": {                       │                          │
│  │     "$type": "...#modEventLabel",  │                          │
│  │     "createLabelVals": ["!hide"]   │                          │
│  │   },                               │                          │
│  │   "subject": {                     │                          │
│  │     "$type": "...strongRef",       │                          │
│  │     "uri": "<at-uri>",             │                          │
│  │     "cid": "<cid>"                 │                          │
│  │   },                               │                          │
│  │   "createdBy": "<mod-did>"         │                          │
│  │ }                                  │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ Step 4: Label Propagates                                          │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ Ozone creates label record:        │                          │
│  │                                    │                          │
│  │ {                                  │                          │
│  │   "src": "<labeler-did>",          │                          │
│  │   "uri": "<subject-uri>",          │                          │
│  │   "cid": "<subject-cid>",          │                          │
│  │   "val": "!hide",                  │                          │
│  │   "cts": "2024-01-15T10:30:00Z"    │                          │
│  │ }                                  │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
│  ┌────────────────────────────────────┐                          │
│  │ Relay broadcasts via firehose:     │                          │
│  │ com.atproto.label.subscribeLabels  │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ Step 5: Sync Service Receives Label                               │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ Sync Service label subscriber:     │                          │
│  │                                    │                          │
│  │ 1. Receive label event             │                          │
│  │ 2. Check if subject_uri matches    │                          │
│  │    our forum's content             │                          │
│  │ 3. Insert into phpbb_atproto_labels│                          │
│  │                                    │                          │
│  │ INSERT INTO phpbb_atproto_labels   │                          │
│  │ (subject_uri, label_value,         │                          │
│  │  label_src, created_at)            │                          │
│  │ VALUES (?, '!hide', ?, NOW())      │                          │
│  └──────────────┬─────────────────────┘                          │
│                 │                                                 │
│                 ▼                                                 │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│ Step 6: phpBB Displays Filtered View                              │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌────────────────────────────────────┐                          │
│  │ viewtopic.php query:               │                          │
│  │                                    │                          │
│  │ SELECT p.*                         │                          │
│  │ FROM phpbb_posts p                 │                          │
│  │ LEFT JOIN phpbb_atproto_posts ap   │                          │
│  │   ON p.post_id = ap.post_id        │                          │
│  │ LEFT JOIN phpbb_atproto_labels l   │                          │
│  │   ON ap.at_uri = l.subject_uri     │                          │
│  │   AND l.label_value = '!hide'      │                          │
│  │   AND l.negated = 0                │                          │
│  │ WHERE p.topic_id = ?               │                          │
│  │   AND l.id IS NULL  -- no !hide    │                          │
│  │                                    │                          │
│  │ Result: Post 123 excluded          │                          │
│  └────────────────────────────────────┘                          │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

---

## Ozone Setup

### Requirements

1. **Forum PDS Account**: The forum needs a PDS account to act as labeler
2. **Ozone Instance**: Deploy Ozone or use hosted service
3. **Labeler Declaration**: Publish labeler service record

### Labeler Service Declaration

The forum PDS publishes a labeler declaration:

```json
// at://forum-did/app.bsky.labeler.service/self
{
  "$type": "app.bsky.labeler.service",
  "policies": {
    "labelValues": ["!hide", "!warn", "spam", "nsfw", "spoiler", "off-topic"],
    "labelValueDefinitions": [
      {
        "identifier": "!hide",
        "severity": "alert",
        "blurs": "content",
        "defaultSetting": "hide",
        "locales": [
          {
            "lang": "en",
            "name": "Hidden",
            "description": "Content hidden by moderators"
          }
        ]
      },
      {
        "identifier": "!warn",
        "severity": "inform",
        "blurs": "content",
        "defaultSetting": "warn",
        "locales": [
          {
            "lang": "en",
            "name": "Content Warning",
            "description": "Content may be objectionable"
          }
        ]
      },
      {
        "identifier": "spam",
        "severity": "alert",
        "blurs": "content",
        "defaultSetting": "warn",
        "locales": [
          {
            "lang": "en",
            "name": "Spam",
            "description": "Marked as spam"
          }
        ]
      }
    ]
  },
  "createdAt": "2024-01-01T00:00:00.000Z"
}
```

### Moderator Authentication

Moderators need Ozone accounts with appropriate permissions:

```json
// Ozone team member
{
  "did": "did:plc:moderator",
  "role": "moderator",
  "disabled": false
}
```

---

## Label Queries

### Check if Post is Hidden

```php
function is_post_hidden($at_uri) {
    $sql = "SELECT COUNT(*) as cnt
            FROM phpbb_atproto_labels
            WHERE subject_uri = ?
            AND label_value = '!hide'
            AND negated = 0
            AND (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP())";
    // ...
    return $result['cnt'] > 0;
}
```

### Get All Labels for Post

```php
function get_post_labels($at_uri) {
    $sql = "SELECT label_value, label_src, created_at
            FROM phpbb_atproto_labels
            WHERE subject_uri = ?
            AND negated = 0
            AND (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP())";
    // ...
}
```

### Filter Posts Query

```php
// Modified viewtopic query
$sql = "SELECT p.*,
               GROUP_CONCAT(l.label_value) as labels
        FROM phpbb_posts p
        JOIN phpbb_atproto_posts ap ON p.post_id = ap.post_id
        LEFT JOIN phpbb_atproto_labels l
            ON ap.at_uri = l.subject_uri
            AND l.negated = 0
        WHERE p.topic_id = ?
        GROUP BY p.post_id
        HAVING labels IS NULL
            OR labels NOT LIKE '%!hide%'";
```

---

## User Reports

Users can report content to the forum's labeler:

### Report Flow

1. User clicks "Report" on a post
2. phpBB extension calls:
   ```json
   POST /xrpc/com.atproto.moderation.createReport
   {
     "reasonType": "com.atproto.moderation.defs#reasonSpam",
     "reason": "This post is advertising",
     "subject": {
       "$type": "com.atproto.repo.strongRef",
       "uri": "at://did:plc:author/net.vza.forum.post/...",
       "cid": "..."
     }
   }
   ```
3. Report appears in Ozone moderation queue
4. Moderator reviews and applies labels

### Report Reasons

| AT Protocol Reason | phpBB Mapping |
|-------------------|---------------|
| `reasonSpam` | Spam report |
| `reasonViolation` | Rule violation |
| `reasonMisleading` | Misinformation |
| `reasonSexual` | Inappropriate content |
| `reasonRude` | Harassment |
| `reasonOther` | Other |

---

## Moderator Permissions

### phpBB ACL → Ozone Roles

| phpBB Permission | Ozone Role | Labels Allowed |
|-----------------|------------|----------------|
| `m_approve` | Moderator | `!hide`, `!warn`, `spam` |
| `m_delete` | Moderator | `!hide` |
| `m_warn` | Moderator | `!warn` |
| `a_board` | Admin | All labels |

### Implementation

```php
function can_moderate_post($user_id, $post_id) {
    // Check phpBB ACL
    $has_permission = $auth->acl_get('m_approve', $forum_id);

    if (!$has_permission) {
        return false;
    }

    // Check if user is registered as Ozone team member
    $ozone_client = $this->container->get('atproto.ozone_client');
    return $ozone_client->is_team_member($user_did);
}
```

---

## Edge Cases

### Post Labeled by Multiple Labelers

- phpBB subscribes to its own labeler
- Labels from other labelers can be optionally shown
- Most restrictive label wins (e.g., `!hide` overrides `!warn`)

### Labeler Unavailable

- Cache labels locally in `phpbb_atproto_labels`
- Continue serving cached labels
- Queue moderation actions for retry
- Alert admin if labeler down for extended period

### User's PDS Unavailable

- Post remains visible from local cache
- Cannot verify content authenticity
- Cannot update labels on that content
- Show "source unavailable" indicator

### Label Expiration

Some labels may have expiration:
```php
// Check expiration in queries
WHERE (expires_at IS NULL OR expires_at > UNIX_TIMESTAMP())
```

### Race Conditions

- User views post while moderation in progress
- Solution: Optimistic UI update + eventual consistency
- After label emit, immediately update local cache
- Firehose confirmation updates authoritative state
