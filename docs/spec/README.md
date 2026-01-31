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
