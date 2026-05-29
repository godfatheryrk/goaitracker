<!-- PLAN-REVIEW-REPORT -->
# Plan Review: S-06 / venture-expenses-and-cost

- **Plan**: context/changes/venture-expenses-and-cost/plan.md
- **Mode**: Deep
- **Date**: 2026-05-29
- **Verdict**: REVISE → SOUND (after fixes applied)
- **Findings**: 0 critical · 2 warnings · 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | WARNING (F1) |
| Architectural Fitness | PASS |
| Blind Spots | PASS |
| Plan Completeness | WARNING (F2, F3) |

## Grounding

11/11 paths ✓ (VenturesController, StepsController, Step, Venture, User, routes/web.php, ventures/index.blade.php, ventures/show.blade.php, StepFactory, AddStepTest, CreateStepRequest, create_steps_table migration), key symbols ✓ (`Step::$touches`@21, doubly-scoped chain in StepsController, `withCount` chain in VenturesController::index), brief↔plan ✓.

Riskiest claims spot-check: precision-safe SUM discipline ✓ · doubly-scoped access path mirrors StepsController ✓ · `Step::$touches` lesson carries over ✓.

## Findings

### F1 — ExpenseFactory diverges from StepFactory (creates two separate users)

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Lean Execution
- **Location**: Phase 1 §5 — ExpenseFactory (plan.md:155-170)
- **Detail**: The proposed factory uses `'venture_id' => Venture::factory()` AND `'owner_id' => User::factory()` — two separate users. The existing `StepFactory` (`database/factories/StepFactory.php:18-30`) deliberately pre-creates the venture and reuses `$venture->owner_id`. Phase 1 manual verification step 1.7 would succeed but produce ownership-incoherent fixtures; tests omitting an explicit `owner_id` override could mask isolation bugs.
- **Fix**: Mirror StepFactory's pattern — pre-create the Venture in `definition()` and reuse its `owner_id`.
- **Decision**: FIXED (Fix in plan applied to Phase 1 §5)

### F2 — Progress block misses change.md status flip; one body↔Progress shift

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — add one bullet, split another, renumber downstream
- **Dimension**: Plan Completeness
- **Location**: Progress block — Phase 2 / Phase 3 (plan.md:616-645)
- **Detail**: Phase 3 Manual body lists 3 items but Progress only carries 3.4 and 3.5 — the `change.md status: implemented` flip has no Progress bullet. Phase 2 item 2.8 collapses two body items (metadata-lines render + `$touches` bubble) into one tick.
- **Fix**: Add `3.6 change.md flipped to status: implemented`; split Phase 2 2.8 into separate metadata-lines and bubble-verification bullets; renumber downstream (2.9 / 2.10 / 2.11).
- **Decision**: FIXED (Fix in plan applied to Phase 2 + Phase 3 Progress subsections)

### F3 — EditExpenseTest "tampered owner_id" claim doesn't prove what it says

- **Severity**: ℹ️ OBSERVATION
- **Impact**: 🏃 LOW — reframe the test's claim
- **Dimension**: Plan Completeness
- **Location**: Phase 3 §2 — EditExpenseTest user_b case (plan.md:476)
- **Detail**: The 404 in the user-B test comes from `$request->user()->ventures()->findOrFail()` (auth/relationship boundary), NOT from the FormRequest whitelist rejecting `owner_id`. The framing conflated cross-user 404 with rightful-owner tamper-resistance — two distinct invariants.
- **Fix**: Reframe the user_b test to claim only the cross-user 404 boundary; note that owner-immutability under same-user tampering is structurally covered by Fillable + FormRequest whitelist + forceFill but not by a dedicated v1 test.
- **Decision**: FIXED (Reframe only applied to Phase 3 §2)
