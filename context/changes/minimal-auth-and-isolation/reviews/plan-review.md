<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Minimal Auth and Per-User Isolation

- **Plan**: context/changes/minimal-auth-and-isolation/plan.md
- **Mode**: Deep
- **Date**: 2026-05-27
- **Verdict**: REVISE → SOUND (after triage fixes)
- **Findings**: 0 critical, 3 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | WARNING |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

6/6 paths ✓, 5/5 symbols ✓ (`password => hashed` cast at User.php:29, `web` session
guard at config/auth.php:40, `sessions` table at the create_users_table migration:30,
welcome-only route at routes/web.php, no starter kit in composer.json — Laravel 13.8 /
PHP 8.4, only framework+tinker+pail+pint), brief↔plan ✓. Progress↔Phase: one `## Progress`
block, 4/4 phases mirrored, all N.M items present ✓. Deep verification done inline
(greenfield slice; only `routes/web.php` + `AGENTS.md` are pre-existing files touched —
trivial blast radius), so the Step 3 sub-agent was redundant.

## Findings

### F1 — Hand-rolled throttle re-derives Breeze's LoginRequest from memory

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architectural Fitness
- **Location**: Critical Implementation Details + Phase 1 §2 (LoginRequest)
- **Detail**: The login throttle was described in prose. Breeze's `LoginRequest::ensureIsNotRateLimited()` already implements exactly this (key `Str::lower(email)|ip`, 5 attempts, `RateLimiter::hit` on failure, `clear` on success, `Lockout` event, `ValidationException` with seconds remaining). Re-deriving freehand risks missing clear-on-success (lockout persists after a valid login), a wrong key (IP-only locks out a NAT; email-only allows distributed guessing), or the `Lockout` event. The plan-brief itself names this the #1 open risk.
- **Fix A ⭐ Recommended**: Pin Breeze's exact throttle contract into the plan.
  - Strength: Keeps the hand-rolled / no-starter-kit decision while removing the "invent from memory" risk — implementer copies a known-good pattern.
  - Tradeoff: More prescriptive plan text; still hand-maintained.
  - Confidence: HIGH — Breeze's LoginRequest is the canonical reference for this mechanism.
  - Blind spot: The Phase 3 throttle test must assert clear-on-success, not just lockout-after-N, or the bug ships green.
- **Fix B**: Install laravel/breeze (blade), then prune to the 3 FRs.
  - Strength: Inherits tested throttle + session-fixation handling.
  - Tradeoff: Reverses the documented "no starter kit" decision; pruning can leave more surface than hand-rolling 5 files.
  - Confidence: MED — depends on how much Breeze scaffold survives pruning.
  - Blind spot: Breeze's default redirect/landing constants may diverge from the /dashboard contract.
- **Decision**: FIXED via Fix A — Critical Implementation Details now pins the full Breeze throttle contract (key formula, 5 attempts / 60s decay, hit-on-fail, clear-on-success, `Lockout` event, `auth.throttle` message). Phase 3 §2 test contract now also asserts a successful login clears the limiter (closes the Fix-A blind spot).

### F2 — Phase-body Success Criteria use `- [ ]` checkboxes

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phases 1–4, `#### Automated/Manual Verification` sub-sections
- **Detail**: The verification sub-blocks use `- [ ]` bullets (plan.md:186, :243, :376…). The progress-format contract reserves checkboxes for the `## Progress` section only. The canonical `## Progress` block is well-formed and mirrors every item 1:1, so blast radius is low — but the duplicated checkboxes are a second toggleable surface a strict `/10x-implement` parser could trip on.
- **Fix**: Normalize phase-body Verification bullets from `- [ ]` to plain `- `, leaving `## Progress` as the only checkbox surface.
- **Decision**: SKIPPED — left as-is; `/10x-implement` writes back to `## Progress`, which is well-formed.

### F3 — Derived `name` is asserted but never rendered

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 §4 vs Phase 2 Manual 2.6 / Phase 3 §1
- **Detail**: Phase 1 §4 greeted the user with `auth()->user()->email`, but Phase 2 Manual 2.6 ("the derived name shows where the UI greets the user") and the Phase 3 registration test assume the derived `name`. No surface rendered `name`, so 2.6 had nothing to verify against (only the DB/test layer covered it).
- **Fix**: Render `auth()->user()->name` in the nav/dashboard greeting and keep 2.6 as a UI check (alternative: reword 2.6 to a DB/test check).
- **Decision**: FIXED — Phase 1 §4 contract now renders `auth()->user()->name` (optionally alongside email), the surface Phase 2 Manual 2.6 verifies.

### F4 — Registration has no rate limit; abuse-vector deferral is implicit

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: What We're NOT Doing / Phase 2
- **Detail**: PRD FR-001's Socrates note flags "open registration + AI calls is an abuse vector." The plan throttles login but adds no registration throttle, and "What We're NOT Doing" deferred only the AI ceiling to F-02. With no AI wired yet the deferral is reasonable, but it was unstated, so F-02/S-01 could assume F-01 covered it.
- **Fix**: Add a line to "What We're NOT Doing" deferring registration rate-limiting to F-02 alongside the AI ceiling.
- **Decision**: FIXED — "What We're NOT Doing" now states F-01 throttles login only and registration rate-limiting rides with F-02's AI ceiling.
