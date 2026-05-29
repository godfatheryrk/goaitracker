<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Step Deadlines & Deadline-Pressure Signals (S-05)

- **Plan**: context/changes/step-deadlines-and-pressure-signals/plan.md
- **Mode**: Deep
- **Date**: 2026-05-29
- **Verdict**: REVISE → SOUND (after fixes)
- **Findings**: 1 critical, 1 warning, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | FAIL → resolved (F1 fixed) |
| Blind Spots | WARNING → resolved (F2 fixed) |
| Plan Completeness | PASS |

## Grounding
11/11 paths ✓, symbols ✓ (withCount / $touches=['venture'] / #[Fillable] attribute / update($request->validated())), brief↔plan ✓, Progress↔Phase consistent ✓, S-06 plan-brief cross-read ✓.

## Findings

### F1 — S-05 and S-06 row-layout contracts contradict each other

- **Severity**: ❌ CRITICAL
- **Impact**: 🔬 HIGH — architectural stakes; the plan's central parallel-safety claim was false
- **Dimension**: Architectural Fitness
- **Location**: Overview (line 7), Critical Implementation Details, Phase 2 §3
- **Detail**: S-05 asserted S-06's total-cost cell lives on the RIGHT (own flex column) with the deadline marker on the left, claiming "neither slice edits the other's region … trivial rebase." S-06's plan-brief (lines 15, 24, 42, 102–104) says cost = left "line 2" `<p>` under the title and reserves left "line 3" for S-05's marker. Both slices edit the same left content `<div>` and disagree on where the cost cell lives; both intend to write conflicting authoritative layouts into contract-surfaces.md. S-06 had pre-flagged this exact divergence as an Open Risk. Minor sub-point: S-06 recommended aggregate aliases `imminent_steps_count`/`overdue_steps_count`; S-05 uses `pressured_steps_count` (no functional collision with `total_cost`, different name).
- **Fix A ⭐ Recommended**: Adopt S-06's written layout — stacked left `<p>` lines (1=progress, 2=cost, 3=marker); drop the "right column" framing; re-state the seam as additive-adjacent (real small rebase, not no-op); reframe the contract-surfaces edit to align with rather than compete with S-06.
  - Strength: S-06 was planned first and explicitly reserved line 3; zero S-06 edits; single-sourced contract.
  - Tradeoff: Both worktrees still touch the left div/query chain — merge is adjacent-line rebase, not disjoint.
  - Confidence: HIGH — doc-alignment fix, not a redesign.
  - Blind spot: Whoever merges second still rebases the adjacent lines + withCount/withSum chain.
- **Fix B**: Keep S-05's right-column cost layout; require editing the S-06 plan + its contract section first.
  - Strength: Cleaner left/right separation; truly disjoint regions.
  - Tradeoff: More churn; defeats S-06 having gone first; risky if S-06 is mid-flight.
  - Confidence: MEDIUM — depends on S-06 status.
  - Blind spot: Whether S-06 is already being implemented.
- **Decision**: FIXED via Fix A (Overview, Critical Implementation Details, Phase 2 §3 + §5, NOT-doing bullet, manual-verification 2.7 all realigned to S-06's stacked-line layout).

### F2 — Un-completing a step that loaded completed won't restore emphasis

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real correctness gap on a reachable path (reload-after-complete)
- **Dimension**: Blind Spots
- **Location**: Phase 2 §2 (badge render) + §4 (app.js toggle), Phase 1 §2 (accessor)
- **Detail**: The accessor returned `null` for completed steps, so `data-pressure-class` was empty for a step rendered already-completed. On un-complete, app.js re-adds the (empty) classes → no red/amber emphasis until reload, contradicting manual test 2.5.
- **Fix**: Make the pressure accessor completion-agnostic (deadline-only: overdue/imminent/null) so `data-pressure-class` always carries the would-be emphasis; gate whether it is *applied* in the initial render on `! $step->is_completed`; guard app.js against splitting an empty `data-pressure-class`.
  - Strength: Symmetric live toggle for every step regardless of load-time completion; one extra blade expression, no new query.
  - Tradeoff: Pressure classification exposed in a completion-agnostic form (small).
  - Confidence: HIGH — local mechanical fix.
  - Blind spot: None significant.
- **Decision**: FIXED (Phase 1 §2 accessor made completion-agnostic; Phase 2 §2 splits data-attr from applied-gate; Phase 2 §4 adds empty-class guard).
