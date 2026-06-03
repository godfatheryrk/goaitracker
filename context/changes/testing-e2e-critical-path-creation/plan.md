# E2E Foundation + Critical-Path Venture-Creation Tests — Implementation Plan

## Overview

Phase 1 of the `context/foundation/test-plan.md` rollout: stand up a deterministic, authenticated Playwright E2E harness and use it to defend the two High×High critical-path risks of venture creation — **#1** (AI fails → graceful amber degrade, input preserved) and **#2** (AI succeeds → all 7 steps render). The harness must drive a *running* Laravel app with the external LLM neutralized at a **server-side** seam (browser-level network interception cannot reach the synchronous server-side AI call), against an isolated database, authenticated once via `storageState`.

## Current State Analysis

The E2E runner is **partially** stood up and two assumptions in `test-plan.md` are wrong:

- **Installed & configured**: `@playwright/test` is in `devDependencies` and installed; `playwright.config.ts` defines a `setup` project (`auth.setup.ts` → `storageState`) + a `chromium` project + a `webServer` booting `php artisan serve --port=8000`. `auth-smoke.spec.ts` proves storageState injection. `npm run test:e2e` → `playwright test`.
- **🔴 The LLM call is server-side.** `VenturesController::store` (`app/Http/Controllers/VenturesController.php:58`) → `AiStepSuggester::suggestSteps` (`app/Services/AiStepSuggester.php:13`) → `StepSuggestionAgent` (Laravel AI gateway) → Groq, **synchronously on the request thread**. Playwright's `page.route()` only intercepts browser→app traffic, so it **cannot** mock the app→Groq call. `test-plan.md` §4 / §6.3 assume "Playwright route interception (network layer)" — that mechanism is invalid here and must be corrected.
- **🔴 E2E auth is broken today.** `auth.setup.ts` logs in as `rafal@test.local / password123`, but `DatabaseSeeder` (`database/seeders/DatabaseSeeder.php:20`) only creates `test@example.com / password`. The dev server runs against the committed `database/database.sqlite` (PHPUnit uses `:memory:` via `phpunit.xml`), which has no such user → `setup` fails.
- **🔴 Real Groq key in play.** `.env` holds a live `AI_API_KEY`. Any E2E run that reuses an unflagged dev server would make real, paid, nondeterministic AI calls.
- **No `seed.spec.ts`** — the load-bearing exemplar every generated test copies (per `change.md`) does not exist yet.

### Key Discoveries:

- `AiStepSuggester` is a concrete class with **no constructor dependencies** (`app/Services/AiStepSuggester.php:11`) and is bound as a bare singleton (`app/Providers/AppServiceProvider.php:16`). The controller type-hints the **concrete class** (no interface), so any stand-in MUST `extends AiStepSuggester`.
- Existing Feature tests already swap the suggester via `$this->mock(AiStepSuggester::class, …)` (`tests/Feature/Ventures/CreateVentureTest.php:28`) — the chosen E2E seam mirrors that established pattern.
- Create-flow locators are clean: `getByLabel('Title')`, `getByLabel('Description')`, `getByRole('button', {name: 'Create venture'})` (`resources/views/ventures/create.blade.php:18,22,30`), redirect to `ventures.show`.
- AI-unavailable notice renders as `<div role="alert" class="alert alert-warning">` with exact text *"AI couldn't suggest steps right now — you can add them manually."* (`VenturesController.php:80`, `resources/views/ventures/show.blade.php:10`).
- Steps render as `<li>` rows, each body in `<span data-step-body>` inside the `<ul>` (`resources/views/ventures/show.blade.php:47-65`).
- Venture delete lives **only on the list/dashboard row** in v1 (S-04 contract), as a form with `onsubmit="return confirm(...)"` — Playwright dismisses dialogs by default, so any delete needs an explicit `dialog.accept()` handler.
- `config/database.php` resolves the sqlite path from `env('DB_DATABASE', database_path('database.sqlite'))` — so a `webServer.env.DB_DATABASE` override cleanly points the E2E server at a separate file.

## Desired End State

`npm run test:e2e` boots a **dedicated** E2E server (own port) that always runs with the fake AI and an isolated `database/e2e.sqlite`, authenticates once as a seeded `rafal@test.local`, and runs four green specs: the smoke check, the `seed.spec.ts` exemplar, and the two risk specs. No real Groq call is ever made; the developer's `database/database.sqlite` is never touched. `test-plan.md` §4/§6 describe the real server-side seam. Verification: each spec passes via `npx playwright test <file>`, and each risk spec has been shown to go **red** when its protected behavior is deliberately broken.

## What We're NOT Doing

- **No CI wiring.** The `test-plan.md` §5 gate ("e2e on PR") is named there; the YAML belongs to an infra lesson, not this change.
- **No auth-UI assertions.** `auth.setup.ts` *produces* `storageState`; we do not test register/login forms (`test-plan.md` §7).
- **No isolation/IDOR or AI-ceiling e2e re-do** — already owned by Feature tests (`test-plan.md` §7).
- **No pixel/visual snapshots, no vision model** — no §2 risk is visual-only.
- **No tests for Phase 2/3 rollout risks** (#3 toggle-persist, #4 deadline pressure, #5 variant compilation) — those are later rollout phases.
- **No change to the production `AiStepSuggester`, the AI seam, or the controller logic.** The fake is additive and env-gated; production behavior is untouched.

## Implementation Approach

Build the harness first (Phase 1), prove the exemplar against it (Phase 2), then write one risk spec per phase (Phases 3–4), each hardened with a deliberate-break check. Finally reconcile the strategy doc (Phase 5). The AI is controlled by an **env-flag-gated fake**: when `E2E_FAKE_AI` is set, `AppServiceProvider` binds a `FakeAiStepSuggester` (extends the real class, overrides `suggestSteps`) whose return is **keyed on the venture title** — a sentinel substring forces the empty-array failure path (risk #1); any other title returns a fixed list of 7 distinct steps (risk #2). This gives per-test control from a single running server with zero network dependency, mirroring the existing `$this->mock(AiStepSuggester::class)` Feature pattern. A dedicated E2E SQLite file (`webServer.env.DB_DATABASE`) plus a Playwright `globalSetup` that runs `migrate:fresh --seed` keeps E2E data isolated and the auth user present; per-test `afterEach` deletes each created venture through the list Delete button for strict independence.

## Critical Implementation Details

- **The fake MUST `extends AiStepSuggester`.** The controller type-hints the concrete class (no interface); a non-subclass binding would fail injection.
- **Dialogs dismiss by default.** Playwright auto-dismisses (`confirm()` → `false`), so the venture-delete form won't submit unless the test registers `page.once('dialog', d => d.accept())` *before* clicking Delete. This applies to every `afterEach` cleanup and any in-test delete.
- **Bind via `env()`, not `config()`, in `register()`.** Config isn't built during `register()`; read the flag with `env('E2E_FAKE_AI')`. This is reliable because the E2E server never runs `config:cache` (a cached config would make `env()` return null outside config files — documented gotcha, not a path we take).
- **Dedicated port prevents reuse of an unflagged dev server.** If the E2E server shared port 8000 with a developer's running `composer run dev`, `reuseExistingServer` could attach to a server lacking `E2E_FAKE_AI`/the E2E DB → real Groq + dev-DB pollution. Use a separate port so the flagged server is always the one under test.
- **DB must be migrated before the server serves a request.** `globalSetup` runs `migrate:fresh --seed` against the e2e file directly (shelling `artisan`), independent of the `webServer`; the seeded file is then read by the booting server. Verify ordering during manual check.

## Phase 1: E2E Foundation — Server Seam, Dedicated DB, Auth

### Overview

Make a running server deterministic (fake AI), isolated (own DB), and authenticatable (seeded user) — so the existing `auth-smoke.spec.ts` passes against the new harness.

### Changes Required:

#### 1. Seed the E2E auth user

**File**: `database/seeders/DatabaseSeeder.php`

**Intent**: Ensure `rafal@test.local / password123` (the user `auth.setup.ts` logs in as) exists whenever the database is seeded, without disturbing the existing `test@example.com` seed.

**Contract**: Add a second `User::factory()->create([...])` with `name`, `email => 'rafal@test.local'`, and an explicit `password => Hash::make('password123')`. Keep the existing user.

#### 2. Deterministic fake suggester

**File**: `app/Services/FakeAiStepSuggester.php` (new)

**Intent**: A no-network, deterministic stand-in for `AiStepSuggester`, used only under the E2E flag, so the running server never calls Groq and each risk path is reproducible.

**Contract**: `class FakeAiStepSuggester extends AiStepSuggester`, overriding `suggestSteps(User $user, string $title, string $description, array $currentSteps = []): array`. Behavior is keyed on `$title`: if it contains a case-insensitive sentinel (e.g. `force-ai-empty`) return `[]`; otherwise return a fixed array of **7 distinct** strings, each ≤200 chars (e.g. prefixed `[E2E] …`). Does not call `parent::`, makes no HTTP call, and does not touch `AiCallCounter`. Lives in `app/` (not `tests/`) because the running server only autoloads `App\`.

#### 3. Env-gated binding

**File**: `app/Providers/AppServiceProvider.php`

**Intent**: Swap the singleton to the fake only when `E2E_FAKE_AI` is set, leaving the production binding intact.

**Contract**: In `register()`, branch on `env('E2E_FAKE_AI')`: when truthy, `$this->app->singleton(AiStepSuggester::class, fn () => new FakeAiStepSuggester());` else the existing bare singleton. (See Critical Implementation Details for the `env()`-vs-`config()` rationale.)

#### 4. Playwright config: dedicated server + isolated DB + globalSetup

**File**: `playwright.config.ts`

**Intent**: Boot a dedicated E2E server that always uses the fake AI and the isolated DB, never reusing a developer's dev server, and prepare the DB once before the suite.

**Contract**: Point `use.baseURL` and `webServer.url` at a dedicated port (e.g. `8001`); `webServer.command` = `php artisan serve --port=8001`; add `webServer.env` = `{ E2E_FAKE_AI: '1', DB_DATABASE: <absolute path to database/e2e.sqlite>, APP_ENV: 'local' }`; add top-level `globalSetup: './tests/e2e/global-setup.ts'`. Keep `reuseExistingServer: !process.env.CI` (safe now that the port is dedicated).

#### 5. Global setup: build the E2E database

**File**: `tests/e2e/global-setup.ts` (new)

**Intent**: Guarantee a fresh, migrated, seeded `e2e.sqlite` (with the auth user) before any test runs.

**Contract**: Default-export an `async` function that ensures the sqlite file exists (create empty file if missing), then runs `php artisan migrate:fresh --seed` via Node `child_process` with `env` including `DB_DATABASE=<e2e path>` and `APP_ENV=local`; reject on non-zero exit so a bad DB fails the run loudly.

#### 6. Ignore ephemeral E2E artifacts

**File**: `.gitignore`

**Intent**: Keep the throwaway E2E DB, auth state, and reports out of version control.

**Contract**: Add `database/e2e.sqlite`, `playwright/.auth/`, `test-results/`, `playwright-report/`.

### Success Criteria:

#### Automated Verification:

- PHP formatting passes: `vendor/bin/pint --test`
- Static analysis stays green: `composer run analyse`
- E2E auth harness works end-to-end: `npx playwright test auth-smoke.spec.ts` (boots the dedicated server, globalSetup seeds `e2e.sqlite`, `setup` logs in as `rafal@test.local`, smoke reaches `/dashboard`)

#### Manual Verification:

- After an E2E run, `database/database.sqlite` (the dev DB) is unchanged — only `database/e2e.sqlite` was written.
- No outbound Groq call occurred (server log shows the fake path; no AI HTTP).
- The fake is inert in normal dev: running `composer run dev` (no `E2E_FAKE_AI`) still uses the real `AiStepSuggester`.

---

## Phase 2: `seed.spec.ts` Exemplar + E2E Rules Lever

### Overview

Create the load-bearing exemplar every generated spec copies, and the rules lever the generator reads — both proven green against the Phase 1 harness.

### Changes Required:

#### 1. The seed exemplar

**File**: `tests/e2e/seed.spec.ts` (new)

**Intent**: A single, exemplary test demonstrating the four non-negotiable patterns (role/label locators, per-test isolation + cleanup, wait-for-state, auth via `storageState`) on a real flow, so its quality propagates to every later spec.

**Contract**: One test — "creates a venture and lands on its detail view" — that: navigates to `ventures.create`; fills via `getByLabel('Title')` (unique timestamp-suffixed title) + `getByLabel('Description')`; submits via `getByRole('button', {name: 'Create venture'})`; awaits `page.waitForURL(/ventures\/\d+/)` (no `waitForTimeout`); asserts the title heading is visible (`getByRole('heading', {name: <title>})` / `toBeVisible()`); and an `afterEach` that deletes the venture via the dashboard Delete button with a registered `dialog.accept()`. Carries a provenance header comment linking to the seed pattern and the E2E rules. Relies on the storageState session (no UI login).

#### 2. E2E rules / cookbook lever

**File**: `tests/e2e/README.md` (new)

**Intent**: The second quality lever — a short, local rules doc the generator reads alongside the root `CLAUDE.md` E2E block; also the source the Phase 5 cookbook lift draws from.

**Contract**: Document (a) the E2E ruleset pointer (defer to `CLAUDE.md`: `getByRole`/`getByLabel`/`getByText`, no `waitForTimeout`, per-test isolation + unique ids + cleanup, risk-named tests); (b) the fake-AI seam (`E2E_FAKE_AI=1`, `FakeAiStepSuggester`, the `force-ai-empty` title sentinel → `[]`, any other title → fixed 7 steps); (c) the conventions (dedicated port + `e2e.sqlite`, `afterEach` UI delete + `dialog.accept()`).

### Success Criteria:

#### Automated Verification:

- The exemplar passes: `npx playwright test seed.spec.ts`

#### Manual Verification:

- Review `seed.spec.ts` against the five anti-patterns (hallucinated assertion, brittle selector, shared state, wait-for-time, no cleanup) — it must violate none, because it is the template.
- Confirm the venture created by the seed is gone from the dashboard after the run (cleanup works).

---

## Phase 3: Risk #1 Spec — AI-Graceful Degrade

### Overview

Prove that when AI fails on create, the venture is still created, the amber notice shows, the typed title is preserved, and the page stays usable — never a 500, a red error, or lost input.

### Changes Required:

#### 1. The risk #1 spec

**File**: `tests/e2e/venture-create-ai-graceful.spec.ts` (new)

**Intent**: Drive the forced-empty AI path and assert the graceful degrade outcome (not the URL).

**Contract**: A risk-named test (provenance header citing `test-plan.md` risk #1) that: fills a unique title **containing the `force-ai-empty` sentinel** + a description; submits; awaits the show URL; then asserts all of — (a) the amber notice is visible via `getByRole('alert')` and carries the exact text *"AI couldn't suggest steps right now — you can add them manually."*; (b) the typed title is preserved as the page heading; (c) the page is usable: the "+ Add your first step" affordance is visible (empty step list, manual path available). `afterEach` deletes the venture (dashboard Delete + `dialog.accept()`). Must NOT assert only the redirect/URL (the named anti-pattern).

### Success Criteria:

#### Automated Verification:

- The spec passes: `npx playwright test venture-create-ai-graceful.spec.ts`

#### Manual Verification:

- **Deliberate-break check**: temporarily make `FakeAiStepSuggester` ignore the sentinel (return 7 steps) — re-run, confirm the spec goes **red** (no alert / wrong state), then revert. (Document the inversion used.)
- Visually confirm the alert renders **amber** (`alert-warning`), not red, and the title input was not lost.

---

## Phase 4: Risk #2 Spec — 7-Step Render

### Overview

Prove that a successful 7-step AI response renders all 7 steps as distinct, usable list items on the detail view — the north-star "AI plan lands as a usable starting plan."

### Changes Required:

#### 1. The risk #2 spec

**File**: `tests/e2e/venture-create-seven-steps.spec.ts` (new)

**Intent**: Drive the fixed 7-step success path and assert the rendered step count (not the redirect).

**Contract**: A risk-named test (provenance header citing `test-plan.md` risk #2) that: fills a unique title **without** the sentinel (→ fake returns the fixed 7) + a description; submits; awaits the show URL; then asserts exactly **7** step rows render — locate the steps list and count `getByRole('listitem')` (or `[data-step-body]`) === 7, and assert at least two distinct step bodies are visible. `afterEach` deletes the venture (dashboard Delete + `dialog.accept()`). Must NOT assert only redirect/URL, and must never hit the real provider.

### Success Criteria:

#### Automated Verification:

- The spec passes: `npx playwright test venture-create-seven-steps.spec.ts`
- Full E2E suite passes together: `npm run test:e2e`

#### Manual Verification:

- **Deliberate-break check**: temporarily make `FakeAiStepSuggester` return 6 steps (or break the show-view `@foreach`) — re-run, confirm the spec goes **red**, then revert. (Document the inversion used.)
- Visually confirm 7 distinct steps render in order on the detail view.

---

## Phase 5: Reconcile `test-plan.md`

### Overview

Correct the strategy doc so the LLM-mock mechanism reflects reality (server-side fake, not browser interception) and fill the now-shipped cookbook entries.

### Changes Required:

#### 1. Fix the LLM-mock mechanism + stack status

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the invalid "Playwright route interception (network layer)" LLM-mock mechanism (§4) with the real server-side env-gated fake, and flip the e2e/auth/mock stack rows from "none yet" to in-place.

**Contract**: Edit the §4 "API / LLM mocking" row to describe the server-side seam (`E2E_FAKE_AI` → `FakeAiStepSuggester`; browser interception cannot reach the synchronous server-side AI call; routing/DB/transaction stay real). Update the "e2e" and "auth for e2e" §4 rows to reflect that Playwright + `storageState` + dedicated DB now exist. Bump the §8 freshness note.

#### 2. Fill the cookbook

**File**: `context/foundation/test-plan.md`

**Intent**: Turn the "TBD — see Phase 1" cookbook stubs into the real recipes now that Phase 1 has shipped them.

**Contract**: §6.2 ("Adding an e2e test") — file location `tests/e2e/<feature>.spec.ts`, model on `seed.spec.ts`, `storageState` auth, `getByRole`, unique title + `afterEach` UI delete with `dialog.accept()`, dedicated port + `e2e.sqlite`. §6.3 ("Mocking the LLM at the server layer" — retitle from "network layer") — the `E2E_FAKE_AI` flag, `FakeAiStepSuggester`, the `force-ai-empty` sentinel for `[]`, fixed 7 otherwise; explicitly note browser route interception does NOT work for this server-side call.

### Success Criteria:

#### Automated Verification:

- The doc no longer presents browser/network route interception as the LLM-mock mechanism: `grep -n "route interception" context/foundation/test-plan.md` returns no §4/§6.3 LLM-mechanism hit (or only a "does NOT work" caveat).

#### Manual Verification:

- §4, §6.2, and §6.3 read correctly and match what Phases 1–4 actually built.

---

## Testing Strategy

### E2E Tests (the deliverable):

- `seed.spec.ts` — exemplar happy-path create (Phase 2).
- `venture-create-ai-graceful.spec.ts` — risk #1 (Phase 3).
- `venture-create-seven-steps.spec.ts` — risk #2 (Phase 4).
- Each: role/label locators, unique timestamped title, wait-for-state, `afterEach` UI delete with `dialog.accept()`, auth via `storageState`.

### Existing coverage (unchanged):

- ~22 PHPUnit Feature tests (Ai / Auth / Expenses / Steps / Ventures) — `composer run test`. The AI-graceful and 7-step paths are also covered at the Feature layer (`tests/Feature/Ventures/CreateVentureTest.php`); the e2e layer adds the cross-boundary render proof those can't reach.

### Manual Testing Steps:

1. Run `npm run test:e2e` from a clean checkout — all four specs green.
2. Confirm `database/database.sqlite` untouched; only `database/e2e.sqlite` changed.
3. For each risk spec, run the documented deliberate-break and confirm red, then revert.

## Performance Considerations

The fake removes the 15s AI timeout from the create path entirely, so E2E runs are fast and deterministic. `migrate:fresh --seed` runs once per suite in `globalSetup`.

## Migration Notes

No production schema or behavior changes. The new `FakeAiStepSuggester` and the `E2E_FAKE_AI` binding branch are dormant unless the flag is set. `database/e2e.sqlite` is ephemeral and git-ignored.

## References

- Test strategy & risk map: `context/foundation/test-plan.md` (§2 risks #1/#2, Risk Response Guidance)
- Change identity: `context/changes/testing-e2e-critical-path-creation/change.md`
- Venture surface contract: `docs/reference/contract-surfaces.md` (Venture surface S-01, AI suggestion surface F-02)
- AI seam: `app/Services/AiStepSuggester.php:13`, `app/Providers/AppServiceProvider.php:16`
- Create/show views: `resources/views/ventures/create.blade.php`, `resources/views/ventures/show.blade.php:10,47`
- Controller: `app/Http/Controllers/VenturesController.php:52`
- Existing harness: `playwright.config.ts`, `tests/e2e/auth.setup.ts`, `tests/e2e/auth-smoke.spec.ts`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: E2E Foundation — Server Seam, Dedicated DB, Auth

#### Automated

- [x] 1.1 PHP formatting passes: `vendor/bin/pint --test` — b9e54a7
- [x] 1.2 Static analysis stays green: `composer run analyse` — b9e54a7
- [x] 1.3 E2E auth harness works end-to-end: `npx playwright test auth-smoke.spec.ts` — b9e54a7

#### Manual

- [x] 1.4 Dev DB `database/database.sqlite` unchanged after an E2E run (only `e2e.sqlite` written) — b9e54a7
- [x] 1.5 No outbound Groq call occurred (fake path in server log) — b9e54a7
- [x] 1.6 Fake is inert in normal dev (`composer run dev` uses the real suggester) — b9e54a7

### Phase 2: seed.spec.ts Exemplar + E2E Rules Lever

#### Automated

- [x] 2.1 The exemplar passes: `npx playwright test seed.spec.ts`

#### Manual

- [x] 2.2 `seed.spec.ts` reviewed against the five anti-patterns — violates none
- [x] 2.3 Seed-created venture gone from the dashboard after the run (cleanup works)

### Phase 3: Risk #1 Spec — AI-Graceful Degrade

#### Automated

- [ ] 3.1 The spec passes: `npx playwright test venture-create-ai-graceful.spec.ts`

#### Manual

- [ ] 3.2 Deliberate-break: fake ignores sentinel → spec goes red → reverted (inversion documented)
- [ ] 3.3 Alert renders amber (not red); typed title not lost

### Phase 4: Risk #2 Spec — 7-Step Render

#### Automated

- [ ] 4.1 The spec passes: `npx playwright test venture-create-seven-steps.spec.ts`
- [ ] 4.2 Full E2E suite passes together: `npm run test:e2e`

#### Manual

- [ ] 4.3 Deliberate-break: fake returns 6 steps (or break show `@foreach`) → spec goes red → reverted (inversion documented)
- [ ] 4.4 7 distinct steps render in order on the detail view

### Phase 5: Reconcile test-plan.md

#### Automated

- [ ] 5.1 Doc no longer presents browser/network route interception as the LLM-mock mechanism: `grep -n "route interception" context/foundation/test-plan.md`

#### Manual

- [ ] 5.2 §4, §6.2, §6.3 read correctly and match what Phases 1–4 built
