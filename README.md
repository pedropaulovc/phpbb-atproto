# phpBB on AT Protocol

A project to port phpBB bulletin board to use AT Protocol as its data backend.

## Architecture

This uses a **Hybrid AppView** approach:
- phpBB's MySQL database serves as a **local cache** (not source of truth)
- All canonical data lives on **AT Protocol PDSes**
- A **Sync Service** bridges AT Protocol ↔ phpBB MySQL
- Forum reads from **public relays** (Bluesky's firehose)
- **Labels-only moderation** following AT Protocol philosophy

See [specs/plan.md](specs/plan.md) for the full project plan.

## Data Ownership

| Data Type | Location |
|-----------|----------|
| Posts, topics | User's PDS |
| User profile & settings | User's PDS |
| Forum structure | Forum PDS |
| Forum config & ACL | Forum PDS |
| Moderation labels | Forum PDS (Ozone) |

## Project Structure

```
phpbb-atproto/
├── docker/              # Development environment
├── docs/                # Documentation
│   ├── schema-analysis.md    # phpBB database schema
│   └── data-ownership.md     # AT Protocol mapping
├── lexicons/            # AT Protocol lexicon definitions
├── specs/               # Implementation specifications
│   └── plan.md          # Project plan
├── ext/                 # phpBB extension (TBD)
└── sync-service/        # Firehose sync daemon (TBD)
```

## Development Setup

### Prerequisites

- Docker and Docker Compose
- PHP 8.2+ (for local development)

### Quick Start

```bash
# Start phpBB + MySQL + phpMyAdmin
cd docker
docker compose up -d

# Wait for installation, then access:
# - phpBB: http://localhost:8080 (admin / adminpassword)
# - phpMyAdmin: http://localhost:8081 (root / rootpassword)
```

### Schema Exploration

The development environment includes a full phpBB installation for schema exploration:

```bash
# Connect to MySQL
docker compose exec db mysql -u phpbb -pphpbbpassword phpbb

# Export schema
docker compose exec db mysqldump -u phpbb -pphpbbpassword --no-data phpbb > schema.sql
```

## Lexicon Namespace

All custom lexicons use the `net.vza.forum.*` namespace:

- `net.vza.forum.post` - Forum posts
- `net.vza.forum.board` - Forum/subforum definitions
- `net.vza.forum.category` - Forum categories
- `net.vza.forum.config` - Forum configuration
- `net.vza.forum.settings` - User forum preferences
- `net.vza.forum.acl` - Permission definitions

## Status

Phase 1 complete. Currently:
- [x] Docker development environment
- [x] phpBB schema documentation
- [x] Data ownership classification
- [ ] Lexicon definitions (Phase 2)
- [ ] phpBB extension skeleton
- [ ] Sync service prototype

## License

TBD
