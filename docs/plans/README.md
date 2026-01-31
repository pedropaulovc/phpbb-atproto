# Implementation Plans

Detailed, task-level implementation plans for building phpBB on AT Protocol.

## Roadmap

| Phase | Directory | Focus | Status |
|-------|-----------|-------|--------|
| 0 | [03-environment-setup.md](./03-environment-setup.md) | Dev environment, tooling, Docker | Ready |
| 1 | [10-foundation/](./10-foundation/) | Extension skeleton, migrations, OAuth | In Progress |
| 2 | 02-write-path/ | Post creation -> user PDS | Pending |
| 3 | 03-forum-pds/ | Forum config on AT Protocol | Pending |
| 4 | 04-sync-service/ | Firehose client, event processing | Pending |
| 5 | 05-moderation/ | Ozone labels, MCP integration | Pending |
| 6 | 06-polish/ | Admin UI, error handling, docs | Pending |

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
