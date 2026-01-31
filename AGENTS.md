# Agent Instructions

## The Rule

**Invoke relevant skills BEFORE any response or action.** Even a 1% chance a skill might apply means you should invoke it.

## Red Flags

These thoughts mean STOP - you're rationalizing:

| Thought | Reality |
|---------|---------|
| "This is just a simple question" | Questions are tasks. Check for skills. |
| "I need more context first" | Skill check comes BEFORE clarifying questions. |
| "Let me explore the codebase first" | Skills tell you HOW to explore. Check first. |
| "This doesn't need a formal skill" | If a skill exists, use it. |
| "I'll just do this one thing first" | Check BEFORE doing anything. |

## Skill Priority

When multiple skills could apply:

1. **Process skills first** (brainstorming, debugging) - these determine HOW to approach the task
2. **Implementation skills second** (frontend-design, feature-dev) - these guide execution

Examples:
- "Let's build X" → brainstorming first, then implementation skills
- "Fix this bug" → systematic-debugging first, then domain-specific skills

## Key Skills

| Skill | When to Use |
|-------|-------------|
| `brainstorming` | Before ANY creative work - features, components, modifications |
| `systematic-debugging` | Before fixing ANY bug or unexpected behavior |
| `test-driven-development` | Before implementing ANY feature or bugfix |
| `writing-plans` | When you have specs/requirements for multi-step work |
| `executing-plans` | When implementing from a written plan |
| `verification-before-completion` | Before claiming work is done |

## Session Completion

**Work is NOT complete until `git push` succeeds.**

1. Run quality gates (tests, linters) if code changed
2. Commit all changes
3. Push to remote:
   ```bash
   git pull --rebase && git push
   git status  # MUST show "up to date with origin"
   ```
4. Verify - all changes committed AND pushed

**Never say "ready to push when you are" - YOU must push.**
