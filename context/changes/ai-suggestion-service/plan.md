# F-02 / ai-suggestion-service Implementation Plan

## Overview

F-02 wires a provider-agnostic LLM step-suggestion service into the app: a thin `AiStepSuggester` service class, backed by a `laravel/ai` `Agent` with structured-output JSON validation, a daily-bucket per-user 24h call counter on Neon Postgres, a 15-second HTTP timeout, and a graceful-failure contract that returns `[]` on any failure mode so AI failure can never block venture creation. Provider default: Groq (OpenAI-compatible, free tier). Provider swap = env-only change.

## Current State Analysis

The repo is post-F-01: email+password auth shipped, `users` table exists, the `owner_id` isolation convention is codified in `docs/reference/contract-surfaces.md` and `context/foundation/lessons.md`, and the `auth` middleware boundary is in place. `app/Models/` carries only `User.php`; `app/Services/` does not yet exist; no domain tables (Venture / Step / Expense) — those land in S-01. F-02 sits before S-01 by design so the rate-limited service is available when the first slice that needs it (S-01's initial 7-step generation, then S-03's on-demand extension) consumes it.

What's already wired: Laravel HTTP client (Guzzle) is available out of the box, Neon Postgres pooled in production / SQLite locally, `auth` middleware boundary + `$request->user()` access pattern. What's missing: an `AI_API_KEY` env-var slot in `.env.example` and `render.yaml`, an AI config file, the service + agent class, the rate-limit counter table, the migration, and the feature-level isolation test for the counter table. Two cosmetic gaps in `render.yaml` will be folded in: the `env: docker` key must become `runtime: docker` per the deploy-plan, and the `AI_API_KEY` slot must be added.

## Desired End State

After this plan lands:

- A solo Laravel service `App\Services\AiStepSuggester` is callable from any authenticated controller as `app(AiStepSuggester::class)->suggestSteps($user, $title, $description, $currentSteps = [])` and ALWAYS returns `array<int, string>` — exactly 7 short strings on success, `[]` on every failure mode (network, timeout, 4xx/5xx, malformed JSON, wrong step count, over-quota, missing config).
- A daily-bucket counter table `ai_call_counters(owner_id, day, count)` with unique `(owner_id, day)` and `owner_id` FK cascade-on-delete enforces the 20 calls / 24h ceiling, incremented BEFORE the provider call (attempt-based) so failure storms can't burn through quota.
- `config/ai.php` holds provider id, base URL, model id, timeout, ceiling, and the 7-step schema constants. Environment vars `AI_API_KEY` (provider key) and `AI_PROVIDER` (default `groq`) are read at runtime. `.env.example` documents both; `render.yaml` slots `AI_API_KEY` as `sync: false` and the `env: docker` → `runtime: docker` drift is fixed.
- `laravel/ai` is installed at a pinned minor (e.g. `~0.x.y`) — so a breaking 0.x release before the 2026-07-04 deadline does not silently break the production deploy.
- Feature tests cover: (a) the seven graceful-fail trigger modes, each asserting `[]` return + counter-incremented; (b) success returns exactly 7 strings + counter-incremented; (c) ceiling enforcement: 21st call within the same UTC day returns `[]` without dispatching a request; (d) two-user isolation on the counter table per F-01's S-01 enforcement checklist.

### Key Discoveries

- F-02 is the only synchronous remote dependency in the entire MVP (`infrastructure.md:69`, Devil's Advocate finding #1). Free Render tier has no Background Workers, so the AI call runs on the `sync` queue driver — synchronously in the web request handler. The PRD NFR(edit-latency) "≤1s feedback" explicitly does NOT apply to the AI path; only to non-AI editing.
- The counter table is the first per-user domain table in the project, so the F-01 S-01 enforcement checklist applies in full (`docs/reference/contract-surfaces.md`): `owner_id` FK with explicit `->constrained('users')->cascadeOnDelete()`, relationship method on `User`, two-user isolation feature test asserting 404 not 403.
- `laravel/ai` natively supports Groq (`Lab::Groq`), provides `HasStructuredOutput` for JSON-schema-validated responses (which replaces a hand-rolled "exactly 7 strings" validator), exposes a built-in `Agent::fake([...])` testing primitive, and supports per-prompt timeout overrides. Status is 0.x — the package is pinned tight to defend the deadline against breaking releases.
- The rate-limit storage shape is prescribed by `infrastructure.md:123`: a Postgres counter table on Neon `(user_id, day, count)` with a unique constraint. F-01's isolation convention forces the column name to `owner_id`, not `user_id`.
- The provider env var was renamed to `AI_API_KEY` in commit `f508749` precisely so the first F-02 commit reads provider-agnostic env, not `GROQ_API_KEY`. The same provider-agnostic discipline applies to `AI_PROVIDER` (default `groq`) and any model-id reference.

## What We're NOT Doing

- **No Venture / Step / Expense models** — they land in S-01, not here. F-02 is service-shaped; the test fixtures used here create only `User` rows.
- **No HTTP route or controller** — F-02 only exposes a service class via the container. Callers (S-01, S-03) will add their own controllers.
- **No retry / backoff logic** — a single attempt with a 15s timeout, then graceful-fail. Retries against a free-tier provider with a request-thread budget would just hold the connection longer; the user is already off the happy path at that point.
- **No queue / background worker / async dispatch** — free Render tier has no Background Worker (`infrastructure.md:69`). The AI call holds the request thread by design.
- **No prompt-engineering iteration loop** — the v1 prompt is "good enough for the 7-step contract"; primary-metric tuning is a downstream concern (PRD Success Criteria, measured against the initial-AI pool).
- **No rate-limit reset endpoint or admin tooling** — calendar-day rollover is sufficient for v1; tunable later via the constant in `config/ai.php`.
- **No request-side caching of AI responses** — every venture description is unique enough that caching has no realistic hit rate, and caching would interact awkwardly with the per-user ceiling.
- **No structured logging beyond a single warning log per failure mode** — observability beyond log lines is out of scope for v1.
- **No live provider integration test** — feature tests use `AiStepSuggesterAgent::fake([...])`; we do not call Groq in CI.

## Implementation Approach

Four phases, ordered so each commit is independently meaningful and reviewable:

1. **Config + env wiring + render.yaml drift fix.** Lands `config/ai.php`, `.env.example` slots, `render.yaml` slot + `runtime: docker` fix, and `composer require laravel/ai:^0.x` (pinned tight). No service code yet — Phase 1 establishes the configuration contract that Phases 2-4 consume.
2. **Rate-limit counter table + model + relationship.** First per-user table in the project — F-01's S-01 enforcement checklist applies. Migration, `AiCallCounter` model with `belongsTo(User::class, 'owner_id')`, `User::aiCallCounters()` relationship, two-user feature test asserting isolation.
3. **`StepSuggestionAgent` + `AiStepSuggester` service + validator.** The `laravel/ai` `Agent` class declares provider via `#[Provider(Lab::Groq)]`, model via `#[Model(...)]`, timeout via `#[Timeout(15)]`, and implements `HasStructuredOutput` with a JSON schema requiring exactly 7 strings (max 200 chars each). The `AiStepSuggester` service is the public seam — it accepts the `User`, runs the counter check + increment, dispatches to the agent, maps exceptions and invalid responses to `[]`, and exposes the single `suggestSteps()` method. Feature tests use `StepSuggestionAgent::fake([...])` to cover all seven graceful-fail modes plus ceiling enforcement.
4. **Cross-slice handoff documentation.** README section + contract-surfaces.md entry naming F-02's public seam, the empty-array failure contract, and the sync-execution caveat. Lessons.md entry on the "increment-before, fail-open" pattern so S-01 and later slices that touch AI carry the convention forward.

## Critical Implementation Details

**Sync-execution constraint.** The AI call holds the web request thread. Render free tier has no Background Worker, so this is not a choice — it's a property of the deployment shape. The 15-second timeout is the request-side defense: any longer and we risk Render's request idle timeout. The NFR(edit-latency) "≤1s feedback" explicitly does NOT apply to the AI path. Document this in Phase 4.

**Counter increment ordering.** Increment BEFORE dispatching the HTTP call (attempt-based), inside a transaction with an `INSERT ... ON CONFLICT (owner_id, day) DO UPDATE SET count = count + 1 RETURNING count` (or Eloquent equivalent via `upsert`+`increment` pattern). Defends against a stampede / retry loop and against a misconfigured account firing dozens of failing calls per minute. The counter cost of a failed attempt is the price we pay for the ceiling actually being load-bearing.

**Provider-agnostic env discipline.** Every reference is to `env('AI_API_KEY')` / `env('AI_PROVIDER', 'groq')`, never `env('GROQ_API_KEY')`. A future provider swap (OpenRouter, OpenAI, Anthropic) is a config + env-value change — no code edit. Enforced by code review on Phase 1.

---

## Phase 1: Config + env wiring + render.yaml drift fix

### Overview

Establish the configuration contract that later phases consume — provider id, base URL, model id, timeout (15s), ceiling (20 calls/24h), JSON-schema constants — and install the `laravel/ai` SDK pinned to a tight minor. Fix the two render.yaml drifts (`env` → `runtime`, add `AI_API_KEY` slot) and document the new env vars in `.env.example`.

### Changes Required

#### 1. Install laravel/ai

**File**: `composer.json` (via `composer require`)

**Intent**: Bring in the Laravel-first-party AI SDK so Phase 3 can use `Agent` / `HasStructuredOutput` / `Agent::fake()` primitives. Pin tight to the current minor (the SDK is pre-1.0) so a 0.x breaking release before the 2026-07-04 deadline does not silently break production.

**Contract**: `composer require laravel/ai:^0.x.y` (the tightest pin that gets latest patch but not new minor). Verify `composer.lock` is updated and committed. Verify `config/ai.php` is publishable via `php artisan vendor:publish --tag=ai-config` (or whatever tag the SDK exposes — fall back to hand-authoring if no publish target exists).

#### 2. AI configuration file

**File**: `config/ai.php`

**Intent**: Single source of truth for provider id, base URL, model id, request timeout, 24h ceiling, JSON-schema parameters. All values either come from env (`AI_API_KEY`, `AI_PROVIDER`) or are local constants. Phase 3 reads from this file via Laravel's `config()` helper.

**Contract**: array with keys:
- `provider` — string, defaults to `env('AI_PROVIDER', 'groq')`
- `api_key` — string, from `env('AI_API_KEY')`
- `model` — string, defaults to `env('AI_MODEL', 'llama-3.3-70b-versatile')` (Groq's current stable production model; refresh if Groq deprecates)
- `timeout_seconds` — int, `15`
- `ceiling_per_day` — int, `20`
- `max_step_length` — int, `200`
- `required_step_count` — int, `7`

If `laravel/ai` ships its own `config/ai.php` and the SDK already binds the provider/model/timeout via PHP attributes on the Agent, this file can be slimmed to only the app-specific constants (`ceiling_per_day`, `max_step_length`, `required_step_count`). Decide at implementation time.

#### 3. Document new env vars

**File**: `.env.example`

**Intent**: Document `AI_API_KEY`, `AI_PROVIDER`, and optionally `AI_MODEL` so local developers and the Render dashboard operator know what to set. The pattern matches how F-01's auth vars were documented.

**Contract**: Append three lines under a new `# AI suggestion service` section:
```
AI_PROVIDER=groq
AI_API_KEY=
AI_MODEL=llama-3.3-70b-versatile
```

#### 4. Render manifest fixes

**File**: `render.yaml`

**Intent**: Fix the `env: docker` → `runtime: docker` drift flagged in `context/deployment/deploy-plan.md` and add the `AI_API_KEY` slot as `sync: false` so the Render dashboard prompts for it on first apply. Optionally add `AI_PROVIDER` and `AI_MODEL` slots with `value: ...` so they're declared but easy to override.

**Contract**: Two edits to the existing service block:
- Rename top-level `env: docker` to `runtime: docker`.
- Under `envVars`, append `- key: AI_API_KEY` with `sync: false`. Append `- key: AI_PROVIDER` with `value: groq` and `- key: AI_MODEL` with `value: llama-3.3-70b-versatile`.

### Success Criteria

#### Automated Verification

- `composer install` clean: `composer install --no-dev --optimize-autoloader` produces no errors.
- `config/ai.php` parses: `php artisan config:show ai` or `php artisan tinker --execute="dump(config('ai'))"` prints the expected array.
- `php artisan config:clear && php artisan config:cache` succeeds.
- Linting/format pass: `vendor/bin/pint`.

#### Manual Verification

- `.env.example` reads cleanly; the new section is visible and order-stable.
- `render.yaml` validates against Render's Blueprint schema when uploaded (no warnings in Render dashboard).
- A spot-check `php artisan tinker` confirms `config('ai.provider')` returns `'groq'` when `AI_PROVIDER` is unset, and the provided value when set.

**Implementation Note**: After Phase 1 passes automated verification, pause for manual confirmation that `.env.example` and `render.yaml` read as expected before moving to Phase 2.

---

## Phase 2: Rate-limit counter table + model + isolation test

### Overview

Add the first per-user domain table in the project. The counter is keyed `(owner_id, day)` with a unique constraint, increments daily, and is the storage backing the 20-calls-per-24h ceiling enforced in Phase 3. Because this is the project's first per-user table, the F-01 S-01 enforcement checklist applies in full.

### Changes Required

#### 1. Migration: create `ai_call_counters` table

**File**: `database/migrations/<timestamp>_create_ai_call_counters_table.php`

**Intent**: Provision the daily-bucket counter table on the production database (Neon Postgres) and the local SQLite. The schema is the prescribed shape from `infrastructure.md:123` adapted to F-01's column naming convention.

**Contract**: a `create` migration declaring:
- `id` (Eloquent default)
- `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` — explicit `'users'` because Laravel's column→table inference would look up `owners`
- `date('day')` — calendar-day bucket (UTC)
- `unsignedInteger('count')->default(0)`
- `timestamps()`
- A unique index on `(owner_id, day)` named `ai_call_counters_owner_day_unique` so the upsert path can target it.

`down()` drops the table.

#### 2. AiCallCounter model

**File**: `app/Models/AiCallCounter.php`

**Intent**: Eloquent model for the counter table. Carries the inverse relationship to `User` so Phase 3's increment logic can access `$user->aiCallCounters()`.

**Contract**: extends `Model`. `$fillable = ['owner_id', 'day', 'count']`. `$casts = ['day' => 'date']`. `belongsTo(User::class, 'owner_id')` named `owner()` (explicit FK name because the column is `owner_id`, not the `user_id` Laravel would assume by default).

#### 3. User relationship

**File**: `app/Models/User.php`

**Intent**: Add the forward relationship so the service in Phase 3 can scope through the authenticated user — matches the structural "via a method on `$request->user()`" rule from F-01.

**Contract**: add `public function aiCallCounters(): HasMany { return $this->hasMany(AiCallCounter::class, 'owner_id'); }`.

#### 4. Two-user isolation feature test

**File**: `tests/Feature/Ai/AiCallCounterIsolationTest.php`

**Intent**: Lock down F-01's privacy floor on the new table. The test creates two users, inserts a counter row for user A, and asserts user B cannot read it via the relationship-scoped path. Per the S-01 enforcement checklist, the expected response on attempted cross-user access is 404, not 403 (existence-leak defense). Because F-02 does not expose any HTTP routes, the test exercises the model layer directly: B's `aiCallCounters()` relationship MUST NOT return A's rows.

**Contract**: a Pest/PHPUnit feature test with at minimum:
- one test that asserts `$userB->aiCallCounters()->count()` is `0` after seeding a row for `$userA`
- one test that asserts cascade-on-delete: deleting `$userA` removes their counter rows

### Success Criteria

#### Automated Verification

- Migration applies cleanly on both SQLite and Postgres: `php artisan migrate:fresh` succeeds locally; the same migration shape works against Neon on first deploy.
- Unit tests pass: `composer run test -- --filter=AiCallCounterIsolationTest`.
- Type check / static analysis: `vendor/bin/pint` clean.
- Two-user isolation test passes per the S-01 enforcement checklist.

#### Manual Verification

- `php artisan tinker` smoke-test: create two users, attach a counter to one, confirm `$userB->aiCallCounters` is empty.
- Confirm the unique constraint on `(owner_id, day)` is in place by attempting a duplicate insert and observing the integrity-error path (don't merge until verified).

**Implementation Note**: After Phase 2 passes automated verification, pause for manual confirmation that the migration round-trips cleanly on a fresh local DB before moving to Phase 3.

---

## Phase 3: StepSuggestionAgent + AiStepSuggester service + validator

### Overview

The core of F-02. A `laravel/ai` Agent class declares the provider/model/timeout via attributes and the response schema (exactly 7 strings, max 200 chars each) via `HasStructuredOutput`. A thin `AiStepSuggester` service is the public seam — it accepts the authenticated user, runs the counter check + increment BEFORE the call, dispatches to the agent, maps every failure mode to `[]`, and returns. Tests cover all seven graceful-fail trigger modes plus ceiling enforcement.

### Changes Required

#### 1. StepSuggestionAgent (laravel/ai Agent class)

**File**: `app/Ai/StepSuggestionAgent.php`

**Intent**: Declares provider, model, timeout, and the JSON-schema contract for the response. This is the only place that names `Lab::Groq` — Phase 4 docs note that swapping providers means changing the attribute (or making it env-driven if `laravel/ai` supports attribute values from config).

**Contract**: a class implementing `Laravel\Ai\Contracts\Agent` and `Laravel\Ai\Contracts\HasStructuredOutput`, with the `Promptable` trait. PHP attributes set `#[Provider(Lab::Groq)]`, `#[Model(config('ai.model'))]` or the literal model id, `#[Timeout(15)]`, `#[Temperature(0.7)]`. The class accepts via constructor:
- `User $user` — used implicitly through the container's DI; not strictly required for the prompt but available for any user-specific prompt tailoring later
- `string $title`, `string $description`, `array $currentSteps`

`instructions()` returns the system prompt: "You are an assistant that proposes the first short, actionable step plan for a personal venture. Always produce exactly 7 short steps (max ~120 characters each) in logical order. Each step must be a complete imperative sentence. Do not include rationale, expected duration, or any other field. If existing steps are provided, do not duplicate or paraphrase them; produce 7 NEW steps that complement them."

`schema(JsonSchema $schema)` returns: `['steps' => $schema->array()->items($schema->string()->maxLength(200))->minItems(7)->maxItems(7)->required()]`.

The agent is prompted by `AiStepSuggester` (next file) — not by controllers directly.

#### 2. AiStepSuggester service (the public seam)

**File**: `app/Services/AiStepSuggester.php`

**Intent**: The only seam callers (S-01, S-03) touch. Wraps the agent so the counter logic, graceful-fail mapping, and provider-agnostic discipline live in one place. If `laravel/ai` 0.x ever breaks, only this file changes.

**Contract**: a class with a single public method:
```
public function suggestSteps(User $user, string $title, string $description, array $currentSteps = []): array
```

Internal behavior (described, not coded — implementer follows the surrounding pattern):
1. **Check ceiling**: query `$user->aiCallCounters()->where('day', today())->value('count') ?? 0`. If `>= config('ai.ceiling_per_day')`, log a warning and return `[]` WITHOUT dispatching the agent.
2. **Increment counter BEFORE dispatch**: upsert via `AiCallCounter::query()->upsert([['owner_id' => $user->id, 'day' => today(), 'count' => 1]], ['owner_id', 'day'], ['count' => DB::raw('ai_call_counters.count + 1')])` — or the Eloquent equivalent that targets the unique index. Wrap in a transaction.
3. **Dispatch agent**: `$response = StepSuggestionAgent::make(user: $user, title: $title, description: $description, currentSteps: $currentSteps)->prompt('Generate the step plan.');` — exact prompt body is short because the agent's `instructions()` carries the heavy spec.
4. **Validate response**: `$steps = data_get($response, 'steps')`. If not an array, or count !== 7, or any element is not a string of length 1..200, log a warning and return `[]`. (Even though `HasStructuredOutput` validates schema-side, defend the contract in code so a SDK schema-laxness bug can't leak through.)
5. **Catch ALL provider/SDK exceptions** (`\Throwable` is acceptable here because the contract is "fail open on any failure"): log a warning with the exception class + message, return `[]`. No retry.
6. **Return** the 7-element string array on success.

The implementer follows existing patterns; no code snippet is needed beyond the method signature and the bullet sequence above.

#### 3. Service-provider binding

**File**: `app/Providers/AppServiceProvider.php`

**Intent**: Make `AiStepSuggester` and `StepSuggestionAgent` injectable via Laravel's container. For Agent classes shaped by `laravel/ai`, the SDK typically auto-resolves via `Agent::make(...)`; verify at implementation time whether explicit binding is required, and add one if so.

**Contract**: `register()` adds, if needed, `$this->app->singleton(AiStepSuggester::class)` so the same instance serves multiple callers in a request. No public-facing facade.

#### 4. Feature tests covering all graceful-fail modes

**File**: `tests/Feature/Ai/AiStepSuggesterTest.php`

**Intent**: Lock down the contract that future slices rely on. Each failure mode gets its own test; success gets a test; ceiling enforcement gets a test.

**Contract**: Pest/PHPUnit tests using `StepSuggestionAgent::fake([...])` to inject canned responses. At minimum:

- **success**: fake returns a valid 7-string `{"steps": [...]}` — assert `suggestSteps()` returns the 7 strings; counter incremented to 1.
- **fail: timeout** — fake throws SDK's timeout exception (or HTTP timeout if `Agent::fake` doesn't model it; use `Http::fake` fallback). Assert `[]` returned; counter still incremented (attempt-based).
- **fail: 4xx** — fake throws auth/quota error. Assert `[]` returned; counter incremented.
- **fail: 5xx** — fake throws server error. Assert `[]` returned; counter incremented.
- **fail: malformed JSON / SDK schema validation rejection** — fake returns non-conforming structure. Assert `[]` returned; counter incremented.
- **fail: wrong step count** (fake returns 6 or 8 strings) — assert `[]` returned; counter incremented.
- **fail: missing API key / provider config** — temporarily unset `AI_API_KEY` config. Assert `[]` returned WITHOUT counter increment (different from over-quota: this is a config-error path, not a usage-attempt path) — OR with counter increment, decide at implementation time and document the choice. Default position: NO counter increment, because the call never had a real chance to fire.
- **ceiling enforcement**: pre-seed a counter row at `count = 20` for today. Call `suggestSteps()`. Assert `[]` returned; assert `StepSuggestionAgent::assertNeverPrompted()`; counter NOT incremented further.
- **isolation regression**: a second user with no counter rows is unaffected by user A's exhausted ceiling; their first call returns 7 strings.

#### 5. App-level test helper

**File**: `tests/TestCase.php` (modify) or `tests/Feature/Ai/AiTestHelpers.php`

**Intent**: A small helper that wires `StepSuggestionAgent::fake([...])` and clears it between tests so individual test methods don't leak state. Optional — only add if Pest's `beforeEach` doesn't already handle it cleanly.

**Contract**: a trait or shared `setUp` hook resetting `StepSuggestionAgent::fake()` to empty between tests; `preventStrayPrompts()` to surface any unfaked dispatch as a test failure.

### Success Criteria

#### Automated Verification

- All Phase 3 feature tests pass: `composer run test -- --filter=AiStepSuggesterTest`.
- `composer run test` (entire suite) still passes — Phase 1 + 2 + F-01 tests must continue to be green.
- `vendor/bin/pint` clean.
- No new lint warnings in `app/Ai/`, `app/Services/`, `tests/Feature/Ai/`.

#### Manual Verification

- A throwaway tinker script can call `app(\App\Services\AiStepSuggester::class)->suggestSteps($user, 'Learn welding', 'I want to learn TIG welding at home', [])` against a real Groq key (loaded into local `.env`) and receive a 7-string array. Run this once at the end of Phase 3; document the smoke check in the change folder.
- The same tinker call with `AI_API_KEY` unset returns `[]` and prints a single warning log line.

**Implementation Note**: After Phase 3 passes automated verification, pause for manual confirmation that the live-Groq smoke check returned 7 strings and that the cold/no-key path returned `[]`, before moving to Phase 4.

---

## Phase 4: Cross-slice handoff documentation

### Overview

F-02's outputs only become useful once S-01 and S-03 consume them, and the conventions F-02 establishes (graceful-fail return shape, counter-increment-before-call, sync-execution caveat) need to be visible to whoever writes those slices. This phase lands the docs that turn F-02's code into a durable contract.

### Changes Required

#### 1. Contract-surfaces entry

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Add an "AI suggestion surface (F-02)" section so S-01 / S-03 readers see the public seam, the graceful-fail contract, and the sync-execution caveat without having to read F-02's plan. Mirrors the "Authentication surface (F-01)" section's shape.

**Contract**: a new top-level section after "Authentication surface (F-01)" naming:
- **Public seam**: `App\Services\AiStepSuggester::suggestSteps(User, string, string, array): array<int, string>`
- **Return contract**: exactly 7 strings on success, `[]` on any failure mode (timeout, 4xx, 5xx, malformed JSON, wrong count, over-quota, missing key)
- **Counter table**: `ai_call_counters(owner_id, day, count)` — first per-user table; counter increments BEFORE the call
- **Ceiling**: 20 calls / user / calendar day (UTC); tunable via `config('ai.ceiling_per_day')`
- **Sync-execution constraint**: the call holds the request thread; NFR(edit-latency) "≤1s" does NOT apply to the AI path

#### 2. Lessons entry

**File**: `context/foundation/lessons.md`

**Intent**: Capture the "increment-before, fail-open" pattern so future code reviews and AI agents enforcing the convention can spot violations. The lesson is one rule, one why, one how-to-apply.

**Contract**: append a new section "AI integration: fail-open, increment-before-dispatch" with:
- **Context**: any service that wraps a remote AI call on the request thread
- **Problem**: a misconfigured account that fails on every call can fire dozens of requests/day and burn provider quota; a thrown exception from a transient provider hiccup can break venture creation
- **Rule**: graceful-fail at the service boundary (return an empty / sentinel value, never let provider exceptions cross the seam), AND increment the per-user counter BEFORE dispatching the call so failures still count toward the ceiling
- **Why**: defends both the user-facing NFR(ai-graceful) and the operator-facing NFR(ai-ceiling)
- **How to apply**: wrap the SDK call in `try { ... } catch (\Throwable $e) { Log::warning(...); return []; }`; do the counter upsert + increment in the same transaction as the existence check; do not retry

#### 3. Roadmap status update

**File**: `context/foundation/roadmap.md`

**Intent**: When F-02 lands, flip its `Status` column from `proposed` to `ready` (or `done` if archived). Also surface that F-02 unlocks S-01 / S-03 in the "Done" section after `/10x-archive` runs.

**Contract**: change the F-02 row's status, but do NOT pre-populate the "Done" section — that is `/10x-archive`'s job. This file edit happens only after Phase 3 is fully merged.

### Success Criteria

#### Automated Verification

- Markdown links in the contract-surfaces entry resolve (no broken `#anchor` references).
- `vendor/bin/pint` (or whatever doc linter is in place) reports no errors.
- Spell-check on the new sections clean.

#### Manual Verification

- Reading the `contract-surfaces.md` entry alone (without opening F-02's plan) gives a reader enough to call the service correctly.
- The lessons entry would catch a hypothetical S-03 PR that throws an exception across the service boundary or increments the counter after the call.
- Roadmap status change is consistent with whether F-02 has actually merged.

**Implementation Note**: After Phase 4 passes automated verification, the change is ready for `/10x-impl-review` and then `/10x-archive`.

---

## Testing Strategy

### Unit / Service-Level Tests

- `AiStepSuggesterTest` exercises every public path: success, all seven graceful-fail modes, ceiling enforcement, two-user isolation (the ceiling on user A does not affect user B), and counter-increment ordering (counter is incremented BEFORE the call even on failure).
- All AI-side calls are stubbed with `StepSuggestionAgent::fake([...])` — no live Groq calls in the test suite.

### Integration / Database Tests

- `AiCallCounterIsolationTest` exercises the model layer: two users, cross-user query returns zero rows; cascade-on-delete removes counter rows when the owning user is deleted.
- The migration is exercised in CI by running `php artisan migrate:fresh` against both SQLite (local) and Postgres (production-shape). Render's auto-deploy runs migrations on each deploy, so a green local run is the primary defense.

### Manual Verification

1. Set `AI_API_KEY` in local `.env` to a real Groq key; run `php artisan tinker --execute="dump(app(\App\Services\AiStepSuggester::class)->suggestSteps(\App\Models\User::first(), 'Renovate the bathroom', 'I want to redo my bathroom tiling and plumbing fixtures', []))"`. Expect 7 strings.
2. Repeat 21 times in the same calendar day. Expect the 21st call to return `[]` and to log a "ceiling reached" warning.
3. Unset `AI_API_KEY`; run the same tinker call. Expect `[]` and a "missing key" warning; counter NOT incremented.

## Performance Considerations

- The 15s timeout is the hard cap on per-request latency on the AI path. The application's NFR(edit-latency) of ≤1s does NOT apply here — explicitly documented in Phase 4.
- Counter upsert is a single Postgres round-trip via the unique index. Negligible compared to the AI call itself.
- The counter table grows at ~365 rows per user per year — well within Neon free tier's 0.5 GB ceiling for any plausible solo MVP user count.
- A future v2 with background workers would move the AI call off the request thread; the service signature does NOT need to change for that — only the dispatch becomes async, which is hidden behind the same `suggestSteps()` seam.

## Migration Notes

- The first migration in this change adds a new table — backward-compatible by definition (nothing read it before).
- The render.yaml `runtime: docker` rename is backward-compatible because Render's manifest schema accepts both forms during the transition window per their docs (verify before deploying); if not, the next deploy will pick up the new key and the old key will be ignored.
- Local SQLite DBs developed before this change need `php artisan migrate` to pick up the new table.

## References

- Research: `context/changes/ai-suggestion-service/research.md`
- F-01 plan-brief (pattern reference): `context/changes/minimal-auth-and-isolation/plan-brief.md`
- F-01 contract surfaces (isolation rules + S-01 enforcement checklist): `docs/reference/contract-surfaces.md`
- Isolation lesson: `context/foundation/lessons.md` § "Per-user isolation: relationship-only access in controllers"
- Sync-queue constraint: `context/foundation/infrastructure.md:69`
- Counter-table prescription: `context/foundation/infrastructure.md:123`
- Roadmap F-02 row: `context/foundation/roadmap.md:42`
- Roadmap F-02 entry: `context/foundation/roadmap.md:87-99`
- Provider-agnostic env decision (commit message): `git show f508749`
- PRD NFRs: `context/foundation/prd.md` § Non-Functional Requirements (ai-graceful, ai-ceiling)
- laravel/ai SDK docs (Context7 mirror): library id `/laravel/ai`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Config + env wiring + render.yaml drift fix

#### Automated

- [x] 1.1 composer install clean: composer install --no-dev --optimize-autoloader produces no errors — db485e6
- [x] 1.2 config/ai.php parses: php artisan config:show ai prints the expected array — db485e6
- [x] 1.3 php artisan config:clear && php artisan config:cache succeeds — db485e6
- [x] 1.4 vendor/bin/pint clean — db485e6

#### Manual

- [x] 1.5 .env.example reads cleanly; new section is visible and order-stable — db485e6
- [x] 1.6 render.yaml validates against Render's Blueprint schema with no warnings — db485e6
- [x] 1.7 php artisan tinker confirms config('ai.provider') returns 'groq' default and provided value when set — db485e6

### Phase 2: Rate-limit counter table + model + isolation test

#### Automated

- [x] 2.1 Migration applies cleanly on SQLite and matches Postgres shape: php artisan migrate:fresh succeeds — 0ad49ac
- [x] 2.2 Unit tests pass: composer run test -- --filter=AiCallCounterIsolationTest — 0ad49ac
- [x] 2.3 vendor/bin/pint clean — 0ad49ac
- [x] 2.4 Two-user isolation test passes per the S-01 enforcement checklist — 0ad49ac

#### Manual

- [x] 2.5 tinker smoke-test: two users, counter attached to one, other user's relationship returns empty — 0ad49ac
- [x] 2.6 Unique constraint on (owner_id, day) confirmed by duplicate-insert integrity-error path — 0ad49ac

### Phase 3: StepSuggestionAgent + AiStepSuggester service + validator

#### Automated

- [x] 3.1 All Phase 3 feature tests pass: composer run test -- --filter=AiStepSuggesterTest — dc7f421
- [x] 3.2 composer run test (entire suite) passes including F-01 tests — dc7f421
- [x] 3.3 vendor/bin/pint clean — dc7f421
- [x] 3.4 No new lint warnings in app/Ai/, app/Services/, tests/Feature/Ai/ — dc7f421

#### Manual

- [x] 3.5 Live-Groq tinker smoke-check returns 7 strings for a sample venture — dc7f421
- [x] 3.6 Cold/no-key tinker path returns [] and logs one warning — dc7f421

### Phase 4: Cross-slice handoff documentation

#### Automated

- [x] 4.1 Markdown anchor links in the contract-surfaces entry resolve — 9b122a9
- [x] 4.2 vendor/bin/pint (or doc linter) reports no errors — 9b122a9
- [x] 4.3 Spell-check on new sections clean — 9b122a9

#### Manual

- [x] 4.4 contract-surfaces.md entry is self-sufficient — a reader can call the service correctly without opening F-02's plan — 9b122a9
- [x] 4.5 Lessons entry would catch a hypothetical S-03 PR that throws across the service boundary or increments after the call — 9b122a9
- [x] 4.6 Roadmap F-02 status updated consistently with merge state — 9b122a9
