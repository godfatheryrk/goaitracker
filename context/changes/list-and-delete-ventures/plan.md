# S-04 / list-and-delete-ventures — Implementation Plan

## Overview

Land the venture list (FR-005) and the venture-delete endpoint (FR-007) on top of the surface S-01/S-02 already built. The slice introduces two new actions on the existing `VenturesController` (`index`, `destroy`), one new view (`ventures/index.blade.php`), and reassigns the documented `dashboard` route name from the create form to the new list — the contract-surfaces hand-off point S-01 explicitly deferred to this slice. Each list row carries title (linked to detail) + a "`X of Y` steps completed" progress signal + a Delete form using the same native `confirm()` pattern S-02 set for step delete. The list ships **thin** — no deadline marker (S-05) and no total-cost cell (S-06), only the columns S-04's own PRD refs warrant. Ordering is `updated_at DESC` so the venture the user last touched bubbles to the top, which is the shape the secondary success metric ("users return to a venture at least once") rewards.

No model, schema, migration, JS island, FormRequest, or fillable change is in scope. The slice is mechanically small but contractually load-bearing — the F-01 enforcement checklist (two-user 404 not 403, `auth` boundary, ownership-scoped resolution) applies to both new endpoints, and the schema-level cascade chain (`steps.venture_id ON DELETE CASCADE`, `steps.owner_id ON DELETE CASCADE` from S-01) is what makes `$ventureModel->delete()` clean up a venture's whole step subtree in one statement without an application-level loop.

## Current State Analysis

- **S-01 + S-02 are landed and untouched.** `VenturesController` implements `create` / `store` / `show` through `$request->user()->ventures()->...` (`app/Http/Controllers/VenturesController.php:17-76`); the read+write step surface lives on the doubly-scoped `steps.*` nested routes (`routes/web.php:32-53`). Source-immutability is locked at the model layer (`Step::$fillable = ['body', 'position']`), and the FR-018 progress text already renders on the detail view via `$completed = $model->steps->where('is_completed', true)->count(); $total = $model->steps->count();` (`app/Http/Controllers/VenturesController.php:67-69`).
- **`dashboard` is currently a route-name alias for the create form.** `Route::get('dashboard', [VenturesController::class, 'create'])->name('dashboard')` (`routes/web.php:24`) is the placeholder S-01 introduced specifically so `route('dashboard')` callers — `AuthenticatedSessionController::store:35` and `RegisteredUserController::store:48`, both `redirect()->intended(route('dashboard', absolute: false))` — keep working without an edit. The nav home link in `resources/views/layouts/navigation.blade.php:5` also points at `route('dashboard')`. The contract-surfaces note explicitly tags S-04 as the reassignment point: _"When S-04 lands and the dashboard concept becomes the venture list, the `dashboard` route name reassigns; `ventures.create` stays."_ (`docs/reference/contract-surfaces.md:114`).
- **Cascade delete is already wired at the schema level.** The ventures table declares `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` (`database/migrations/2026_05_28_210000_create_ventures_table.php:13`), and the steps table declares both `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` and `foreignId('venture_id')->constrained()->cascadeOnDelete()` (`database/migrations/2026_05_28_210001_create_steps_table.php`). So `$venture->delete()` drops the row plus its step subtree in one DB statement; no application-level `foreach ($venture->steps as $step) $step->delete()` loop is needed (and adding one would be wrong — the cascade does it atomically). The `ai_call_counters` table is keyed by `owner_id`, NOT `venture_id`, so deleting a venture does NOT consume the user's 24h ceiling budget back — that's correct (the calls really happened).
- **`Venture::steps()` is `hasMany(Step::class)->orderBy('position')`** (`app/Models/Venture.php:21-24`). Calling `withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true)])` from the index gives us `$venture->steps_count` and `$venture->completed_steps_count` without N+1 — one extra subquery per aggregate against the indexed `(venture_id, position)` index. Verified Laravel 13.x supports the named `as` subquery shape.
- **`updated_at` is touched automatically by S-02's step write actions** (Eloquent's `touches` is unset, so it ISN'T touched today — important caveat). Currently the venture row's `updated_at` only changes when the venture's own attributes (`title` / `description`) change, which they currently never do post-create. **This is a behavioural gap S-04 needs to surface:** with the chosen `ORDER BY updated_at DESC` sort, a venture the user is actively editing the steps of will NOT bubble to the top of the list until S-02's `Step` model declares `protected $touches = ['venture'];`. See "Critical Implementation Details" below.
- **F-01 access-path discipline is set in stone.** Both new endpoints MUST resolve the venture through `$request->user()->ventures()->...` — the relationship-scoped query produces 404 (not 403) for a foreign user, which is what the F-01 S-01 enforcement checklist item 4 mandates. Pattern lives at `app/Http/Controllers/VenturesController.php:67` and `app/Http/Controllers/StepsController.php:22-24,29,46-47`. The destroy endpoint is the first delete on the venture model itself; the same rule applies.
- **The S-02 step-delete pattern is the v1 confirmation shape.** `resources/views/ventures/show.blade.php:76-85` uses an inline `<form ... onsubmit="return confirm('Delete this step?')">` with a `@csrf` + `@method('DELETE')` body. S-04's venture-delete uses the same shape (`'Delete this venture?'`); the blast radius difference is acknowledged in §What We're NOT Doing rather than driving the surface to a heavier modal.

## Desired End State

A logged-in user lands on `/dashboard` (post-login, post-register, or via the nav home link) and sees the venture list. The list orders by `updated_at DESC`. Each row shows: the venture title as a link to `/ventures/{n}`, a small `"X of Y steps completed"` progress string (or `"—"` when total is 0), and a Delete button that fires a native `confirm('Delete this venture? This will also remove all its steps.')` dialog. A "+ New venture" button sits above or below the list, pointing at `ventures.create`. The empty state replaces the list with a paragraph + a primary CTA pointing at `ventures.create` — the form a brand-new user reaches via register-then-dashboard.

Clicking Delete and confirming POSTs a DELETE to `/ventures/{n}`, drops the venture row plus its step rows via the schema cascade, and redirects back to the list. A second user POSTing a DELETE to a venture they don't own gets 404, never 403. Guests hitting either endpoint get 302 to `/login` via the `auth` middleware boundary (no separate guest-redirect test ships — the boundary is the same `auth` group already proven for `ventures.show`). The S-02 step write surface still works unchanged; existing tests still pass.

`docs/reference/contract-surfaces.md` carries a new "Venture list + destroy surface (S-04)" section that names the new routes, restates the F-01 access-path rule in the slice-specific shape (`$request->user()->ventures()->findOrFail($id)->delete()`), and explicitly flags the `Step::$touches = ['venture']` coupling so S-05/S-06 don't trip over it. The roadmap `S-04` row flips from `proposed` to `done`; `change.md` flips from `planned` to `implemented`.

### Key Discoveries:

- **The cascade chain in the existing schema is already enough.** `database/migrations/2026_05_28_210001_create_steps_table.php` declares both `venture_id` and `owner_id` with `cascadeOnDelete()`, and the F-02 `ai_call_counters` table has no `venture_id` column (counter is owner-scoped only). So `$venture->delete()` drops the venture + step rows in a single DB statement; no manual loop, no risk of partial delete.
- **`withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true)])`** gives the list two count columns without N+1. The named subquery shape is the Laravel-idiomatic way to add a filtered count alongside the unfiltered one. Confirmed against Laravel 13.x docs.
- **`updated_at` does NOT bubble on step writes today.** Eloquent's `$touches` array is unset on `Step`. With the chosen sort, this means editing steps would NOT move the parent venture toward the top of the list — which contradicts the user-visible promise of "most recently touched first." The fix is `protected $touches = ['venture'];` on `Step` (one line); the alternative (sorting by `ventures.updated_at` only) is wrong because it doesn't fire when the user is editing steps, which is the main return-engagement signal. See "Critical Implementation Details" below; ships in Phase 1.
- **The `dashboard` URL keeps existing.** Per the S-01 pattern (`Route::get('dashboard', [VenturesController::class, 'create'])->name('dashboard')` AND `Route::get('ventures/create', ...)->name('ventures.create')` are two routes with the same action), S-04 mirrors that: one `Route::get('dashboard', ...)->name('dashboard')` AND one `Route::get('ventures', ...)->name('ventures.index')`. Two URLs, one action, two route names. `route('dashboard')` callers in F-01 auth controllers and the nav link work without an edit; tests/views written for "the list" use `ventures.index`.
- **No `Route::scopeBindings()` is needed.** The destroy endpoint takes only `{venture}` (no nested step), so the doubly-scoped pattern S-02 needs doesn't apply here; we resolve via `$request->user()->ventures()->findOrFail($venture)` exactly like the existing `show`.
- **The `ai_call_counters` table has its own NFR-driven retention story (orthogonal to delete).** Per the F-02 surface in `docs/reference/contract-surfaces.md`, the counter is `owner_id`-scoped. Deleting a venture does NOT roll any counter back: the AI calls really happened against the user's 24h budget regardless of whether the resulting venture survives. No code path in S-04 touches the counter; flagged here so reviewers don't expect refund logic.

## What We're NOT Doing

- **No total-cost cell on the list row.** FR-019 (cost on the list) lands in S-06; the column placeholder/HTML slot is NOT pre-built — S-06 owns the layout decision.
- **No deadline marker on the list row.** FR-021 (imminent/overdue marker on the list) lands in S-05; no slot reserved.
- **No description excerpt on the list row.** Description is optional in v1 and many ventures will have none; rendering an empty text block on most rows is visual noise. Description is shown on the detail view (already, by S-01).
- **No pagination.** PRD §Non-Goals: "No cross-venture dashboard or progress roll-up" is the strongest signal that v1 is built for small N. Solo users on a v1 MVP will have a handful of ventures; pagination is a v2 concern when the list reaches ~50+ rows.
- **No sort/filter/active-vs-completed UI.** Explicit FR-005 Socrates resolution: _"flat list is v1; filter/sort/dashboard are v2 once usage volume justifies them."_
- **No delete button on the venture detail view.** Delete only lives on the list row in this slice. The detail view as a destructive-action surface is ergonomically nice but not necessary, and adding it would double the test matrix without a PRD ref. v2 follow-up.
- **No "type the title" or modal confirmation for delete.** Native `confirm()` matches S-02's step-delete pattern and meets NFR("explicit second user action") cheaply; PRD §Non-Goals locks out undo. A heavier guard for the higher-blast-radius venture delete is a v2 ergonomic enhancement.
- **No soft-delete / archive semantics.** Hard delete with cascade is the v1 model; FR-007 Socrates resolution explicitly defers archive to v2.
- **No undo.** PRD §Guardrails accepts the confirm dialog as the second-gesture requirement; undo is v2.
- **No expense / step counter on the row beyond `X of Y steps completed`.** Cost and deadline counters are S-05/S-06 surfaces.
- **No new authorization layer (Policy classes).** Ownership is enforced by going through the user relationship, same as S-01/S-02. Policies graduate in v3+ per `docs/reference/contract-surfaces.md#v3-co-editing-multi-user-write-access`.
- **No JS island.** The list is read-only; delete is a form POST + native confirm. No fetch handler, no DOM diffing, no Vite app.js edits.
- **No model fillable / migration / factory changes.** S-01's `VentureFactory` produces what we need (`owner_id`, `title`, `description`); cascade is already wired.

## Implementation Approach

Two phases, each ending in a verifiable gate.

1. **Backend + view + nav rewire.** Add `VenturesController::index` (load + render with eager-aggregated step counts, sorted by `updated_at DESC`) and `VenturesController::destroy` (resolve via the user relationship, `->delete()`, redirect). Reassign the `dashboard` route name to `index` and add `ventures.index` + `ventures.destroy` route names (mirrors the S-01 dashboard-aliasing pattern). Add `protected $touches = ['venture'];` to `Step` so the sort actually bubbles step-edited ventures. Land `resources/views/ventures/index.blade.php` with the empty state, the "+ New venture" CTA, and the row template (title link + progress string + Delete confirm-form). Manual walkthrough: log in → land on list → create a new venture → see it at the top → edit a step → return to list → see it bubble to the top.
2. **Test matrix + cross-slice handoff.** 4 feature tests: `ListVenturesTest` (empty-state renders the CTA; user A sees only their own ventures), `DeleteVentureTest` (happy path drops venture + its step rows via cascade; user B gets 404 on user A's venture-destroy URL). Update `docs/reference/contract-surfaces.md` — add "Venture list + destroy surface (S-04)" section, update the "Authentication surface (F-01)" routes line to reflect the new `dashboard` target, edit the existing "Venture surface (S-01)" `dashboard` paragraph to record the reassignment, and flip the "Out of scope for S-01 → S-04" item to a back-reference. Roadmap S-04 row → `done`. `change.md` → `implemented`.

## Critical Implementation Details

- **`Step::$touches = ['venture']` is load-bearing for the sort.** Without it, editing a step (S-02's edit / delete / toggle-completion / create paths) does NOT bump the parent venture's `updated_at`. The `ORDER BY updated_at DESC` sort on the list would then surface a stale order — a venture the user is actively editing the steps of would stay buried under a never-touched venture they created later. Eloquent's `$touches` is the one-line fix: declaring it on `Step` means every save / delete on a Step also issues `$venture->touch()`. Verified Laravel 13.x behaviour: `$touches` fires on save and delete, not on read, and not on toggleCompletion's `$step->save()` path? Actually yes — `save()` triggers `$touches`, regardless of which attribute changed, so the toggle endpoint (which calls `save()` after assigning `is_completed`) bubbles correctly. Ships in Phase 1, item 2.
- **Cascade delete is a single DB statement; do NOT loop.** `$ventureModel->delete()` triggers the schema-level cascade on `steps.venture_id` and removes the venture + its step subtree atomically. Writing `foreach ($venture->steps as $step) { $step->delete(); } $venture->delete();` would be slower AND wrong (model events on Step fire for each, fragility around concurrent edits). Skip the loop.
- **The destroy action returns redirect, not JSON.** There's no JS consumer; the list reloads on success. Mirrors S-02's step-destroy contract (`StepsController::destroy` returns redirect). No `wantsJson()` branching.
- **Empty `description` renders as `—`** on the detail view today (`resources/views/ventures/show.blade.php:19-21`); list rows do NOT show description at all (see §What We're NOT Doing), so no equivalent fallback needed on the list.
- **`completed_steps_count` of NULL** can happen if `withCount` returns no rows for that subquery? No — Laravel returns 0 for `withCount` aggregates when the relation is empty. Safe to render `{{ $venture->completed_steps_count }} of {{ $venture->steps_count }}` directly. The "0 of 0" → "—" substitution is a Blade conditional, not a count-coalescing concern.

## Phase 1: Backend + view + nav rewire

### Overview

Two new controller actions, three route edits, one new view, one model touch (one line), one nav-implicit rewire (no edit needed — nav already points at `route('dashboard')`). No existing tests should break. The visible behaviour change: `/dashboard` now renders the list, not the create form.

### Changes Required:

#### 1. `Step::$touches = ['venture']`

**File**: `app/Models/Step.php`

**Intent**: Make step writes bubble the parent venture's `updated_at` so the list sort surfaces the actually-recently-touched venture, not just the venture-row-edited venture (which is rare in v1). Without this, the chosen `ORDER BY updated_at DESC` is a lie for any venture whose only recent activity is step edits.

**Contract**: Add `protected $touches = ['venture'];` as a class-level property on `Step`. The `venture` relationship already exists on the model (S-01 declared `belongsTo(Venture::class)` via the inverse of `Venture::steps()`). No other model edits.

#### 2. `VenturesController::index`

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: List the authenticated user's ventures with the per-row aggregates the list view needs (total steps, completed steps), sorted `updated_at DESC` so the most-recently-touched venture sits at the top.

**Contract**: New public method `index(Request $request): View`. Body: resolve `$ventures = $request->user()->ventures()->withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true)])->orderByDesc('updated_at')->get();` and return `view('ventures.index', ['ventures' => $ventures])`. No pagination. No conditional. The relationship-scoped access path is what enforces ownership.

#### 3. `VenturesController::destroy`

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: Drop one venture (and its step subtree via schema cascade) on POST `DELETE /ventures/{n}`. Ownership is enforced by resolving through the user relationship — a foreign venture id resolves to no row and `findOrFail` returns 404 (the F-01 enforcement-checklist item 4 shape).

**Contract**: New public method `destroy(Request $request, int $venture): RedirectResponse`. Body: `$request->user()->ventures()->findOrFail($venture)->delete();` then `return redirect()->route('ventures.index');` (NOT `route('dashboard')` — semantic clarity; both resolve to the same action, but tests and downstream code read better against `ventures.index`). No `DB::transaction` wrapper — the schema-level cascade is a single DELETE statement, atomic at the DB layer.

#### 4. Route surface: reassign `dashboard`, add `ventures.index` + `ventures.destroy`

**File**: `routes/web.php`

**Intent**: Point `/dashboard` at the new list (closing the contract-surfaces hand-off S-01 deferred), add a parallel `/ventures` URL for the canonical RESTful name, and mount the destroy endpoint. The two F-01 callsites (`AuthenticatedSessionController::store:35`, `RegisteredUserController::store:48`) and the nav home link continue to work because they all target `route('dashboard')`, which still resolves — just to a different action.

**Contract**: In the `auth` middleware group:
- Replace `Route::get('dashboard', [VenturesController::class, 'create'])->name('dashboard');` with `Route::get('dashboard', [VenturesController::class, 'index'])->name('dashboard');`.
- Insert `Route::get('ventures', [VenturesController::class, 'index'])->name('ventures.index');` immediately after the (existing) `ventures/create` line.
- Insert `Route::delete('ventures/{venture}', [VenturesController::class, 'destroy'])->whereNumber('venture')->name('ventures.destroy');` immediately after the (existing) `ventures/{venture}` GET show route.

Two routes (`/dashboard`, `/ventures`) sharing the same `VenturesController::index` action and carrying two different route names is the same pattern S-01 used for `dashboard` + `ventures.create`. Both URLs are reachable; tests use `route('ventures.index')`.

#### 5. `resources/views/ventures/index.blade.php`

**File**: `resources/views/ventures/index.blade.php`

**Intent**: Render the user's portfolio. Empty state → CTA. Non-empty state → ordered list of rows; each row is a `<li>` with the venture title (anchor to `ventures.show`), a small progress string, and an inline `<form>` POSTing a DELETE to `ventures.destroy` with an `onsubmit="return confirm(...)"` second-gesture guard. A "+ New venture" link sits above the list (or in the empty-state CTA) pointing at `ventures.create`.

**Contract**: Extends `layouts.app`. `@section('header')` renders `<h2>My ventures</h2>`. `@section('content')` renders a `max-w-3xl` container; inside:
- If `$ventures->isEmpty()`: a paragraph ("You haven't created any ventures yet.") + a primary dark `<a href="{{ route('ventures.create') }}">Create your first venture</a>` button (same Tailwind classes as the existing `ventures/create.blade.php` submit button: `bg-gray-800 ... hover:bg-gray-700` etc.).
- Otherwise: a flex row with the title "Your ventures" on the left and a `<a href="{{ route('ventures.create') }}">+ New venture</a>` secondary link on the right; then an `<ul>` (NOT ordered; venture order is by recency, not by sequence) where each `<li>` carries:
  - `<a href="{{ route('ventures.show', $venture) }}">{{ $venture->title }}</a>` styled as a heading-weight link.
  - A small grey `<p>` rendering `{{ $venture->completed_steps_count }} of {{ $venture->steps_count }} steps completed` — or, when `$venture->steps_count === 0`, the literal `—` (Blade `@if` block).
  - A right-aligned `<form method="POST" action="{{ route('ventures.destroy', $venture) }}" onsubmit="return confirm('Delete this venture? This will also remove all its steps.')">` with `@csrf` + `@method('DELETE')` + a `<button type="submit" class="text-xs text-red-600 hover:text-red-800">Delete</button>` — identical pattern to S-02's step-delete form, just with a venture-scoped confirm string.

No data-attributes for JS hooks; no progress bar; no description excerpt. The row is intentionally austere — S-05 will add a deadline pill on the right side and S-06 will add a total-cost cell; the current layout leaves natural slots for both without churn.

### Success Criteria:

#### Automated Verification:

- Existing test suite stays green: `composer run test` exits 0 with the prior 7 Ventures + 17 Steps tests still passing.
- `php artisan route:list --name=ventures` shows `ventures.index`, `ventures.show`, `ventures.store`, `ventures.create`, `ventures.destroy` (and the `dashboard` row now points at `App\Http\Controllers\VenturesController@index`).
- `vendor/bin/pint` reports no formatting drift.

#### Manual Verification:

- Logging in lands on the list at `/dashboard`; the URL `/ventures` also renders the list with the same content.
- Empty state renders the "Create your first venture" CTA; clicking it navigates to `/ventures/create`.
- Creating a new venture redirects to its detail view; navigating back via the nav home link lands on the list with the new venture at the top.
- Editing a step on an existing venture and returning to the list shows that venture bubbled to the top (verifies the `Step::$touches` wiring).
- Clicking Delete on a row fires the native confirm; clicking OK removes the row from the list on reload; the venture's step rows are gone (open the venture's detail URL directly post-delete → 404).

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to Phase 2.

---

## Phase 2: Test matrix + cross-slice handoff

### Overview

Ship the 4 chosen feature tests, write the contract-surfaces section for S-04, and flip the durable status markers (roadmap row + change.md status). No production code edits in this phase.

### Changes Required:

#### 1. `tests/Feature/Ventures/ListVenturesTest.php`

**File**: `tests/Feature/Ventures/ListVenturesTest.php`

**Intent**: Prove FR-005 in two shapes: (a) the empty-state surface points at the create form, and (b) the list shows only the authenticated user's ventures (the F-01 isolation enforcement-checklist item 4 manifestation on this endpoint — viewing a foreign venture row is the leak shape).

**Contract**: PHPUnit test class extending `Tests\TestCase`, uses `RefreshDatabase`. Three methods:
- `test_empty_state_renders_create_cta()` — log in a freshly created user (no ventures), GET `route('ventures.index')`, assert 200, assert sees text "haven't created any ventures yet" (or whatever the empty-state copy ends up as — assert the substring the Blade renders), assert sees `route('ventures.create')` href in the HTML (so the CTA is wired).
- `test_user_sees_only_their_own_ventures()` — create two users (A and B) via `User::factory()`; create one venture for A (`A's venture`) and one for B (`B's venture`) via `$user->ventures()->create([...])`; log in as A; GET `route('ventures.index')`; assert 200; assert response sees `'A\\'s venture'`; assert response does NOT see `'B\\'s venture'`. The pair of assertions is what proves the relationship-scoped `$request->user()->ventures()` access path actually filters by owner.
- `test_step_save_touches_parent_venture_updated_at()` — locks the `Step::$touches = ['venture']` wiring named in Phase 1 item 1 + Critical Implementation Details. Create one user + one venture (any owner); push the venture's `updated_at` back via `$venture->update(['updated_at' => now()->subDay()])` (or `forceFill` + `save` to avoid touching `updated_at` via the update path) and capture the pushed-back timestamp; create a `Step` on the venture via `$venture->steps()->create([... explicit owner_id, source, position ...])` (or via `Step::factory()->for($venture, 'venture')->create()`); assert `$venture->fresh()->updated_at` is greater than the pushed-back timestamp. Without `$touches` the `updated_at` would NOT advance — the list sort would lie silently for any venture whose only recent activity is step edits. The plan's Open Risks section names this exact failure mode; this test is the cheap automated lock.

#### 2. `tests/Feature/Ventures/DeleteVentureTest.php`

**File**: `tests/Feature/Ventures/DeleteVentureTest.php`

**Intent**: Prove FR-007 in two shapes: (a) the happy path drops the venture row AND its step rows via the schema cascade (the cascade is what makes this slice's destroy action a one-liner — if the chain were broken, the venture would delete but its steps would orphan, and a future query that doesn't filter by `whereHas('venture')` would surface ghosts), and (b) user B POSTing a DELETE to user A's venture URL gets 404, not 403, and user A's venture is still in the DB after the call.

**Contract**: PHPUnit test class extending `Tests\TestCase`, uses `RefreshDatabase`. Two methods:
- `test_owner_can_delete_their_own_venture()` — create user A; create TWO ventures for A — a target with 3 steps and a sibling with 2 steps — via `$user->ventures()->create([...])->steps()->createMany([...])` (each step needs `owner_id`, `body`, `position`, `source` — use `Step::factory()->for($venture, 'venture')->create()` if cleaner, but the explicit shape is fine); record `$targetId = $target->id`, `$targetStepIds = $target->steps->pluck('id')->all()`, `$siblingId = $sibling->id`, `$siblingStepIds = $sibling->steps->pluck('id')->all()`; log in as A; `delete(route('ventures.destroy', $target))`; assert 302 redirect to `route('ventures.index')`; assert `Venture::find($targetId)` is `null` and `Step::whereIn('id', $targetStepIds)->count()` is `0` (cascade fired on the target); assert `Venture::find($siblingId)` is still present and `Step::whereIn('id', $siblingStepIds)->count()` is `2` (cascade scope is `venture_id`, not `owner_id` — locks the destroy against a future migration that accidentally over-cascades).
- `test_user_b_gets_404_on_user_a_venture_destroy()` — create users A and B; create a venture for A; log in as B; `delete(route('ventures.destroy', $venture))`; assert 404 (`assertNotFound()`); assert `Venture::find($venture->id)` is still present (no spillover delete).

The "guest 302 to /login on destroy" assertion that the F-01 enforcement-checklist item 4 mentions is NOT separately ship-tested in this slice — the `auth` middleware group is the same boundary already proven for `ventures.show` in `tests/Feature/Ventures/VentureIsolationTest::test_guest_gets_redirect_on_venture_show`. The user explicitly scoped to 4 tests; the boundary is structurally identical. Decision recorded.

#### 3. `docs/reference/contract-surfaces.md` — new "Venture list + destroy surface (S-04)" section, plus edits to the F-01 and S-01 sections to record the reassignment

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Capture the new routes, the access-path realization for the destroy endpoint, the `Step::$touches` coupling (so S-05/S-06 reviewers know why it's there), the sort decision, and the deliberate test-matrix scope (4 tests, no separate guest-on-destroy assertion). Update the S-01 "Out of scope" item that names S-04 as the future home of the list/delete UI to a back-reference. Update the F-01 "Authentication surface" paragraph that names the `dashboard` route to reflect its new target.

**Contract**:
- After the existing "## Step surface (S-02)" section, add `## Venture list + destroy surface (S-04)`. Cover: PRD anchors (FR-005, FR-006-on-list-via-link, FR-007), the new routes (`ventures.index` GET `/ventures`, `ventures.destroy` DELETE `/ventures/{venture}`), the `dashboard` route name reassignment (now points at `index`, URL `/dashboard` still exists for the F-01 intended-redirect contract), the access-path rule realization (`$request->user()->ventures()->findOrFail($venture)->delete()`; cascade-on-delete handles step rows; `ai_call_counters` are NOT rolled back — calls really happened), the sort decision (`updated_at DESC`) and the `Step::$touches = ['venture']` coupling that makes it work (with a "if you remove this, the sort lies" caveat), the cross-slice carve-out (list ships thin; S-05 adds the deadline marker, S-06 adds total cost), the test scope (2 + 2 tests, why guest-on-destroy is not separately asserted), and the "Out of scope for S-04" sub-section (no detail-view delete, no pagination, no sort/filter, no soft-delete).
- In the existing "## Venture surface (S-01)" section, update the paragraph that says _"the `dashboard` route name reassigns; `ventures.create` stays."_ — flip it to past tense ("S-04 has reassigned the `dashboard` route name to `ventures.index`; the `/dashboard` URL persists for the F-01 intended-redirect contract") and add a back-reference to the new S-04 section.
- In the existing "## Authentication surface (F-01)" section, update the **Routes** line to reflect that `dashboard` now lands on the list, not the create form.

#### 4. `context/foundation/roadmap.md` — flip S-04 row to `done`

**File**: `context/foundation/roadmap.md`

**Intent**: Reflect the slice as landed in the index table so subsequent planning runs read accurate state.

**Contract**: In the "At a glance" table, change the `S-04` row's `Status` cell from `proposed` to `done`. In the "Backlog Handoff" table, change the same row's `Ready for /10x-plan` cell from `no` to `done`. No other roadmap edits.

#### 5. `context/changes/list-and-delete-ventures/change.md` — flip to `implemented`

**File**: `context/changes/list-and-delete-ventures/change.md`

**Intent**: Move the change folder into the post-implementation lifecycle. The actual archive happens via `/10x-archive` in a separate step.

**Contract**: Update frontmatter: `status: implemented`, `updated: <today>`. No body edits.

### Success Criteria:

#### Automated Verification:

- Full test suite passes: `composer run test` → 0 failures, 0 errors. The two new test classes (5 new tests) bring the Ventures suite to 12 tests (was 7).
- `composer run test -- --filter=ListVenturesTest` runs the 3 list tests and passes.
- `composer run test -- --filter=DeleteVentureTest` runs the 2 delete tests and passes.
- `vendor/bin/pint` reports no formatting drift on the new test files.

#### Manual Verification:

- `docs/reference/contract-surfaces.md` reads as a coherent narrative: the F-01 → S-01 → S-04 dashboard-reassignment story is internally consistent across the three sections; no contradictory statements remain.
- `context/foundation/roadmap.md` "At a glance" S-04 row reads `done`; the "Backlog Handoff" S-04 row reads `done`.
- `context/changes/list-and-delete-ventures/change.md` frontmatter shows `status: implemented`.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human before considering the slice complete (the close-out + `/10x-archive` step is separate from this plan, per S-01/S-02 cadence).

---

## Testing Strategy

### Unit Tests:

- None. No new pure-logic classes (no services, no enums, no policies). The two new controller actions are integration-shaped (HTTP + DB); feature tests cover them.

### Integration / Feature Tests:

- `ListVenturesTest::test_empty_state_renders_create_cta` — FR-005 empty-state path.
- `ListVenturesTest::test_user_sees_only_their_own_ventures` — FR-005 + F-01 isolation enforcement.
- `ListVenturesTest::test_step_save_touches_parent_venture_updated_at` — locks the `Step::$touches = ['venture']` wiring; without it the chosen `ORDER BY updated_at DESC` sort lies silently.
- `DeleteVentureTest::test_owner_can_delete_their_own_venture` — FR-007 happy path + cascade verification + sibling-venture survival assertion (locks cascade scope to `venture_id`).
- `DeleteVentureTest::test_user_b_gets_404_on_user_a_venture_destroy` — F-01 enforcement checklist item 4 on the new destroy endpoint.

### Manual Testing Steps:

1. Log out + register a new user → assert post-register redirect lands on `/dashboard` rendering the empty-state CTA (proves `route('dashboard')` callsite still works post-reassignment).
2. Click "Create your first venture" → submit valid title/description → land on `/ventures/{n}` (S-01 behaviour unchanged) → click the nav home link → assert the new venture is on the list with `0 of 7 steps completed` (AI happy path) or `—` (AI unavailable path).
3. From the detail view, toggle a step's completion checkbox; navigate back to the list; assert the progress line for that venture now reads `1 of 7 steps completed` AND that venture sits at the top of the list (proves `Step::$touches` wiring + sort).
4. Create a second venture; assert it now sits above the first (newest-touched, since toggling a step on the first happened earlier in the session) — actually wait: creating the second venture is fresh activity on it, AND toggling the first's step also bumped it; whichever was last touched bubbles. Re-verify after a deliberate step-edit on the first.
5. Click Delete on the older venture → confirm in the native dialog → assert the row disappears from the list AND opening the deleted venture's URL directly (paste `/ventures/{old_id}`) returns 404.
6. Open a second browser as a different registered user; assert the list shows zero ventures (the first user's ventures are not visible).

## Performance Considerations

- `withCount(['steps', 'steps as completed_steps_count' => ...])` adds two subqueries per index render. For a v1 portfolio of 1-20 ventures and per-venture step counts of 0-20, this is well under any latency budget (NFR(edit-latency) targets the edit loop, not the list render). If the portfolio grows past ~100, pagination (deferred to v2) becomes the right answer rather than removing the counts.
- `Step::$touches = ['venture']` adds an `UPDATE ventures SET updated_at = NOW() WHERE id = ?` to every step save/delete. One additional indexed-PK update per step write is negligible (~1ms on Postgres, less on SQLite). Documented but not a flag.
- `$venture->delete()` issues one DELETE on the ventures row; Postgres executes the cascade on `steps.venture_id` as part of the same transaction. Single round-trip for the user; no application-level loop.

## Migration Notes

None. No schema change, no data migration. The `Step::$touches = ['venture']` change has no migration effect; it only changes the value of `ventures.updated_at` going forward (existing rows retain whatever value they had).

## References

- F-01 / minimal-auth-and-isolation: `context/changes/minimal-auth-and-isolation/plan.md`
- F-02 / ai-suggestion-service: `context/changes/ai-suggestion-service/plan.md`
- S-01 / create-venture-with-ai-plan: `context/changes/create-venture-with-ai-plan/plan.md`
- S-02 / edit-and-track-steps: `context/changes/edit-and-track-steps/plan.md`
- Contract surfaces (load-bearing names + rules): `docs/reference/contract-surfaces.md`
- Roadmap S-04 row: `context/foundation/roadmap.md:46`
- Step-delete view pattern: `resources/views/ventures/show.blade.php:76-85`
- Dashboard route placeholder (to be reassigned): `routes/web.php:24`
- Two-user-404 test template: `tests/Feature/Ventures/VentureIsolationTest.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend + view + nav rewire

#### Automated

- [x] 1.1 Existing test suite stays green: `composer run test` exits 0 with the prior 7 Ventures + 17 Steps tests still passing
- [x] 1.2 `php artisan route:list --name=ventures` shows `ventures.index`, `ventures.show`, `ventures.store`, `ventures.create`, `ventures.destroy` (and the `dashboard` row now points at `App\Http\Controllers\VenturesController@index`)
- [x] 1.3 `vendor/bin/pint` reports no formatting drift

#### Manual

- [ ] 1.4 Logging in lands on the list at `/dashboard`; the URL `/ventures` also renders the list with the same content
- [ ] 1.5 Empty state renders the "Create your first venture" CTA; clicking it navigates to `/ventures/create`
- [ ] 1.6 Creating a new venture redirects to its detail view; navigating back via the nav home link lands on the list with the new venture at the top
- [ ] 1.7 Editing a step on an existing venture and returning to the list shows that venture bubbled to the top (verifies the `Step::$touches` wiring)
- [ ] 1.8 Clicking Delete on a row fires the native confirm; clicking OK removes the row from the list on reload; the venture's step rows are gone (open the venture's detail URL directly post-delete → 404)

### Phase 2: Test matrix + cross-slice handoff

#### Automated

- [ ] 2.1 Full test suite passes: `composer run test` → 0 failures, 0 errors; Ventures suite now totals 12 tests (was 7)
- [ ] 2.2 `composer run test -- --filter=ListVenturesTest` runs the 3 list tests and passes
- [ ] 2.3 `composer run test -- --filter=DeleteVentureTest` runs the 2 delete tests and passes
- [ ] 2.4 `vendor/bin/pint` reports no formatting drift on the new test files

#### Manual

- [ ] 2.5 `docs/reference/contract-surfaces.md` reads as a coherent narrative — F-01 → S-01 → S-04 dashboard-reassignment story is internally consistent; no contradictions remain
- [ ] 2.6 `context/foundation/roadmap.md` "At a glance" S-04 row reads `done`; "Backlog Handoff" S-04 row reads `done`
- [ ] 2.7 `context/changes/list-and-delete-ventures/change.md` frontmatter shows `status: implemented`
