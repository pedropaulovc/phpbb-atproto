# phpBB Data Ownership Classification

This document classifies all phpBB data by ownership pattern for AT Protocol migration.

## Ownership Model Overview

| Location | Description | Sync Direction |
|----------|-------------|----------------|
| **User's PDS** | Content and settings owned by the user | User writes → Firehose → Local cache |
| **Forum PDS** | Configuration owned by forum operators | Admin writes → Firehose → Local cache |
| **Local Cache** | Derived/computed data, no AT Protocol representation | Rebuilt from firehose |

## User-Owned Data (→ User's PDS)

Data that belongs to individual users and should live on their Personal Data Server.

### Posts & Content

| phpBB Table | AT Protocol Record | Collection |
|-------------|-------------------|------------|
| `phpbb_posts` | `net.vza.forum.post` | User creates posts on their PDS |
| `phpbb_topics` (metadata) | Embedded in first post | Topic title, type, poll stored in first post |
| `phpbb_attachments` | Blob + reference in post | Binary data on user's PDS |
| `phpbb_privmsgs` | `net.vza.forum.message` (future) | Private messages (future E2EE) |
| `phpbb_poll_options` | Embedded in post | Poll choices in topic's first post |
| `phpbb_poll_votes` | `net.vza.forum.vote` | User's vote on a poll |

### User Profile

| phpBB Table/Column | AT Protocol Record | Notes |
|-------------------|-------------------|-------|
| `phpbb_users.username` | Handle or displayName | DID becomes primary identity |
| `phpbb_users.user_avatar*` | Blob + profile record | Avatar image |
| `phpbb_users.user_sig*` | Profile record | Signature |
| `phpbb_profile_fields_data` | Profile record | Custom fields |

### User Settings & Preferences

| phpBB Table/Column | AT Protocol Record | Notes |
|-------------------|-------------------|-------|
| `phpbb_users.user_lang` | `net.vza.forum.settings` | Language preference |
| `phpbb_users.user_timezone` | `net.vza.forum.settings` | Timezone |
| `phpbb_users.user_dateformat` | `net.vza.forum.settings` | Date format |
| `phpbb_users.user_style` | `net.vza.forum.settings` | Theme preference |
| `phpbb_users.user_notify*` | `net.vza.forum.settings` | Notification prefs |
| `phpbb_users.user_allow_*` | `net.vza.forum.settings` | Privacy settings |
| `phpbb_users.user_topic_*` | `net.vza.forum.settings` | Display preferences |
| `phpbb_users.user_post_*` | `net.vza.forum.settings` | Sort preferences |

### User Relationships & Actions

| phpBB Table | AT Protocol Record | Notes |
|-------------|-------------------|-------|
| `phpbb_zebra` | `app.bsky.graph.follow` or custom | Friends/foes list |
| `phpbb_bookmarks` | `net.vza.forum.bookmark` | Saved posts |
| `phpbb_forums_watch` | `net.vza.forum.subscription` | Forum subscriptions |
| `phpbb_topics_watch` | `net.vza.forum.subscription` | Topic subscriptions |
| `phpbb_drafts` | `net.vza.forum.draft` | Saved drafts |

### User Reports

| phpBB Table | AT Protocol | Notes |
|-------------|-------------|-------|
| `phpbb_reports` | `com.atproto.moderation.createReport` | Reports go to Ozone |

---

## Forum-Owned Data (→ Forum PDS)

Data owned by the forum operator, managed through a forum-controlled PDS account.

### Forum Structure

| phpBB Table | AT Protocol Record | Notes |
|-------------|-------------------|-------|
| `phpbb_forums` | `net.vza.forum.board` | Forum/subforum definitions |
| Categories (forum_type=0) | `net.vza.forum.category` | Parent groupings |

**Board Record Structure**:
```json
{
  "$type": "net.vza.forum.board",
  "name": "General Discussion",
  "description": "Talk about anything",
  "slug": "general",
  "order": 1,
  "parent": { "uri": "at://forum-did/...", "cid": "..." },
  "forumType": "forum",
  "settings": {
    "allowPolls": true,
    "requireApproval": false,
    "topicsPerPage": 25
  }
}
```

### Forum Configuration

| phpBB Table | AT Protocol Record | Notes |
|-------------|-------------------|-------|
| `phpbb_config` | `net.vza.forum.config` | Global settings |
| `phpbb_config_text` | `net.vza.forum.config` | Large text settings |
| `phpbb_bbcodes` | `net.vza.forum.config` | Custom BBCodes |
| `phpbb_smilies` | `net.vza.forum.config` | Emoticons |
| `phpbb_icons` | `net.vza.forum.config` | Post icons |
| `phpbb_words` | `net.vza.forum.config` | Word censoring |
| `phpbb_ranks` | `net.vza.forum.rank` | User rank definitions |
| `phpbb_styles` | `net.vza.forum.config` | Theme definitions |

### Permissions (ACL)

| phpBB Table | AT Protocol Record | Notes |
|-------------|-------------------|-------|
| `phpbb_acl_options` | `net.vza.forum.acl` | Permission definitions |
| `phpbb_acl_roles` | `net.vza.forum.acl` | Role templates |
| `phpbb_acl_roles_data` | `net.vza.forum.acl` | Role permissions |
| `phpbb_acl_groups` | `net.vza.forum.acl` | Group assignments |
| `phpbb_acl_users` | `net.vza.forum.acl` | User overrides |
| `phpbb_groups` | `net.vza.forum.group` | Group definitions |
| `phpbb_user_group` | `net.vza.forum.membership` | Group membership |

**ACL Record Structure**:
```json
{
  "$type": "net.vza.forum.acl",
  "roles": [
    {
      "id": "moderator",
      "name": "Moderator",
      "permissions": ["m_approve", "m_delete", "m_edit", "m_lock"]
    }
  ],
  "forumPermissions": [
    {
      "forum": { "uri": "at://forum-did/net.vza.forum.board/general" },
      "groupAssignments": [
        { "group": "registered", "role": "standard" }
      ]
    }
  ]
}
```

### Moderation (Labels)

| phpBB Concept | AT Protocol | Notes |
|---------------|-------------|-------|
| Post disapproval | `!hide` label | Via Ozone labeler |
| Soft delete | `!hide` label | Via Ozone labeler |
| Content warnings | `!warn` label | Via Ozone labeler |
| Spam marking | `spam` label | Via Ozone labeler |
| Topic lock | Board config update | Lock state in forum PDS |
| Topic move | User re-publishes | New forum reference |
| `phpbb_banlist` | `net.vza.forum.ban` + local enforcement | Ban list in forum PDS |

---

## Local Cache Only (No AT Protocol)

Data computed locally or used only for operational purposes.

### Derived Statistics

| phpBB Table/Column | Derivation |
|-------------------|------------|
| `phpbb_users.user_posts` | COUNT from firehose posts |
| `phpbb_users.user_lastvisit` | Local session tracking |
| `phpbb_users.user_lastpost_time` | MAX post_time from firehose |
| `phpbb_forums.forum_posts_*` | COUNT aggregations |
| `phpbb_forums.forum_topics_*` | COUNT aggregations |
| `phpbb_forums.forum_last_*` | Latest post info |
| `phpbb_topics.topic_views` | Local view tracking |
| `phpbb_topics.topic_posts_*` | COUNT aggregations |
| `phpbb_topics.topic_last_*` | Latest post info |
| `phpbb_attachments.download_count` | Local download tracking |

### Search Index

| phpBB Table | Notes |
|-------------|-------|
| `phpbb_search_wordlist` | Rebuilt from firehose content |
| `phpbb_search_wordmatch` | Rebuilt from firehose content |
| `phpbb_search_results` | Cached search results |

### Session & Auth (Replaced)

| phpBB Table | AT Protocol Equivalent |
|-------------|----------------------|
| `phpbb_sessions` | AT Protocol OAuth sessions |
| `phpbb_sessions_keys` | AT Protocol refresh tokens |
| `phpbb_login_attempts` | Local rate limiting |
| `phpbb_oauth_*` | AT Protocol native auth |

### System Tables

| phpBB Table | Notes |
|-------------|-------|
| `phpbb_log` | Local audit log |
| `phpbb_moderator_cache` | Permission cache, rebuilt |
| `phpbb_confirm` | CAPTCHA state |
| `phpbb_migrations` | Schema version tracking |
| `phpbb_ext` | Extension registry |
| `phpbb_modules` | ACP module registry |
| `phpbb_bots` | Bot detection patterns |

### Notifications

| phpBB Table | Notes |
|-------------|-------|
| `phpbb_notifications` | Generated from firehose events |
| `phpbb_notification_types` | Local notification definitions |
| `phpbb_notification_emails` | Email delivery queue |
| `phpbb_user_notifications` | User notification preferences |

---

## Mapping Tables (New)

Required for AT Protocol integration:

| Table | Purpose |
|-------|---------|
| `phpbb_atproto_users` | DID ↔ user_id + OAuth tokens |
| `phpbb_atproto_posts` | AT URI ↔ post_id mapping |
| `phpbb_atproto_topics` | AT URI ↔ topic_id mapping |
| `phpbb_atproto_forums` | AT URI ↔ forum_id mapping |
| `phpbb_atproto_labels` | Cached moderation labels |
| `phpbb_atproto_cursors` | Firehose cursor positions |
| `phpbb_atproto_queue` | Retry queue for failed PDS writes |

---

## Data Flow Summary

### Write Path (User Creates Post)

```
User submits post via phpBB
        │
        ▼
Extension intercepts core.posting_modify_submit_post_before
        │
        ▼
Extension writes to User's PDS
  POST /xrpc/com.atproto.repo.createRecord
  {
    repo: user-did,
    collection: "net.vza.forum.post",
    record: { text, forum, reply, ... }
  }
        │
        ▼
On success: phpBB saves to local cache + stores URI mapping
On failure: Queue for retry, show "syncing..." status
```

### Read Path (Sync Service)

```
Firehose event (net.vza.forum.post)
        │
        ▼
Sync Service receives via WebSocket
        │
        ▼
Filter: is collection net.vza.forum.*?
        │
        ▼
Map author DID → phpBB user_id (create user if new)
        │
        ▼
Insert into phpbb_posts + phpbb_atproto_posts
        │
        ▼
Update derived counts (user_posts, forum_posts, etc.)
```

### Moderation Path

```
Moderator clicks "Disapprove" in MCP
        │
        ▼
Extension intercepts core.mcp_post_approve
        │
        ▼
Lookup post's AT URI from phpbb_atproto_posts
        │
        ▼
Call Ozone labeler API
  POST /xrpc/tools.ozone.moderation.emitEvent
  {
    subject: { uri, cid },
    createLabelVals: ["!hide"]
  }
        │
        ▼
Label propagates via relay firehose
        │
        ▼
Sync Service receives label, stores in phpbb_atproto_labels
        │
        ▼
phpBB queries JOIN with labels, excludes !hide posts
```

---

## Migration Considerations

### One-Time Export

For existing phpBB forums migrating to AT Protocol:

1. **Users**: Create PDS accounts or link existing DIDs
2. **Posts**: Backfill to users' PDSes (requires consent)
3. **Forums**: Create forum PDS, publish structure
4. **Config**: Publish to forum PDS
5. **Permissions**: Publish to forum PDS

### Ongoing Sync

After migration:
- All writes go to AT Protocol first
- Local DB is always a cache
- Derived data recalculated from firehose
- Labels control visibility, not local soft-delete flags
