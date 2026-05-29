<!-- PLAN-REVIEW-REPORT -->
# Plan Review: S-04 / list-and-delete-ventures

- **Plan**: `context/changes/list-and-delete-ventures/plan.md`
- **Mode**: Deep
- **Date**: 2026-05-29
- **Verdict**: SOUND
- **Findings**: 0 critical · 1 warning · 3 observations

## Verdicts

| Dimension              | Verdict |
|------------------------|---------|
| End-State Alignment    | PASS    |
| Lean Execution         | PASS    |
| Architectural Fitness  | PASS    |
| Blind Spots            | WARNING |
| Plan Completeness      | PASS    |

## Grounding

6/6 paths verified · 5/5 symbols verified · brief↔plan consistent.

- `app/Http/Controllers/VenturesController.php` — `create` / `store` / `show` via `$request->user()->ventures()` ✓
- `app/Http/Controllers/StepsController.php` — doubly-scoped resolve, `$step->save()` path confirmed ✓
- `app/Models/Step.php` — `belongsTo(Venture::class)` exists; uses `#[Fillable]` attribute (see F3) ✓
- `app/Models/Venture.php` — `steps()` HasMany ordered by `position` ✓
- `routes/web.php` — current `dashboard` alias to `create` confirmed ✓
- `database/migrations/2026_05_28_210001_create_steps_table.php` — both `venture_id` and `owner_id` declare `cascadeOnDelete()` ✓
- `route('dashboard')` callsites: `AuthenticatedSessionController:35`, `RegisteredUserController:48`, `navigation.blade.php:5` ✓
- `withCount(['relation as alias' => fn])` is a known-good Laravel shape ✓
- `$touches` fires on `save()` regardless of which attribute changed; confirmed against `StepsController::toggleCompletion`'s `$step->save()` path ✓

## Findings

### F1 — `Step::$touches` has no automated guard despite being load-bearing

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Blind Spots
- **Location**: Phase 1 item 1; Phase 2 tests; Open Risks (plan-brief.md)
- **Detail**: The plan explicitly names `Step::$touches = ['venture']` as the "single point of failure for the sort promise" and warns the failure mode is silent (sort starts surfacing stale order). The mitigation is documentation + manual verification 1.7 — neither runs in CI. A 5-line automated assertion (set venture's `updated_at` to a known past timestamp, save a step on it, assert it advances) locks the behaviour against a future "unused property" cleanup pass.
- **Fix**: Add `test_step_save_touches_parent_venture_updated_at` to `ListVenturesTest` (or `EditStepTest`). Set venture's `updated_at` back, save a step, assert `$venture->fresh()->updated_at` advanced.
  - Strength: Locks the sort-promise behaviour in CI; cheap; matches the existing test directory; the plan already names this risk in two places.
  - Tradeoff: Adds a fifth test to the matrix the plan deliberately scoped to four.
  - Confidence: HIGH — `$touches` semantics on `save()` are well-documented and the assertion shape is mechanical.
  - Blind spot: Doesn't cover the step-delete path; if S-05/S-06 starts deleting individual steps as a primary action, a parallel assertion may be warranted then.
- **Decision**: FIXED (added `test_step_save_touches_parent_venture_updated_at` to `ListVenturesTest`; bumped Phase 2 counts from 4→5 new tests / 11→12 Ventures total / 2→3 list tests; added entry under Testing Strategy)

### F2 — "11 Steps tests" claim in Phase 1.1 is stale (actual: 17)

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 → Success Criteria → Automated → item 1.1
- **Detail**: Plan reads "the prior 7 Ventures + 11 Steps tests still passing". Actual repo: 7 Ventures (4+1+2) + **17** Steps (AddStepTest 2 + DeleteStepTest 2 + EditStepTest 3 + StoreSuggestedStepsTest 4 + SuggestExtensionTest 4 + ToggleStepTest 2). S-03 added 8 Steps tests after S-02 settled at 11. Phase 2's "Ventures suite now totals 11 tests (was 7)" is itself accurate — only Phase 1's Steps count is stale.
- **Fix**: Change 1.1 to read "the prior 7 Ventures + 17 Steps tests still passing."
- **Decision**: FIXED (updated Phase 1 Success Criteria + Progress 1.1)

### F3 — `Step::$fillable` reference in Current State is stale (actual: `#[Fillable]` attribute)

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Current State Analysis (line 11)
- **Detail**: Plan says "Source-immutability is locked at the model layer (`Step::$fillable = ['body', 'position']`)". Actual code at `app/Models/Step.php:11` uses the Laravel 12+ PHP attribute `#[Fillable(['body', 'position'])]`. Functionally equivalent, but worth noting because Phase 1 adds `protected $touches = ['venture'];` as a property (Laravel 12.x does not ship a `#[Touches]` attribute). Implementer should not "consistency-fix" the styling — the mixed attribute/property model is intentional.
- **Fix**: Update line 11 to reference `#[Fillable(['body', 'position'])]`. Add a one-line note in Phase 1 item 1 that the property/attribute styling mix is intentional.
- **Decision**: SKIPPED — implementer will read the model directly.

### F4 — Destroy test doesn't assert OTHER ventures' steps survive

- **Severity**: 📝 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 2 item 2 — `DeleteVentureTest::test_owner_can_delete_their_own_venture`
- **Detail**: Happy-path test creates one venture with 3 steps, deletes it, asserts the venture row + its 3 step rows are gone. Good. But it doesn't create a SECOND venture (same owner) with its own steps and assert THOSE steps survive. A future migration that accidentally cascades on `owner_id` instead of `venture_id` would over-delete and the current test would still pass. Defense-in-depth, not a known-bug catch; current schema is correctly declared.
- **Fix**: In the happy-path test, create a second venture (same owner) with 2 steps before deleting the first; after delete, assert the second venture's 2 steps still exist (~5 extra lines).
- **Decision**: FIXED (extended `test_owner_can_delete_their_own_venture` with target + sibling ventures; sibling survives; Testing Strategy line updated)
