# Agent Instructions

This project uses **bd** (beads) for issue tracking.

## Essential Commands

```bash
npm run bd -- ready                              # See tasks ready to work on
npm run bd -- create "Task title" -p 0           # Create priority-0 task
npm run bd -- show <id>                          # View task details
npm run bd -- update <id> --status in_progress   # Start working on task
npm run bd -- close <id>                         # Mark task complete
npm run bd -- dep add <child> <parent>           # Add dependency
npm run bd -- sync                               # Sync with git
```

## Workflow

1. Before starting work: `npm run bd -- ready` to find available tasks
2. Claim a task: `npm run bd -- update <id> --status in_progress`
3. When done: `npm run bd -- close <id>`
4. Create follow-up tasks for remaining work

## Session Completion (Landing the Plane)

**When ending a work session**, complete ALL steps below. Work is NOT complete until `git push` succeeds.

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   npm run bd -- sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
