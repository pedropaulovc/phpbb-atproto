# Documentation Reorganization Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Reorganize specs/ and docs/ into a cohesive functional specification and clear implementation plan structure.

**Architecture:** Consolidate all specification documents into `docs/spec/` (functional spec) and keep implementation plans in `docs/plans/`. Remove the now-redundant `specs/` directory since exploration phases 1-4 are complete.

**Tech Stack:** Git, file operations

---

## Current Structure (Problem)

```
specs/                              # Confusing name - exploration is done
├── plan.md                         # Master spec + exploration history
├── components/                     # Component specs (good content, wrong location)
│   ├── phpbb-extension/
│   ├── sync-service/
│   └── moderation/
├── testing/                        # Testing specs
└── deployment/                     # Infrastructure specs

docs/                               # Mixed research + plans
├── schema-analysis.md              # Research output
├── data-ownership.md               # Research output
├── data-mapping.md                 # Research output
├── architecture.md                 # Architecture doc
├── api-contracts.md                # Interface definitions
├── moderation-flow.md              # Flow documentation
├── risks.md                        # Risk assessment
└── plans/
    └── 2026-01-30-foundation-phase.md  # Implementation plan
```

## Target Structure

```
docs/
├── spec/                           # Functional Specification (reference docs)
│   ├── README.md                   # Spec overview + navigation
│   ├── architecture.md             # System architecture (from docs/)
│   ├── data-model.md               # Combined: schema + ownership + mapping
│   ├── lexicons/                   # Move from lexicons/
│   │   └── *.json
│   ├── components/                 # Move from specs/components/
│   │   ├── phpbb-extension/
│   │   ├── sync-service/
│   │   └── moderation/
│   ├── testing/                    # Move from specs/testing/
│   ├── deployment/                 # Move from specs/deployment/
│   └── risks.md                    # Risk assessment
│
└── plans/                          # Implementation Plans (actionable tasks)
    ├── README.md                   # Implementation roadmap overview
    ├── 01-foundation/
    │   └── 2026-01-30-foundation-phase.md
    ├── 02-write-path/              # (future)
    ├── 03-forum-pds/               # (future)
    ├── 04-sync-service/            # (future)
    ├── 05-moderation/              # (future)
    └── 06-polish/                  # (future)
```

---

## Task Dependencies

```
Task 1 (Create spec/README.md) ───┐
                                  │
Task 2 (Move lexicons) ───────────┼──> Task 7 (Update cross-references)
                                  │
Task 3 (Move components) ─────────┤
                                  │
Task 4 (Move testing/deployment) ─┤
                                  │
Task 5 (Create data-model.md) ────┤
                                  │
Task 6 (Create plans/README.md) ──┘
                                  │
                                  └──> Task 8 (Delete specs/)
                                           │
                                           └──> Task 9 (Commit)
```

---

## Task 1: Create Spec Directory with README

**Files:**
- Create: `docs/spec/README.md`

**Step 1: Create the spec README**

```markdown
# phpBB on AT Protocol - Functional Specification

This directory contains the complete functional specification for porting phpBB to use AT Protocol as its data backend.

## Overview

**Approach:** Hybrid AppView
- phpBB's MySQL database serves as a local cache (not source of truth)
- All canonical data lives on AT Protocol PDSes
- A Sync Service (PHP) bridges AT Protocol ↔ phpBB MySQL
- Labels-only moderation (AT Protocol philosophy)

**Repository:** https://github.com/pedropaulovc/phpbb-atproto
**Lexicon namespace:** `net.vza.forum.*`

## Document Index

### Core Architecture
- [Architecture](./architecture.md) - System components and data flows
- [Data Model](./data-model.md) - Schema mapping and ownership patterns
- [Risks](./risks.md) - Technical risks and mitigations

### Lexicons
- [Lexicon Schemas](./lexicons/) - AT Protocol record definitions (`net.vza.forum.*`)

### Component Specifications
- [phpBB Extension](./components/phpbb-extension/) - Auth, write intercept, labels
- [Sync Service](./components/sync-service/) - Firehose, event processing, DB sync
- [Moderation](./components/moderation/) - Ozone setup, MCP integration

### Testing & Deployment
- [Testing Strategy](./testing/) - Unit, integration, and E2E tests
- [Deployment](./deployment/) - Infrastructure and Docker setup

## Implementation Roadmap

| Phase | Focus | Status |
|-------|-------|--------|
| 1. Foundation | Extension skeleton, migrations, OAuth | 🔄 In Progress |
| 2. Write Path | Post creation → user PDS | ⏳ Pending |
| 3. Forum PDS | Forum config on AT Protocol | ⏳ Pending |
| 4. Sync Service | Firehose client, event processing | ⏳ Pending |
| 5. Moderation | Ozone labels, MCP integration | ⏳ Pending |
| 6. Polish | Admin UI, error handling, docs | ⏳ Pending |

See [Implementation Plans](../plans/) for detailed task breakdowns.
```

**Step 2: Commit**

```bash
git add docs/spec/README.md
git commit -m "$(cat <<'EOF'
docs: create spec directory with README

Establishes docs/spec/ as the home for functional specification
documents. Provides overview and navigation for the complete spec.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Move Lexicons to Spec Directory

**Files:**
- Move: `lexicons/*.json` → `docs/spec/lexicons/`

**Step 1: Move lexicons directory**

```bash
mkdir -p docs/spec/lexicons
git mv lexicons/*.json docs/spec/lexicons/
rmdir lexicons  # Remove empty directory if needed
```

**Step 2: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: move lexicons to docs/spec/lexicons/

Consolidates all specification documents under docs/spec/.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Move Component Specs

**Files:**
- Move: `specs/components/` → `docs/spec/components/`

**Step 1: Move components directory**

```bash
mkdir -p docs/spec/components
git mv specs/components/phpbb-extension docs/spec/components/
git mv specs/components/sync-service docs/spec/components/
git mv specs/components/moderation docs/spec/components/
```

**Step 2: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: move component specs to docs/spec/components/

Moves phpbb-extension, sync-service, and moderation specs.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Move Testing and Deployment Specs

**Files:**
- Move: `specs/testing/` → `docs/spec/testing/`
- Move: `specs/deployment/` → `docs/spec/deployment/`

**Step 1: Move directories**

```bash
git mv specs/testing docs/spec/
git mv specs/deployment docs/spec/
```

**Step 2: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: move testing and deployment specs to docs/spec/

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Create Consolidated Data Model Document

**Files:**
- Create: `docs/spec/data-model.md`
- Move: `docs/architecture.md` → `docs/spec/architecture.md`
- Move: `docs/risks.md` → `docs/spec/risks.md`
- Delete: `docs/schema-analysis.md` (content merged)
- Delete: `docs/data-ownership.md` (content merged)
- Delete: `docs/data-mapping.md` (content merged)
- Delete: `docs/api-contracts.md` (content merged into components)
- Delete: `docs/moderation-flow.md` (content in specs/plan.md)

**Step 1: Move architecture and risks**

```bash
git mv docs/architecture.md docs/spec/
git mv docs/risks.md docs/spec/
```

**Step 2: Create consolidated data-model.md**

This file should combine the essential content from:
- `docs/schema-analysis.md` - phpBB table documentation
- `docs/data-ownership.md` - What lives where
- `docs/data-mapping.md` - How phpBB maps to AT Protocol

Create `docs/spec/data-model.md` with this structure:

```markdown
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
```

**Step 3: Remove merged documents**

```bash
git rm docs/schema-analysis.md
git rm docs/data-ownership.md
git rm docs/data-mapping.md
git rm docs/api-contracts.md
git rm docs/moderation-flow.md
```

**Step 4: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: consolidate data model documentation

- Create docs/spec/data-model.md combining schema, ownership, and mapping
- Move architecture.md and risks.md to docs/spec/
- Remove redundant docs (content preserved in consolidated files)

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Create Plans README and Reorganize

**Files:**
- Create: `docs/plans/README.md`
- Move: `docs/plans/2026-01-30-foundation-phase.md` → `docs/plans/01-foundation/2026-01-30-foundation-phase.md`

**Step 1: Create plans README**

```markdown
# Implementation Plans

Detailed, task-level implementation plans for building phpBB on AT Protocol.

## Roadmap

| Phase | Directory | Focus | Status |
|-------|-----------|-------|--------|
| 1 | [01-foundation/](./01-foundation/) | Extension skeleton, migrations, OAuth | 🔄 In Progress |
| 2 | 02-write-path/ | Post creation → user PDS | ⏳ Pending |
| 3 | 03-forum-pds/ | Forum config on AT Protocol | ⏳ Pending |
| 4 | 04-sync-service/ | Firehose client, event processing | ⏳ Pending |
| 5 | 05-moderation/ | Ozone labels, MCP integration | ⏳ Pending |
| 6 | 06-polish/ | Admin UI, error handling, docs | ⏳ Pending |

## How to Use These Plans

Each plan follows TDD methodology:
1. Write failing test
2. Verify test fails
3. Write minimal implementation
4. Verify test passes
5. Commit

**For Claude:** Use `superpowers:executing-plans` skill to work through plans.

## Specification Reference

See [Functional Specification](../spec/) for detailed component specs, lexicons, and architecture.
```

**Step 2: Create phase directory and move plan**

```bash
mkdir -p docs/plans/01-foundation
git mv docs/plans/2026-01-30-foundation-phase.md docs/plans/01-foundation/
```

**Step 3: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: create plans README and organize by phase

- Add docs/plans/README.md with roadmap overview
- Move foundation plan to 01-foundation/ subdirectory

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Update Cross-References

**Files:**
- Modify: All moved markdown files to update relative paths

**Step 1: Find and update cross-references**

Search for references to old paths and update them:

| Old Path | New Path |
|----------|----------|
| `specs/components/` | `docs/spec/components/` |
| `specs/testing/` | `docs/spec/testing/` |
| `specs/deployment/` | `docs/spec/deployment/` |
| `lexicons/` | `docs/spec/lexicons/` |
| `docs/schema-analysis.md` | `docs/spec/data-model.md` |
| `docs/data-ownership.md` | `docs/spec/data-model.md` |
| `docs/data-mapping.md` | `docs/spec/data-model.md` |
| `docs/architecture.md` | `docs/spec/architecture.md` |
| `docs/risks.md` | `docs/spec/risks.md` |

Check each moved file for broken references.

**Step 2: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: update cross-references for new structure

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Delete Old specs/ Directory

**Files:**
- Delete: `specs/plan.md` (content preserved in docs/spec/README.md)
- Delete: `specs/` directory

**Step 1: Remove specs directory**

The specs/ directory should now be empty except for plan.md. The exploration phases (1-4) are complete and their output is now in docs/spec/.

```bash
git rm specs/plan.md
# Remove any empty directories
```

**Step 2: Commit**

```bash
git add -A
git commit -m "$(cat <<'EOF'
docs: remove specs/ directory (content moved to docs/spec/)

Exploration phases 1-4 are complete. All specification documents
are now consolidated under docs/spec/ for better organization.

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Final Verification

**Step 1: Verify structure**

```bash
# Expected structure
ls -la docs/spec/
# Should show: README.md, architecture.md, data-model.md, risks.md
# Plus directories: lexicons/, components/, testing/, deployment/

ls -la docs/plans/
# Should show: README.md, 01-foundation/

ls -la docs/spec/lexicons/
# Should show: 10 JSON files

ls -la docs/spec/components/
# Should show: phpbb-extension/, sync-service/, moderation/

# Verify specs/ is gone
test -d specs && echo "ERROR: specs/ still exists" || echo "OK: specs/ removed"
```

**Step 2: Verify no broken links**

```bash
# Search for any remaining references to old paths
grep -r "specs/" docs/ --include="*.md" | grep -v "docs/spec"
# Should return nothing
```

**Step 3: Push changes**

```bash
git push
```

---

## Final Structure

After completing all tasks:

```
docs/
├── spec/                           # Functional Specification
│   ├── README.md                   # Spec overview + navigation
│   ├── architecture.md             # System architecture
│   ├── data-model.md               # Schema + ownership + mapping
│   ├── risks.md                    # Risk assessment
│   ├── lexicons/                   # AT Protocol schemas
│   │   ├── net.vza.forum.post.json
│   │   ├── net.vza.forum.board.json
│   │   └── ... (10 files)
│   ├── components/
│   │   ├── phpbb-extension/        # 5 component specs
│   │   ├── sync-service/           # 5 component specs
│   │   └── moderation/             # 2 component specs
│   ├── testing/                    # 3 testing specs
│   └── deployment/                 # 1 infra spec
│
└── plans/                          # Implementation Plans
    ├── README.md                   # Roadmap overview
    └── 01-foundation/
        └── 2026-01-30-foundation-phase.md
```

**specs/ directory removed** - exploration phases complete, content consolidated.
