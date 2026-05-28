# S-01 / create-venture-with-ai-plan — Implementation Plan

## Overview

Add the first per-user domain model (`Venture`) and its child (`Step`), replace the placeholder `/dashboard` with a "create a venture" form, call F-02's `AiStepSuggester::suggestSteps()` inline on POST to seed exactly 7 `ai_initial` steps, and render those steps read-only on the venture detail view. AI failure must not block creation — the venture persists with an empty step list and a flash notice. This is the north-star slice: the validation milestone for the product thesis ("AI converts the blank page into a starting plan"). It is also the first slice to exercise the S-01 enforcement checklist in `docs/reference/contract-surfaces.md` against real code rather than docs.

## Current State Analysis

- **F-01 (auth + isolation contract)** is landed: email+password register / login / logout, `auth`-middleware boundary, the `owner_id` cascade-on-delete + relationship-only-access convention codified in `docs/reference/contract-surfaces.md` and `context/foundation/lessons.md`. No per-user **domain** table exists yet — `ai_call_counters` (F-02) is the only existing `owner_id` table, and it has no controller surface. S-01 is therefore the **first** slice to exercise the full enforcement checklist (FK + relationships + relationship-only controller access + two-user 404 test + ownership-scoped binding).
- **F-02 (AI service seam)** is landed: `app/Services/AiStepSuggester.php:13` exposes `suggestSteps(User, string, string, array = []): array` and returns exactly 7 strings on success or `[]` on every failure mode (timeout, provider error, malformed JSON, wrong count, ceiling reached). Counter increments BEFORE dispatch in its own transaction. S-01 is a plain consumer — it does not touch the counter, the provider, or the ceiling.
- **No domain tables yet.** `database/migrations/` contains only framework migrations + F-02's `ai_call_counters`. No `ventures`, no `steps`.
- **`/dashboard` is a placeholder.** `routes/web.php:22` returns `view('dashboard')`, which renders a "Welcome, {name}" panel. F-01's plan-brief explicitly tags this as "replaceable by S-01/S-04". It is the natural post-login landing surface for S-01's create form.
- **No frontend framework.** Blade + Tailwind v4 + Vite. `resources/views/layouts/app.blade.php` is the shell; `auth/register.blade.php` is the closest existing form pattern for styling reference.
- **No `Route::resource`-style controllers exist yet.** Auth controllers are thin (`AuthenticatedSessionController`, `RegisteredUserController`) — S-01's `VenturesController` should mirror that style (skinny actions delegating to FormRequests + relationship calls).
- **Scope cap (from roadmap):** S-01 ships **create + show only**. Edit / delete / toggle steps is S-02. Venture list / venture delete is S-04. AI extension is S-03. Deadlines are S-05. Expenses are S-06. The slice must not pre-build any of those surfaces.

## Desired End State

A logged-in user lands on `/dashboard` and sees a "Start a new venture" form with two fields: title (required) and description (optional). On submit the controller calls `AiStepSuggester::suggestSteps()` once, then persists a `Venture` row plus 0 or 7 child `Step` rows in a DB transaction, and redirects to `/ventures/{venture}` (the show view). The show view renders the venture title + description and the 7 steps (or an empty step list plus a flash notice "AI couldn't suggest steps right now — add some manually below" when the AI call returned `[]`). A second user visiting `/ventures/{first-user's-id}` gets a 404, never 403. Guests visiting any of these routes redirect to `/login`. The F-01 S-01 enforcement checklist is satisfied with executable proof (a two-user feature test) and the contract surface is documented for S-02 / S-03 / S-04 to inherit.

### Key Discoveries:

- `AiStepSuggester::suggestSteps()` already manages its counter in an inner `DB::transaction()` (`app/Services/AiStepSuggester.php:29`). If we wrap S-01's POST in an outer transaction, the AI counter increment would roll back on a venture-persistence failure — which is wrong (the AI attempt happened; it must count). The AI call therefore runs **outside** the outer transaction, before the Venture+Steps inserts.
- The F-01 lessons.md rule (`context/foundation/lessons.md:14`) is **structural**, not nominal: route-model binding via `findOrFail` on a bare model is a privacy bug regardless of any follow-up `where('owner_id', …)` clause. The controller MUST go through `$request->user()->ventures()->findOrFail($id)` for ownership scoping (chosen mechanism per Q7).
- F-01 contract-surfaces.md (S-01 enforcement checklist) demands a two-user 404 test — not 403, which would leak existence. The 6-test matrix below honours that.
- The PRD primary metric ("≥3 of 7 AI-initial steps kept") requires a stable AI-pool snapshot. The `steps.source` enum (`ai_initial` / `ai_extension` / `manual`) is the snapshot mechanism, landed now so S-03 doesn't need a backfill migration.
- Tailwind v4 + the existing `auth/register.blade.php` form structure is the styling reference for the create form; no new component primitives are needed.

## What We're NOT Doing

- **No step editing, no step delete, no step completion toggle, no manual-add-step UI.** That is S-02.
- **No venture list, no venture delete UI.** That is S-04. (We still need a `User::ventures()` relationship; S-04 will consume the same relationship for the list query.)
- **No AI extension trigger.** That is S-03. The `source` enum carries an `ai_extension` value because adding it later would force a backfill, but no code path writes that value in this slice.
- **No deadline field on steps.** Per Q2 deviation rejection — S-05 adds it via a single `alter` migration when the badge / list-marker surface lands together. No dead columns now.
- **No expense surface.** That is S-06.
- **No "regenerate AI" affordance** on failure or otherwise. PRD §Non-Goals explicitly excludes this. The AI runs exactly once at venture creation.
- **No password-reset, email-verification, or profile surface** — out of scope per F-01.
- **No global scope on `Venture`.** F-01's contract-surfaces.md says enforcement remains discipline-based + per-slice tests. Adding a global scope is its own contract change.
- **No new authorization layer (Policies).** Ownership is enforced by going through the user relationship. Policies graduate in v3+ co-editing per `docs/reference/contract-surfaces.md#v3-co-editing-multi-user-write-access`.

## Implementation Approach

Three phases, each ending in a verifiable gate.

1. **Schema + models + relationships.** Two migrations declaring the F-01 FK convention explicitly. `Venture` and `Step` Eloquent models with explicit FK-named relationships (`owner_id`, `venture_id`). `User::ventures()` `hasMany`. Tinker-verified cascade-on-delete proves the schema before any controller exists.
2. **Create-venture flow + read-only detail view.** `CreateVentureRequest` FormRequest. `VenturesController::store` orchestrates: (a) AI call **outside** transaction so failed-attempt counter persists; (b) inner `DB::transaction()` persists `Venture` + 0-or-7 `Step` rows; (c) flash notice on `[]` return; (d) redirect to `show`. `VenturesController::show` resolves the venture through the user relationship (`$request->user()->ventures()->findOrFail($id)`) and renders title + description + steps. `/dashboard` redirected to the new create surface; old `dashboard.blade.php` retired.
3. **Feature-test matrix + cross-slice handoff.** Six feature tests covering the US-01 acceptance criteria + the full S-01 enforcement checklist (including 404-not-403 isolation). `AiStepSuggester` mocked via container binding in tests — no real Groq calls in CI. `docs/reference/contract-surfaces.md` gets a new "Venture surface (S-01)" section. `context/foundation/roadmap.md` S-01 row flips to `done`. `change.md` flips to `implemented` and `updated:` is bumped.

## Critical Implementation Details

- **AI call sits OUTSIDE the outer transaction.** `AiStepSuggester::suggestSteps()` runs its own `DB::transaction()` for the counter increment. If S-01 wraps the POST in `DB::transaction(fn() => […AI call + persist…])`, a venture-persistence failure would roll back the counter — corrupting NFR(ai-ceiling) accounting. Correct order: validate → call `suggestSteps()` → enter `DB::transaction()` for `Venture::create()` + bulk `Step` inserts → exit transaction → set flash if `$steps === []` → redirect.
- **Controller resolves the venture via the user relationship.** No `Route::model('venture', Venture::class)`, no `Route::bind()`, no global scope. The controller signature is `show(Request $request, int $venture)` and the body opens with `$model = $request->user()->ventures()->findOrFail($venture);`. This is the mechanism that produces 404-not-403 automatically (the scoped query simply finds no row).
- **Description-optional path.** Form posts `description` as `nullable|string|max:2000`. Controller coerces null to empty string via `$request->string('description')->toString()` before passing to `suggestSteps()` (signature requires `string`, not `?string`). The empty-string case is documented in the plan as a known success-metric risk (AI quality on title-only ventures is lower; mitigation is the "richer descriptions yield better suggestions" UX hint, mirroring `auth/register.blade.php`'s hint pattern).

## Phase 1: Schema, models, and the User↔Venture↔Step relationship chain

### Overview

Land the two tables and their Eloquent models. Prove the FK cascade chain with a tinker check before any HTTP surface exists. No controllers, no routes, no views in this phase.

### Changes Required:

#### 1. `ventures` migration

**File**: `database/migrations/2026_05_28_xxxxxx_create_ventures_table.php`

**Intent**: Create the `ventures` table satisfying the F-01 S-01 enforcement checklist item 1 (explicit `'users'` constraint, cascade-on-delete on `owner_id`).

**Contract**: Columns: `id`, `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`, `string('title', 120)`, `text('description')->nullable()`, `timestamps()`. The explicit `'users'` argument to `constrained()` is required because Laravel's column→table inference would otherwise look up `owners`.

#### 2. `steps` migration

**File**: `database/migrations/2026_05_28_xxxxxx_create_steps_table.php`

**Intent**: Create the `steps` table with the venture FK + the duplicate `owner_id` FK (per the F-01 convention "every per-user domain table carries `owner_id`") and the `source` enum that anchors the FR-008 AI-pool snapshot.

**Contract**: Columns: `id`, `foreignId('venture_id')->constrained()->cascadeOnDelete()`, `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`, `string('body', 200)`, `boolean('is_completed')->default(false)`, `string('source')` (values `ai_initial` / `ai_extension` / `manual` — string-typed not native-enum for SQLite/Postgres portability; values constrained at the model level), `unsignedInteger('position')->default(0)`, `timestamps()`. Index on `(venture_id, position)` to support ordered list queries cheaply.

#### 3. `Venture` model

**File**: `app/Models/Venture.php`

**Intent**: Eloquent model with the inverse-of-ownership relationship (`owner()`) and the child-collection relationship (`steps()`), both with explicit FK names to match the schema.

**Contract**: `extends Model`. `#[Fillable(['title', 'description'])]` — no `owner_id` in fillable because controllers create through `$user->ventures()->create()` which sets it automatically. Relationships: `owner(): BelongsTo` → `belongsTo(User::class, 'owner_id')`; `steps(): HasMany` → `hasMany(Step::class)->orderBy('position')`.

#### 4. `Step` model

**File**: `app/Models/Step.php`

**Intent**: Step Eloquent model with both parent relationships and the source enum constants.

**Contract**: `extends Model`. `#[Fillable(['body', 'is_completed', 'source', 'position'])]`. `protected $casts = ['is_completed' => 'boolean']`. Class constants `SOURCE_AI_INITIAL = 'ai_initial'`, `SOURCE_AI_EXTENSION = 'ai_extension'`, `SOURCE_MANUAL = 'manual'`. Relationships: `venture(): BelongsTo`; `owner(): BelongsTo` → `belongsTo(User::class, 'owner_id')`.

#### 5. `User::ventures()` relationship

**File**: `app/Models/User.php`

**Intent**: Add the `hasMany` that controllers will reach into. Explicit FK name because the column is `owner_id`, not Laravel's default `user_id`.

**Contract**: `public function ventures(): HasMany { return $this->hasMany(Venture::class, 'owner_id'); }`. Add the `use App\Models\Venture;` import.

### Success Criteria:

#### Automated Verification:

- Migrations apply cleanly: `php artisan migrate:fresh`
- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test`

#### Manual Verification:

- Tinker cascade smoke: `php artisan tinker` →
  ```
  $u = User::factory()->create();
  $v = $u->ventures()->create(['title' => 't', 'description' => 'd']);
  $v->steps()->create(['owner_id' => $u->id, 'body' => 's', 'source' => Step::SOURCE_MANUAL, 'position' => 0]);
  $u->delete();
  Venture::count(); // 0
  Step::count();    // 0
  ```

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the tinker cascade test was successful before proceeding to the next phase.

---

## Phase 2: Create-venture flow + read-only detail view

### Overview

Replace the `/dashboard` placeholder with the create form. POST persists Venture + 0-or-7 Steps via the AI service. Detail view renders title, description, and the step list. AI failure is surfaced as a flash notice on the detail view, never as a 500. No edit / delete / toggle in this phase (that's S-02).

### Changes Required:

#### 1. `CreateVentureRequest` FormRequest

**File**: `app/Http/Requests/Ventures/CreateVentureRequest.php`

**Intent**: Centralize the create-form validation per Q5: title required + bounded, description optional + bounded. Returns 422 with field-level errors on failure.

**Contract**: `extends FormRequest`. `authorize(): bool { return true; }` (the `auth` middleware is the boundary). `rules(): array` returns `['title' => ['required', 'string', 'min:1', 'max:120'], 'description' => ['nullable', 'string', 'max:2000']]`.

#### 2. `VenturesController`

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: Two skinny actions — `store` for POST /ventures, `show` for GET /ventures/{venture}. Both go through `$request->user()->ventures()` per the F-01 lessons.md rule. Store calls `AiStepSuggester` outside the persistence transaction (counter must survive a Venture-insert failure).

**Contract**: Constructor injects `AiStepSuggester $suggester`. `store(CreateVentureRequest $request): RedirectResponse`: extract `$title = $request->string('title')->toString()`, `$description = $request->string('description')->toString()` (coerces null→''); call `$steps = $this->suggester->suggestSteps($request->user(), $title, $description)`; then `DB::transaction(function () use (…) { $venture = $request->user()->ventures()->create(['title' => $title, 'description' => $description ?: null]); foreach ($steps as $i => $body) { $venture->steps()->create(['owner_id' => $request->user()->id, 'body' => $body, 'source' => Step::SOURCE_AI_INITIAL, 'position' => $i]); } });` then `if (empty($steps)) { session()->flash('ai_unavailable', 'AI couldn\'t suggest steps right now — you can add them manually.'); }`; `return redirect()->route('ventures.show', $venture);`. `show(Request $request, int $venture): View`: `$model = $request->user()->ventures()->with('steps')->findOrFail($venture); return view('ventures.show', ['venture' => $model]);`.

#### 3. Route changes

**File**: `routes/web.php`

**Intent**: Mount the venture surface under the existing `auth` middleware group. Repoint `/dashboard` to the create form (the simplest way is to redirect the route name to `ventures.create` so any existing `route('dashboard')` callers — F-01's login + register — keep working without an edit).

**Contract**: Inside the `Route::middleware('auth')->group(...)` block, add: `Route::get('/dashboard', [VenturesController::class, 'create'])->name('dashboard');` (replaces the inline closure); `Route::get('/ventures/create', [VenturesController::class, 'create'])->name('ventures.create');` (canonical name; both route names point at the same handler so when S-04 lands and moves the dashboard concept to the list view, only the `dashboard` name reassigns); `Route::post('/ventures', [VenturesController::class, 'store'])->name('ventures.store');`; `Route::get('/ventures/{venture}', [VenturesController::class, 'show'])->whereNumber('venture')->name('ventures.show');`. Add a `create(): View` action on `VenturesController` that returns `view('ventures.create')`.

#### 4. Create view

**File**: `resources/views/ventures/create.blade.php`

**Intent**: Title + description form mirroring the existing `auth/register.blade.php` styling. POST to `route('ventures.store')`. Renders `@error` for both fields. UX hint near the description field signals the FR-004 commitment ("Richer descriptions yield better step suggestions"); title field uses `autofocus`.

**Contract**: Extends `layouts.app`. Form with `@csrf`, two field blocks (text input for `title`, textarea rows=4 for `description`), a submit button styled to match the dark-button pattern in `auth/register.blade.php`.

#### 5. Show view

**File**: `resources/views/ventures/show.blade.php`

**Intent**: Read-only render of the venture and its steps. If a `session('ai_unavailable')` flash is present, render it above the steps as a non-blocking notice (yellow / amber Tailwind utility classes — never red, since this is not an error). Step list iterates `$venture->steps` and shows the body as plain text; no checkbox, no edit button — those are S-02.

**Contract**: Extends `layouts.app`. Header block shows venture title. Body shows description (or an em-dash if null) and a `<ul>` of step bodies in `position` order. Empty step list renders an empty-state placeholder line ("No steps yet."). The S-02 / S-04 surfaces (toggle, edit, delete, list, navigate-home) are intentionally absent.

#### 6. Retire the old dashboard view

**File**: `resources/views/dashboard.blade.php`

**Intent**: Delete. The `dashboard` route now points at `VenturesController::create`, so the placeholder Blade file is unreachable.

**Contract**: File removed.

### Success Criteria:

#### Automated Verification:

- `php artisan route:list` shows `dashboard`, `ventures.create`, `ventures.store`, `ventures.show` under the `auth` middleware
- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test`

#### Manual Verification:

- Register a fresh user → /dashboard renders the create form
- Submit a venture with title "learn welding" + description "MIG and TIG basics" → land on /ventures/{id} showing 7 steps
- Submit a title-only venture (no description) → same flow; venture exists with 0 or 7 steps depending on AI behaviour

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the browser happy-path verification was successful before proceeding to the next phase.

---

## Phase 3: Feature-test matrix + cross-slice handoff

### Overview

Six feature tests (the Q6 matrix), the `contract-surfaces.md` "Venture surface (S-01)" section, the roadmap status flip to `done`, and the `change.md` flip to `implemented`. Tests use a fake binding for `AiStepSuggester` — no Groq calls in CI.

### Changes Required:

#### 1. `tests/Feature/Ventures/CreateVentureTest.php`

**File**: `tests/Feature/Ventures/CreateVentureTest.php`

**Intent**: Cover the four user-facing acceptance criteria for US-01 — happy path, AI fail, validation, guest boundary — using a container-bound fake for `AiStepSuggester`.

**Contract**: Four `#[Test]` methods. (a) `creating_a_venture_persists_seven_ai_initial_steps_on_success` — binds a fake suggester returning a fixed 7-string array, POSTs as an authenticated user, asserts `Venture` count 1, `Step` count 7 all `source = ai_initial` with `position` 0–6, asserts redirect to `ventures.show`. (b) `creating_a_venture_succeeds_with_empty_steps_on_ai_failure` — binds a fake suggester returning `[]`, POSTs, asserts venture persists with 0 steps, asserts flash `ai_unavailable` is set, asserts redirect succeeds (no 500). (c) `validation_fails_when_title_is_missing` — POST without title returns 422-ish (Laravel session-redirects with errors); asserts `errors->has('title')`. (d) `guest_post_redirects_to_login` — unauthenticated POST → 302 to `/login`.

#### 2. `tests/Feature/Ventures/VentureIsolationTest.php`

**File**: `tests/Feature/Ventures/VentureIsolationTest.php`

**Intent**: Prove the F-01 S-01 enforcement checklist item 4 (two-user 404 not 403) and item 5 (ownership-scoped binding) with executable tests.

**Contract**: Two `#[Test]` methods. (a) `user_b_gets_404_on_user_a_venture_show` — create users A and B (factories), create venture as A, authenticate as B, GET `/ventures/{A's venture id}` returns **404 (not 403)**. (b) `guest_gets_redirect_on_venture_show` — GET `/ventures/{any id}` while unauthenticated returns 302 to `/login` (re-confirms the `auth` boundary on the show route). Together with the store-side guest test in `CreateVentureTest`, this covers the full S-01 checklist for the new surface.

#### 3. `docs/reference/contract-surfaces.md` — Venture surface (S-01)

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Add an "## Venture surface (S-01)" section after the AI suggestion surface. Document the seam, the access path, and the test that proves the isolation rule for downstream slices (S-02 / S-03 / S-04 / S-05 / S-06 all reuse this surface).

**Contract**: A new section listing: established by S-01; PRD anchors (US-01, FR-004, FR-008, FR-006); routes (`dashboard`, `ventures.create`, `ventures.store`, `ventures.show`); access path rule (`$request->user()->ventures()->...` — explicit re-statement of the F-01 lessons.md rule realized in code); step `source` enum values and what each means for the primary-metric AI pool; the two-user 404 test as the proof artifact. Update the S-01 enforcement checklist section to mark the items as satisfied with file:line references to the migration, the model relationships, the controller, and the two tests.

#### 4. Roadmap status flip + `change.md` flip

**File**: `context/foundation/roadmap.md`, `context/changes/create-venture-with-ai-plan/change.md`

**Intent**: Reflect the completed slice. Roadmap "At a glance" table row for S-01 flips `Status: proposed` → `Status: done`. The `### S-01: Create a venture with an AI plan` block's `Status:` line flips the same way. Backlog Handoff row's `Ready for /10x-plan` flips to `done`. `change.md` frontmatter: `status: planned` → `status: implemented`, `updated: 2026-05-28` bumped to the actual implementation date.

**Contract**: Markdown edits only. No code change. Mirror the pattern F-01 and F-02 used when they flipped to `ready` (already in the roadmap).

### Success Criteria:

#### Automated Verification:

- All six feature tests pass: `composer run test`
- Linting / formatting passes: `vendor/bin/pint --test`
- `php artisan route:list` shows exactly the four `ventures.*` + `dashboard` routes added by this slice (no unintended additions)

#### Manual Verification:

- `docs/reference/contract-surfaces.md` reads sensibly: the new section has working file:line references, the S-01 checklist items are crossed off with proof links, and a reader landing on the file can locate the access-path rule without reading the F-01 section twice
- `context/foundation/roadmap.md` S-01 row is `done` in the table and the slice block

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the contract-surfaces and roadmap edits read sensibly before considering the slice complete.

---

## Testing Strategy

### Unit Tests:

None — this slice has no pure-logic seams worth a unit test. The `source` enum lives on a Laravel model and is exercised by the feature happy-path test.

### Integration Tests (Feature tests):

The six tests above. AI is faked at the container boundary (`$this->app->bind(AiStepSuggester::class, …)`) — never hits Groq in CI. No queue used (sync path).

### Manual Testing Steps:

1. Register a fresh user → land on `/dashboard` and see the create form (not the old "Welcome, {name}" panel)
2. Submit title "throw a 30-person birthday party" + a paragraph description → land on `/ventures/{id}` with 7 numbered (by position) steps
3. Submit title only (no description) → same flow with the same 7-step result (slightly lower-quality steps)

(AI-fail and isolation paths are covered by automated tests per Q8.)

## Performance Considerations

- POST blocks on the AI call (sync). `AiStepSuggester`'s 15-second HTTP timeout is the hard cap; Groq typical latency is sub-second. Render's request idle ceiling sits well above 15s.
- Bulk inserting 7 step rows is cheap; doing it inside a transaction protects against the partial-write case where 3 of 7 steps land and the 4th throws (no zombie partial step lists).
- The `(venture_id, position)` index keeps the `show` page's `orderBy('position')` query at index-scan cost as ventures accumulate (will matter once S-02 adds more steps).

## Migration Notes

- Two new tables (`ventures`, `steps`). The `dashboard.blade.php` view is deleted; the `dashboard` route name is preserved (now pointing at `ventures.create`). No data migration required (no production data yet).

## References

- F-01 contract: `docs/reference/contract-surfaces.md` (per-user-isolation rules + S-01 enforcement checklist)
- F-02 plan + brief: `context/changes/ai-suggestion-service/plan.md`, `context/changes/ai-suggestion-service/plan-brief.md`
- F-02 service implementation: `app/Services/AiStepSuggester.php:13`
- Existing form-style reference: `resources/views/auth/register.blade.php`
- Lessons: `context/foundation/lessons.md` (per-user isolation + AI fail-open rules)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Schema, models, and the User↔Venture↔Step relationship chain

#### Automated

- [x] 1.1 Migrations apply cleanly: `php artisan migrate:fresh` — 2775a36
- [x] 1.2 Linting / formatting passes: `vendor/bin/pint --test` — 2775a36
- [x] 1.3 Existing test suite still green: `composer run test` — 2775a36

#### Manual

- [x] 1.4 Tinker cascade smoke confirms `Venture::count() === 0` and `Step::count() === 0` after deleting the parent user — 2775a36

### Phase 2: Create-venture flow + read-only detail view

#### Automated

- [x] 2.1 `php artisan route:list` shows `dashboard`, `ventures.create`, `ventures.store`, `ventures.show` under the `auth` middleware — 1499752
- [x] 2.2 Linting / formatting passes: `vendor/bin/pint --test` — 1499752
- [x] 2.3 Existing test suite still green: `composer run test` — 1499752

#### Manual

- [x] 2.4 Register a fresh user → `/dashboard` renders the create form — 1499752
- [x] 2.5 Submit a venture with title "learn welding" + description "MIG and TIG basics" → land on `/ventures/{id}` showing 7 steps — 1499752
- [x] 2.6 Submit a title-only venture (no description) → same flow; venture exists with 0 or 7 steps depending on AI behaviour — 1499752

### Phase 3: Feature-test matrix + cross-slice handoff

#### Automated

- [x] 3.1 All six feature tests pass: `composer run test` — 00cdcf6
- [x] 3.2 Linting / formatting passes: `vendor/bin/pint --test` — 00cdcf6
- [x] 3.3 `php artisan route:list` shows exactly the four `ventures.*` + `dashboard` routes added by this slice (no unintended additions) — 00cdcf6

#### Manual

- [x] 3.4 `docs/reference/contract-surfaces.md` "Venture surface (S-01)" reads sensibly with working file:line references and S-01 checklist items crossed off — 00cdcf6
- [x] 3.5 `context/foundation/roadmap.md` S-01 row is `done` in the table and the slice block — 00cdcf6
