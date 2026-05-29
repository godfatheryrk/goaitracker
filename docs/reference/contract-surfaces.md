# Contract Surfaces

> Load-bearing names and rules established by completed changes. Edits here are intentional contract changes — they ripple through every later slice that follows the rule. When a slice violates a rule, the slice changes, not the rule.

## Per-user data isolation

**Established by**: F-01 (`context/changes/minimal-auth-and-isolation/`).

**PRD anchors**: Access Control, NFR(isolation) — _"a user is never able to observe another user's ventures, steps, expenses, deadlines, or any other per-user data via any product surface."_

**Why it is load-bearing**: Wrong scoping here is a privacy incident regardless of feature correctness. Every slice that adds per-user data (S-01 onward) inherits these rules; they are the project's privacy floor.

### Rules

1. **`owner_id` foreign-key convention.** Every per-user domain table MUST carry an `owner_id` column declared as a foreign key to `users.id` with `onDelete('cascade')`. Naming is deliberate: `owner_id` means _"the user who created this row and has unrestricted access to it"_ — semantically distinct from `user_id`, which in pivot / membership contexts means _"any user with some level of access"_ (reserved for future use; see _Future evolution_). Declare the FK in the create migration, not a follow-up `alter`. Because Laravel infers the referenced table from the column prefix (`owner_id` → `owners`), the constraint MUST be written explicitly: `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`.

2. **Relationship-only access in authenticated controllers.** Authenticated controllers MUST reach per-user domain data through a relationship method on the authenticated user — today `$request->user()->ventures()`, `$request->user()->expenses()`, etc. A bare global query on a per-user model (`Venture::find(...)`, `Venture::where(...)`) is a privacy bug, even if a `where('owner_id', ...)` clause follows. The rule is **structural** ("via a method on `$request->user()`") rather than **nominal** ("via a method named `ventures()`"): the specific method name may evolve as the data model grows (e.g. an `accessibleVentures()` union of owned + shared in v3+ — see _Future evolution_), but going through the authenticated user is fixed. The audit pattern is _"did the controller go through `$request->user()->...`?"_.

3. **Ownership-scoped route-model binding.** When binding an owned model into a route parameter (`Route::get('/ventures/{venture}', ...)`), the binding MUST be ownership-scoped — either via explicit child binding (`Route::scopeBindings()` with a nested-resource shape), an explicit `Route::bind()` that filters by `$request->user()`, or by resolving the model inside the controller via the user relationship and rejecting a miss with 404. A global `findOrFail` on the route parameter leaks existence of other users' rows (404 vs 403 distinguishability) and is forbidden.

4. **The `auth` middleware is the authentication boundary.** Every per-user route MUST sit behind `auth` middleware. Unauthenticated requests to a gated route redirect to `login`. There is no second authentication boundary downstream — domain controllers assume `$request->user()` is non-null because the middleware guarantees it. (Public-by-design routes — e.g. the v2 read-only share-link route — are a separate access category that does not run under `auth` and does not consult `$request->user()`; those are not "per-user routes" in the sense of this rule.)

### What is NOT enforced by code yet

F-01 establishes the convention but does NOT install a global scope, trait, or runtime check that enforces it. The discipline is documentation + tests on each slice. The deferred enforcement proof lands in S-01 (the first domain model) per the checklist below.

---

## S-01 enforcement checklist

When S-01 (or any slice introducing the first / a new per-user domain model — Venture, Step, Expense) adds a model, the slice's plan MUST satisfy all of the following before it can merge:

- [x] **Schema**: the new table's create migration declares `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` — explicit `'users'` because the column→table inference would otherwise look up `owners`. _Satisfied by S-01_: `database/migrations/2026_05_28_210000_create_ventures_table.php:13`, `database/migrations/2026_05_28_210001_create_steps_table.php:14`.
- [x] **Model relationships**: `User` declares `hasMany(Venture::class, 'owner_id')` named to match the domain (`ventures()`, `expenses()`, …), and the new model declares the inverse `belongsTo(User::class, 'owner_id')` (typically named `owner()`). The explicit FK name is required because the column is `owner_id`, not the `user_id` Laravel would assume by default. _Satisfied by S-01_: `app/Models/User.php:39` (`ventures()`), `app/Models/Venture.php:13` (`owner()`), `app/Models/Step.php:27` (`owner()`).
- [x] **Controller access path**: every authenticated controller action that reads/writes the new model goes through `$request->user()->ventures()` (or equivalent). No bare `Venture::find(...)` / `Venture::where(...)` in authenticated controllers. _Satisfied by S-01_: `app/Http/Controllers/VenturesController.php` — `store` writes via `$user->ventures()->create()` + `$venture->steps()->create()`; `show` reads via `$request->user()->ventures()->with('steps')->findOrFail(...)`.
- [x] **Two-user isolation feature test**: a feature test creates two users (A and B), creates a venture as A, and asserts user B cannot read, update, or delete it — expecting **404** from the relationship-scoped path (not 403, which would leak existence). The test also asserts a guest gets a 302 to `/login` (re-confirms the `auth` boundary). _Satisfied by S-01_: `tests/Feature/Ventures/VentureIsolationTest.php` (`test_user_b_gets_404_on_user_a_venture_show`, `test_guest_gets_redirect_on_venture_show`) plus `tests/Feature/Ventures/CreateVentureTest.php::test_guest_post_redirects_to_login` for the store-side boundary.
- [x] **Ownership-scoped route-model binding**: routes that take the new model as a parameter resolve it through the user relationship, not via a global `findOrFail`. The two-user test exercises this surface. _Satisfied by S-01_: `routes/web.php` registers `ventures/{venture}` with `whereNumber('venture')` as a plain integer parameter; `VenturesController::show` resolves it via `$request->user()->ventures()->...->findOrFail(...)` (no global `findOrFail`, no model binding on the route).
- [x] **Plan reference**: the slice's plan links to this section and notes each item explicitly in its Progress block. _Satisfied by S-01_: `context/changes/create-venture-with-ai-plan/plan.md` (Phase 1 + Phase 2 + Phase 3 changes reference this checklist by item number).

If any item is missing when S-01 reaches `/10x-plan-review`, the reviewer MUST block on it. The cost of catching a cross-user leak in F-01's downstream slice is cheap; the cost of catching it in production after multiple ventures and expenses have flowed through the wrong path is not.

---

## Authentication surface (F-01)

**Established by**: F-01.

- **Routes**: `register` (GET/POST), `login` (GET/POST), `logout` (POST), `dashboard` (GET). Named exactly. The `login` route name is referenced by the `auth` middleware redirect, the `LoginRequest` throttle response, and downstream slice tests — do not rename without a coordinated update. The `dashboard` route name is the canonical post-login landing target referenced by `RegisteredUserController::store` and `LoginRequest` via `redirect()->intended(route('dashboard'))`; S-04 reassigned its underlying action from the create form to the venture list (`VenturesController@index`) while preserving the route name and the `/dashboard` URL.
- **Login throttle**: 5 failed attempts per `Str::lower(email)|ip` key, 60s decay; success clears the limiter. Mirrors Laravel Breeze's `LoginRequest` contract.
- **Session security**: regenerate on login, invalidate + regenerate token on logout.
- **Derived `name`**: registration derives `users.name` from the email local-part (portion before `@`). The `users.name` column remains NOT NULL; no UI collects it.
- **Out of scope (intentionally)**: password reset, email verification, password confirmation, profile management, OAuth/magic-link/SSO. The `password_reset_tokens` table exists but is unused.

---

## AI suggestion surface (F-02)

**Established by**: F-02 (`context/changes/ai-suggestion-service/`).

**PRD anchors**: FR-008, FR-009, NFR(ai-ceiling), NFR(ai-graceful).

### Public seam

```php
app(\App\Services\AiStepSuggester::class)
    ->suggestSteps(User $user, string $title, string $description, array $currentSteps = []): array
```

Resolve via the container (singleton-bound in `AppServiceProvider`). Callers do not instantiate directly.

### Return contract

- **Success**: exactly 7 `string` elements, each 1–200 characters. The array is ordered as the model returned it; callers MAY reorder for display.
- **Any failure**: empty array `[]`. Failure modes include: network timeout, HTTP 4xx/5xx from the provider, malformed/non-JSON response, wrong step count, over-quota, and any other `\Throwable`. The empty-array contract is unconditional — provider exceptions never cross this seam.
- Callers MUST treat `[]` as "AI unavailable, proceed manually." They MUST NOT surface the failure as an error to the end user (NFR(ai-graceful)).

### Length cap coupling

The 200-character upper bound is enforced inside `AiStepSuggester` via `config('ai.step_suggestion.max_step_length')` (default 200). The S-01 `steps.body` column is `string('body', 200)` to match. **If `max_step_length` changes, the `steps.body` column MUST move in lockstep** — Postgres would otherwise throw on insert AFTER the counter increment, breaking the NFR(ai-graceful) "`[]` = unavailable" contract (the AI call succeeded but persistence threw, which is not a failure mode the service or its callers handle).

### Rate-limit counter table

Table: `ai_call_counters(id, owner_id, day, count, created_at, updated_at)`

- `owner_id` FK → `users.id` cascade-on-delete (F-01 convention).
- Unique index on `(owner_id, day)`.
- Counter is incremented **BEFORE** the provider call (attempt-based). A call that fails still costs one unit toward the ceiling — this prevents a misconfigured key from issuing unlimited requests.
- **Ceiling**: `config('ai.step_suggestion.ceiling_per_day')` (default 20) calls per user per UTC calendar day.
- When the ceiling is reached, `suggestSteps()` returns `[]` immediately without dispatching the agent. The counter is NOT incremented further for ceiling-blocked calls.

### Sync-execution constraint

The AI call runs synchronously on the web request thread. Render's free tier has no Background Worker, so this is a deployment property, not a choice. The NFR(edit-latency) "≤1s perceived feedback" does NOT apply to the AI path — the 15-second HTTP timeout is the hard cap. Future v2 background-worker migration hides behind the same `suggestSteps()` seam without a signature change.

### Provider configuration

Provider and model are env-driven:
- `AI_PROVIDER` (default `groq`) → `config('ai.default')`
- `AI_API_KEY` → `config('ai.providers.<provider>.key')`
- `AI_MODEL` (default `llama-3.3-70b-versatile`) → `config('ai.step_suggestion.model')`

Swapping providers is an env-only change. Note: the provider must support plain JSON-text generation — the service does NOT use structured-output (`json_schema`) response format because not all provider models support it.

---

## Venture surface (S-01)

**Established by**: S-01 (`context/changes/create-venture-with-ai-plan/`).

**PRD anchors**: US-01, FR-004 (create venture from title + description), FR-006 (single-venture unified view), FR-008 (exactly 7 AI-initial steps), NFR(isolation), NFR(ai-graceful).

### Routes (all under the `auth` middleware)

- `dashboard` (GET) — post-login landing surface. **S-04 has reassigned the `dashboard` route name to `VenturesController@index` (the venture list)**; the `/dashboard` URL persists for the F-01 intended-redirect contract (`RegisteredUserController::store`, `LoginRequest`), and the route name keeps working for nav links without a per-callsite edit. See [Venture list + destroy surface (S-04)](#venture-list--destroy-surface-s-04) for the new landing behaviour.
- `ventures.create` (GET `/ventures/create`) — canonical name for the create form. Reached from the venture list's "+ New venture" / empty-state CTA; no longer aliased to `dashboard`.
- `ventures.store` (POST `/ventures`) — validates `CreateVentureRequest`, calls `AiStepSuggester::suggestSteps()` OUTSIDE a DB transaction (so a failed venture-insert cannot roll back the counter increment for an AI attempt that really happened), then persists `Venture` + 0-or-7 `Step` rows inside a transaction, then redirects to `ventures.show`.
- `ventures.show` (GET `/ventures/{venture}`, `whereNumber('venture')`) — read-only render of title, description, and step list in `position` order. AI-unavailable flash notice renders above the step list when `session('ai_unavailable')` is set.

### Access path rule (realization of the F-01 contract)

Every authenticated controller action on the venture surface MUST reach the model through `$request->user()->ventures()`. The `show` action resolves `int $venture` via `$request->user()->ventures()->with('steps')->findOrFail($venture)` — this is the mechanism that produces 404 (not 403) for a foreign user, because the relationship-scoped query simply finds no row. No global `findOrFail(Venture::class)`, no `Route::bind('venture', …)`, no global scope on the model. The same rule applies to S-02 / S-03 / S-04 / S-05 / S-06 when they extend this surface.

### Step `source` enum (FR-008 primary-metric AI pool snapshot)

The `steps.source` column is a string with three valid values, declared as a backed PHP enum `App\Enums\StepSource` and cast on the `Step` model via `protected $casts = ['source' => StepSource::class]`. Writes use the enum case (typed at the call site); reads return the enum instance:

- `StepSource::AiInitial` (`'ai_initial'`) — written by `VenturesController::store` for each of the 7 steps that `AiStepSuggester::suggestSteps()` returned at venture creation. **This is the frozen denominator for the PRD primary metric** ("≥3 of 7 AI-initial steps kept, verbatim or edited"). S-02's edit / delete must preserve this value across edits; S-04's venture delete cascades the rows so the snapshot disappears with its parent.
- `StepSource::AiExtension` (`'ai_extension'`) — reserved for S-03 (AI-extend-on-demand). No code path in S-01 writes this value. Steps added via the FR-009 extension trigger carry this source and are excluded from the FR-008 metric.
- `StepSource::Manual` (`'manual'`) — reserved for S-02 (manual add-step). No code path in S-01 writes this value. Excluded from the FR-008 metric.

The column itself is string-typed (not a native DB enum) for SQLite/Postgres portability; the `StepSource` backed enum + the model cast are the source of truth for valid values, so any caller passing an unknown string fails the type system at the seam.

### AI failure contract (NFR(ai-graceful) realization)

`AiStepSuggester::suggestSteps()` returns `[]` on every failure mode. The store action treats `[]` as "AI unavailable, proceed manually": the venture is still persisted (with 0 steps) and a flash key `ai_unavailable` carrying the user-facing message is set on the session, surfaced by the show view as a non-blocking amber notice (never red — it is not an error). There is no 500, no error banner, no retry affordance.

### Test proof

- `tests/Feature/Ventures/CreateVentureTest.php` — 4 tests covering happy path (7 `ai_initial` steps persisted with `position` 0–6), AI failure (0 steps + flash), validation (missing title → 422 + zero ventures), and guest boundary (POST → 302 to `/login`). The `AiStepSuggester` is faked via `$this->mock(AiStepSuggester::class, …)` — no Groq calls in CI.
- `tests/Feature/Ventures/VentureIsolationTest.php` — 2 tests proving the F-01 S-01 enforcement checklist items 4 + 5 (two-user 404, guest 302 on the show route).

### Out of scope for S-01 (deferred to downstream slices)

- Step edit / delete / completion toggle / manual add → **S-02**.
- AI extension trigger (`FR-009`) → **S-03**.
- Venture list / venture delete UI → **S-04** — see [Venture list + destroy surface (S-04)](#venture-list--destroy-surface-s-04).
- Deadlines on steps + the 3-day badge / list-level marker → **S-05**.
- Expenses → **S-06**.

S-01 deliberately does not pre-build any of those surfaces; downstream slices add them under the same access-path rule.

---

## Step surface (S-02)

**Established by**: S-02 (`context/changes/edit-and-track-steps/`).

**PRD anchors**: FR-010 (manual add), FR-011 (edit body), FR-012 (delete with confirmation), FR-013 (toggle completion directly on the list — no confirmation), FR-018 (proportion of completed vs total), NFR(edit-latency).

### Routes (all under the `auth` middleware, nested under `ventures/{venture}`)

- `steps.create` (GET `/ventures/{venture}/steps/create`, `whereNumber('venture')`) — render the add-step form bound to the venture.
- `steps.store` (POST `/ventures/{venture}/steps`, `whereNumber('venture')`) — validate `CreateStepRequest`, persist one `manual` step appended to the list (`max(position) + 1`), redirect to `ventures.show`.
- `steps.edit` (GET `/ventures/{venture}/steps/{step}/edit`, both params `whereNumber`) — render the edit form pre-populated with the step's current body.
- `steps.update` (PATCH `/ventures/{venture}/steps/{step}`, both params `whereNumber`) — validate `EditStepRequest`, write `body` only, redirect to `ventures.show`.
- `steps.destroy` (DELETE `/ventures/{venture}/steps/{step}`, both params `whereNumber`) — delete the step row, redirect to `ventures.show`. Confirmation is the frontend's job (native `confirm()` in `ventures.show`).
- `steps.completion` (PATCH `/ventures/{venture}/steps/{step}/completion`, both params `whereNumber`) — flip `is_completed`. Two response shapes (see _Toggle endpoint response contract_ below).

### Access path rule (realization of the F-01 contract on a nested resource)

Every authenticated action on the step surface MUST resolve both the venture and the step through the doubly-scoped relationship chain:

```php
$ventureModel = $request->user()->ventures()->findOrFail($venture);
$stepModel = $ventureModel->steps()->findOrFail($step);
```

This produces **404 (not 403)** for either a foreign venture OR a foreign step under an owned venture — the relationship-scoped query simply finds no row, so the user cannot distinguish "exists but forbidden" from "does not exist." No global `findOrFail(Step::class)`, no `Route::bind('step', …)`, no global scope on the `Step` model. The same rule applies to S-03 / S-05 when they extend this surface (e.g. S-03's AI-extension trigger nests on the same `ventures/{venture}` parent).

### Source-immutability invariant (FR-008 metric depends on this)

The `steps.source` column carries the frozen FR-008 primary-metric AI-pool snapshot at venture creation. Any code path that lets an edit overwrite `source` corrupts the metric for every venture from that point forward. Defense in depth is the discipline:

- **Layer 1 — `Step::$fillable = ['body', 'position']`.** `source` is NOT mass-assignable. `->update($input)` or `->fill($input)` cannot rewrite it. `is_completed` is also absent from `$fillable` (toggle is the only writer; see below).
- **Layer 2 — `EditStepRequest` whitelists `body` only.** Even if a malicious client sends `source: manual` in the request body, the `validated()` array passed to `->update()` contains only `body`. The two layers stack: removing either still leaves the other as a backstop.

Writes that genuinely need to set `source` (the AI-initial seed loop in `VenturesController::store`, the manual-add path in `StepsController::store`) use `->forceFill(['source' => StepSource::AiInitial])` or `['source' => StepSource::Manual]` — forcing the assignment is explicit and audited, not accidental.

### Toggle endpoint response contract

`StepsController::toggleCompletion` returns two response shapes depending on `Accept`:

- `Accept: application/json` → **200 JSON** `{is_completed: bool, completed: int, total: int}`. The JS island consumes this to update the checkbox state, the body's `line-through` styling, and the progress text — all without a page reload (NFR(edit-latency) "≤1s perceived feedback" for FR-013).
- Otherwise → **302 redirect** to `ventures.show`. This is the `<noscript>` Save-button fallback path — when JS is off, the form submits normally and the redirect lands the user back on the detail view with completion persisted.

`is_completed` is NOT in `Step::$fillable`. The toggle action MUST set the attribute directly (`$step->is_completed = ! $step->is_completed; $step->save();`) — `->update(['is_completed' => ...])` would silently no-op. Any future code path writing this field MUST follow the same pattern.

### Length cap

`CreateStepRequest` and `EditStepRequest` enforce `body: ['required', 'string', 'min:1', 'max:200']`. The 200 matches both the `steps.body` column width (`string('body', 200)`) and F-02's `config('ai.step_suggestion.max_step_length')` — per the [AI suggestion surface](#ai-suggestion-surface-f-02) "Length cap coupling" note, all three move in lockstep.

### Test proof

- `tests/Feature/Steps/AddStepTest.php` — 2 tests covering FR-010 happy path (`manual` source, position appended at end, owner = current user) and the two-user-404 isolation boundary.
- `tests/Feature/Steps/EditStepTest.php` — 3 tests covering FR-011 happy path, the source-immutability invariant (`source: manual` in the request body is rejected by the FormRequest whitelist; `source` remains `ai_initial`), and the two-user-404 isolation boundary.
- `tests/Feature/Steps/DeleteStepTest.php` — 2 tests covering FR-012 happy path (row removed, redirect to show) and the two-user-404 isolation boundary. The "with confirmation" requirement is a frontend concern (native `confirm()`); the backend just deletes.
- `tests/Feature/Steps/ToggleStepTest.php` — 2 tests covering FR-013 happy path on the JSON response shape (`patchJson` → `{is_completed: true, completed: 1, total: 1}`) and the two-user-404 isolation boundary on the same endpoint.
- `tests/Feature/Ventures/ShowVentureProgressTest.php` — 1 test covering FR-018 ("X of Y completed" text) for the mixed-completion case (2/4). The 0/0 and N/N variants share the same render path and don't need separate proof for v1.

### Out of scope for S-02 (deferred to downstream slices)

- AI-extension trigger (FR-009) → **S-03** (adds `StepSource::AiExtension` writes to this surface).
- Step deadlines + 3-day badge (FR-014, FR-020) → **S-05** (adds a `deadline` column and badge UI).
- Venture list / venture delete UI (FR-005, FR-007) → **S-04**.
- Expenses (FR-015–FR-017) → **S-06**.
- Reorder action (drag-and-drop, position rewrite) — not in PRD scope; append-only adds with stable position remain the v1 model.
- Undo for delete — PRD §Guardrails accepts the confirm dialog as the second-gesture requirement; undo is a v2 enhancement.

---

## Step extension surface (S-03)

**Established by**: S-03 (`context/changes/extend-plan-with-ai/`).

**PRD anchors**: FR-009 (trigger AI to extend the step list on demand; extension steps do NOT count toward the FR-008 primary-metric AI pool), NFR(ai-ceiling), NFR(ai-graceful).

### Routes (all under the `auth` middleware, nested under `ventures/{venture}`)

- `steps.suggestions.preview` (POST `/ventures/{venture}/steps/suggestions`, `whereNumber('venture')`) — call `AiStepSuggester::suggestSteps()` with the venture's current step bodies as `$currentSteps`; on success render `steps.suggestions.preview`, on `[]` flash `ai_unavailable` and redirect to `ventures.show`. POST (not GET) because the call has the side effect of incrementing the per-user AI counter — GET would let browser prefetch / bookmark / refresh re-fire AI.
- `steps.suggestions.store` (POST `/ventures/{venture}/steps/suggestions/confirm`, `whereNumber('venture')`) — validate `SuggestStepsRequest`, filter the previewed rows by the `keep` flag, persist the chosen rows as `ai_extension` steps appended to the tail, redirect to `ventures.show`. No AI call here — the suggester ran in the earlier preview request.

### Access path rule (realization of the F-01 / S-02 contract)

Both actions resolve the venture through the same singly-scoped relationship chain as the rest of the step surface: `$ventureModel = $request->user()->ventures()->findOrFail($venture);`. This produces **404 (not 403)** for a foreign venture. Critically, the venture-resolution `findOrFail` MUST be the **first statement** of `suggest()` — it throws 404 BEFORE the suggester is dispatched, so a user posting to another user's venture cannot burn that user's per-user AI ceiling (the F-02 counter increments before dispatch, NFR(ai-ceiling) per-user accounting). The isolation test asserts this explicitly: the `AiStepSuggester` mock is set `->shouldReceive('suggestSteps')->never()` and user B's `AiCallCounter` count is asserted to stay 0.

### Preview-then-confirm flow

The user-selection step resolves the partial-persistence question: the user decides which of the 7 candidates to keep, so a partial-DB error after confirmation is a real bug (handled by `DB::transaction`), not a product question.

- **Preview** (`suggest`): renders `steps.suggestions.preview` carrying the candidate bodies as hidden `suggestions[*][body]` fields and default-checked `suggestions[*][keep]` checkboxes. Carried as hidden form fields (not session state) so the selection survives the round-trip without re-fetching from AI. A refresh on the preview result resubmits the form (re-fires AI) — annoying but bounded by the per-day ceiling, not a correctness bug.
- **Confirm** (`storeSuggestions`): `$chosen = array_filter($rows, fn ($row) => ! empty($row['keep']))` (browsers omit unchecked boxes entirely, so `keep` is a presence flag). Position is computed once before the loop (`$nextPosition = (max(position) ?? -1) + 1`) then incremented per persisted row, so kept rows land contiguously at the tail. The persistence loop runs inside `DB::transaction` (all-or-nothing). Keeping none → 0 rows persist, no flash, plain redirect — explicit user choice, not a failure.

### Source invariant (FR-008 metric carve-out)

Confirmed rows are persisted with `source = StepSource::AiExtension` via `->forceFill(['owner_id' => …, 'source' => StepSource::AiExtension])`. Because `source` is not in `Step::$fillable` (S-02 hardening) and `SuggestStepsRequest` whitelists only `suggestions.*.body` + `suggestions.*.keep`, a tampered payload carrying `source: ai_initial` cannot pollute the FR-008 metric pool — three defense layers stack (model fillable + FormRequest whitelist + controller forceFill). `ai_extension` rows are excluded from the "3 of 7 kept" denominator structurally (the metric measures only `ai_initial`); no extra controller code enforces the carve-out.

### Failure-flash contract (reuses S-01's `ai_unavailable`)

The suggest endpoint reuses S-01's amber `ai_unavailable` flash key with extension-specific wording: "AI couldn't suggest more steps right now — try again later or add steps manually." Both provider-failure and over-ceiling paths return `[]` from `AiStepSuggester` and take this branch identically. The `ventures.show` flash-rendering block picks up either S-01's or S-03's wording without view-level branching.

### Length cap

`SuggestStepsRequest` enforces `suggestions.*.body: ['required', 'string', 'min:1', 'max:200']` and `suggestions: ['required', 'array', 'min:1', 'max:7']`. The `max:200` matches `steps.body` column width and F-02's `config('ai.step_suggestion.max_step_length')` — per the [AI suggestion surface](#ai-suggestion-surface-f-02) "Length cap coupling" note, all move in lockstep. The `max:7` outer ceiling defends against tampering that adds extra rows.

### Test proof

- `tests/Feature/Steps/SuggestExtensionTest.php` — 4 tests: happy-path preview render (7 hidden body fields + 7 keep checkboxes, `suggestions` view-data), AI-unavailable flash + redirect + no rows persisted, two-user-404 on the suggest endpoint **with the suggester asserted never-called and user B's counter asserted 0** (the assertion that closes the "B can't burn A's ceiling" loop), and guest-POST → 302 to `/login`.
- `tests/Feature/Steps/StoreSuggestedStepsTest.php` — 4 tests: keep-all (7 `ai_extension` rows appended at positions 3–9, owner = current user, bodies in order), keep-subset (only checked rows persist in order at contiguous positions), keep-none (no rows, no flash, redirect), and two-user-404 on the confirm endpoint (A's step count unchanged).

### Out of scope for S-03 (deferred / explicitly not done)

- Regenerate / re-roll suggestions on the preview page → PRD §Non-Goals ("No 'regenerate AI suggestion' action"). Cancel + re-trigger (counting another ceiling unit) is the path.
- Inline body editing on the preview → v2; the user keeps a row then edits it via S-02's step Edit link.
- Counter-status surface ("N AI calls left today") → v2.
- Soft-cap warning at high step counts → v2 (PRD §Open Questions / FR-009 Socrates resolution).

---

## Venture list + destroy surface (S-04)

**Established by**: S-04 (`context/changes/list-and-delete-ventures/`).

**PRD anchors**: FR-005 (user can view a list of all their own ventures), FR-006 (single-venture detail view — reached from the list via each row's title link), FR-007 (user can delete a venture, with confirmation), NFR(isolation).

### Routes (all under the `auth` middleware)

- `ventures.index` (GET `/ventures`) — canonical RESTful name for the list.
- `dashboard` (GET `/dashboard`) — **reassigned by S-04** from the create form to `VenturesController@index` (the same action as `ventures.index`). Two URLs (`/dashboard`, `/ventures`) share one action under two route names — mirrors the S-01 dashboard / `ventures.create` aliasing pattern. The route name `dashboard` is preserved so `route('dashboard')` callers in F-01 (`RegisteredUserController::store`, `LoginRequest::authenticate` → `redirect()->intended(route('dashboard'))`) and the nav home link continue to work without per-callsite edits. Tests written against the list use `route('ventures.index')`.
- `ventures.destroy` (DELETE `/ventures/{venture}`, `whereNumber('venture')`) — delete one venture and (via the schema cascade) its step subtree.

### Access path rule (realization of the F-01 contract)

Both new actions resolve the venture through `$request->user()->ventures()` — the same singly-scoped relationship chain S-01 established. `index` calls `$request->user()->ventures()->withCount([...])->orderByDesc('updated_at')->get()`. `destroy` calls `$request->user()->ventures()->findOrFail($venture)->delete()`. This produces **404 (not 403)** for a foreign user POSTing a DELETE to a venture they don't own — the relationship-scoped query simply finds no row. No global `Venture::find($venture)->delete()`, no `Route::bind('venture', …)`, no global scope on the model.

### Cascade semantics (schema-level, not application-level)

`$venture->delete()` issues one `DELETE FROM ventures WHERE id = ?` statement; Postgres / SQLite cascade `steps.venture_id ON DELETE CASCADE` and remove the step subtree as part of the same transaction. **No application-level loop is needed** (and writing one would be wrong — model events on `Step` fire per row, and a partial-delete failure would leave the DB inconsistent). The `ai_call_counters` table is keyed on `owner_id` (NOT `venture_id`), so deleting a venture does NOT roll back any per-user 24h counter — the AI calls really happened, and the counter accounting must reflect that regardless of whether the venture they fed survives. No refund logic.

### Sort: `ORDER BY updated_at DESC` (+ the `Step::$touches` coupling)

The list orders by `updated_at DESC` so the venture the user last touched bubbles to the top — the shape that rewards the secondary success metric ("users return to a venture at least once") and the "+ X of Y completed" progress signal. Critically, **`Step::$touches = ['venture']`** is the wiring that makes this sort honest: without it, editing a step (S-02's edit / delete / toggle / create paths, S-03's AI-extension confirm path) would NOT bump the parent venture's `updated_at`, and a venture whose only recent activity is step edits would stay buried under a never-touched venture created later. The one-line `protected $touches = ['venture'];` on `Step` causes every step save/delete to issue `$venture->touch()` as part of the same transaction; downstream slices that add new step writers (S-05's deadline editor, S-06 if/when expenses move per-step) MUST not bypass `save()` / `delete()` (e.g., raw `DB::table('steps')->...`) without re-touching the parent. **If you remove `$touches`, the list sort lies silently — there is no surface that screams about it.**

### Per-row aggregates (FR-018 mirror on the list)

`withCount(['steps', 'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true)])` adds two subqueries per index render — `$venture->steps_count` (total) and `$venture->completed_steps_count` (filtered) — so each row renders `"X of Y steps completed"` (or `"—"` when `steps_count === 0`) without N+1. Laravel's named-subquery aggregate shape is the idiomatic way to combine filtered + unfiltered counts. The same `$completed / $total` signal already lives on the detail view (S-01 / S-02); the list mirrors it.

### Confirmation gesture (NFR realization for FR-007)

Delete is an inline `<form method="POST" onsubmit="return confirm('Delete this venture? This will also remove all its steps.')">` with `@csrf + @method('DELETE')` — the same native-`confirm()` pattern S-02's step-delete row uses. The blast radius is larger (a venture carries its step subtree, and S-05/S-06 will add deadlines + expenses), but the v1 confirm shape is uniform across both delete points. A heavier guard ("type the title", typed-confirm modal) is a v2 ergonomic enhancement noted in the §Out of scope sub-section below.

### Test proof

- `tests/Feature/Ventures/ListVenturesTest.php` — 3 tests: empty-state renders the "Create your first venture" CTA and the `route('ventures.create')` href is wired (FR-005 empty-state surface); user A sees their own ventures and does NOT see user B's title (FR-005 + F-01 isolation enforcement on the index endpoint); a step save advances the parent venture's `updated_at` (locks the `Step::$touches = ['venture']` wiring — the silent-lie failure mode named above is what this assertion catches).
- `tests/Feature/Ventures/DeleteVentureTest.php` — 2 tests: owner can delete their own venture (target row gone, target step rows gone via cascade, sibling venture and its step rows survive — the sibling assertion locks cascade scope to `venture_id`, not over-cascading on `owner_id`); user B gets 404 (not 403) on user A's destroy URL and user A's venture is still in the DB (F-01 enforcement-checklist item 4 on the destroy endpoint).

The guest-302-on-destroy assertion the F-01 enforcement checklist names is NOT separately ship-tested in this slice — the `auth` middleware boundary is structurally identical to the boundary already proven for `ventures.show` in `tests/Feature/Ventures/VentureIsolationTest::test_guest_gets_redirect_on_venture_show`. Documented here so a future reviewer doesn't read this as an oversight.

### Out of scope for S-04 (deferred / explicitly not done)

- Total-cost cell on the list row → satisfied by S-06 — see [Expense surface (S-06)](#expense-surface-s-06).
- Imminent / overdue deadline marker on the list row → **S-05** (FR-021).
- Description excerpt on the list row → not planned for v1; description is optional and most v1 rows would render an empty block. Detail view (S-01) is the description surface.
- Pagination / sort / filter / active-vs-completed splits → v2; FR-005 Socrates resolution locks v1 to a flat list.
- Delete button on the detail view → v2 ergonomic enhancement; delete only lives on list rows for v1.
- Soft-delete / archive semantics → v2 (FR-007 Socrates resolution).
- Undo for delete → v2 (PRD §Guardrails accepts the native-confirm gesture as the second-gesture requirement).
- Heavier delete-confirmation guard (typed-title modal) → v2 ergonomic enhancement.
- JS island for any list-row interaction → not needed; the list is read-only + form-POST delete with a native confirm.

---

## Expense surface (S-06)

**Established by**: S-06 (`context/changes/venture-expenses-and-cost/`).

**PRD anchors**: FR-015 (add expense: amount + description + date), FR-016 (delete expense), FR-017 (edit expense), FR-019 (accumulated total cost on BOTH the detail view AND the venture list), NFR(isolation), NFR(edit-latency).

### Routes (all under the `auth` middleware, nested under `ventures/{venture}/expenses/...`)

- `expenses.create` (GET `/ventures/{venture}/expenses/create`, `whereNumber('venture')`) — render the add-expense form bound to the venture.
- `expenses.store` (POST `/ventures/{venture}/expenses`, `whereNumber('venture')`) — validate `CreateExpenseRequest`, persist one expense, redirect to `ventures.show`.
- `expenses.edit` (GET `/ventures/{venture}/expenses/{expense}/edit`, both params `whereNumber`) — render the edit form pre-populated with the expense's current values.
- `expenses.update` (PATCH `/ventures/{venture}/expenses/{expense}`, both params `whereNumber`) — validate `EditExpenseRequest`, write `amount` / `description` / `date` only, redirect to `ventures.show`.
- `expenses.destroy` (DELETE `/ventures/{venture}/expenses/{expense}`, both params `whereNumber`) — delete the row, redirect to `ventures.show`. Confirmation is the frontend's job (native `confirm('Delete this expense?')` in `ventures.show`).

### Access path rule (realization of the F-01 / S-02 contract on a nested resource)

Every authenticated action resolves both the venture and the expense through the doubly-scoped relationship chain:

```php
$ventureModel = $request->user()->ventures()->findOrFail($venture);
$expenseModel = $ventureModel->expenses()->findOrFail($expense);
```

This produces **404 (not 403)** for either a foreign venture OR a foreign expense under an owned venture — the relationship-scoped query simply finds no row, so the user cannot distinguish "exists but forbidden" from "does not exist." No global `Expense::find(...)`, no `Route::bind('expense', …)`, no global scope on the `Expense` model.

### Money precision discipline (the load-bearing decision of this slice)

`amount` is `decimal(12,2)` with the Eloquent `decimal:2` cast, so reads return a stringified decimal (`"12.50"`) that never coerces to float. **Two float-coercion traps are avoided:**

- **List-row total** via `withSum('expenses as total_cost', 'amount')` on the `VenturesController::index` aggregate chain — PG / SQLite compute the SUM in exact decimal arithmetic as one subquery (no N+1). The alias `total_cost` is NOT auto-cast (it's a raw query alias), is NULL when the venture has no expenses, and is rendered via `number_format($venture->total_cost ?? 0, 2)` so the empty case shows `Total: 0.00` and the line layout stays stable for S-05's next-line slot.
- **Detail-view total** via `$venture->expenses()->sum('amount')` (DB-side `SELECT SUM(amount)`), **NOT** `$venture->expenses->sum('amount')` (PHP `array_sum`, which float-coerces). The detail action issues one extra DB query against the `(venture_id, date)` index; sub-millisecond, trivially under NFR(edit-latency).

### `Expense::$touches = ['venture']` coupling

Bubbles expense writes into the parent venture's `updated_at` so the venture-list `ORDER BY updated_at DESC` sort honors recent expense activity — the same lesson S-04 codified for `Step`. Any future code path writing an `Expense` outside `save()` / `delete()` (e.g. raw `DB::table('expenses')->...`) MUST call `$venture->touch()` explicitly, or the sort lies silently.

### Owner-immutability (defense in depth)

`Expense::$fillable = ['amount', 'description', 'date']` — `owner_id` and `venture_id` are deliberately absent. The store action sets `owner_id` via `->forceFill(['owner_id' => …])` and `venture_id` via the relationship's `make()`. Combined with the `CreateExpenseRequest` / `EditExpenseRequest` body-only whitelist, a tampered payload carrying `owner_id: 99999` or `venture_id: 99999` is a no-op at the model layer. Same shape S-02 uses for `Step::source`.

### Shared-surface contracts S-05 inherits

S-06 runs in parallel with S-05 on the same return-surface page and list; these three contracts are codified so S-05 lands without a silent rebase:

1. **Venture-list per-row metadata is a stack of `<p>` lines** under the title link. Line 1 = `"X of Y steps completed"` (or `"—"` when no steps — owned by S-04). Line 2 = `"Total: X.XX"` (always rendered, `0.00` when empty — owned by S-06). **Line 3 = the deadline marker — reserved for S-05 to fill.** S-05 SHOULD insert its `<p>` immediately after S-06's total line, never above. A Blade comment in `ventures/index.blade.php` flags the slot explicitly.
2. **Venture-detail outer stack ordering** (the `<div class="...space-y-6">` in `ventures/show.blade.php`): AI-unavailable flash → Description → Steps → **Expenses** (S-06, appended below Steps). S-05 modifies only the Steps card's inner `<li>` rows (a deadline badge per step); the outer stack ordering is OWNED by S-06. S-05 MUST NOT insert a new card above Steps or below Expenses without a plan-revision conversation.
3. **`VenturesController::index` aggregate chain ordering**: `->withCount([...])->withSum('expenses as total_cost', 'amount')->orderByDesc('updated_at')->get()`. S-05 SHOULD add its deadline-pressure aggregate into the same `withCount([...])` array as another named subquery, preserving the existing `withSum` and `orderByDesc` lines. The `total_cost` alias must not collide with any S-05 alias.

### Test proof

- `tests/Feature/Expenses/AddExpenseTest.php` — 2 tests: FR-015 happy path (`owner_id` / `venture_id` / cast values persisted) and the two-user-404 isolation boundary (`Expense::count()` stays 0).
- `tests/Feature/Expenses/EditExpenseTest.php` — 2 tests: FR-017 happy path (three fields update, `owner_id` / `venture_id` unchanged) and the two-user-404 boundary on the nested `{venture}/{expense}` resource (A's expense unchanged).
- `tests/Feature/Expenses/DeleteExpenseTest.php` — 2 tests: FR-016 happy path (row removed, redirect to show) and the two-user-404 boundary (A's expense survives).
- `tests/Feature/Ventures/VentureTotalCostTest.php` — 1 test: FR-019 cross-surface render (`Total: 24.75` on BOTH `ventures.show` and `ventures.index` from the same DB SUM).

Guest-302 is deferred — the `auth` middleware boundary is structurally identical to the one already proven by `tests/Feature/Ventures/VentureIsolationTest::test_guest_gets_redirect_on_venture_show`. Same justification S-04 used.

### Out of scope for S-06 (deferred / explicitly not done)

- Multi-currency / currency symbol / locale formatting → PRD §Non-Goals (single currency v1).
- Per-step expense allocation (`step_id` on `Expense`) → PRD §Non-Goals (venture-level only v1).
- Expense categories → v2 (FR-015 Socrates resolution).
- Budget comparison / expense-vs-budget view → v2.
- File-format export / report → PRD §Non-Goals.
- Undo for delete → v2 (native confirm is the v1 second-gesture).
- Deadline marker on the list row → **S-05** owns line 3 of the metadata stack.
- Cross-venture total-cost roll-up → PRD §Non-Goals (no cross-venture dashboard v1).
- JS island → none; five form POSTs + full-reload redirects.
- Policy classes / authorization layer → v3+ per [v3+: co-editing](#v3-co-editing-multi-user-write-access).

---

## Future evolution

> Both expansions below are PRD-contemplated in the Non-Goals _"forward-compatibility note: if added in v2+, the per-user-isolation NFR must continue to hold"_ but are NOT in the v1 roadmap. These notes exist so the F-01 contract does not foreclose either path — and so S-01 does not pick a structure (e.g. a `unique` constraint that assumes one venture per user) that would block them.

### v2: read-only public sharing (link / token)

A user shares a venture with a non-user (or another user) by generating a short-lived token. **Structurally orthogonal to the four rules above** — the owner remains the sole writer; no membership table is needed.

- New table: `shares(venture_id, token, expires_at, ...)` with `foreignId('venture_id')->constrained()->cascadeOnDelete()` (so deleting the venture cleans up its tokens).
- New route: `/share/{token}` mounted OUTSIDE the `auth` middleware. The controller resolves the share by token, loads the venture in **read-only** mode, and renders a stripped-down view. There is no `$request->user()` and the relationship-only rule does not apply here (the access path is token-scoped, not user-scoped — rule 4's parenthetical names this exemption).
- The two-user isolation test (S-01 checklist item 4) **continues to pass unchanged**: non-share holders still get 404 from the authenticated path; share-token holders get 200 from the public path.
- **No change to the four rules above is required for v2.**

### v3+: co-editing (multi-user write access)

Additional users can edit a venture — add steps, mark completion, etc. This is where the membership pivot actually lands.

- New pivot table: `venture_user(venture_id, user_id, role)` with `role` enumerating something like `viewer` / `editor`. Note the deliberate column naming — `user_id` on the pivot means _"any user with some level of access"_ (per rule 1's reservation), while `ventures.owner_id` retains its meaning as the canonical owner.
- Canonical access path shifts from `$request->user()->ventures()` to `$request->user()->accessibleVentures()` — a union of owned + pivot-member. Rule 2's structural form ("via a method on the authenticated user") survives unchanged; the specific method name evolves.
- Authorization graduates to Laravel's Policy primitive (`VenturePolicy@view`, `@update`, `@delete`) — the policy body becomes `owner_id === user->id OR pivot-member-with-required-role`. Controllers call `$this->authorize('update', $venture)` and stop caring whether access came via ownership or membership.
- **`owner_id` stays.** It does not collapse into the pivot. The owner has special status (cannot lose their own access; deletion cascades; UI labels them as such), and keeping `owner_id` as a first-class column on `ventures` is cheaper than reconstructing "who owns this" from the pivot on every query.
- The S-01 isolation test grows: a third assertion that a non-member of B's venture (and not a share-token holder) still gets 404.

### What this means for F-01 today

Nothing additional. The four rules and the S-01 checklist are written to survive both expansions unchanged in form; only the specific method name in rule 2 is expected to evolve, which is why rule 2 is stated structurally rather than nominally.
