# Agent Instructions

This project uses **bd** (beads) for issue tracking.

## Essential Commands

```bash
bd ready                              # See tasks ready to work on
bd create "Task title" -p 0           # Create priority-0 task
bd show <id>                          # View task details
bd update <id> --status in_progress   # Start working on task
bd close <id>                         # Mark task complete
bd dep add <child> <parent>           # Add dependency
bd sync                               # Sync with git
```

## Workflow

1. Before starting work: `bd ready` to find available tasks
2. Claim a task: `bd update <id> --status in_progress`
3. When done: `bd close <id>`
4. Create follow-up tasks for remaining work

## Session Completion (Landing the Plane)

**When ending a work session**, complete ALL steps below. Work is NOT complete until `git push` succeeds.

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd sync
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

