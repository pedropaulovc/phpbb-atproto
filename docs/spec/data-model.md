# Data Model

## Overview

This document defines how phpBB's data model maps to AT Protocol, including:
- Data ownership patterns (User PDS vs Forum PDS vs Cache)
- Lexicon mappings for each data type
- Database schema for local cache

## Data Ownership Model

| Data Type | Primary Location | Synced To |
|-----------|------------------|-----------|
| Posts, topics | User's PDS | Local MySQL cache |
| User profile | User's PDS | Local cache |
| User settings | User's PDS | Local cache |
| Forum structure | Forum PDS | Local cache |
| Forum config | Forum PDS | Local cache |
| Permissions/ACL | Forum PDS | Local cache (enforced locally) |
| Moderation labels | Forum PDS (labeler) | Local cache |
| Private messages | User's PDS | Local cache (future E2EE) |
| Derived data | Local only | - |

## Lexicon Mappings

### User PDS Collections

| phpBB Table | Lexicon | Notes |
|-------------|---------|-------|
| `phpbb_posts` | `net.vza.forum.post` | First post = topic starter |
| `phpbb_users` | N/A | Profile via DID document |
| `phpbb_poll_votes` | `net.vza.forum.vote` | Poll participation |
| `phpbb_bookmarks` | `net.vza.forum.bookmark` | Saved topics |
| `phpbb_topics_watch` | `net.vza.forum.subscription` | Notifications |

### Forum PDS Collections

| phpBB Table | Lexicon | Notes |
|-------------|---------|-------|
| `phpbb_forums` | `net.vza.forum.board` | Includes categories |
| `phpbb_config` | `net.vza.forum.config` | Global settings |
| `phpbb_acl_*` | `net.vza.forum.acl` | Permission templates |
| `phpbb_user_group` | `net.vza.forum.membership` | Group assignments |

### Cache-Only (Local MySQL)

| phpBB Table | Purpose |
|-------------|---------|
| `phpbb_sessions*` | Active sessions |
| `phpbb_log` | Admin/mod logs |
| `phpbb_search_*` | Search index |
| `phpbb_notifications` | Notification queue |

## Local Cache Tables (New)

These tables map AT Protocol data to phpBB IDs:

| Table | Purpose |
|-------|---------|
| `phpbb_atproto_users` | DID ↔ user_id + encrypted tokens |
| `phpbb_atproto_posts` | AT URI ↔ post_id |
| `phpbb_atproto_forums` | AT URI ↔ forum_id |
| `phpbb_atproto_labels` | Cached moderation labels |
| `phpbb_atproto_cursors` | Firehose positions |
| `phpbb_atproto_queue` | Retry queue |

See [Migrations Spec](./components/phpbb-extension/migrations.md) for full schemas.
