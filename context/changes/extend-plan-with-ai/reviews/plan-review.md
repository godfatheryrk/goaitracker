<!-- PLAN-REVIEW-REPORT -->
# Plan Review: S-03 / extend-plan-with-ai

- **Plan**: `context/changes/extend-plan-with-ai/plan.md`
- **Mode**: Deep
- **Date**: 2026-05-29
- **Verdict**: REVISE → SOUND (after triage: F1 fixed; F2 + F3 accepted as deferred)
- **Findings**: 0 critical, 1 warning, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | WARNING |
| Blind Spots | PASS |
| Plan Completeness | WARNING |

## Grounding

10/10 paths ✓, 4/4 symbols ✓, brief↔plan ✓, contract-surfaces ✓ (F-01 isolation / F-02 service / S-01 venture / S-02 step rules all accurately quoted), Progress↔Phase ✓ (2 phases, 6+5 success-criteria items, all enumerated as `- [ ] N.M`).

## Findings

### F1 — Route name `steps.suggestions.create` collides with Laravel's GET/POST resource convention

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architectural Fitness
- **Location**: Phase 1 — change #1 (routes/web.php)
- **Detail**: Laravel's resource-route convention reserves `.create` for a GET that renders a form and `.store` for the POST that persists it. The plan names a POST that calls AI (side effect) and renders a preview view `steps.suggestions.create`, and a POST that persists `steps.suggestions.store`. Both are POST. A reader scanning `route:list` will see `steps.suggestions.create` next to S-02's existing `steps.create` (GET → manual-add form) and reasonably assume the same shape.
- **Fix**: Rename `steps.suggestions.create` → `steps.suggestions.preview` (POST → renders preview). `.store` stays. Update the five sites that reference the name: `routes/web.php`, `ventures.show.blade.php` (×2 button forms), `suggestions/preview.blade.php` form action, and the two test files.
- **Decision**: FIXED — renamed across plan.md (6 sites) and plan-brief.md (2 sites) via `replace_all`. Controller method names (`suggest`, `storeSuggestions`) stay as-is — only the route NAME changes.

### F2 — Empty-state CTA label reads awkwardly when there are 0 steps

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 — change #6 (`ventures.show` empty-state block)
- **Detail**: Plan reuses "Suggest more with AI" in both surface positions. Empty-state user lands on "No steps yet." next to a button saying "Suggest **more** with AI". More of what? Wording-only.
- **Fix**: Use parallel-but-distinct labels per position. Populated state keeps "Suggest more with AI". Empty state uses "Suggest steps with AI" (or "Generate a plan with AI").
- **Decision**: ACCEPTED — implementer to choose wording during Phase 1.

### F3 — No success acknowledgment after the confirm action

- **Severity**: OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 1 — change #3 (`StepsController::storeSuggestions`)
- **Detail**: On confirm success the user is redirected to `ventures.show` with N new steps appended at the end. No flash confirms "added N steps" — the user has to scroll/count to verify. Keep-none path is visually indistinguishable from a swallowed error.
- **Fix**: Optional. Set `session()->flash('steps_added', "Added {$count} step(s).")` at the end of `storeSuggestions` when `count($chosen) > 0`; add a green/blue notice block in `ventures.show` mirroring the existing amber `ai_unavailable` block.
- **Decision**: ACCEPTED — deferred; v1 leaves the confirm path silent (user can see step count change). Reconsider if usage shows confusion.
