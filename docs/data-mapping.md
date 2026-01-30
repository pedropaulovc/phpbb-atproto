# phpBB to AT Protocol Data Mapping

This document specifies how phpBB data structures map to AT Protocol lexicons and records.

## Lexicon Overview

| Lexicon ID | Storage | Purpose |
|------------|---------|---------|
| `net.vza.forum.post` | User PDS | Forum posts and topic first posts |
| `net.vza.forum.board` | Forum PDS | Forum/subforum definitions |
| `net.vza.forum.config` | Forum PDS | Global forum configuration |
| `net.vza.forum.acl` | Forum PDS | Permissions and group definitions |
| `net.vza.forum.settings` | User PDS | User preferences |
| `net.vza.forum.vote` | User PDS | Poll votes |
| `net.vza.forum.reaction` | User PDS | Post likes/reactions |
| `net.vza.forum.subscription` | User PDS | Forum/topic subscriptions |
| `net.vza.forum.bookmark` | User PDS | Bookmarked topics |
| `net.vza.forum.membership` | Forum PDS | Group memberships |

---

## Post Mapping

### phpBB → AT Protocol

| phpBB Column | AT Protocol Field | Notes |
|--------------|-------------------|-------|
| `post_id` | Record key (TID) | Generated, mapped in `phpbb_atproto_posts` |
| `post_text` | `text` | BBCode preserved |
| `post_time` | `createdAt` | Converted to ISO 8601 |
| `forum_id` | `forum.uri` | Reference to board record |
| `topic_id` | `reply.root.uri` | For replies, points to first post |
| `post_subject` | `subject` | Only on first post of topic |
| `topic_type` | `topicType` | Only on first post |
| `enable_bbcode` | `enableBbcode` | Boolean |
| `enable_smilies` | `enableSmilies` | Boolean |
| `enable_sig` | `enableSignature` | Boolean |

### Record Key Strategy

Posts use **TID (Timestamp ID)** format for record keys:
- Format: Base32-encoded timestamp + random suffix
- Example: `3jui7kd2zoik2`
- Ensures chronological ordering and uniqueness

### AT URI Format

```
at://{author-did}/net.vza.forum.post/{tid}
```

Example:
```
at://did:plc:abc123/net.vza.forum.post/3jui7kd2zoik2
```

### Topic vs Reply

**First post of topic (creates topic):**
```json
{
  "$type": "net.vza.forum.post",
  "text": "Welcome to this discussion about...",
  "createdAt": "2024-01-15T10:30:00.000Z",
  "forum": {
    "uri": "at://did:plc:forum/net.vza.forum.board/general",
    "cid": "bafyreig..."
  },
  "subject": "Interesting Topic Title",
  "topicType": "normal"
}
```

**Reply to topic:**
```json
{
  "$type": "net.vza.forum.post",
  "text": "I agree with the original poster...",
  "createdAt": "2024-01-15T11:00:00.000Z",
  "forum": {
    "uri": "at://did:plc:forum/net.vza.forum.board/general",
    "cid": "bafyreig..."
  },
  "reply": {
    "root": {
      "uri": "at://did:plc:author1/net.vza.forum.post/3jui7kd2zoik2",
      "cid": "bafyreid..."
    },
    "parent": {
      "uri": "at://did:plc:author1/net.vza.forum.post/3jui7kd2zoik2",
      "cid": "bafyreid..."
    }
  }
}
```

### Attachments

Attachments are stored as blobs on the user's PDS:

```json
{
  "attachments": [
    {
      "file": {
        "$type": "blob",
        "ref": { "$link": "bafkreig..." },
        "mimeType": "image/png",
        "size": 12345
      },
      "mimeType": "image/png",
      "filename": "screenshot.png",
      "comment": "See attached image"
    }
  ]
}
```

### Polls

Polls are embedded in the first post:

```json
{
  "poll": {
    "title": "What's your favorite color?",
    "options": ["Red", "Blue", "Green", "Yellow"],
    "maxOptions": 1,
    "endAt": "2024-02-15T00:00:00.000Z",
    "allowVoteChange": true
  }
}
```

---

## Board/Forum Mapping

### phpBB → AT Protocol

| phpBB Column | AT Protocol Field | Notes |
|--------------|-------------------|-------|
| `forum_id` | Record key (TID) | Mapped in `phpbb_atproto_forums` |
| `forum_name` | `name` | Display name |
| `forum_desc` | `description` | BBCode allowed |
| `forum_type` | `boardType` | 0=category, 1=forum, 2=link |
| `parent_id` | `parent.uri` | Reference to parent board |
| `left_id`/`right_id` | `order` | Converted to simple ordering |
| `forum_rules` | `rules` | Forum-specific rules |
| `forum_status` | `status` | 0=open, 1=locked |

### Board Type Mapping

| phpBB `forum_type` | AT Protocol `boardType` |
|--------------------|------------------------|
| 0 (FORUM_CAT) | `category` |
| 1 (FORUM_POST) | `forum` |
| 2 (FORUM_LINK) | `link` |

### Record Key Strategy

Boards use **TID (Timestamp ID)** format for record keys:
- Format: Base32-encoded timestamp + random suffix
- Example: `3jui7kd2zoik2`
- Ensures stable identity even when board is renamed

The `slug` field within the record provides human-readable routing:
- Slug is mutable and can be updated on rename
- phpBB maintains a `slug → AT URI` lookup table for URL routing

### AT URI Format

```
at://{forum-did}/net.vza.forum.board/{tid}
```

Example:
```
at://did:plc:forum/net.vza.forum.board/3jui7kd2zoik2
```

The slug (`general-discussion`) lives in the record's `slug` field, not the URI.

### Example Board Record

```json
{
  "$type": "net.vza.forum.board",
  "name": "General Discussion",
  "slug": "general-discussion",
  "description": "Talk about anything and everything",
  "boardType": "forum",
  "order": 1,
  "parent": {
    "uri": "at://did:plc:forum/net.vza.forum.board/3jui7kd0abcde",
    "cid": "bafyreig..."
  },
  "settings": {
    "topicsPerPage": 25,
    "allowPolls": true,
    "requireApproval": false,
    "displayOnIndex": true
  },
  "status": "open"
}
```

---

## Configuration Mapping

### phpBB → AT Protocol

| phpBB Config Key | AT Protocol Field | Notes |
|------------------|-------------------|-------|
| `sitename` | `siteName` | Forum title |
| `site_desc` | `siteDescription` | Meta description |
| `site_logo` | `siteLogo` | Blob reference |
| `default_lang` | `defaultLanguage` | Language code |
| `board_timezone` | `defaultTimezone` | Timezone ID |
| `default_style` | `defaultStyle` | Theme name |
| `default_dateformat` | `dateFormat` | PHP date format |

### Record Key Strategy

Config uses the special key `self`:
```
at://{forum-did}/net.vza.forum.config/self
```

### Example Config Record

```json
{
  "$type": "net.vza.forum.config",
  "siteName": "My Awesome Forum",
  "siteDescription": "A community for discussing awesome things",
  "defaultLanguage": "en",
  "defaultTimezone": "UTC",
  "features": {
    "allowAttachments": true,
    "allowBbcode": true,
    "allowSmilies": true,
    "allowSignatures": true,
    "allowQuickReply": true
  },
  "posting": {
    "maxPostChars": 60000,
    "minPostChars": 1,
    "floodInterval": 15,
    "editTime": 0,
    "deleteTime": 0
  },
  "registration": {
    "requireActivation": "none",
    "minUsernameChars": 3,
    "maxUsernameChars": 20,
    "minPasswordChars": 6
  },
  "ranks": [
    {
      "title": "Newbie",
      "minPosts": 0,
      "isSpecial": false
    },
    {
      "title": "Member",
      "minPosts": 10,
      "isSpecial": false
    },
    {
      "title": "Administrator",
      "isSpecial": true
    }
  ]
}
```

---

## User Settings Mapping

### phpBB → AT Protocol

| phpBB Column | AT Protocol Field | Notes |
|--------------|-------------------|-------|
| `user_lang` | `language` | Language code |
| `user_timezone` | `timezone` | Timezone ID |
| `user_dateformat` | `dateFormat` | Format string |
| `user_style` | `style` | Theme preference |
| `user_topic_show_days` | `display.topicShowDays` | Filter setting |
| `user_topic_sortby_type` | `display.topicSortBy` | Sort field |
| `user_topic_sortby_dir` | `display.topicSortDir` | Sort direction |
| `user_notify_type` | `notifications.notifyMethod` | Notification method |

### Record Key Strategy

Settings use the special key `self`:
```
at://{user-did}/net.vza.forum.settings/self
```

---

## ACL/Permission Mapping

### phpBB → AT Protocol

| phpBB Table | AT Protocol | Notes |
|-------------|-------------|-------|
| `phpbb_groups` | `acl.groups[]` | Group definitions |
| `phpbb_acl_roles` | `acl.roles[]` | Role templates |
| `phpbb_acl_groups` | `acl.globalPermissions[]` | Global assignments |
| `phpbb_acl_roles_data` | `role.permissions[]` | Role permission sets |

### Permission Format

```json
{
  "permission": "f_post",
  "setting": "yes"
}
```

Settings: `yes` | `no` | `never`
- `yes`: Permission granted
- `no`: Permission denied (can be overridden)
- `never`: Permission denied (cannot be overridden)

### Example ACL Record

```json
{
  "$type": "net.vza.forum.acl",
  "groups": [
    {
      "id": "guests",
      "name": "Guests",
      "type": "special"
    },
    {
      "id": "registered",
      "name": "Registered Users",
      "type": "special"
    },
    {
      "id": "moderators",
      "name": "Moderators",
      "type": "closed",
      "color": "00AA00",
      "showOnLegend": true
    }
  ],
  "roles": [
    {
      "id": "standard-user",
      "name": "Standard User",
      "type": "user",
      "permissions": [
        { "permission": "u_viewprofile", "setting": "yes" },
        { "permission": "u_sendpm", "setting": "yes" }
      ]
    },
    {
      "id": "forum-moderator",
      "name": "Forum Moderator",
      "type": "moderator",
      "permissions": [
        { "permission": "m_edit", "setting": "yes" },
        { "permission": "m_delete", "setting": "yes" },
        { "permission": "m_approve", "setting": "yes" },
        { "permission": "m_lock", "setting": "yes" }
      ]
    }
  ],
  "globalPermissions": [
    {
      "groupId": "registered",
      "roleId": "standard-user"
    }
  ],
  "forumPermissions": [
    {
      "forumUri": "at://did:plc:forum/net.vza.forum.board/3jui7kd2zoik2",
      "groupId": "moderators",
      "roleId": "forum-moderator"
    }
  ]
}
```

---

## Mapping Tables

### phpbb_atproto_users

Maps DIDs to phpBB user IDs and stores OAuth tokens.

| Column | Type | Description |
|--------|------|-------------|
| `user_id` | int | phpBB user ID |
| `did` | varchar(255) | User's DID |
| `handle` | varchar(255) | User's handle (cached) |
| `pds_url` | varchar(255) | User's PDS URL |
| `access_token` | text | Encrypted access token |
| `refresh_token` | text | Encrypted refresh token |
| `token_expires_at` | int | Token expiry timestamp |
| `created_at` | int | Mapping creation time |

### phpbb_atproto_posts

Maps AT URIs to phpBB post IDs. Topics are represented by their first post (no separate topic table).

| Column | Type | Description |
|--------|------|-------------|
| `post_id` | int | phpBB post ID |
| `at_uri` | varchar(512) | AT Protocol URI |
| `at_cid` | varchar(64) | Content identifier |
| `author_did` | varchar(255) | Author's DID |
| `is_topic_starter` | tinyint | 1 if this post creates a topic |
| `sync_status` | enum | 'synced', 'pending', 'failed' |
| `created_at` | int | Record creation time |
| `updated_at` | int | Last update time |

**Note**: Topics don't have separate AT Protocol records. The first post's AT URI represents the topic, and the topic title lives in the first post's `subject` field.

### phpbb_atproto_forums

Maps AT URIs to phpBB forum IDs with slug-based routing.

| Column | Type | Description |
|--------|------|-------------|
| `forum_id` | int | phpBB forum ID |
| `at_uri` | varchar(512) | AT Protocol URI (uses TID key) |
| `at_cid` | varchar(64) | Content identifier |
| `slug` | varchar(255) | URL-friendly slug (mutable) |
| `updated_at` | int | Last sync time |

**Note**: The AT URI uses a TID key (immutable), while the slug is a mutable field for human-readable URL routing.

### phpbb_atproto_labels

Caches moderation labels from the Ozone labeler.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int | Auto-increment ID |
| `subject_uri` | varchar(512) | AT URI of labeled subject |
| `label_value` | varchar(128) | Label value (e.g., '!hide') |
| `label_src` | varchar(255) | Labeler DID |
| `created_at` | int | Label creation time |
| `negated` | tinyint | 1 if label was negated |
| `expires_at` | int | Expiry timestamp (optional) |

### phpbb_atproto_cursors

Tracks firehose cursor positions for resumable sync.

| Column | Type | Description |
|--------|------|-------------|
| `service` | varchar(255) | Service identifier |
| `cursor` | bigint | Cursor position |
| `updated_at` | int | Last update time |

### phpbb_atproto_queue

Retry queue for failed PDS writes.

| Column | Type | Description |
|--------|------|-------------|
| `id` | int | Auto-increment ID |
| `operation` | enum | 'create', 'update', 'delete' |
| `collection` | varchar(255) | AT Protocol collection |
| `record_data` | text | JSON record data |
| `user_did` | varchar(255) | Target user DID |
| `attempts` | int | Retry attempt count |
| `last_error` | text | Last error message |
| `next_retry_at` | int | Next retry timestamp |
| `created_at` | int | Queue entry creation time |

---

## ID Resolution

### Finding AT URI from phpBB ID

```php
// Post lookup
SELECT at_uri, at_cid FROM phpbb_atproto_posts WHERE post_id = ?

// Forum lookup
SELECT at_uri, at_cid FROM phpbb_atproto_forums WHERE forum_id = ?

// User lookup
SELECT did FROM phpbb_atproto_users WHERE user_id = ?
```

### Finding phpBB ID from AT URI

```php
// Post lookup
SELECT post_id FROM phpbb_atproto_posts WHERE at_uri = ?

// Forum lookup
SELECT forum_id FROM phpbb_atproto_forums WHERE at_uri = ?

// User lookup
SELECT user_id FROM phpbb_atproto_users WHERE did = ?
```

---

## Write Operations

### Creating a Post

1. User submits post via phpBB
2. Extension intercepts `core.posting_modify_submit_post_before`
3. Build AT Protocol record from form data
4. Call `com.atproto.repo.createRecord` on user's PDS
5. On success:
   - Store URI mapping in `phpbb_atproto_posts`
   - Allow phpBB to save to local cache
6. On failure:
   - Add to `phpbb_atproto_queue`
   - Save locally with `sync_status = 'pending'`

### Editing a Post

1. User edits post via phpBB
2. Extension intercepts edit event
3. Look up AT URI from `phpbb_atproto_posts`
4. Call `com.atproto.repo.putRecord` on user's PDS
5. Update `at_cid` in mapping table
6. Update local cache

### Deleting a Post

1. User deletes post via phpBB
2. Extension intercepts delete event
3. Look up AT URI from `phpbb_atproto_posts`
4. Call `com.atproto.repo.deleteRecord` on user's PDS
5. Remove mapping or mark as deleted
6. Remove from local cache

---

## Read Operations (Sync Service)

### Processing Firehose Events

```
Event Type: com.atproto.sync.subscribeRepos#commit
  → Filter for net.vza.forum.* collections
  → For each operation:
      create: Insert into local DB + mapping table
      update: Update local DB + mapping CID
      delete: Remove from local DB or mark deleted
```

### User Resolution

When a new DID is encountered:
1. Check `phpbb_atproto_users` for existing mapping
2. If not found:
   - Resolve DID to get handle via `com.atproto.identity.resolveHandle`
   - Create phpBB user with handle as username
   - Store mapping in `phpbb_atproto_users`
3. Return `user_id`

### Forum Resolution

Posts reference forum URIs:
1. Look up `phpbb_atproto_forums` for `forum_id`
2. If not found, post goes to "uncategorized" or is rejected
3. Validates user has permission to post in forum
