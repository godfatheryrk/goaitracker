<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Minimal Auth and Per-User Isolation

- **Plan**: context/changes/minimal-auth-and-isolation/plan.md
- **Scope**: All 4 phases (full plan review)
- **Date**: 2026-05-28
- **Verdict**: APPROVED
- **Findings**: 0 critical | 0 warnings | 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS (1 intentional drift; see F1) |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Grounding

- **Files changed**: 20 (13 implementation, 3 convention artifacts, 2 toolkit motion, 2 benign housekeeping).
- **Diff range**: `21c1621..acb23ce` (5 implementation commits: `121978f` p1, `bd9a30d` p2, `09ce79b` p3, `b3a8e90` p4, `acb23ce` epilogue).
- **Drift detection**: 13 MATCH, 0 MISSING, 1 DRIFT (intentional, to the better choice), 4 EXTRA (all benign).
- **Safety/quality scan**: no CRITICAL or WARNING findings across security, performance, reliability, data safety, pattern.
- **Plan-review F1/F3 fixes** demonstrably present in code and tests:
  - LoginRequest reproduces Breeze's `ensureIsNotRateLimited` + `throttleKey` verbatim — exact key formula `Str::transliterate(Str::lower($email).'|'.$ip)`, 5 attempts / 60s decay, `RateLimiter::clear()` on success, `event(new Lockout($this))`, `ValidationException::withMessages(['email' => __('auth.throttle', ...)])`.
  - Dashboard and nav both render `{{ auth()->user()->name }}` (escaped); the derived local-part is the surface Phase 2 Manual 2.6 verifies.
  - `tests/Feature/Auth/AuthenticationTest.php` contains `test_successful_login_clears_the_rate_limiter` — the regression test that guards the F1 bug-class.

### Success criteria run

- Routes: `php artisan route:list` shows `login` GET/POST, `logout` POST, `register` GET/POST, `dashboard` GET — all named.
- Tests: `composer run test` → 17 passed, 37 assertions.
- Formatting: `vendor/bin/pint --test` → passed.
- Build: `npm run build` → built in 454ms, exit 0.
- Phase 4 artifacts: `docs/reference/contract-surfaces.md` ✓, `context/foundation/lessons.md` ✓, AGENTS.md "Per-user isolation" rule ✓ (line 21, above the BEGIN marker at line 29).

## Findings

### F1 — Plan text still says `user_id` but convention crystallized to `owner_id`

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `context/changes/minimal-auth-and-isolation/plan.md` (Phase 4 §1 Contract bullet; Phase 4 §4 checklist bullet)
- **Detail**: plan.md's Phase 4 contract literally names `user_id` as the per-user FK, but every implemented convention artifact uses `owner_id`:
  - `docs/reference/contract-surfaces.md` (rules 1–4 + S-01 checklist)
  - `context/foundation/lessons.md` (rule + how-to-apply)
  - `AGENTS.md` (Per-user isolation paragraph)

  The implementation is internally consistent and strictly better — the doc explains the deliberate `owner_id ≠ user_id` semantic split, preserving `user_id` for v3+ membership-pivot semantics so S-01's schema decision doesn't foreclose v2 share-links or v3 co-editing. This is drift FROM the plan TO the better choice; flagging only so the plan text isn't taken as the authority later. The convention artifacts are the source of truth going forward.
- **Fix**: Optional — leave the plan text as a frozen historical record (the convention artifacts now lead), OR add a one-line addendum under Phase 4 §1 noting "implemented as `owner_id`; see contract-surfaces.md for why."
- **Decision**: FIXED via approach 1 (rewrite in place with strikethrough). Five `user_id` references in plan.md contract text rewritten as `owner_id` (~~`user_id`~~); a new "Implementation note (post-review)" callout sits under Phase 4 Overview pointing to `docs/reference/contract-surfaces.md` for the `owner_id ≠ user_id` rationale. The Progress-block historical line at plan.md:516 was deliberately left untouched (Progress step titles are append-only by convention).

### F2 — Throttle test reconstructs the key formula by hand

- **Severity**: 🔭 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/Auth/AuthenticationTest.php` (`test_successful_login_clears_the_rate_limiter`)
- **Detail**: The clear-on-success regression test (which guards the load-bearing F1 fix from the plan-review) inspects `RateLimiter::attempts($key)` by re-deriving the key formula in the test body — `Str::transliterate(Str::lower($user->email).'|127.0.0.1')`. If `LoginRequest::throttleKey()` ever evolves shape, this test silently keys against a non-existent counter and asserts `0` for the wrong reason — green test, broken guarantee. Acceptable for this slice; worth knowing for any future evolution of the throttle.
- **Fix**: Optional — none required now. If the throttle key formula ever changes, grep `transliterate(Str::lower` in tests and re-sync. (Or, in a follow-up, expose `throttleKey()` as public/testable and call it from the test instead of duplicating the formula.)
- **Decision**: PENDING

## Notes on the EXTRA files (not in plan, in diff)

- `.gitignore` — benign housekeeping (`/.claude/`, `/.codex`, `/.cursor/`, `_ide_helper.php`, etc.) added in p3.
- `package-lock.json` — npm toolchain side-effect from Vite build during p1.
- `context/changes/minimal-auth-and-isolation/change.md` — `/10x-archive` epilogue stamp.
- `context/changes/minimal-auth-and-isolation/plan.md` — `/10x-implement` flipping Progress checkboxes + commit SHAs.

None are scope creep.

## What's worth carrying forward to S-01

- The convention artifacts agree on `owner_id` — S-01 must use `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` (explicit `'users'` because Laravel infers `owners` from the column prefix).
- `User::hasMany(Venture::class, 'owner_id')` requires the explicit FK name; default would be `user_id`.
- Two-user feature test must assert **404, not 403** — distinguishing 404 vs 403 leaks existence.
- Classic Blade style (`@extends` / `@yield` / `@include`) is what this project uses; don't introduce `<x-…>` anonymous components on the venture/step/expense views.
