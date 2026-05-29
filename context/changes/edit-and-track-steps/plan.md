# S-02 / edit-and-track-steps — Implementation Plan

## Overview

Land the four step write-actions (add / edit / delete / toggle-completion) plus the FR-018 progress display on top of S-01's read-only step list, all gated by the same `$request->user()->ventures()` access path the F-01 contract requires. The four actions are nested under `/ventures/{venture}/steps/...`; three are plain server-rendered forms with full-reload redirects; the toggle is the one place a ~20-line vanilla-JS fetch handler upgrades the UX, because it's the high-frequency action and the NFR(edit-latency) "instant" semantics for FR-013 ("toggle completion directly from the list — no confirmation") would feel broken under a full reload per click. Source-immutability — the load-bearing invariant the FR-008 primary metric depends on — is enforced by dropping `source` from `Step::$fillable` and by a body-only `EditStepRequest`, defense in depth.

## Current State Analysis

- **S-01 (`create-venture-with-ai-plan`) is landed and the venture surface is the foundation S-02 extends.** `VenturesController` (`app/Http/Controllers/VenturesController.php`) implements `create` / `store` / `show` through `$request->user()->ventures()->...`; `ventures.show` renders a read-only `<ol>` of step bodies with the AI-unavailable flash notice already in place. The `steps` table (`database/migrations/2026_05_28_210001_create_steps_table.php`) carries `body string(200)`, `is_completed boolean default false`, `source string`, `position unsignedInteger default 0`, plus the `(venture_id, position)` index, and cascades on `owner_id` and `venture_id` deletion.
- **`Step::$fillable` (`app/Models/Step.php:11`) currently lists `['body', 'source', 'position']`.** `source` being mass-assignable is the surface S-02 hardens — any future controller forwarding `$request->all()` to `Step::update()` could otherwise rewrite the FR-008 metric snapshot.
- **`is_completed` is NOT in `$fillable` today** — toggle therefore cannot use `->update(['is_completed' => ...])` directly; it sets the attribute on the model instance and calls `save()`. The toggle endpoint is the only writer of this field.
- **`StepSource` (`app/Enums\StepSource.php`) is a backed enum with three cases: `AiInitial`, `AiExtension`, `Manual`.** S-02 writes only `Manual` for new steps; `AiExtension` remains reserved for S-03.
- **`StepFactory` (`database/factories/StepFactory.php`) defines `'source' => StepSource::Manual` via the factory definition.** Laravel factories use `Model::unguarded(...)` during model instantiation so they bypass `$fillable` entirely — removing `source` from `$fillable` does NOT break the factory; verified against Laravel 13.8 `Factory::makeInstance`.
- **The existing `VenturesController::store` uses `->make([...])` then `->forceFill([...])` for owner_id (`app/Http/Controllers/VenturesController.php:46-50`).** Since S-02 drops `source` from `$fillable`, the `make()` call there will silently drop `source` — needs the same forceFill treatment that `owner_id` already gets.
- **No JS framework on the page.** `resources/js/app.js` is essentially empty; Vite is wired; `layouts/app.blade.php` already loads `resources/css/app.css` and `resources/js/app.js` via `@vite`. The CSRF token is exposed at `<meta name="csrf-token">` in the layout (`resources/views/layouts/app.blade.php:6`) — the toggle JS can read it.
- **`auth/register.blade.php` and `ventures/create.blade.php` are the form-styling references.** Same `bg-gray-800` dark-button pattern, same `mt-1 w-full border-gray-300 rounded-md shadow-sm` input pattern.
- **F-01 access-path discipline is set in stone.** Every authenticated controller MUST resolve the venture (and now the step) through `$request->user()->ventures()->findOrFail(...)->steps()->findOrFail(...)` per `context/foundation/lessons.md:14` and `docs/reference/contract-surfaces.md` rule 2. The two-user-404 test pattern from `tests/Feature/Ventures/VentureIsolationTest.php` is the template S-02's isolation tests follow.

## Desired End State

A logged-in user on `/ventures/{v}` sees the venture title, description, a `"X of Y completed"` progress line, and the step list. Each step row has a checkbox (toggle), the body text, an Edit link, and a Delete button. Clicking the checkbox flips `is_completed` and updates the progress text without a reload (vanilla fetch); the change persists. Clicking Edit navigates to a single-textarea page (`/ventures/{v}/steps/{s}/edit`) that PATCHes back. Clicking Delete fires a native confirm dialog and DELETEs on confirm. A "+ Add step" link (or — on an empty list — a CTA) navigates to a single-textarea page (`/ventures/{v}/steps/create`) that POSTs a manual step appended to the list and redirects back. A second user visiting any of these URLs gets 404, never 403; guests redirect to `/login`. The FR-008 metric snapshot is intact across edits: any attempt to set `source` through the edit endpoint is rejected by both the FormRequest whitelist AND the model's `$fillable` (defense in depth). The F-01 enforcement checklist applies to each of the four new endpoints and is proven by ~9 feature tests.

### Key Discoveries:

- **Laravel factories bypass `$fillable`** via `Model::unguarded(...)` in `Factory::makeInstance`. So `StepFactory` keeps working when `source` leaves `$fillable`; no factory edit is needed for that reason. (`database/factories/StepFactory.php:18-30` does NOT depend on fillable.)
- **`->update(['key' => value])` silently no-ops on non-fillable keys.** This is what protects edits from setting `source`, and it also means the toggle action can't use `->update(['is_completed' => ...])` directly — it has to set the attribute and `save()`. Documented explicitly in Phase 2's controller contract.
- **The CSRF meta is already in the layout** at `resources/views/layouts/app.blade.php:6` — the toggle JS reads it via `document.querySelector('meta[name=csrf-token]')`, no additional plumbing needed.
- **`<noscript>` fallback Save button** is the cleanest way to keep the toggle functional with JS off — it's hidden when JS is on and visible when it isn't, no Tailwind utility classes get stripped.
- **The 200-char `steps.body` cap is coupled to F-02's `config('ai.step_suggestion.max_step_length')`** per `docs/reference/contract-surfaces.md` (F-02 surface, "Length cap coupling"). S-02's `CreateStepRequest` and `EditStepRequest` enforce the same 200 cap for the manual path so the schema column never throws on insert.
- **The FR-008 "kept includes edited" metric rule** means edits MUST preserve `source` even when the body changes. Defense in depth (fillable + FormRequest whitelist) is how this is enforced — the alternative ("just remember to not forward source in the controller") relies on every future contributor remembering, which is a future-bug shape.
- **Position uniqueness** is not enforced at the DB level (the existing `(venture_id, position)` index is non-unique). Append-only adds compute `max(position) + 1` server-side; with no reorder action in scope, gaps from deletions are fine and ordering stays stable.

## What We're NOT Doing

- **No AI extension trigger.** FR-009 / `StepSource::AiExtension` is S-03's job. The "add step" surface in this slice is FR-010 ONLY — a single-textarea manual add. Confirmed in the planning conversation after the user reconsidered an AI-shaped UX answer.
- **No drag-and-drop or other reorder action.** PRD §Non-Goals doesn't list reorder, but it's also nowhere in S-02's PRD refs (FR-010 / FR-011 / FR-012 / FR-013 / FR-018). Append-only adds, edits-preserve-position. Reorder is a future v2.
- **No "clear completed" or bulk-action surface.** PRD says nothing about bulk actions; not in S-02 refs. Skip.
- **No deadline field on steps.** S-05.
- **No expenses.** S-06.
- **No venture list / venture delete UI.** S-04.
- **No richer step state (in-progress / blocked / done).** PRD FR-013 explicitly settles on binary; the Socrates resolution flagged "richer states are v2."
- **No "regenerate AI" action.** PRD §Non-Goals; out of scope for every slice.
- **No undo for delete.** PRD §Guardrails says destructive actions need "an explicit second user action" — the confirm dialog satisfies that; undo is v2.
- **No new authorization layer (Policy classes).** Ownership is enforced by going through the user relationship, same as S-01. Policies graduate in v3+ per `docs/reference/contract-surfaces.md#v3-co-editing-multi-user-write-access`.
- **No completed-vs-total progress on the venture list.** S-02 only renders progress on the venture detail view (the venture list itself doesn't exist yet — S-04). Cross-venture progress roll-up is explicitly PRD §Non-Goals.

## Implementation Approach

Three phases, each ending in a verifiable gate.

1. **Defensive model tweak + nested route surface + skeleton controller + FormRequests.** Drop `'source'` from `Step::$fillable`. Update `VenturesController::store` so the AI-initial seed loop moves `source` from `make()` into the existing `forceFill()` block (otherwise removing it from fillable silently drops it from the new step). Add `StepsController` with six method stubs (returning `abort(404)` placeholder) so `route:list` works and routes are mounted. Add `CreateStepRequest` + `EditStepRequest` (body-only rules). Register the six nested routes under the existing `auth` middleware group. No user-facing change yet; all S-01 tests stay green.
2. **Wire the five actions + rework the show view + ship the toggle JS island.** Implement all six controller methods. `ventures.show` becomes the interactive surface: each step row gets a checkbox-form (toggle), an Edit link, a Delete confirm-form. Progress text renders above the step list. The empty state becomes a CTA ("Add your first step"). Two new views land: `steps.create` and `steps.edit`. The JS island in `resources/js/app.js` upgrades the toggle to fetch + DOM update; graceful-degrades via `<noscript>` Save button. Manual browser walkthrough verifies all four actions.
3. **Essentials test matrix (~9 feature tests) + cross-slice handoff.** One test class per action (`AddStepTest`, `EditStepTest`, `DeleteStepTest`, `ToggleStepTest`) covering happy-path + two-user-404 isolation + the source-immutability invariant; one `ShowVentureProgressTest` for FR-018 rendering. `docs/reference/contract-surfaces.md` gets a new "Step surface (S-02)" section. `context/foundation/roadmap.md` S-02 row flips to `done`. `change.md` flips to `implemented`.

## Critical Implementation Details

- **`->update(['is_completed' => ...])` will silently no-op.** Because `is_completed` is not in `Step::$fillable` and S-02 deliberately does not add it (toggle is the only writer, and it goes through a dedicated endpoint that needs no mass-assignment), the toggle action MUST set the attribute directly (`$step->is_completed = !$step->is_completed; $step->save();`) — not via `update()`. Same pattern applies if any future code path needs to write `source` (force the assignment, don't go through fillable).
- **The AI-initial seed loop in `VenturesController::store` breaks when `source` leaves `$fillable`.** The current code passes `'source' => StepSource::AiInitial` inside `->make([...])`, which uses fillable. Phase 1 must move that line into the existing `->forceFill([...])` block alongside `'owner_id'`. Forgetting this would make every newly created venture's seven AI-initial steps persist with NULL `source`, breaking the FR-008 metric snapshot for all new ventures from the moment Phase 1 ships.
- **Toggle controller returns two response shapes.** When `$request->wantsJson()` (which Laravel evaluates from `Accept: application/json`), return `{is_completed, completed, total}` for the JS island. Otherwise (form-only / no-JS path), redirect back to `ventures.show`. The JS handler always sends `Accept: application/json`; the `<noscript>` form fallback never does, so the redirect path serves it.
- **Native `confirm()` for delete is the v1 second-gesture.** Meets NFR("Destructive actions … cannot complete from a single user gesture alone") cheaply; can be swapped for a Tailwind modal later without changing the route shape (the form still does a DELETE).

## Phase 1: Defensive model tweak + nested route surface + skeleton controller + FormRequests

### Overview

Lock down the source-immutability invariant at the model layer, plumb the six new routes through to a stub controller, and land the two FormRequests. No view changes, no user-visible behavior change. Existing tests must stay green; the S-01 controller is patched in lockstep with the model change so the AI seed loop still writes `ai_initial`.

### Changes Required:

#### 1. `Step::$fillable` — drop `'source'`

**File**: `app/Models/Step.php`

**Intent**: Make `source` non-mass-assignable so any future controller using `->update($request->all())` or `->fill($input)` cannot rewrite the FR-008 metric snapshot. Defense in depth alongside the `EditStepRequest` whitelist that lands below.

**Contract**: Replace the existing `#[Fillable(['body', 'source', 'position'])]` attribute on the class with `#[Fillable(['body', 'position'])]`. The `is_completed` field stays out of `$fillable` (it was already absent); the toggle endpoint writes it via direct attribute assignment, not mass-assignment.

#### 2. `VenturesController::store` — move `source` into the existing `forceFill` block

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: Patch the AI-initial seed loop in lockstep with the model change so the new `ai_initial` steps still persist their source. Without this edit, the `make([...'source' => StepSource::AiInitial...])` line silently drops `source` (it's no longer fillable), and every newly created venture seeds steps with NULL `source` — a silent metric-corruption bug.

**Contract**: Inside the existing `foreach ($steps as $i => $body)` loop (lines 45-51), move `'source' => StepSource::AiInitial` from the `make([...])` array into the `->forceFill([...])` array alongside `'owner_id' => $user->id`. The `make([...])` array keeps only `'body'` and `'position'`. No other behaviour changes.

#### 3. `CreateStepRequest` FormRequest

**File**: `app/Http/Requests/Steps/CreateStepRequest.php`

**Intent**: Centralize FR-010 validation for the manual-add path: required body, bounded by the same 200-char cap as the `steps.body` column and as F-02's `max_step_length`. Authorization is the `auth` middleware boundary, so `authorize()` returns true.

**Contract**: `extends FormRequest`. `authorize(): bool { return true; }`. `rules(): array` returns `['body' => ['required', 'string', 'min:1', 'max:200']]`. No `prepareForValidation` needed (no nullable coercion required for a required field).

#### 4. `EditStepRequest` FormRequest

**File**: `app/Http/Requests/Steps/EditStepRequest.php`

**Intent**: The body-only whitelist that is the FormRequest layer of source-immutability defense. The rules array deliberately omits `source`, `is_completed`, `position`, `venture_id`, `owner_id` — so even when the controller does `->update($request->validated())`, only `body` reaches the model.

**Contract**: Same shape as `CreateStepRequest`: `extends FormRequest`, `authorize(): bool { return true; }`, `rules()` returns `['body' => ['required', 'string', 'min:1', 'max:200']]`. The two requests are duplicates today because the rule sets are identical; keep them separate so future divergence (e.g. an edit-only audit field) doesn't churn the create-side rules.

#### 5. `StepsController` skeleton

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: Plumb all six method signatures so the routes resolve. Each method body is a placeholder `abort(404)` in Phase 1; real bodies arrive in Phase 2. The skeleton is what lets `php artisan route:list` succeed and the route binding to work before any view exists.

**Contract**: `class StepsController extends Controller`. Six public methods, each accepting the route parameters as integers (no model binding) and the `Request` / `CreateStepRequest` / `EditStepRequest` as the first parameter:
- `create(Request $request, int $venture): View`
- `store(CreateStepRequest $request, int $venture): RedirectResponse`
- `edit(Request $request, int $venture, int $step): View`
- `update(EditStepRequest $request, int $venture, int $step): RedirectResponse`
- `destroy(Request $request, int $venture, int $step): RedirectResponse`
- `toggleCompletion(Request $request, int $venture, int $step): JsonResponse|RedirectResponse`

Each body in Phase 1 is `abort(404);` — placeholder only.

#### 6. Six new routes registered under `auth` middleware

**File**: `routes/web.php`

**Intent**: Mount the step surface. All six routes carry `whereNumber()` constraints on `venture` (and `step` where applicable) so route resolution is integer-only and the `auth` middleware boundary handles authentication. Naming follows `steps.*` for namespace-cleanliness; no collision with the existing `ventures.*` names.

**Contract**: Inside the `Route::middleware('auth')->group(...)` block in `routes/web.php`, add:
- `Route::get('ventures/{venture}/steps/create', [StepsController::class, 'create'])->whereNumber('venture')->name('steps.create');`
- `Route::post('ventures/{venture}/steps', [StepsController::class, 'store'])->whereNumber('venture')->name('steps.store');`
- `Route::get('ventures/{venture}/steps/{step}/edit', [StepsController::class, 'edit'])->whereNumber('venture')->whereNumber('step')->name('steps.edit');`
- `Route::patch('ventures/{venture}/steps/{step}', [StepsController::class, 'update'])->whereNumber('venture')->whereNumber('step')->name('steps.update');`
- `Route::delete('ventures/{venture}/steps/{step}', [StepsController::class, 'destroy'])->whereNumber('venture')->whereNumber('step')->name('steps.destroy');`
- `Route::patch('ventures/{venture}/steps/{step}/completion', [StepsController::class, 'toggleCompletion'])->whereNumber('venture')->whereNumber('step')->name('steps.completion');`

Import `use App\Http\Controllers\StepsController;` at the top of the file.

### Success Criteria:

#### Automated Verification:

- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test` (especially `CreateVentureTest` — the AI seed loop tweak must not regress)
- `php artisan route:list` shows the six new `steps.*` routes under the `auth` middleware

#### Manual Verification:

- Visiting `/ventures/{v}/steps/create` while authenticated returns a placeholder 404 (skeleton stub fired)
- Visiting any of the six routes while unauthenticated redirects to `/login` (auth middleware boundary intact)
- `php artisan tinker` quick check: `\App\Models\Step::factory()->create()` still works (factory bypasses fillable)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the route surface + skeleton work and existing tests are green before proceeding to Phase 2.

---

## Phase 2: Wire the five actions + rework the show view + ship the toggle JS island

### Overview

Implement all six controller methods, build the two new views (`steps.create`, `steps.edit`), rework `ventures.show` into the interactive surface, and ship the vanilla-JS toggle handler. End state: a user can add / edit / delete / toggle steps and see progress on the venture detail view.

### Changes Required:

#### 1. `StepsController::create` and `StepsController::store`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: `create` renders the add-step form bound to the venture. `store` persists ONE manual step, computing position as `max(position) + 1` (or 0 if the list is empty), with `source = Manual` and `owner_id = $user->id` set via forceFill (defense in depth — never trust mass-assignment for these invariants).

**Contract**:
- `create` body: `$ventureModel = $request->user()->ventures()->findOrFail($venture); return view('steps.create', ['venture' => $ventureModel]);`
- `store` body: resolve venture via `$request->user()->ventures()->findOrFail($venture)`; compute `$nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;`; then `$ventureModel->steps()->make(['body' => $request->validated('body'), 'position' => $nextPosition])->forceFill(['owner_id' => $request->user()->id, 'source' => StepSource::Manual])->save();`; return `redirect()->route('ventures.show', $ventureModel);`.

#### 2. `StepsController::edit` and `StepsController::update`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: `edit` renders the edit form pre-populated with the step's current body. `update` writes ONLY the body — the EditStepRequest's whitelist + the model's `$fillable` ensure `source` and any other field cannot change. After save, redirect to `ventures.show`.

**Contract**:
- `edit` body: resolve venture then step via the chained relationship; return `view('steps.edit', ['venture' => $ventureModel, 'step' => $stepModel]);`.
- `update` body: resolve venture then step via the chained relationship; `$stepModel->update($request->validated());` (validated() returns `['body' => '...']` only); return `redirect()->route('ventures.show', $ventureModel);`.

#### 3. `StepsController::destroy`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: Delete a single step. Cascade is already in the schema for the parent venture; this is a direct deletion of a child row. Confirmation is the frontend's job (native `confirm()` in the show view).

**Contract**: Resolve venture then step via the chained relationship; `$stepModel->delete();`; return `redirect()->route('ventures.show', $ventureModel);`.

#### 4. `StepsController::toggleCompletion`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: Flip `is_completed`. Two response shapes: JSON for AJAX (the JS island wants the new bool + the new progress counts so it can update the DOM); redirect for the `<noscript>` fallback. Because `is_completed` is not in `$fillable`, set the attribute directly and `save()` — do NOT call `->update(['is_completed' => …])` (it would silently no-op).

**Contract**: Resolve venture then step via the chained relationship. Then:
```php
$stepModel->is_completed = ! $stepModel->is_completed;
$stepModel->save();

if ($request->wantsJson()) {
    return response()->json([
        'is_completed' => $stepModel->is_completed,
        'completed' => $ventureModel->steps()->where('is_completed', true)->count(),
        'total' => $ventureModel->steps()->count(),
    ]);
}

return redirect()->route('ventures.show', $ventureModel);
```

#### 5. `steps.create` view

**File**: `resources/views/steps/create.blade.php`

**Intent**: Single-textarea form to add one step. Same styling primitives as `ventures/create.blade.php` (dark `bg-gray-800` button, `mt-1 w-full border-gray-300 rounded-md shadow-sm` input). Form posts to `route('steps.store', $venture)`. Cancel link returns to `ventures.show`.

**Contract**: Extends `layouts.app`. `@section('header')` shows `Add step to: {{ $venture->title }}`. Form with `@csrf`, a labelled textarea `name="body" maxlength="200" required autofocus` (rows=3) with `{{ old('body') }}` value, an `@error('body')` block, an Add submit button, and a Cancel link to `ventures.show`.

#### 6. `steps.edit` view

**File**: `resources/views/steps/edit.blade.php`

**Intent**: Same shape as `steps.create` but pre-populated with the step's body and POSTing as PATCH. Cancel returns to `ventures.show`.

**Contract**: Extends `layouts.app`. `@section('header')` shows `Edit step in: {{ $venture->title }}`. Form with `@csrf`, `@method('PATCH')`, posting to `route('steps.update', [$venture, $step])`, textarea `name="body" maxlength="200" required autofocus` with `{{ old('body', $step->body) }}` value, `@error('body')` block, Save button, Cancel link to `ventures.show`.

#### 7. `ventures.show` rework

**File**: `resources/views/ventures/show.blade.php`

**Intent**: Convert the existing read-only step list into the interactive surface. Each row gains: a checkbox-form (the toggle), Edit link, Delete confirm-form. Above the list, render the FR-018 progress text. Empty state becomes a CTA inviting the first manual add (this is the surface a user lands on when AI failed at creation — they need an action, not a dead-end notice). Preserve the existing `session('ai_unavailable')` flash.

**Contract**: Extends `layouts.app`. Replace the existing Steps panel (`resources/views/ventures/show.blade.php:25-39`) with:
- A header row: `<h3>Steps</h3>` and `<p data-progress-text>{{ $completed }} of {{ $total }} completed</p>` (the controller's `show` action passes these counts into the view).
- If `$venture->steps->isEmpty()`: an empty-state line `"No steps yet."` plus a primary CTA link `"+ Add your first step"` to `route('steps.create', $venture)`.
- Otherwise: an `<ol>` of step rows. Each row contains:
  - A toggle form: `<form method="POST" action="{{ route('steps.completion', [$venture, $step]) }}" data-toggle-completion>` with `@csrf @method('PATCH')`, a checkbox input (`name="is_completed"`, `{{ $step->is_completed ? 'checked' : '' }}`), the body wrapped in a label (with `line-through text-gray-400` Tailwind classes when `$step->is_completed`), and a `<noscript><button type="submit">Save</button></noscript>` fallback.
  - An Edit link: `<a href="{{ route('steps.edit', [$venture, $step]) }}">Edit</a>`.
  - A Delete form: `<form method="POST" action="{{ route('steps.destroy', [$venture, $step]) }}" onsubmit="return confirm('Delete this step?')">@csrf @method('DELETE')<button type="submit">Delete</button></form>`.
- Below the list: `<a href="{{ route('steps.create', $venture) }}">+ Add step</a>`.

The `VenturesController::show` action also needs to pass `$completed` and `$total` to the view alongside the existing `$venture`.

#### 8. `VenturesController::show` — pass progress counts

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: The show view needs `$completed` and `$total` for the progress text. Compute them off the eager-loaded steps collection — no extra query needed since `with('steps')` is already in place.

**Contract**: In `show()`, after `$model = $request->user()->ventures()->with('steps')->findOrFail($venture);`, compute `$completed = $model->steps->where('is_completed', true)->count();` and `$total = $model->steps->count();`. Pass them in the view payload: `return view('ventures.show', ['venture' => $model, 'completed' => $completed, 'total' => $total]);`.

#### 9. Toggle JS island

**File**: `resources/js/app.js`

**Intent**: Find every toggle-completion form on the page; intercept checkbox change; PATCH via fetch with the CSRF token; update the checkbox visual state, the body's line-through styling, and the progress text. Graceful-degrade: if fetch fails or returns non-OK, revert the checkbox and let the form submit normally (the user's intent persists either way).

**Contract**: A single bound block running on `DOMContentLoaded`. For each `form[data-toggle-completion]`, attach a `change` listener on its checkbox that calls `event.preventDefault()`, fetches the form's `action` URL with `method: 'PATCH'`, headers `{Accept: 'application/json', 'X-CSRF-TOKEN': <meta csrf-token>, 'X-Requested-With': 'XMLHttpRequest'}`, and on success applies the returned `{is_completed, completed, total}` to the DOM:
- Checkbox `.checked = data.is_completed`
- The body `<span>` toggles `line-through text-gray-400` classes based on `data.is_completed`
- The single `[data-progress-text]` element receives `${data.completed} of ${data.total} completed`

On any failure (`!response.ok`, fetch throws, JSON parse fails): revert the checkbox, then call `form.submit()` to fall through to the server redirect path.

#### 10. Vite asset rebuild

**File**: (build artifact — `public/build/manifest.json` etc.)

**Intent**: The new JS in `resources/js/app.js` needs to be compiled for production. The `composer setup` script already runs `npm run build`; Phase 2 just runs it locally before manual verification.

**Contract**: `npm run build` succeeds; the production `public/build/manifest.json` is regenerated. (No source-file change; this is a build-step note.)

### Success Criteria:

#### Automated Verification:

- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test`
- `npm run build` completes without errors
- `php artisan route:list` still shows the six `steps.*` routes (no accidental removal)

#### Manual Verification:

- On a venture with 7 AI-initial steps: toggle one step → checkbox flips, body gets line-through styling, "0 of 7 completed" updates to "1 of 7 completed" with no page reload
- On the same venture: click Edit on a step → land on edit page → change the body → Save → land back on show view with the body updated and source preserved (verified by tinker `\App\Models\Step::find($id)->source` returning `StepSource::AiInitial`)
- Click Delete on a step → confirm dialog → confirm → step removed from list, total drops by 1
- Click "+ Add step" → land on create page → enter "Manual step body" → Add → land back on show view with the new step at the END of the list, source = `Manual`
- On a venture with 0 steps (simulate by creating a venture under a mocked failing `AiStepSuggester`): the empty-state CTA renders and "+ Add your first step" works
- Disable JS in the browser → toggle a step's checkbox → click the now-visible Save button → page reloads → step's completion persists (graceful fallback works)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the four browser interactions above all work — both with JS on and with JS off — before proceeding to Phase 3.

---

## Phase 3: Essentials test matrix + cross-slice handoff

### Overview

Ship the ~9 feature tests proving the four actions land correctly and inherit the F-01 isolation discipline; document the Step surface for downstream slices; flip the roadmap and `change.md`.

### Changes Required:

#### 1. `AddStepTest`

**File**: `tests/Feature/Steps/AddStepTest.php`

**Intent**: Cover the FR-010 happy path + the two-user-404 isolation that the F-01 S-01 enforcement checklist requires for every new per-user route.

**Contract**: Two `#[Test]` methods.
- `manual_step_persists_at_end_of_list_with_source_manual` — create user A and a venture, seed the venture with 3 steps via `Step::factory()` at positions 0/1/2, authenticate as A, POST to `route('steps.store', $venture)` with body "Manual addition", assert the new step has `body = 'Manual addition'`, `source = StepSource::Manual`, `position = 3`, `owner_id = A->id`, `is_completed = false`; assert redirect to `ventures.show`.
- `user_b_gets_404_adding_step_to_user_a_venture` — create users A and B, create venture as A, authenticate as B, POST to `route('steps.store', $A_venture)` with body "evil", assert 404 response; assert `Step::count()` did not increase.

#### 2. `EditStepTest`

**File**: `tests/Feature/Steps/EditStepTest.php`

**Intent**: Cover FR-011 + the source-immutability invariant (the load-bearing rule the FR-008 metric depends on) + two-user-404 isolation.

**Contract**: Three `#[Test]` methods.
- `body_updates_successfully` — create user A, venture, one `ai_initial` step with body "Original" via `Step::factory()`; authenticate as A, PATCH `route('steps.update', [$venture, $step])` with body "Updated"; assert step's `body = 'Updated'`, redirect to `ventures.show`, `source` is still `StepSource::AiInitial`.
- `source_stays_immutable_even_when_input_includes_source_field` — create A, venture, one `ai_initial` step; PATCH the update endpoint with `{body: 'New body', source: 'manual'}`; assert step's `body = 'New body'` AND `source = StepSource::AiInitial` (the input `source` field was rejected by the FormRequest whitelist).
- `user_b_gets_404_editing_user_a_step` — create A and B, A's venture and step; authenticate as B; PATCH the update endpoint with body "evil"; assert 404; assert A's step body is unchanged.

#### 3. `DeleteStepTest`

**File**: `tests/Feature/Steps/DeleteStepTest.php`

**Intent**: Cover FR-012 happy path + isolation. The "with confirmation" requirement is a frontend concern (native `confirm()`); the backend just deletes — that's what the test asserts.

**Contract**: Two `#[Test]` methods.
- `step_is_removed` — create A, venture, one step; authenticate as A; DELETE `route('steps.destroy', [$venture, $step])`; assert step row gone (`Step::count() === 0`), redirect to `ventures.show`.
- `user_b_gets_404_deleting_user_a_step` — create A and B, A's venture and step; authenticate as B; DELETE the destroy endpoint; assert 404; assert A's step still exists.

#### 4. `ToggleStepTest`

**File**: `tests/Feature/Steps/ToggleStepTest.php`

**Intent**: Cover FR-013 happy path (both JSON and redirect response shapes) + isolation.

**Contract**: Two `#[Test]` methods.
- `completion_flips_and_returns_json_for_ajax` — create A, venture, one step with `is_completed = false`; authenticate as A; PATCH `route('steps.completion', [$venture, $step])` with `Accept: application/json` header; assert response is 200 with JSON `{is_completed: true, completed: 1, total: 1}`; assert step's `is_completed` is now true.
- `user_b_gets_404_toggling_user_a_step` — create A and B, A's venture and step; authenticate as B; PATCH the completion endpoint with `Accept: application/json`; assert 404; assert A's step `is_completed` is unchanged.

#### 5. `ShowVentureProgressTest`

**File**: `tests/Feature/Ventures/ShowVentureProgressTest.php`

**Intent**: Prove FR-018 renders correctly on the show view across the three meaningful states: zero steps, mixed completion, all completed.

**Contract**: One `#[Test]` method `progress_text_renders_correctly_for_mixed_completion`.
- Create user A, venture with 4 steps (2 completed, 2 incomplete) via `Step::factory()`.
- Authenticate as A, GET `route('ventures.show', $venture)`.
- Assert response sees "2 of 4 completed" string in the body.

(One test covers the case; the 0/0 and N/N cases are trivial variants whose render path is identical and don't need separate proof for v1.)

#### 6. `docs/reference/contract-surfaces.md` — Step surface (S-02)

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Add an "## Step surface (S-02)" section immediately after the existing "Venture surface (S-01)" section, documenting the routes, the access path discipline applied to nested resources, the source-immutability invariant (two-layer defense), the toggle's two response shapes, and the test proofs. Downstream slices (S-03 / S-05) extend this surface and must inherit these rules.

**Contract**: New section listing:
- Established by: S-02; PRD anchors: FR-010, FR-011, FR-012, FR-013, FR-018, NFR(edit-latency).
- Routes (all under `auth` middleware): `steps.create`, `steps.store`, `steps.edit`, `steps.update`, `steps.destroy`, `steps.completion` — with their HTTP methods and URLs.
- Access path rule (realization of the F-01 contract on a nested resource): every action chains `$request->user()->ventures()->findOrFail($venture)->steps()->findOrFail($step)` — the doubly-scoped query produces 404-not-403 for either a foreign venture OR a foreign step under an owned venture.
- Source-immutability invariant: `Step::$fillable = ['body', 'position']` (no `source`); `EditStepRequest` whitelists `body` only. Both layers must hold for the FR-008 primary-metric AI-pool snapshot to remain stable across edits.
- Toggle endpoint response contract: `Accept: application/json` → 200 JSON `{is_completed, completed, total}`; otherwise → 302 redirect to `ventures.show`.
- `is_completed` is NOT in `$fillable`; toggle writes it via direct attribute assignment. Future code paths writing this field MUST do the same.
- Test proofs: file:line references to `AddStepTest`, `EditStepTest` (especially the `source_stays_immutable` test), `DeleteStepTest`, `ToggleStepTest`, `ShowVentureProgressTest`.

#### 7. Roadmap status flip + `change.md` flip

**File**: `context/foundation/roadmap.md`, `context/changes/edit-and-track-steps/change.md`

**Intent**: Reflect the completed slice. Roadmap "At a glance" table row for S-02 flips `Status: proposed` → `Status: done`. The `### S-02: Edit and track steps` block's `Status:` line flips the same way. Backlog Handoff row's `Ready for /10x-plan: no` flips to `done`. `change.md` frontmatter: `status: planned` → `status: implemented`, `updated:` bumped to the actual implementation date.

**Contract**: Markdown edits only. Mirror the pattern S-01 used when it flipped to `done`.

### Success Criteria:

#### Automated Verification:

- All new feature tests pass: `composer run test`
- Linting / formatting passes: `vendor/bin/pint --test`
- `php artisan route:list` still shows the six `steps.*` routes

#### Manual Verification:

- `docs/reference/contract-surfaces.md` reads sensibly: the new "Step surface (S-02)" section has working file:line references; the source-immutability invariant is unambiguous; a reader landing on the file can find the toggle endpoint's response contract without reading the Venture surface section twice
- `context/foundation/roadmap.md` S-02 row is `done` in the table and the slice block, and the Backlog Handoff column reads `done`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the contract-surfaces section and roadmap edits read sensibly before considering the slice complete.

---

## Testing Strategy

### Unit Tests:

None — this slice has no pure-logic seams that warrant a unit test. The source-immutability invariant lives at the model + FormRequest layer and is exercised by `EditStepTest::source_stays_immutable_even_when_input_includes_source_field`.

### Integration Tests (Feature tests):

The nine tests above. Total breakdown: AddStepTest (2), EditStepTest (3), DeleteStepTest (2), ToggleStepTest (2), ShowVentureProgressTest (1).

### Manual Testing Steps:

1. Create a venture (`/dashboard` → fill in title + description → submit) → land on `/ventures/{id}` with 7 AI-initial steps + "0 of 7 completed".
2. Toggle the first step's checkbox → checkbox flips with no reload, body gets line-through, progress reads "1 of 7 completed".
3. Click "Edit" on a step → land on edit page → change the body → Save → land on show view with the body updated; tinker `\App\Models\Step::find($id)->source` returns `StepSource::AiInitial`.
4. Click "Delete" on a step → confirm in the native dialog → step disappears, total drops to 6.
5. Click "+ Add step" → land on create page → enter "Get a welding mask" → Add → land on show view with the new step at position 6 (end of list), no checkbox checked.
6. Open the same venture in a second browser logged in as user B → expect 404 on every `/ventures/{A's id}/...` URL.

(Source-immutability via input-tampering, JSON response shape, and isolation are covered by automated tests.)

## Performance Considerations

- Every action queries via the doubly-scoped relationship: `$request->user()->ventures()->findOrFail($v)->steps()->findOrFail($s)`. That's two indexed queries per request (PK on `ventures.id` AND `ventures.owner_id`; PK on `steps.id` AND `steps.venture_id`). Cheap at any realistic data volume for v1.
- The toggle endpoint's JSON path runs three queries (find step, save, count completed+total). The two count queries hit the `(venture_id, position)` index already in place — sub-millisecond at any realistic step-list size. If profiling later shows this matters, replace with a single `selectRaw('COUNT(*), SUM(is_completed)')` call.
- The JS island runs entirely client-side except for the fetch round-trip; perceived latency is dominated by network RTT. On Render's EU region with Neon Postgres in the same region, sub-100ms total is the expected shape — well under the NFR(edit-latency) ~1s ceiling.
- Vite-built JS adds ~1KB gzipped to the bundle for the toggle handler. Negligible.

## Migration Notes

- No new tables, no schema changes — only `Step::$fillable` and a controller patch in Phase 1. No data migration required.
- The change is backward-compatible with any in-flight venture (none exist in production yet anyway). Existing AI-initial steps retain their `source` value through any subsequent edit because the new EditStepRequest only forwards `body`.

## References

- F-01 contract (per-user isolation rules + S-01 enforcement checklist): `docs/reference/contract-surfaces.md`
- S-01 plan + brief: `context/changes/create-venture-with-ai-plan/plan.md`, `context/changes/create-venture-with-ai-plan/plan-brief.md`
- S-01 controller (the `$request->user()->ventures()->...` access pattern S-02 nests): `app/Http/Controllers/VenturesController.php:31-68`
- F-02 length-cap coupling note (why max:200 must match `steps.body` column width): `docs/reference/contract-surfaces.md` (AI suggestion surface — "Length cap coupling")
- StepSource enum: `app/Enums/StepSource.php`
- Existing isolation test pattern: `tests/Feature/Ventures/VentureIsolationTest.php`
- Existing form-styling reference: `resources/views/ventures/create.blade.php`
- Lessons: `context/foundation/lessons.md:14` (per-user isolation rule)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Defensive model tweak + nested route surface + skeleton controller + FormRequests

#### Automated

- [x] 1.1 Linting / formatting passes: `vendor/bin/pint --test` — 0700dc0
- [x] 1.2 Existing test suite still green: `composer run test` (especially `CreateVentureTest`) — 0700dc0
- [x] 1.3 `php artisan route:list` shows the six new `steps.*` routes under the `auth` middleware — 0700dc0

#### Manual

- [x] 1.4 Visiting `/ventures/{v}/steps/create` while authenticated returns the placeholder 404 (skeleton stub fired) — 0700dc0
- [x] 1.5 Visiting any of the six routes while unauthenticated redirects to `/login` — 0700dc0
- [x] 1.6 `php artisan tinker` quick check: `\App\Models\Step::factory()->create()` still works — 0700dc0

### Phase 2: Wire the five actions + rework the show view + ship the toggle JS island

#### Automated

- [x] 2.1 Linting / formatting passes: `vendor/bin/pint --test`
- [x] 2.2 Existing test suite still green: `composer run test`
- [x] 2.3 `npm run build` completes without errors
- [x] 2.4 `php artisan route:list` still shows the six `steps.*` routes

#### Manual

- [x] 2.5 Toggle a step's checkbox → flips with no reload, body gets line-through, progress text updates
- [x] 2.6 Edit a step → body updates, `source` preserved (verified by tinker)
- [x] 2.7 Delete a step → native confirm dialog → step removed, total drops by 1
- [x] 2.8 Add a manual step → lands at end of list with `source = Manual`
- [x] 2.9 Empty-state venture: "+ Add your first step" CTA renders and works
- [x] 2.10 JS-disabled browser: toggle checkbox + click Save → page reloads → completion persists

### Phase 3: Essentials test matrix + cross-slice handoff

#### Automated

- [ ] 3.1 All new feature tests pass: `composer run test`
- [ ] 3.2 Linting / formatting passes: `vendor/bin/pint --test`
- [ ] 3.3 `php artisan route:list` still shows the six `steps.*` routes

#### Manual

- [ ] 3.4 `docs/reference/contract-surfaces.md` "Step surface (S-02)" reads sensibly with working file:line references and the source-immutability invariant unambiguous
- [ ] 3.5 `context/foundation/roadmap.md` S-02 row is `done` in the table, the slice block, and the Backlog Handoff column
