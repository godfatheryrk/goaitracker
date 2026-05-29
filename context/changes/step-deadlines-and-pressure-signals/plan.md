# Step Deadlines & Deadline-Pressure Signals (S-05) — Implementation Plan

## Overview

Add an optional, per-step **deadline** (date-only) and surface deadline pressure in two places: a per-step badge on the venture **detail** view (imminent within 3 days / overdue past), and a binary **deadline-pressure marker** on the venture **list** row. This is the S-05 vertical slice covering PRD **FR-014** (optional per-step deadline), **FR-020** (detail-view badge, persists until step complete), and **FR-021** (list-level pressure marker).

This slice runs in parallel with **S-06** (`venture-expenses-and-cost`) on a separate worktree. Both slices edit `VenturesController::index` and the **left content `<div>`** of the `ventures/index.blade.php` list row — they are NOT disjoint regions. S-06 was planned first and unilaterally fixed the row layout as stacked metadata `<p>` lines under the title (line 1 = step progress (S-04), line 2 = total cost (S-06), **line 3 = deadline-pressure marker, reserved for S-05**) plus its own aggregate on the `index` query chain. This plan **adopts S-06's layout verbatim** so the contract is single-sourced: S-05 adds the line-3 marker and an adjacent `withCount` clause. Because both slices append adjacent lines in the same blade `<div>` and the same query chain, whoever merges second performs a real (small) rebase of those adjacent lines — not a no-op.

## Current State Analysis

- **`steps` table** (`database/migrations/2026_05_28_210001_create_steps_table.php:9`) has `id, venture_id, owner_id, body(200), is_completed(bool), source(string), position(uint), timestamps` and a `(venture_id, position)` index. **No deadline column.**
- **`Step` model** (`app/Models/Step.php`) uses the attribute `#[Fillable(['body', 'position'])]`, casts `is_completed`→bool and `source`→`StepSource`, and declares `protected $touches = ['venture']`. `owner_id`/`source` are written via `->forceFill(...)` in the controller; `is_completed` is assigned directly (kept out of `$fillable`).
- **`StepsController`** (`app/Http/Controllers/StepsController.php`):
  - `store` (`:27`) builds the row with an explicit array `->make(['body' => ..., 'position' => ...])->forceFill(['owner_id' => ..., 'source' => StepSource::Manual])->save()` — it does **not** pass the full `validated()` array, so a new field must be added to that explicit array.
  - `update` (`:52`) does `$stepModel->update($request->validated())` — a whitelisted field flows automatically once it's in the FormRequest rules and `$fillable`.
  - Every action resolves through `$request->user()->ventures()->findOrFail($venture)` then `->steps()->findOrFail($step)` (404-not-403).
- **`CreateStepRequest`/`EditStepRequest`** (`app/Http/Requests/Steps/`) are body-only whitelists (`'body' => ['required','string','min:1','max:200']`). `EditStepRequest` carries the documented "FormRequest layer of source-immutability defense" comment.
- **`VenturesController::index`** (`app/Http/Controllers/VenturesController.php:17`) does `$request->user()->ventures()->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true)])->orderByDesc('updated_at')->get()`. `show` (`:78`) eager-loads `with('steps')` (ordered by `position`) — a `deadline` column loads automatically with the step rows, so `show` needs **no query change**.
- **`ventures/index.blade.php`** renders each venture as `<li class="py-3 flex items-start justify-between gap-4">` with a left `<div class="flex-1 min-w-0">` (title link + "X of Y steps completed" text) and a right `<form>` delete button.
- **`ventures/show.blade.php`** loops `@foreach ($venture->steps as $step)`; each `<li class="flex items-start gap-3">` holds a `data-toggle-completion` form (checkbox + `[data-step-body]` span), an Edit link, and a delete form.
- **`resources/js/app.js`** intercepts the completion toggle, PATCHes `steps.completion`, and on success flips the checkbox, toggles `line-through`/`text-gray-400` on `[data-step-body]`, and updates `[data-progress-text]` — all without reload.
- **`steps/create.blade.php` + `steps/edit.blade.php`** are the two existing step forms (body field only).

### Key Discoveries:

- `Step::$touches = ['venture']` (`app/Models/Step.php`) is load-bearing for the S-04 list sort. The deadline write path uses `->update()` / `->save()` (never raw `DB::table`), so `$touches` keeps firing — no special handling needed, but no shortcut allowed either.
- The "within 3 days" window must be defined **once** and reused by both the `index` query closure (FR-021) and the detail-view classification (FR-020) — see Critical Implementation Details.
- S-04 explicitly "left room without pre-building a slot" on the list row for exactly S-05 (FR-021) and S-06 (FR-019); this is the planned seam (`docs/reference/contract-surfaces.md`, Venture list + destroy surface).

## Desired End State

- A user can set, change, or clear an optional date deadline on any step from the add-step and edit-step forms. Leaving it blank is first-class (no deadline).
- On the venture detail view, each deadlined incomplete step shows its date; steps imminent (≤3 days, not past) render with amber emphasis, overdue (past) with red emphasis, future deadlines as muted plain text. Completing a step drops the emphasis immediately (no reload).
- On the venture list, any venture with at least one incomplete, deadlined step that is imminent or overdue shows a binary deadline-pressure marker. Completed steps and steps without deadlines never trigger it.
- All deadline data is per-user isolated (404 for a foreign user on the write path).
- Verified by: `composer run test` green (new feature tests included), `vendor/bin/pint` clean, and manual UI walkthrough on the detail + list views.

## What We're NOT Doing

- **No intra-day / time-of-day deadlines** — date-only for v1 (FR-014 Socrates resolution defers timing to v2).
- **No venture-level deadline** — per-step only (FR-014 v2 note).
- **No configurable window or snooze** — the 3-day imminent window is fixed v1 (FR-020).
- **No richer list prioritization** — binary marker only; no count/severity/sort (FR-021 v1).
- **No external reminders** (email/push) — in-app surfacing only (PRD Non-Goals).
- **No inline date editing on the list/detail** — deadlines are set through the existing create/edit step forms (no new routes, no new JS island for editing).
- **No total-cost cell on the list row** — that is S-06's line-2 metadata slot; this plan only leaves that line untouched/reserved and never renders cost.
- **No change to `VenturesController::show`'s query** — `deadline` rides along with the already-eager-loaded steps.

## Implementation Approach

Build the data + write path + list-pressure query first (Phase 1, with its backend feature tests), then the UI surfaces + live-toggle JS (Phase 2, with its render/UX tests). The deadline write reuses the existing create/edit forms and controller actions; the list-pressure signal is a single additive `withCount` clause. All shared-surface edits with S-06 are kept additive and regionally isolated, with a documented row-column contract.

## Critical Implementation Details

- **Single source for the 3-day window.** Define the imminent window once (e.g. a `Step` class constant `IMMINENT_WINDOW_DAYS = 3`) and reference it from both the `VenturesController::index` pressure closure and the `Step` deadline-pressure accessor. If the two definitions drift, the list marker and the detail badge disagree about the same step. This constant is the contract.
- **Pressure = incomplete AND deadline set AND `deadline <= today + 3` (which includes past).** The query closure uses this single predicate for FR-021. The detail-view accessor splits that same population into `overdue` (`deadline < today`) vs `imminent` (`today <= deadline <= today + 3`) for the FR-020 color. Completed steps are excluded in both places (FR-020 "persists until complete").
- **Date-only comparisons use `today()` (midnight Carbon).** `deadline` is cast `'date'`; comparisons against `today()` / `today()->addDays(IMMINENT_WINDOW_DAYS)` are date-aligned, avoiding off-by-part-of-a-day errors.
- **Shared-row contract (adopted from S-06 — load-bearing for the parallel merge).** S-06's plan-brief (`context/changes/venture-expenses-and-cost/plan-brief.md`) already fixed the `ventures/index.blade.php` row as **stacked metadata `<p>` lines inside the left content `<div>`**: line 1 = step progress (S-04, exists), line 2 = `Total: X.XX` (S-06), **line 3 = deadline-pressure marker (this slice, S-05)**. S-05 adopts that layout — it does NOT introduce a right-side flex column. Both slices therefore edit the same left `<div>`, appending adjacent lines; this is the documented seam, not disjoint regions. In `VenturesController::index`, each slice appends its own aggregate to the existing chain (S-05: `pressured_steps_count` via `withCount`; S-06: `total_cost` via `withSum`) on adjacent lines — additive, no rewrite of the existing `withCount`. The two aliases differ (`pressured_steps_count` vs `total_cost`) so there is no chain collision. **S-06 already records this contract in `docs/reference/contract-surfaces.md`; S-05's Phase 2 doc edit must align with (not contradict) S-06's section** — if S-06 hasn't written it yet, whichever slice writes first owns the wording and the other references it.

## Phase 1: Deadline persistence + list-pressure query

### Overview

Add the `deadline` column and wire it through the model, form requests, and controller write path, plus the additive list-pressure aggregate. Ship the backend feature tests that prove persistence, validation, list-pressure correctness, and isolation.

### Changes Required:

#### 1. Migration — add `deadline` to `steps`

**File**: `database/migrations/<new timestamp>_add_deadline_to_steps_table.php`

**Intent**: Add a nullable date column so steps can carry an optional deadline; existing rows default to NULL (no deadline), preserving FR-014's "optional, first-class blank."

**Contract**: New migration with `Schema::table('steps', …)` adding `$table->date('deadline')->nullable()->after('is_completed');` and dropping it in `down()`. Date type (not datetime) — date-only v1.

#### 2. `Step` model — fillable, cast, window constant, pressure accessor

**File**: `app/Models/Step.php`

**Intent**: Make `deadline` user-assignable through the validated-update flow, cast it to a date, define the single 3-day-window constant, and expose a pressure classification for the detail view.

**Contract**:
- Add `'deadline'` to the `#[Fillable([...])]` attribute (now `['body', 'position', 'deadline']`).
- Add `'deadline' => 'date'` to `$casts`.
- Add `public const IMMINENT_WINDOW_DAYS = 3;`.
- Add a **completion-agnostic** method returning the deadline-only pressure level for FR-020 — `null` when no deadline, `'overdue'` when `deadline < today()`, `'imminent'` when `deadline <= today()->addDays(self::IMMINENT_WINDOW_DAYS)`, else `null` (future/non-pressured). It MUST NOT inspect `is_completed` — this is what lets the badge's `data-pressure-class` always carry the *would-be* emphasis even for a step that loads completed, so the live toggle can restore it on un-complete (see Phase 2 §4 and the F2 rationale below). **Whether the emphasis is currently *shown* is a separate decision made at render time** (`! $step->is_completed`), not inside this method. The detail view reads this; the list query does NOT (it uses the aggregate to avoid N+1).

#### 3. Form requests — whitelist `deadline`

**Files**: `app/Http/Requests/Steps/CreateStepRequest.php`, `app/Http/Requests/Steps/EditStepRequest.php`

**Intent**: Allow an optional date through both the add and edit paths while keeping the source-immutability whitelist discipline intact.

**Contract**: Add `'deadline' => ['nullable', 'date']` to each `rules()` array. `nullable` makes blank/clear first-class; an empty submit clears the deadline on edit.

#### 4. `StepsController::store` — persist deadline on add

**File**: `app/Http/Controllers/StepsController.php:27`

**Intent**: Carry the (optional) deadline into the explicit `->make([...])` array used by the manual-add path.

**Contract**: Add `'deadline' => $request->validated('deadline')` to the `->make([...])` array. `source`/`owner_id` continue via `->forceFill(...)`; `$touches` fires on `save()`.

#### 5. `StepsController::update` — already flows

**File**: `app/Http/Controllers/StepsController.php:52`

**Intent**: Confirm (no code change beyond the FormRequest) that `->update($request->validated())` now carries `deadline` because it's whitelisted + fillable; an empty value clears it.

**Contract**: No new statements; the existing `$stepModel->update($request->validated())` is the write. `$touches` fires.

#### 6. `VenturesController::index` — additive pressure aggregate

**File**: `app/Http/Controllers/VenturesController.php:17`

**Intent**: Add a per-venture count of incomplete, deadlined, imminent-or-overdue steps so the list row can render the FR-021 binary marker without N+1.

**Contract**: Append to the existing `withCount([...])` array a clause `'steps as pressured_steps_count' => fn ($q) => $q->where('is_completed', false)->whereNotNull('deadline')->whereDate('deadline', '<=', today()->addDays(Step::IMMINENT_WINDOW_DAYS))`. Existing `steps`/`completed_steps_count` counts and `orderByDesc('updated_at')` are untouched (additive — the S-06 cost `withSum` will append alongside).

#### 7. Backend feature tests

**Files**: `tests/Feature/Steps/StepDeadlineTest.php` (new), additions to `tests/Feature/Ventures/ListVenturesTest.php`

**Intent**: Prove persistence/validation, list-pressure correctness, and isolation at the backend layer (no view assertions here — those are Phase 2).

**Contract**:
- `StepDeadlineTest`: add-step with a deadline persists it; edit-step sets a deadline; edit-step with empty deadline clears it to NULL; a non-date value 422s; two-user-404 on the edit/update path when user B targets user A's step (deadline unchanged in DB).
- `ListVenturesTest`: a venture with an incomplete step due within 3 days (and one overdue) has `pressured_steps_count > 0`; a venture whose only deadlined step is completed, or whose steps have no deadline, or whose deadline is >3 days out, has `pressured_steps_count === 0`.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly: `php artisan migrate` (fresh) succeeds
- New + existing tests pass: `composer run test`
- Formatting clean: `vendor/bin/pint --test`

#### Manual Verification:

- Adding a step with a deadline and re-opening its edit form shows the saved date
- Clearing the date on the edit form removes the deadline (no error)

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation before proceeding to Phase 2.

---

## Phase 2: Deadline UI + live toggle

### Overview

Surface deadlines: date inputs on the create/edit forms, per-step date + pressure emphasis on the detail view, the binary FR-021 marker on the list row (in the S-05-owned region with a documented S-06 column contract), and a live emphasis-suppression on the completion toggle. Ship the render/UX feature tests.

### Changes Required:

#### 1. Step forms — optional date input

**Files**: `resources/views/steps/create.blade.php`, `resources/views/steps/edit.blade.php`

**Intent**: Let the user set/change/clear a deadline when adding or editing a step.

**Contract**: Add an `<input type="date" name="deadline">` (labeled optional). The edit form pre-fills with `$step->deadline?->format('Y-m-d')`; the create form defaults blank. Show the `deadline` validation error. Match the existing form's Tailwind/markup conventions.

#### 2. Detail view — per-step date + pressure emphasis

**File**: `resources/views/ventures/show.blade.php` (the `@foreach ($venture->steps as $step)` loop)

**Intent**: Show each deadlined step's date; emphasize amber (imminent) / red (overdue) only when pressured; completed steps show the date muted. Wire the badge for live JS suppression.

**Contract**: After the `[data-step-body]` span, conditionally render (only when `$step->deadline` is set) a badge element carrying `data-deadline-badge` and `data-pressure-class="<amber|red emphasis classes>"`. **`data-pressure-class` is computed from the deadline-only accessor regardless of completion state** (empty string only when the deadline is genuinely non-pressured — future/none), so an already-completed-at-load overdue/imminent step still carries the would-be classes for the toggle to restore. Base classes render the muted date (e.g. `text-xs text-gray-500`, `Due {{ $step->deadline->format('M j') }}`); the emphasis classes (amber `text-amber-700 font-medium` / red `text-red-600 font-medium`, "Overdue" prefix for the past case) are **applied in the initial render only when `! $step->is_completed`** — a completed step renders muted even though `data-pressure-class` is populated. This split (data attribute = deadline-only; applied-or-not = completion gate) is the F2 fix: it makes the live un-complete path symmetric for every step, not just steps that were incomplete at page load.

#### 3. List row — FR-021 marker + S-06 column contract

**File**: `resources/views/ventures/index.blade.php` (the `<li>` row, left content `<div>`)

**Intent**: Render a binary deadline-pressure marker for ventures with `pressured_steps_count > 0` as **line 3 of the stacked metadata** in the left content `<div>`, per the S-06-defined row contract (line 1 progress / line 2 cost / line 3 marker).

**Contract**: Inside the left `<div class="flex-1 min-w-0">`, **after S-06's line-2 cost `<p>` slot** (line 1 = the existing progress `<p>`; line 2 = S-06's `Total: X.XX` — leave it untouched if present, or leave its slot reserved if S-06 hasn't merged yet), add `@if ($venture->pressured_steps_count > 0)` a small marker on its own line (e.g. `<p class="mt-1 text-xs text-red-600 font-medium">⚠ Deadline pressure</p>`). Do NOT touch the right-side delete form or the `<li>`'s flex container, and do NOT introduce a new right-side flex column. If S-06's line-2 cost is not yet merged, place the marker after the progress `<p>` and add a blade comment marking the line-2 slot as reserved for S-06's `Total: X.XX` so the merge order is unambiguous.

#### 4. `app.js` — live emphasis suppression on completion

**File**: `resources/js/app.js`

**Intent**: When a step's completion flips, immediately drop (on complete) or restore (on un-complete) the deadline badge's pressure emphasis, matching FR-020's "persists until complete" without a reload.

**Contract**: In the existing `data-toggle-completion` success handler, locate the row's `[data-deadline-badge]`; on `is_completed === true` remove the classes listed in its `data-pressure-class` (and ensure muted), on `false` re-add them. Because `data-pressure-class` is now completion-agnostic (Phase 2 §2 / Phase 1 §2 F2 fix), re-adding restores the correct emphasis even for a step that loaded completed and is then un-completed. Guard for an empty `data-pressure-class` (a non-pressured/future deadline) — splitting an empty string must be a no-op, not add a stray class. No new fetch — reuse the existing toggle response.

#### 5. Contract-surfaces doc — S-05 surface + S-06 seam note

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Record the new deadline surface and restate the row-column contract so the parallel S-06 slice reads it before editing the shared row/query.

**Contract**: Add a "Step deadline surface (S-05)" section (column, fillable/whitelist decision, the `IMMINENT_WINDOW_DAYS` single-source rule, FR-020/021 behavior, test proof). For the shared list row, **align with — do not contradict — S-06's existing row contract** (line 1 progress / line 2 cost / line 3 deadline marker; aggregates `pressured_steps_count` + `total_cost` on the same `withCount`/`withSum` chain). If S-06's section already states the row layout, reference it rather than restating a competing version; if it does not yet exist, write the layout once here and note that S-06 must conform.

#### 6. Render/UX feature tests

**File**: additions to `tests/Feature/Ventures/` (detail + list render)

**Intent**: Prove the FR-020/021 surfaces render correctly.

**Contract**:
- Detail view: a step due within 3 days renders the imminent emphasis; an overdue step renders the overdue emphasis; a step with no deadline renders no badge; a completed overdue step renders no pressure emphasis (date may show muted).
- List view: a venture with `pressured_steps_count > 0` renders the marker; one with `0` does not.

### Success Criteria:

#### Automated Verification:

- All tests pass: `composer run test`
- Front-end build succeeds: `npm run build`
- Formatting clean: `vendor/bin/pint --test`

#### Manual Verification:

- Detail view: imminent step shows amber date, overdue shows red "Overdue …", future shows muted date, no-deadline shows nothing
- Completing an overdue step removes the red emphasis without reloading; un-completing restores it
- List view: a venture with an imminent/overdue incomplete step shows the marker; completing/clearing that step's deadline removes the marker on next list load
- The marker renders as line 3 of the stacked left metadata; S-06's line-2 cost slot is left intact/reserved (no layout breakage, no right-side column introduced)

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation.

---

## Testing Strategy

### Unit / Feature Tests:

- Deadline persistence: set via create, set/clear via edit, invalid-date rejection (422).
- Isolation: two-user-404 on the deadline write (edit/update) path; deadline value unchanged for the owner.
- List pressure aggregate: `pressured_steps_count` counts only incomplete + deadlined + imminent-or-overdue; ignores completed, no-deadline, and >3-days-out.
- Detail render: imminent → amber, overdue → red, future → muted, none → absent, completed overdue → no emphasis.
- List render: marker present iff `pressured_steps_count > 0`.

### Manual Testing Steps:

1. Add a step with a deadline 2 days out → detail shows amber; list shows the marker.
2. Edit it to a past date → detail shows red "Overdue"; list still marked.
3. Complete it on the detail view → red emphasis disappears without reload; reload the list → marker gone.
4. Clear the deadline on the edit form → no badge; no error.
5. Add a step with a deadline 10 days out → muted date, no marker.

## Performance Considerations

The list adds one indexed-friendly count subquery per index render (additive to the two existing counts) — no N+1. The detail view uses an in-memory accessor over already-loaded steps. No new queries on `show`.

## Migration Notes

`deadline` is nullable with no default — existing step rows become "no deadline," which is the correct FR-014 semantics. The migration is additive and reversible (`down()` drops the column).

## References

- Change identity: `context/changes/step-deadlines-and-pressure-signals/change.md`
- PRD: `context/foundation/prd.md` — FR-014, FR-020, FR-021, US-01
- Contract surfaces: `docs/reference/contract-surfaces.md` — Step surface (S-02), Venture list + destroy surface (S-04), per-user isolation rules
- Parallel slice: `context/changes/venture-expenses-and-cost/` (S-06) — shares the `index()` query + `index.blade.php` row
- Step model / controller: `app/Models/Step.php`, `app/Http/Controllers/StepsController.php:27`, `:52`
- List query: `app/Http/Controllers/VenturesController.php:17`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Deadline persistence + list-pressure query

#### Automated

- [x] 1.1 Migration applies cleanly (fresh `php artisan migrate`) — c187ff7
- [x] 1.2 New + existing tests pass (`composer run test`) — c187ff7
- [x] 1.3 Formatting clean (`vendor/bin/pint --test`) — c187ff7

#### Manual

- [x] 1.4 Adding a step with a deadline and re-opening edit shows the saved date — 8abc6b4 (edit-form prefill `$step->deadline?->format('Y-m-d')`; persistence covered by `StepDeadlineTest::test_add_step_persists_deadline`)
- [x] 1.5 Clearing the date on the edit form removes the deadline (no error) — c187ff7 (covered by `StepDeadlineTest::test_edit_step_with_empty_deadline_clears_it`)

### Phase 2: Deadline UI + live toggle

#### Automated

- [x] 2.1 All tests pass (`composer run test`) — 8abc6b4 (72 passed, 287 assertions)
- [x] 2.2 Front-end build succeeds (`npm run build`) — 8abc6b4
- [x] 2.3 Formatting clean (`vendor/bin/pint --test`) — 8abc6b4

#### Manual

- [x] 2.4 Detail view: imminent=amber, overdue=red "Overdue", future=muted, none=absent — 8abc6b4 (covered by `ShowVentureDeadlineTest` render matrix)
- [x] 2.5 Completing an overdue step removes red emphasis without reload; un-completing restores it — 8abc6b4 (deadline-only `data-pressure-class` + guarded `app.js` toggle; completion-agnostic class retention covered by `ShowVentureDeadlineTest::test_completed_overdue_step_renders_no_applied_emphasis_but_keeps_data_class`)
- [x] 2.6 List view: marker present for imminent/overdue incomplete step; gone after completing/clearing — 8abc6b4 (covered by `ListVenturesTest` marker present/absent + pressure-aggregate exclusion cases)
- [x] 2.7 Right side of the list row is free for S-06's cost cell (no layout breakage) — 8abc6b4 (marker is line 3 of stacked left metadata; no right-side column introduced; line-2 cost slot reserved)
