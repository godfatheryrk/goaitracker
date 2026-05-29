# S-03 / extend-plan-with-ai — Implementation Plan

## Overview

Ship FR-009: a "Suggest more with AI" trigger on the venture detail view that opens a server-rendered preview of 7 candidate steps; the user picks which to keep (checkbox per row), submits, and only the chosen rows persist at the tail of the step list with `source = StepSource::AiExtension`. The slice nests under the existing S-02 `steps.*` surface, reuses F-02's `AiStepSuggester` seam unchanged (the service already accepts `array $currentSteps`), and reuses S-01's amber `ai_unavailable` flash pattern for both the over-ceiling and provider-failure paths. Extension rows stay off the FR-008 "3 of 7" primary metric automatically because the metric measures only `ai_initial` — the carve-out is structural in the enum, not extra controller code.

## Current State Analysis

- **F-02 (`ai-suggestion-service`) is shipped.** `AiStepSuggester::suggestSteps(User, string $title, string $description, array $currentSteps = [])` (`app/Services/AiStepSuggester.php:13-18`) already accepts a `$currentSteps` array; it threads it into `StepSuggestionAgent` (`app/Ai/StepSuggestionAgent.php:46-54`), which appends an "Existing steps:\n…" block to the system prompt and asks the model for 7 NEW non-overlapping steps. Same return shape: exactly 7 strings on success, `[]` on every failure mode (timeout, 4xx/5xx, malformed JSON, wrong count, over-quota). Same counter rules: increment BEFORE dispatch (failures count); over-ceiling returns `[]` without dispatching. **No service change is needed for S-03 — wiring only.**
- **S-01 (`create-venture-with-ai-plan`) is shipped.** `VenturesController::store` (`app/Http/Controllers/VenturesController.php:31-63`) is the precedent for the AI-call shape S-03 mirrors: the suggester call sits OUTSIDE the persistence transaction (so a venture-insert / step-insert failure cannot roll back a counter increment for an attempt that really happened), then the persistence loop runs inside `DB::transaction(...)`. The `session()->flash('ai_unavailable', '…')` pattern is the user-facing failure shape — show.blade.php renders it as an amber notice (`resources/views/ventures/show.blade.php:10-14`).
- **S-02 (`edit-and-track-steps`) is shipped.** The `steps.*` nested route surface (`routes/web.php:32-53`) is in place under the `auth` middleware with `whereNumber('venture')` constraints; `StepsController::store` (`app/Http/Controllers/StepsController.php:22-37`) is the precedent for the position-append + forceFill-source pattern S-03 reuses (`$nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;` for the first, increment for subsequent). `Step::$fillable = ['body', 'position']` (`app/Models/Step.php:11`) — `source` is non-mass-assignable, so S-03 MUST set it via `->forceFill(['source' => StepSource::AiExtension])` (same defense-in-depth pattern S-01 and S-02 already use for `ai_initial` / `manual`).
- **`StepSource::AiExtension` is already declared** in `app/Enums/StepSource.php` (S-01 reserved it; the model cast in `Step::$casts` (`app/Models/Step.php:18`) is already `'source' => StepSource::class`). S-03 is the first slice that writes this enum value; no enum / model / migration change.
- **`Venture::steps()` is ordered by `position`** (`app/Models/Venture.php:23`), so the preview-page "Existing steps" list and the persistence position computation both see the same order without extra plumbing.
- **The view layer has no JS framework.** S-02 ships a vanilla-JS island for the toggle (`resources/js/app.js`); S-03 does NOT need a JS island — preview and confirm are plain `<form method="POST">` round-trips with full-reload redirects. No `resources/js/app.js` change.

## Desired End State

A logged-in user on `/ventures/{v}` with a non-empty step list sees a "Suggest more with AI" button next to the existing "+ Add step" link at the bottom of the steps panel. (Same button doubles as the empty-state CTA, alongside "+ Add your first step", when the list is empty — a venture whose initial AI generation failed at creation can still recover via this path.) Clicking it POSTs to `steps.suggestions.preview`. On success: the response renders a `suggestions/preview.blade.php` view showing the venture title, the 7 AI-generated candidate step bodies as rows with a checkbox each (default `checked`), a "Keep selected" submit button, and a "Cancel" link back to `ventures.show`. The user can uncheck any number of rows, then submit; the controller persists only the rows with `keep` ticked, each as a new `Step` with `source = StepSource::AiExtension`, `owner_id = $request->user()->id`, contiguous `position` values appended to the existing `max(position) + 1 .. + N`. The user lands back on `ventures.show` with the new steps at the end of the list, source-tagged `ai_extension` and therefore excluded from the FR-008 metric. On AI failure (provider error OR over-ceiling — `AiStepSuggester` returns `[]` either way): no preview is rendered; the controller redirects to `ventures.show` with the same amber `ai_unavailable` flash S-01 uses, wording adapted to the extension context. A second user posting to either endpoint with the first user's `venture` parameter gets 404 (not 403); guests redirect to `/login`. The FR-008 metric snapshot is unaffected — `ai_initial` rows on this venture retain their source through any S-02 edit, and the new `ai_extension` rows are never counted toward the "3 of 7 kept" denominator.

### Key Discoveries:

- **F-02's `$currentSteps` parameter does the heavy lifting** — `StepSuggestionAgent::instructions()` (`app/Ai/StepSuggestionAgent.php:39-57`) already appends an "Existing steps:" block to the system prompt when `$currentSteps` is non-empty, with imperative wording that asks the model for 7 NEW non-overlapping steps. S-03 just passes `$ventureModel->steps()->pluck('body')->toArray()` into the call; no prompt / agent / service edit.
- **The atomicity question (Q3) is resolved by the user-selection step.** The preview-then-confirm flow means the user is the one deciding which rows persist; a partial-persistence DB error after that confirmation is a real bug, not a product question. The confirm controller wraps the persistence loop in `DB::transaction(...)` so all chosen rows land or none do; on a thrown DB error the user gets the same amber flash. This mirrors S-01's `VenturesController::store` pattern.
- **The AI-call counter is incremented BEFORE the suggester dispatches** (`AiStepSuggester` lines 28-35), so a user who clicks "Suggest more" and lands on the preview but then clicks "Cancel" has still paid one ceiling unit. This matches NFR(ai-ceiling) and the F-02 contract; document it in the user-facing wording of the preview-page "Cancel" link so a confused user doesn't think Cancel is free.
- **The preview page carries the candidate bodies as hidden form fields**, not session state. Refresh on the preview POST result resubmits the form (browser prompt), which would re-fire the AI call — annoying but bounded by the per-day ceiling; not a correctness bug. Session-flash carry-over was considered and rejected because it dies on the first GET and doesn't survive a refresh either; hidden fields are the cleanest round-trip for a one-shot preview-then-confirm.
- **Hidden-field tampering is bounded by validation + forceFill.** A user editing a hidden body to 300 chars gets rejected by `SuggestStepsRequest`'s `max:200` rule (matches `steps.body` column width and F-02's `max_step_length` — see `docs/reference/contract-surfaces.md` "Length cap coupling"). A user trying to inject `source: ai_initial` via the form is rejected because (a) `source` is not in `Step::$fillable` and (b) the controller writes source via `forceFill([... 'source' => StepSource::AiExtension])`, not from input. Privacy is unaffected because ownership goes through `$request->user()->ventures()->findOrFail()` like every other authenticated step endpoint.
- **The "keep" checkbox is a presence flag, not a value-validation field.** Browsers send `keep=1` for checked boxes and OMIT the field entirely for unchecked. The FormRequest treats `suggestions.*.keep` as nullable; the controller filters `array_filter($validated['suggestions'], fn ($row) => ! empty($row['keep']))` before persisting. Edge case: user unchecks all → 0 rows persist, no flash, plain redirect back — explicit user choice, not a failure.

## What We're NOT Doing

- **No AI-suggestion regeneration / retry-on-the-preview-page.** PRD §Non-Goals: "No 'regenerate AI suggestion' action." If the user dislikes all 7 suggestions, they Cancel and click Suggest again from the venture page (which counts another call against the daily ceiling, as it should). The preview page does not get a "Re-roll suggestions" button.
- **No inline body editing on the preview page.** Each row is checkbox-or-skip; the body text is read-only on the preview. If a user wants to tweak a kept suggestion, they keep it, redirect lands on `ventures.show`, then they click the step's existing Edit link from S-02. Inline edit on the preview was considered and rejected as a v2 UX concern — adds a textarea per row, doubles the FormRequest validation, and crosses the metric carve-out into the user's word-for-word edits (which complicates wording downstream).
- **No partial-success flash.** Atomicity covers this — either all chosen rows persist or none do; a DB error on the confirm path falls through to the same amber flash and zero rows persist.
- **No new model, table, migration, or enum value.** `StepSource::AiExtension` already exists; no schema change.
- **No JS island.** Preview is plain HTML; submit is a plain form POST with full-reload redirect. `resources/js/app.js` is untouched.
- **No soft-cap warning at high step counts** (e.g., "your venture has 28 steps — add another 7?"). PRD §Open Questions and the FR-009 Socrates resolution explicitly defer total-step soft warnings to v2.
- **No edit to F-02's prompt, schema, or service signature.** S-03 is a pure consumer of the existing seam.
- **No counter-status surface** (e.g., "you have 3 AI calls left today"). The F-02 ceiling is invisible to the user except via the `ai_unavailable` flash when hit; surfacing remaining quota is a v2 UX consideration.
- **No backfill / migration of S-02 ai-extension data.** No such data exists yet.
- **No new auth layer.** Ownership is enforced by the doubly-scoped relationship chain `$request->user()->ventures()->findOrFail($venture)`, same as S-01 / S-02.

## Implementation Approach

Two phases, each ending in a verifiable gate.

1. **Backend + view + button.** Two new routes (`steps.suggestions.preview` POST, `steps.suggestions.store` POST), two new `StepsController` actions (`suggest`, `storeSuggestions`), one new FormRequest (`SuggestStepsRequest`), one new view (`resources/views/steps/suggestions/preview.blade.php`), and the "Suggest more with AI" button wired into both surface positions on `ventures.show` (next to "+ Add step" on the populated state, alongside "+ Add your first step" on the empty state). Existing test suite stays green; manual browser walkthrough exercises the four end-to-end shapes (success → keep all, success → keep subset, success → keep none, AI-fail → flash).
2. **Test matrix + cross-slice handoff.** Five feature tests (full happy-path, partial selection, no-selection, AI-unavailable, two-user-404 isolation on both endpoints). New "Step extension surface (S-03)" section in `docs/reference/contract-surfaces.md`. Roadmap S-03 row + slice block + Backlog Handoff column flip to `done`. `change.md` flips to `implemented` with `updated:` bumped.

## Critical Implementation Details

- **The suggester call MUST sit outside the persistence transaction.** On the confirm path, the AI call already happened in the earlier suggest request — there's no AI in the confirm controller. On the suggest path, no DB writes happen in the controller (the suggester does its own counter transaction), so this constraint applies primarily to S-01's precedent and to the confirm path's persistence loop (which writes 0..7 step rows). The confirm path wraps the persistence loop in `DB::transaction(...)`; the AI call is NOT inside it.
- **`source` is set via `forceFill`, not via `make` / `update`.** `Step::$fillable = ['body', 'position']` deliberately omits `source`. Following S-02's hardening, the confirm-path persistence loop uses `$ventureModel->steps()->make(['body' => $body, 'position' => $nextPosition])->forceFill(['owner_id' => $request->user()->id, 'source' => StepSource::AiExtension])->save();` per row. Forgetting the forceFill would persist `source = null`, silently breaking the FR-009 metric carve-out (steps would not be excluded from the FR-008 denominator because they would not be tagged `ai_initial` either — they'd just be unclassified).
- **Position computation runs once per confirm request, BEFORE the loop, then increments per persisted row.** Read `$nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;` once; inside the loop, write `$nextPosition`, then `$nextPosition++;`. Computing `max(position) + 1` inside the loop would persist all kept steps at the same position (the in-memory `$nextPosition` and the DB `max(position)` would not be synchronized in a transaction). Same gotcha as S-02's manual-add path, but more visible because S-03 writes multiple rows in one request.
- **Two-user-404 on the suggest endpoint must check the venture-resolution path BEFORE the suggester is called.** A test that lets the suggester call fire before the 404 returns would silently consume an AI call against user B's ceiling (counter is increment-before-dispatch). The controller's first line MUST be `$ventureModel = $request->user()->ventures()->findOrFail($venture);` — the `findOrFail` throws 404 before any suggester call. Phase 2's isolation test asserts this by also asserting the `AiStepSuggester` mock was NOT called when user B posts to user A's venture.
- **`session()->flash('ai_unavailable', '…')` wording is extension-specific.** S-01 flashes "AI couldn't suggest steps right now — you can add them manually." For S-03 use a parallel wording: "AI couldn't suggest more steps right now — try again later or add steps manually." The flash KEY (`ai_unavailable`) is the same so the existing `ventures.show` rendering picks it up without view changes; only the message text differs.

## Phase 1: Backend + view + button

### Overview

Wire the two new routes, the two new controller actions, the FormRequest, the preview view, and the show-view button. End state: a user can click "Suggest more with AI", see the preview, choose which rows to keep, submit, and see the chosen rows appended to the step list. No new tests in this phase (existing suite must stay green).

### Changes Required:

#### 1. Two new routes registered under `auth` middleware

**File**: `routes/web.php`

**Intent**: Mount the two new endpoints on the existing `steps.*` nested surface. Both are POST (the suggest endpoint has the side effect of calling AI + incrementing the counter; using GET would let browser prefetch / bookmark / refresh re-fire AI). Naming follows `steps.suggestions.*` to keep the namespace tidy without colliding with the existing `steps.create` / `steps.store` (which mean "manual add").

**Contract**: Inside the `Route::middleware('auth')->group(...)` block, after the existing `steps.completion` route, add:
- `Route::post('ventures/{venture}/steps/suggestions', [StepsController::class, 'suggest'])->whereNumber('venture')->name('steps.suggestions.preview');`
- `Route::post('ventures/{venture}/steps/suggestions/confirm', [StepsController::class, 'storeSuggestions'])->whereNumber('venture')->name('steps.suggestions.store');`

No import edits required; `StepsController` is already imported.

#### 2. `StepsController::suggest`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: Call the AI suggester with the venture's current step bodies; on success render the preview view; on `[]` flash `ai_unavailable` and redirect to `ventures.show`. The suggester call sits outside any transaction (the suggester manages its own counter transaction). Constructor-inject `AiStepSuggester` the same way `VenturesController` does (`app/Http/Controllers/VenturesController.php:15`).

**Contract**:
- Add `private readonly AiStepSuggester $suggester` to the controller via a new constructor (currently no constructor exists). Inject via Laravel's auto-resolution.
- Method signature: `public function suggest(Request $request, int $venture): View|RedirectResponse`.
- Body sequence: resolve `$ventureModel = $request->user()->ventures()->findOrFail($venture);` → collect existing step bodies as `$currentSteps = $ventureModel->steps()->pluck('body')->toArray();` → call `$suggestions = $this->suggester->suggestSteps($request->user(), $ventureModel->title, $ventureModel->description ?? '', $currentSteps);` → on `empty($suggestions)`: `session()->flash('ai_unavailable', "AI couldn't suggest more steps right now — try again later or add steps manually."); return redirect()->route('ventures.show', $ventureModel);` → otherwise: `return view('steps.suggestions.preview', ['venture' => $ventureModel, 'suggestions' => $suggestions]);`.

#### 3. `StepsController::storeSuggestions`

**File**: `app/Http/Controllers/StepsController.php`

**Intent**: Receive the user's checkbox selection, filter to chosen rows, persist each as a new `Step` row with `source = ai_extension`, contiguous `position` values appended to `max(position) + 1`. All-or-nothing via `DB::transaction(...)`. No AI call here — the suggester ran in the earlier `suggest()` request.

**Contract**:
- Method signature: `public function storeSuggestions(SuggestStepsRequest $request, int $venture): RedirectResponse`.
- Body sequence: resolve `$ventureModel = $request->user()->ventures()->findOrFail($venture);` → extract `$rows = $request->validated('suggestions', []);` → filter `$chosen = array_values(array_filter($rows, fn ($row) => ! empty($row['keep'])));` → compute `$nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1;` → run `DB::transaction(function () use ($ventureModel, $chosen, $request, &$nextPosition) { foreach ($chosen as $row) { $ventureModel->steps()->make(['body' => $row['body'], 'position' => $nextPosition])->forceFill(['owner_id' => $request->user()->id, 'source' => StepSource::AiExtension])->save(); $nextPosition++; } });` → return `redirect()->route('ventures.show', $ventureModel);`.

Edge case: `$chosen` empty → transaction body is a no-op → user redirected back to the venture with no new steps and no flash. Explicit user choice; not a failure.

#### 4. `SuggestStepsRequest` FormRequest

**File**: `app/Http/Requests/Steps/SuggestStepsRequest.php`

**Intent**: Validate the array-shaped form payload from the preview page. `authorize()` returns true (the `auth` middleware boundary is the authentication gate; ownership is enforced via the controller's `$request->user()->ventures()->findOrFail()`). The rules deliberately omit `source`, `position`, `owner_id`, `venture_id` so they cannot reach the controller via mass-assignment — defense in depth alongside the controller's explicit forceFill.

**Contract**: `extends FormRequest`. `authorize(): bool { return true; }`. `rules(): array` returns:
- `'suggestions' => ['required', 'array', 'min:1', 'max:7']`
- `'suggestions.*.body' => ['required', 'string', 'min:1', 'max:200']`
- `'suggestions.*.keep' => ['nullable']`

The `max:7` ceiling on the outer array defends against tampering that adds extra rows; `max:200` on each body matches `steps.body` column width and F-02's `max_step_length`.

#### 5. `suggestions/preview.blade.php` view

**File**: `resources/views/steps/suggestions/preview.blade.php`

**Intent**: Render the 7 AI-suggested step bodies as a single form with a checkbox + read-only body per row, a hidden field per row carrying the body text (so the user's selection survives the round-trip without re-fetching from AI), a "Keep selected" submit button, and a "Cancel" link back to `ventures.show`. Style primitives match the existing `steps/create.blade.php` and `ventures/create.blade.php` patterns (dark `bg-gray-800` submit button, `text-sm text-gray-600 hover:text-gray-900` cancel link).

**Contract**: Extends `layouts.app`.
- `@section('header')` shows `Suggest more steps for: {{ $venture->title }}`.
- Form: `<form method="POST" action="{{ route('steps.suggestions.store', $venture) }}">` with `@csrf`. No `@method` (POST native).
- A short instructional paragraph above the rows: "Uncheck any suggestions you don't want to keep. We'll add the rest to the end of your step list."
- An `<ol>` of suggestion rows. Each row:
  - `<input type="checkbox" name="suggestions[{{ $i }}][keep]" value="1" checked id="suggestion-{{ $i }}" class="rounded border-gray-300">`
  - `<input type="hidden" name="suggestions[{{ $i }}][body]" value="{{ $body }}">`
  - `<label for="suggestion-{{ $i }}" class="ml-2 text-gray-800">{{ $body }}</label>`
- A row with: a Cancel link to `ventures.show` and a submit button labelled "Keep selected".
- An `@error('suggestions')` block above the list rendering any validation errors. (Per-row body errors are extremely unlikely under normal use because the bodies come from a contracted-7-strings-each-≤200-chars response, but defending the surface is cheap.)

Place under `resources/views/steps/suggestions/` (new subdirectory). The single underscore-style `suggestions/` matches the route-name segment.

#### 6. `ventures.show` — add "Suggest more with AI" button

**File**: `resources/views/ventures/show.blade.php`

**Intent**: Render the trigger in two positions: (a) inside the empty-state block, alongside "+ Add your first step", as a secondary action; (b) in the populated-state bottom action bar, next to the existing "+ Add step" link. The trigger is a `<form method="POST">` (NOT a link) because the suggest endpoint has side effects.

**Contract**:
- In the empty-state block (`@if ($venture->steps->isEmpty()) ... @endif`, currently `resources/views/ventures/show.blade.php:34-43`), add below the existing "+ Add your first step" link a second affordance:
  ```html
  <form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}" class="inline-block">
      @csrf
      <button type="submit" class="text-sm text-gray-700 hover:text-gray-900">
          Suggest more with AI
      </button>
  </form>
  ```
- In the populated-state bottom action bar (currently `resources/views/ventures/show.blade.php:90-95`), replace the existing single `<a>` with a `<div class="mt-4 flex items-center gap-4">` that wraps both the existing "+ Add step" link AND the same form-button above. The two affordances sit side by side at the bottom of the panel.

The CSRF token is the only field; no other form state.

### Success Criteria:

#### Automated Verification:

- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test`
- `php artisan route:list` shows the two new `steps.suggestions.*` routes under the `auth` middleware

#### Manual Verification:

- On a venture with 7 AI-initial steps: click "Suggest more with AI" → land on preview page with 7 candidate steps, all checkboxes checked → submit → land on `ventures.show` with 14 steps total (7 original + 7 new); tinker `\App\Models\Step::where('venture_id', $v)->where('source', \App\Enums\StepSource::AiExtension)->count()` returns 7
- On the same venture: click "Suggest more with AI" → uncheck 4 of 7 → submit → 3 new steps appended (total 17); tinker confirms the 3 new steps have `source = ai_extension` and `position` values are contiguous after the previous max
- On the same venture: click "Suggest more with AI" → uncheck all → submit → land on `ventures.show` with no new steps and no flash (silent no-op is expected behaviour for "user explicitly chose nothing")
- On a venture with 0 steps (created with a mocked failing `AiStepSuggester` at venture creation): the empty-state shows BOTH "+ Add your first step" AND "Suggest more with AI"; clicking the latter exercises the same flow
- Force the suggester to return `[]` (set `config('ai.step_suggestion.ceiling_per_day')` to `0` temporarily in tinker, OR truly exhaust the daily ceiling): click "Suggest more with AI" → no preview, redirect to `ventures.show` with the amber `ai_unavailable` notice reading "AI couldn't suggest more steps right now — try again later or add steps manually."
- Two-user sanity check: log in as user B; visit user A's venture URL (expect 404); POST to user A's suggest endpoint via a curl or browser dev-tools form-tampering smoke test → 404; counter for user B is still 0 (Phase 2 covers this automatically)

**Implementation Note**: After Phase 1 passes automated verification, pause for manual confirmation that all five browser walkthroughs above work as expected before proceeding to Phase 2. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes live in `## Progress` at the bottom.

---

## Phase 2: Test matrix + cross-slice handoff

### Overview

Land the five feature tests proving the slice's contract; document the surface for downstream slices (none in the v1 roadmap depend on S-03 directly, but S-05 layering deadlines onto the same step rows will need the access-path discipline documented); flip the roadmap and `change.md`.

### Changes Required:

#### 1. `SuggestExtensionTest`

**File**: `tests/Feature/Steps/SuggestExtensionTest.php`

**Intent**: Cover the full happy-path round trip + partial-selection + no-selection + AI-unavailable behaviour on the suggest endpoint. Mocks `AiStepSuggester` via `$this->mock(AiStepSuggester::class, …)` the same way `tests/Feature/Ventures/CreateVentureTest.php` does (verify by reading that file — the mock pattern is established).

**Contract**: A Pest/PHPUnit test class with at minimum four `#[Test]` methods (or Pest `test('…')` blocks following the existing project style). For each:

- `happy_path_renders_preview_with_seven_candidates` — Authenticate as user A. Create one venture (factory). Mock `AiStepSuggester::suggestSteps()` to return 7 strings `['step1', 'step2', …, 'step7']`. POST to `route('steps.suggestions.preview', $venture)`. Assert response is 200 with view `steps.suggestions.preview`, with `suggestions` view-data containing the 7 strings, with the rendered HTML carrying 7 `<input type="hidden" name="suggestions[…][body]">` fields and 7 `<input type="checkbox" name="suggestions[…][keep]" … checked>` fields.
- `ai_unavailable_flashes_and_redirects` — Authenticate as user A. Create one venture. Mock `AiStepSuggester::suggestSteps()` to return `[]`. POST to `route('steps.suggestions.preview', $venture)`. Assert response is 302 to `route('ventures.show', $venture)`; assert `session('ai_unavailable')` contains the expected substring "couldn't suggest more steps"; assert `Step::count()` is unchanged (no rows persisted).
- `user_b_gets_404_on_user_a_suggest_endpoint_and_suggester_not_called` — Create users A and B; create venture as A. Authenticate as B. Mock `AiStepSuggester::suggestSteps()` with an explicit "should never be called" expectation (`->shouldReceive('suggestSteps')->never()` for Mockery, or the equivalent in the project's test style). POST to `route('steps.suggestions.preview', $A_venture)`. Assert 404. Assert `AiCallCounter::where('owner_id', $B->id)->count()` is 0 (B was NOT charged a ceiling unit for the rejected call).
- `guest_post_to_suggest_endpoint_redirects_to_login` — No authentication. POST to `route('steps.suggestions.preview', $any_id)`. Assert 302 to `/login`.

#### 2. `StoreSuggestedStepsTest`

**File**: `tests/Feature/Steps/StoreSuggestedStepsTest.php`

**Intent**: Cover the confirm endpoint: full-keep, partial-keep, no-keep, and isolation. The AI suggester is NOT called in this endpoint (the suggestions are passed in via form fields), so no mock setup is needed for the suggester.

**Contract**: Four `#[Test]` methods.

- `keeping_all_seven_appends_seven_ai_extension_rows_at_end_of_list` — Create user A and a venture; seed the venture with 3 existing `ai_initial` steps at positions 0/1/2 via `Step::factory()`. Authenticate as A. POST to `route('steps.suggestions.store', $venture)` with payload `{suggestions: [{body: 'a', keep: '1'}, {body: 'b', keep: '1'}, …, {body: 'g', keep: '1'}]}`. Assert response is 302 to `route('ventures.show', $venture)`. Assert `$venture->steps()->count()` is 10. Assert the 7 newest rows (ordered by `position`) have `source = StepSource::AiExtension`, `owner_id = $A->id`, `position` values 3–9 in order, and `body` values matching the input order ('a' through 'g').
- `keeping_subset_persists_only_chosen_rows_in_order` — Same setup as above but POST with `keep` set only on rows 0, 2, 4 (three rows). Assert the persisted-rows count grew by 3 (total 6 steps); assert the new rows have `body` values 'a' / 'c' / 'e' in that order at `position` 3 / 4 / 5; assert their `source` is `StepSource::AiExtension`.
- `keeping_none_persists_nothing_and_redirects` — Same setup. POST with `keep` absent on all 7 rows (only `body` hidden fields). Assert response is 302 to `route('ventures.show', $venture)`; assert `$venture->steps()->count()` is still 3 (unchanged); assert no flash key is set (this is an explicit no-op, not a failure).
- `user_b_gets_404_on_user_a_confirm_endpoint` — Create A and B; A's venture and 3 existing steps. Authenticate as B. POST to `route('steps.suggestions.store', $A_venture)` with a non-empty `suggestions` payload. Assert 404. Assert A's venture step count is still 3 (no rows persisted).

#### 3. `docs/reference/contract-surfaces.md` — Step extension surface (S-03)

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Add a "## Step extension surface (S-03)" section immediately after the existing "## Step surface (S-02)" section, documenting the two new routes, the doubly-scoped access path (already documented for S-02 — restate one sentence here for the inheritance), the preview-then-confirm flow shape, the source-tagging invariant (`StepSource::AiExtension` via `forceFill`), the failure-flash contract (reuses S-01's `ai_unavailable` key), and the test proofs.

**Contract**: New section listing:
- Established by: S-03. PRD anchors: FR-009.
- Routes (under `auth` middleware): `steps.suggestions.preview` (POST `/ventures/{venture}/steps/suggestions`), `steps.suggestions.store` (POST `/ventures/{venture}/steps/suggestions/confirm`). Both `whereNumber('venture')`.
- Access path rule: same doubly-scoped `$request->user()->ventures()->findOrFail($venture)` rule as the S-02 surface — the venture-resolution call MUST be the first line of each action so an unauthorized POST throws 404 BEFORE the AI suggester is dispatched (otherwise user B could burn user A's AI ceiling on a rejected request, contradicting NFR(ai-ceiling)'s per-user accounting).
- Preview-then-confirm flow: the suggest action either renders `steps.suggestions.preview` with hidden `suggestions[*][body]` fields and default-checked `suggestions[*][keep]` checkboxes OR flashes `session('ai_unavailable')` and redirects to `ventures.show`. The confirm action filters by `keep`, persists the chosen rows as `ai_extension` at `max(position) + 1 .. + N`, and redirects to `ventures.show`. Both endpoints are POST; the suggest endpoint has the side effect of incrementing the per-user AI counter (because the F-02 service increments BEFORE dispatch).
- Source invariant: confirmed-suggestion rows are persisted with `source = StepSource::AiExtension` via `->forceFill(['source' => StepSource::AiExtension, 'owner_id' => …])`. `source` is not in `Step::$fillable` per the S-02 hardening, so a tampered form payload carrying `source: ai_initial` cannot pollute the FR-008 metric pool — defense in depth: model-layer fillable + FormRequest whitelist + controller-layer forceFill.
- Failure flash contract: the suggest endpoint reuses S-01's amber `ai_unavailable` flash key with extension-specific wording ("AI couldn't suggest more steps right now — try again later or add steps manually."). The `ventures.show` view's existing flash-rendering block picks up either S-01's or S-03's wording without view-level branching.
- Test proofs: file:line references to the four `SuggestExtensionTest` methods and the four `StoreSuggestedStepsTest` methods. Note explicitly that the suggest-side isolation test asserts the suggester was NOT called when user B posts to user A's venture — that's the assertion that closes the "B can't burn A's ceiling" loop.

#### 4. Roadmap status flip + `change.md` flip

**File**: `context/foundation/roadmap.md`, `context/changes/extend-plan-with-ai/change.md`

**Intent**: Reflect the completed slice. Roadmap "At a glance" table row for S-03 flips `Status: proposed` → `Status: done`. The `### S-03: Extend the plan with AI` block's `Status:` line flips the same way. Backlog Handoff row's `Ready for /10x-plan: no` flips to `done`. `change.md` frontmatter: `status: planned` → `status: implemented`, `updated:` bumped to the actual implementation date.

**Contract**: Markdown edits only. Mirror the pattern S-01 and S-02 used when they flipped to `done`.

### Success Criteria:

#### Automated Verification:

- All new feature tests pass: `composer run test`
- Linting / formatting passes: `vendor/bin/pint --test`
- `php artisan route:list` still shows the two `steps.suggestions.*` routes under `auth`

#### Manual Verification:

- `docs/reference/contract-surfaces.md` reads sensibly: the new "Step extension surface (S-03)" section is self-sufficient (a reader can implement against it without reading this plan); the suggester-not-called assertion under the isolation test is clearly tied to the NFR(ai-ceiling) per-user accounting rule
- `context/foundation/roadmap.md` S-03 row is `done` in the "At a glance" table, the slice block, and the Backlog Handoff column

**Implementation Note**: After Phase 2 passes automated verification, pause for manual confirmation that the contract-surfaces section and roadmap edits read sensibly before considering the slice complete.

---

## Testing Strategy

### Unit Tests:

None — this slice has no pure-logic seams that warrant a unit test. The `keep` filtering, position computation, and source-forceFill are exercised end-to-end by the feature tests.

### Integration Tests (Feature tests):

The eight tests above. Total breakdown: `SuggestExtensionTest` (4: happy-path, ai-unavailable, user-b-404 with suggester-never-called, guest-redirect), `StoreSuggestedStepsTest` (4: keep-all, keep-subset, keep-none, user-b-404).

The two-user isolation tests are required by the F-01 S-01 enforcement checklist (`docs/reference/contract-surfaces.md` § "S-01 enforcement checklist", item 4) regardless of S-03's discretionary test selection — they are the privacy floor every new per-user endpoint must clear.

The AI-unavailable test on the suggest endpoint is required because S-03's user-facing failure contract (Q2 in planning: "Reuse the amber 'ai_unavailable' flash pattern") would otherwise be untested.

### Manual Testing Steps:

1. Sign in as user A → create a venture (mock AI to succeed at creation; if AI is live, accept the 7 ai_initial steps).
2. Click "Suggest more with AI" → preview page renders with 7 candidates, all checked.
3. Submit unchanged → land on `ventures.show` with 14 steps total; the new 7 carry `source = ai_extension` (verify via tinker).
4. Click "Suggest more with AI" again → preview again → uncheck 4 → submit → 3 new steps appended (17 total).
5. Click "Suggest more with AI" → preview → click Cancel → land back on `ventures.show` with no new steps (the AI call DID count against the daily ceiling — verify via tinker `\App\Models\AiCallCounter::where('owner_id', $A_id)->where('day', now()->toDateString())->value('count')`).
6. Click "Suggest more with AI" → preview → uncheck all → submit → land back on `ventures.show` with no new steps and no flash (silent no-op is expected; the AI call still counted against the ceiling).
7. Force ceiling: in tinker, seed `AiCallCounter` for user A with `count = 20` for today (matches `config('ai.step_suggestion.ceiling_per_day')`). Click "Suggest more with AI" → no preview, amber `ai_unavailable` notice on `ventures.show` reading "AI couldn't suggest more steps right now — try again later or add steps manually."
8. Sign in as user B → visit user A's venture URL → 404. Use browser dev-tools to construct a POST to user A's suggest endpoint (or a curl with a stolen CSRF — for a smoke test it's easier to just confirm via the feature test).

### Source-immutability sanity check:

Run `\App\Models\Step::where('venture_id', $v)->pluck('source', 'body')` after a mixed flow (initial generation + one extension round); confirm the original 7 are still `ai_initial` and the new ones are `ai_extension`. This is also covered automatically by the existing `EditStepTest::source_stays_immutable...` test (which proves the FormRequest+fillable defense layers in isolation) — no new immutability test is needed for S-03 because the same defense layers apply.

## Performance Considerations

- The suggest endpoint runs one AI call (synchronous, up to 15s timeout per F-02's `Timeout` attribute). Same latency budget as venture creation — explicitly outside the NFR(edit-latency) ≤1s envelope per F-02's contract.
- The confirm endpoint runs N inserts inside a single transaction (N ≤ 7). On Render's EU region with Neon Postgres in the same region, ~50ms total. Well under any user-facing latency budget.
- The "Existing steps:" prompt-block in `StepSuggestionAgent::instructions()` (`app/Ai/StepSuggestionAgent.php:46-54`) grows linearly with the current step count. For a venture with 20+ steps this is a few KB of prompt text; well within Groq's context window and a negligible token-cost increase. If profiling shows this matters, the prompt can later cap to the latest 30 steps without a contract change.
- Counter table grows at ≤1 row per user per UTC calendar day, regardless of how many extensions a user runs that day. No performance concern.

## Migration Notes

- No new tables, no schema changes, no enum value additions.
- The slice is backward-compatible with any existing venture (no in-flight production data anyway).
- No deploy-time migration; only an app-code deploy. After deploy, the new routes appear in `route:list` and the button appears on every venture detail page.

## References

- F-01 contract (per-user isolation rules + S-01 enforcement checklist): `docs/reference/contract-surfaces.md`
- F-02 plan + brief (AI service shape and the `$currentSteps` parameter S-03 consumes): `context/changes/ai-suggestion-service/plan.md`, `context/changes/ai-suggestion-service/plan-brief.md`
- F-02 surface and "Length cap coupling" note: `docs/reference/contract-surfaces.md` § "AI suggestion surface (F-02)"
- S-01 plan + brief (precedent for the `session('ai_unavailable')` flash + AI-call-outside-transaction pattern): `context/changes/create-venture-with-ai-plan/plan.md`, `context/changes/create-venture-with-ai-plan/plan-brief.md`
- S-02 plan + brief (precedent for the nested-route + access-path-discipline + source-immutability hardening): `context/changes/edit-and-track-steps/plan.md`, `context/changes/edit-and-track-steps/plan-brief.md`
- S-01 controller (the AI-outside-transaction + forceFill-source pattern S-03 mirrors): `app/Http/Controllers/VenturesController.php:31-63`
- S-02 controller (the doubly-scoped access path + manual-add position pattern): `app/Http/Controllers/StepsController.php:22-37`
- `AiStepSuggester` service: `app/Services/AiStepSuggester.php:13-18`
- `StepSuggestionAgent` (the prompt that consumes `$currentSteps`): `app/Ai/StepSuggestionAgent.php:39-57`
- `StepSource` enum (`AiExtension` already declared): `app/Enums/StepSource.php`
- Existing isolation test pattern: `tests/Feature/Ventures/VentureIsolationTest.php`
- Existing AI-fail test pattern (S-01 mocked-suggester): `tests/Feature/Ventures/CreateVentureTest.php`
- Existing form-styling reference: `resources/views/steps/create.blade.php`, `resources/views/ventures/show.blade.php`
- Lessons: `context/foundation/lessons.md` (per-user isolation rule + AI fail-open / increment-before-dispatch rule)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend + view + button

#### Automated

- [x] 1.1 Linting / formatting passes: `vendor/bin/pint --test` — dfb89ab
- [x] 1.2 Existing test suite still green: `composer run test` — dfb89ab
- [x] 1.3 `php artisan route:list` shows the two new `steps.suggestions.*` routes under the `auth` middleware — dfb89ab

#### Manual

- [x] 1.4 Happy path keep-all: click "Suggest more with AI" → 7 candidates rendered, all checked → submit → 14 total steps; the 7 new have `source = ai_extension` (verified via tinker)
- [x] 1.5 Partial keep: same flow, uncheck 4 of 7 → submit → 3 new steps appended with contiguous positions after the previous max
- [x] 1.6 Keep-none: same flow, uncheck all 7 → submit → no new steps, no flash, silent return to `ventures.show`
- [x] 1.7 Empty-state CTA: a venture with 0 steps shows both "+ Add your first step" AND "Suggest more with AI"; the latter exercises the same flow end-to-end
- [x] 1.8 AI-unavailable flash: force `AiStepSuggester::suggestSteps()` to return `[]` → click "Suggest more with AI" → no preview, amber `ai_unavailable` notice on `ventures.show` with extension-specific wording

### Phase 2: Test matrix + cross-slice handoff

#### Automated

- [x] 2.1 All new feature tests pass: `composer run test` — a3a4340
- [x] 2.2 Linting / formatting passes: `vendor/bin/pint --test` — a3a4340
- [x] 2.3 `php artisan route:list` still shows the two `steps.suggestions.*` routes — a3a4340

#### Manual

- [x] 2.4 `docs/reference/contract-surfaces.md` "Step extension surface (S-03)" section is self-sufficient (a reader can implement against it without opening this plan) and the suggester-not-called assertion is clearly tied to NFR(ai-ceiling) per-user accounting
- [x] 2.5 `context/foundation/roadmap.md` S-03 row is `done` in the "At a glance" table, the slice block, and the Backlog Handoff column
