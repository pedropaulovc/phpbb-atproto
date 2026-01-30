# phpBB Database Schema Analysis

This document provides a comprehensive analysis of phpBB 3.3.x database schema for AT Protocol integration planning.

## Overview

phpBB uses 67 tables organized into logical groups. The schema uses a `phpbb_` prefix for all tables.

## Table Categories

### 1. Content Tables (User-Generated)

#### phpbb_posts
Primary content storage for forum posts.

| Column | Type | Description |
|--------|------|-------------|
| post_id | int unsigned | Primary key, auto-increment |
| topic_id | int unsigned | FK to phpbb_topics |
| forum_id | mediumint unsigned | FK to phpbb_forums |
| poster_id | int unsigned | FK to phpbb_users |
| poster_ip | varchar(40) | Author's IP address |
| post_time | int unsigned | Unix timestamp |
| post_subject | varchar(255) | Post subject |
| post_text | mediumtext | Post content (BBCode encoded) |
| post_username | varchar(255) | Username (for guest posts) |
| post_visibility | tinyint | 0=approved, 1=unapproved, 2=deleted |
| post_edit_time | int unsigned | Last edit timestamp |
| post_edit_user | int unsigned | User who last edited |
| post_edit_count | smallint unsigned | Number of edits |
| post_reported | tinyint unsigned | Has been reported |
| post_attachment | tinyint unsigned | Has attachments |
| bbcode_uid | varchar(8) | BBCode processing ID |
| bbcode_bitfield | varchar(255) | BBCode features used |
| enable_bbcode/smilies/sig | tinyint unsigned | Formatting flags |
| post_delete_time/reason/user | Soft delete tracking |

**AT Protocol Mapping**: → User's PDS as `net.vza.forum.post`

#### phpbb_topics
Topic metadata linking posts together.

| Column | Type | Description |
|--------|------|-------------|
| topic_id | int unsigned | Primary key |
| forum_id | mediumint unsigned | FK to phpbb_forums |
| topic_title | varchar(255) | Topic title |
| topic_poster | int unsigned | Original poster user_id |
| topic_time | int unsigned | Creation timestamp |
| topic_status | tinyint | 0=unlocked, 1=locked |
| topic_type | tinyint | 0=normal, 1=sticky, 2=announcement, 3=global |
| topic_first_post_id | int unsigned | FK to first post |
| topic_last_post_id | int unsigned | FK to last post |
| topic_views | mediumint unsigned | View count |
| topic_visibility | tinyint | Same as post visibility |
| poll_title | varchar(255) | Optional poll |
| poll_start/length/max_options | Poll settings |
| topic_posts_approved/unapproved/softdeleted | Post counts |

**AT Protocol Mapping**: Topic metadata embedded in first post's `net.vza.forum.post` record

#### phpbb_attachments
File attachments for posts and private messages.

| Column | Type | Description |
|--------|------|-------------|
| attach_id | int unsigned | Primary key |
| post_msg_id | int unsigned | FK to post or PM |
| topic_id | int unsigned | FK to topic |
| poster_id | int unsigned | Uploader user_id |
| in_message | tinyint unsigned | 0=post, 1=PM |
| physical_filename | varchar(255) | Server filename |
| real_filename | varchar(255) | Original filename |
| filesize | int unsigned | Size in bytes |
| mimetype | varchar(100) | MIME type |
| download_count | mediumint unsigned | Download counter |

**AT Protocol Mapping**: → User's PDS as blob, referenced in post

#### phpbb_privmsgs
Private messages between users.

| Column | Type | Description |
|--------|------|-------------|
| msg_id | int unsigned | Primary key |
| author_id | int unsigned | Sender user_id |
| message_time | int unsigned | Timestamp |
| message_subject | varchar(255) | Subject |
| message_text | mediumtext | Content |
| to_address | text | Recipient DIDs |
| bcc_address | text | BCC recipients |
| message_reported | tinyint unsigned | Has been reported |

**AT Protocol Mapping**: → User's PDS (future E2EE consideration)

---

### 2. User Tables

#### phpbb_users
User accounts and preferences.

| Column | Type | Description |
|--------|------|-------------|
| user_id | int unsigned | Primary key |
| username | varchar(255) | Display name |
| username_clean | varchar(255) | Lowercase for lookup |
| user_password | varchar(255) | Hashed password |
| user_email | varchar(100) | Email address |
| user_type | tinyint | 0=normal, 1=inactive, 2=bot/ignore, 3=founder |
| group_id | mediumint unsigned | Primary group |
| user_regdate | int unsigned | Registration timestamp |
| user_lastvisit | int unsigned | Last login |
| user_posts | mediumint unsigned | Post count |
| user_lang | varchar(30) | Preferred language |
| user_timezone | varchar(100) | Timezone |
| user_dateformat | varchar(64) | Date display format |
| user_style | mediumint unsigned | Theme preference |
| user_rank | mediumint unsigned | User rank |
| user_avatar | varchar(255) | Avatar path |
| user_avatar_type | varchar(255) | Avatar source type |
| user_sig | mediumtext | Signature |
| user_allow_pm | tinyint unsigned | Accept PMs |
| user_notify/notify_pm | tinyint unsigned | Notification prefs |
| user_colour | varchar(6) | Username color |

**AT Protocol Mapping**:
- Profile (name, avatar, sig) → User's PDS `net.vza.forum.profile`
- Settings (lang, tz, style, notifications) → User's PDS `net.vza.forum.settings`
- Post count, last visit → Local cache only (derived)
- Authentication → User's PDS via OAuth

#### phpbb_profile_fields_data
Custom profile field values per user.

| Column | Type | Description |
|--------|------|-------------|
| user_id | int unsigned | FK to users |
| pf_* | various | Dynamic columns per field |

**AT Protocol Mapping**: → User's PDS in profile record

#### phpbb_user_group
Group membership junction table.

| Column | Type | Description |
|--------|------|-------------|
| group_id | mediumint unsigned | FK to groups |
| user_id | int unsigned | FK to users |
| group_leader | tinyint unsigned | Is group leader |
| user_pending | tinyint unsigned | Membership pending |

**AT Protocol Mapping**: → Forum PDS (group assignment) + local cache

---

### 3. Forum Structure Tables

#### phpbb_forums
Forum/subforum definitions using nested set model.

| Column | Type | Description |
|--------|------|-------------|
| forum_id | mediumint unsigned | Primary key |
| parent_id | mediumint unsigned | Parent forum (0=root/category) |
| left_id | mediumint unsigned | Nested set left bound |
| right_id | mediumint unsigned | Nested set right bound |
| forum_name | varchar(255) | Display name |
| forum_desc | text | Description |
| forum_type | tinyint | 0=category, 1=forum, 2=link |
| forum_status | tinyint | 0=unlocked, 1=locked |
| forum_style | mediumint unsigned | Override style |
| forum_image | varchar(255) | Icon path |
| forum_rules | text | Forum rules |
| forum_topics_per_page | smallint unsigned | Pagination |
| forum_password | varchar(255) | Password protection |
| enable_indexing | tinyint unsigned | Search indexing |
| enable_prune | tinyint unsigned | Auto-prune enabled |
| prune_days/viewed/freq | Prune settings |
| forum_posts_approved/unapproved/softdeleted | Counters |
| forum_topics_approved/unapproved/softdeleted | Counters |
| forum_last_post_* | Last post cache |

**AT Protocol Mapping**: → Forum PDS as `net.vza.forum.board`

#### phpbb_groups
User groups for permissions.

| Column | Type | Description |
|--------|------|-------------|
| group_id | mediumint unsigned | Primary key |
| group_name | varchar(255) | Name |
| group_type | tinyint | 0=open, 1=closed, 2=hidden, 3=special |
| group_desc | text | Description |
| group_avatar | varchar(255) | Group avatar |
| group_rank | mediumint unsigned | Group rank |
| group_colour | varchar(6) | Username color |

**AT Protocol Mapping**: → Forum PDS

---

### 4. Permission Tables (ACL System)

phpBB uses a sophisticated ACL system with options, roles, and assignments.

#### phpbb_acl_options
Available permission flags.

| Column | Type | Description |
|--------|------|-------------|
| auth_option_id | mediumint unsigned | Primary key |
| auth_option | varchar(50) | Permission name (e.g., f_post, m_delete, a_board) |
| is_global | tinyint unsigned | Applies globally |
| is_local | tinyint unsigned | Applies per-forum |
| founder_only | tinyint unsigned | Founder-only permission |

**Permission Prefixes**:
- `f_*` - Forum permissions (post, reply, edit, delete, etc.)
- `m_*` - Moderator permissions (approve, delete, move, ban, etc.)
- `a_*` - Admin permissions (board settings, users, extensions, etc.)
- `u_*` - User permissions (PM, avatar, signature, etc.)

#### phpbb_acl_roles
Permission role templates.

| Column | Type | Description |
|--------|------|-------------|
| role_id | mediumint unsigned | Primary key |
| role_name | varchar(255) | Role name |
| role_type | varchar(10) | a_, m_, u_, or f_ |
| role_order | smallint unsigned | Display order |

**Standard Roles**:
- Admin: STANDARD, FORUM, USERGROUP, FULL
- Mod: FULL, STANDARD, SIMPLE, QUEUE
- User: FULL, STANDARD, LIMITED, NOPM, NOAVATAR
- Forum: FULL, STANDARD, NOACCESS, READONLY, LIMITED, BOT, ONQUEUE, POLLS

#### phpbb_acl_roles_data
Permissions assigned to each role.

| Column | Type | Description |
|--------|------|-------------|
| role_id | mediumint unsigned | FK to roles |
| auth_option_id | mediumint unsigned | FK to options |
| auth_setting | tinyint | 0=no, 1=yes, -1=never |

#### phpbb_acl_groups
Group permission assignments.

| Column | Type | Description |
|--------|------|-------------|
| group_id | mediumint unsigned | FK to groups |
| forum_id | mediumint unsigned | 0=global, else specific forum |
| auth_option_id | mediumint unsigned | Specific option (if not using role) |
| auth_role_id | mediumint unsigned | Role assignment |
| auth_setting | tinyint | Override value |

#### phpbb_acl_users
Per-user permission overrides.

| Column | Type | Description |
|--------|------|-------------|
| user_id | int unsigned | FK to users |
| forum_id | mediumint unsigned | 0=global, else specific |
| auth_option_id | mediumint unsigned | Specific option |
| auth_role_id | mediumint unsigned | Role assignment |
| auth_setting | tinyint | Override value |

**AT Protocol Mapping**: → Forum PDS as `net.vza.forum.acl`
- Enforced at AppView level (Sync Service filters based on DID)
- Labels provide moderation layer

---

### 5. Configuration Tables

#### phpbb_config
Key-value configuration store.

| Column | Type | Description |
|--------|------|-------------|
| config_name | varchar(255) | Primary key |
| config_value | varchar(255) | Value |
| is_dynamic | tinyint unsigned | Frequently changing |

**Sample Settings**:
- `allow_attachments`, `allow_avatar`, `allow_bbcode`, `allow_privmsg`
- `board_email`, `board_timezone`, `sitename`, `site_desc`
- `max_post_chars`, `max_sig_chars`, `max_filesize`
- `board_disable`, `board_disable_msg`

#### phpbb_config_text
Large text configuration values.

| Column | Type | Description |
|--------|------|-------------|
| config_name | varchar(255) | Primary key |
| config_value | mediumtext | Large value |

**AT Protocol Mapping**: → Forum PDS as `net.vza.forum.config`

---

### 6. Session & Auth Tables

#### phpbb_sessions
Active user sessions.

| Column | Type | Description |
|--------|------|-------------|
| session_id | char(32) | Primary key |
| session_user_id | int unsigned | FK to users |
| session_ip | varchar(40) | Client IP |
| session_time | int unsigned | Last activity |
| session_page | varchar(255) | Current page |
| session_admin | tinyint unsigned | In ACP |

#### phpbb_sessions_keys
"Remember me" tokens.

| Column | Type | Description |
|--------|------|-------------|
| key_id | char(32) | Token hash |
| user_id | int unsigned | FK to users |
| last_ip | varchar(40) | Last used IP |
| last_login | int unsigned | Last used time |

**AT Protocol Mapping**: Local only - replaced by AT Protocol OAuth tokens

---

### 7. Moderation Tables

#### phpbb_reports
Content reports.

| Column | Type | Description |
|--------|------|-------------|
| report_id | int unsigned | Primary key |
| reason_id | smallint unsigned | FK to reasons |
| post_id | int unsigned | Reported post |
| pm_id | int unsigned | Reported PM |
| user_id | int unsigned | Reporter |
| report_text | mediumtext | Report details |
| report_time | int unsigned | Timestamp |
| report_closed | tinyint unsigned | Resolved |

#### phpbb_log
Admin/mod action log.

| Column | Type | Description |
|--------|------|-------------|
| log_id | int unsigned | Primary key |
| log_type | tinyint | 0=admin, 1=mod, 2=user, 3=critical |
| user_id | int unsigned | Actor |
| forum_id | mediumint unsigned | Context forum |
| topic_id | int unsigned | Context topic |
| log_ip | varchar(40) | Actor IP |
| log_time | int unsigned | Timestamp |
| log_operation | text | Action description |
| log_data | mediumtext | Serialized data |

**AT Protocol Mapping**:
- Reports → Ozone moderation queue
- Mod actions → Labels on content
- Logs → Local only (audit trail)

---

### 8. Notification Tables

#### phpbb_notifications
User notifications.

| Column | Type | Description |
|--------|------|-------------|
| notification_id | int unsigned | Primary key |
| notification_type_id | smallint unsigned | Type FK |
| user_id | int unsigned | Recipient |
| item_id | mediumint unsigned | Related content |
| notification_read | tinyint unsigned | Read status |
| notification_time | int unsigned | Timestamp |
| notification_data | text | Serialized data |

#### phpbb_notification_types
Notification type definitions.

| Column | Type | Description |
|--------|------|-------------|
| notification_type_id | smallint unsigned | Primary key |
| notification_type_name | varchar(255) | Type identifier |
| notification_type_enabled | tinyint unsigned | Active |

**AT Protocol Mapping**: Local only - generated from firehose events

---

### 9. Search Tables

#### phpbb_search_wordlist
Full-text search word index.

| Column | Type | Description |
|--------|------|-------------|
| word_id | int unsigned | Primary key |
| word_text | varchar(255) | Indexed word |
| word_common | tinyint unsigned | Common word flag |
| word_count | mediumint unsigned | Occurrence count |

#### phpbb_search_wordmatch
Word to post mapping.

| Column | Type | Description |
|--------|------|-------------|
| post_id | int unsigned | FK to posts |
| word_id | int unsigned | FK to wordlist |
| title_match | tinyint unsigned | In title |

**AT Protocol Mapping**: Local only - rebuilt from firehose data

---

### 10. Other Tables

| Table | Purpose | AT Proto |
|-------|---------|----------|
| phpbb_bbcodes | Custom BBCode definitions | Forum PDS |
| phpbb_smilies | Emoticon definitions | Forum PDS |
| phpbb_icons | Post icons | Forum PDS |
| phpbb_ranks | User rank definitions | Forum PDS |
| phpbb_styles | Theme definitions | Forum PDS |
| phpbb_bots | Bot user definitions | Local only |
| phpbb_banlist | Banned users/IPs/emails | Forum PDS + local |
| phpbb_words | Word censoring | Forum PDS |
| phpbb_bookmarks | User bookmarks | User PDS |
| phpbb_drafts | Saved post drafts | User PDS |
| phpbb_zebra | User friends/foes | User PDS |
| phpbb_forums_watch | Forum subscriptions | User PDS |
| phpbb_topics_watch | Topic subscriptions | User PDS |
| phpbb_poll_options | Poll choices | User PDS (in post) |
| phpbb_poll_votes | Poll votes | User PDS |

---

## Key Relationships

```
phpbb_users (1) ─────────────── (N) phpbb_posts
     │                              │
     │                              │
     └── (N) phpbb_user_group       │
              │                     │
              │                     │
     phpbb_groups (1) ──────────────┼── (N) phpbb_acl_groups
                                    │
                                    │
phpbb_forums (1) ───────────────────┴── (N) phpbb_topics
     │                                       │
     │                                       │
     └── nested set (parent_id, left_id, right_id)
                                             │
                                             └── (N) phpbb_posts
```

## Data Volume Considerations

Typical phpBB installation metrics:
- Posts: 10K - 10M records (heaviest table)
- Topics: 1K - 1M records
- Users: 100 - 100K records
- Forums: 10 - 500 records
- Config: ~500 key-value pairs

## Schema Version

This analysis is based on phpBB 3.3.14 schema. The `phpbb_migrations` table tracks schema changes.
