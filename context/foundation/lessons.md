# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Include roadmap slice ID in commit subject

- **Context**: Committing phase of a plan implementation.
- **Problem**: Without the roadmap slice ID, commit subjects only reference the change-id, leaving no shortcut back to the roadmap feature/slice the commit advances.
- **Rule**: In commit subjects, include the roadmap feature/slice ID alongside the change-id — e.g. `feat(F-01/minimal-auth-and-isolation): ...` instead of `feat(minimal-auth-and-isolation): ...`.
- **Applies to**: implement
