# Step Deadlines & Deadline-Pressure Signals (S-05) — Plan Brief

> Full plan: `context/changes/step-deadlines-and-pressure-signals/plan.md`

## What & Why

Give each step an optional date deadline and make deadline pressure visible where the user already looks — a badge on the venture detail view and a marker on the venture list. This closes the third PRD pain ("deadlines drift unnoticed"): FR-014 (optional per-step deadline), FR-020 (detail badge for imminent/overdue), FR-021 (list-level pressure marker).

## Starting Point

The `steps` table has no deadline column. The `Step` model, `StepsController` (create/edit/update), and the two step forms exist and work; `VenturesController::index` already builds per-row step counts via `withCount` and the list/detail blades already render steps. S-04 deliberately left room on the list row for exactly this signal (and S-06's cost).

## Desired End State

A user sets/changes/clears a deadline from the existing add and edit step forms. The detail view shows each deadlined step's date — amber when due within 3 days, red when overdue, muted otherwise — and completing a step drops the emphasis live (no reload). The list flags any venture with an incomplete, deadlined, imminent-or-overdue step. All per-user isolated.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Deadline granularity | Date-only, nullable | FR-014 v1 defers intra-day timing to v2 | PRD |
| Edit surface | Reuse existing create + edit step forms | No new routes/JS; deadline settable at add-time or later | Plan |
| Mass-assignment | Add `deadline` to `#[Fillable]` + FormRequest whitelist | User-editable (unlike `source`); matches the `validated()->update()` flow | Plan |
| List detection | Additive `withCount` of pressured steps | One subquery, no N+1, orthogonal to S-06's cost `withSum` | Plan |
| Detail rendering | Always show date; emphasize only when pressured | Keeps the imminent/overdue signal without noise on non-urgent steps | Plan |
| Completion + badge | Suppress emphasis live in `app.js` toggle | FR-020 "persists until complete" without a reload | Plan |
| S-06 parallel seam | Sequence + isolate; documented row-column contract | S-05 owns left content region; right side reserved for S-06 cost cell | Plan |
| 3-day window | Single `Step::IMMINENT_WINDOW_DAYS` constant | Query and badge must agree on the same boundary | Plan |

## Scope

**In scope:** `deadline` column; model cast/fillable/accessor + window constant; create/edit form date inputs; controller write path; `index` pressure aggregate; detail-view date + emphasis; list-row marker; `app.js` live suppression; feature tests; contract-surfaces doc update.

**Out of scope:** intra-day time, venture-level deadlines, configurable window/snooze, richer list prioritization, external reminders, inline date editing, S-06's total-cost cell (only its column position is reserved), any change to `show`'s query.

## Architecture / Approach

Phase 1 lands the data + write path + the additive list-pressure `withCount`, with backend feature tests. Phase 2 adds the UI (forms, detail badge, list marker) + the live-toggle JS, with render/UX tests. The 3-day window lives in one constant used by both the list query and the detail accessor. Shared-with-S-06 edits (`index()` query, list-row blade) are additive and regionally isolated under a documented row-column contract.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Persistence + list query | `deadline` column, model/forms/controller write path, `pressured_steps_count` aggregate, backend tests | Window predicate drift between query and accessor (mitigated by the shared constant) |
| 2. UI + live toggle | Form date inputs, detail badge, list marker, `app.js` suppression, contract doc, render tests | Merge collision with S-06 on the list row / `index` query (mitigated by the documented column contract) |

**Prerequisites:** S-01–S-04 merged (venture + step + list surfaces exist). Awareness that S-06 runs in parallel on a separate worktree.
**Estimated effort:** ~1–2 sessions across 2 phases.

## Open Risks & Assumptions

- **Parallel S-06 merge.** Both slices touch `VenturesController::index` and `ventures/index.blade.php`. Risk reduced to a trivial rebase by keeping S-05's edits additive and confined to documented regions; whoever merges second still rebases the adjacent `withCount`/row lines.
- **Date math is date-only.** Comparisons use `today()`; correct for a date-only v1, but anyone later adding time-of-day must revisit the window logic.
- Assumes `Step::$touches = ['venture']` keeps firing — guaranteed because the deadline write goes through `update()`/`save()`, never raw SQL.

## Success Criteria (Summary)

- A user can set, change, and clear an optional step deadline; blank stays first-class.
- Detail view distinguishes imminent (amber) vs overdue (red) and clears emphasis on completion without reload.
- The venture list flags ventures with incomplete imminent/overdue steps and ignores completed / no-deadline / far-future ones; no cross-user leakage.
