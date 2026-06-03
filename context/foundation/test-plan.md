# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-06-03 (Phase 1 shipped: e2e foundation + critical-path venture-creation specs)

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic check that already catches the
   regression. This project already has a **meaningful backend Feature suite
   (~22 tests)** — e2e is added ONLY for risks that cross several boundaries
   (form → controller → AI seam → DB → redirect → render) or exist only in
   the rendered UI / JS islands, never to re-prove what a Feature test
   already owns.
2. **User concerns are first-class evidence.** Risks anchored in "the
   builder is worried about X, and the failure would surface somewhere in
   `<area>`" carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `app/`, `resources/`
(excludes `routes/`, `database/`, docs, fixtures, build output — per the
Phase 1 scope confirmation).

## 2. Risk Map

The top failure scenarios this project must protect against, ordered by
risk = impact × likelihood. Risks are failure scenarios in user / business
terms, not test names. The Source column cites the *evidence that surfaced
this risk* — never a specific file as "where the failure lives" (that is
research's job, see §1 principle #3).

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | AI times out / fails on venture-create, but instead of a clean amber "add steps manually" notice (venture still created, typed title + description preserved, page usable), the user gets a broken page or loses their input | High | High | interview Q1; US-01 AI-failure AC; NFR(ai-graceful); hot-spot dirs `app/Http/Controllers` (16/30d), `resources/views/ventures` (16/30d) |
| 2 | AI returns 7 steps, but the detail view does not render all 7 as a usable, distinct list — the north-star "AI plan lands as a usable starting plan" fails at the render layer | High | High | interview Q4; US-01, FR-006, FR-008; roadmap north star; hot-spot dir `resources/views/ventures` (16/30d), `app/Http/Controllers` (16/30d) |
| 3 | User toggles a step complete (JS island updates the DOM without reload), but the change never persisted — on a real page reload it silently reverts ("the toggle lied"). The S-07 daisyUI restyle just rewrote the markup the island hooks into | High | Medium | roadmap S-07 Risk (island selectors `data-toggle-completion`); FR-013; hot-spot dirs `resources/js` (4/30d), `resources/views/ventures` (16/30d) |
| 4 | Deadline pressure does not surface correctly in the rendered UI: imminent vs overdue misclassified, the list "⚠ pressure" marker missing, or stale emphasis lingers after a step is completed (live un-emphasis broken) | Medium | Medium | interview Q3; FR-020, FR-021; roadmap S-07 Risk (`data-pressure-class`); hot-spot dirs `resources/views/ventures` (16/30d), `resources/js` (4/30d) |
| 5 | A daisyUI variant class on a key control (primary action button, amber AI-unavailable alert, deadline badge) silently fails to compile and renders as a bare / colorless fallback — "nothing errors, so the bug ships" | Medium | Medium | interview Q3; `context/foundation/lessons.md` S-07 pitfall (realized across ui.button / ui.alert / ui.badge); hot-spot dirs `resources/views/components` (8/30d), `resources/views/ventures` (16/30d) |

**Impact × Likelihood rubric.** High = user loses access / data / money or
the failure is publicly visible / area changes weekly or already burned;
Medium = feature degrades with a workaround, touched occasionally; Low =
cosmetic, stable code. Rows are ordered High × High first.

**Abuse / security lens (resolved by cost × signal).** The product has auth,
user input, and an AI-cost surface, so an abuse scenario is in scope — but
the two real abuse surfaces are already protected by the *cheapest possible*
test, and adding an e2e re-do would yield zero new signal:

- **Per-user isolation / IDOR** — proven by two-user-404 Feature tests in
  every shipped slice (see `docs/reference/contract-surfaces.md` S-01
  enforcement checklist). E2E re-do is redundant. Recorded in §7.
- **AI rate-limit ceiling abuse** — proven by `AiCallCounter` Feature tests
  (F-02 surface). E2E re-do is redundant. Recorded in §7.

This is a deliberate cost × signal decision (and aligns with interview Q5),
not an oversight: the abuse surfaces are covered, just not at the e2e layer.

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | Venture row persisted; the degraded detail view is usable — typed title preserved, empty step list + manual add-step path visible. The amber `ai_unavailable` flash itself is asserted at the **Feature layer** (`CreateVentureTest`), not e2e: flash has a one-request lifetime and the shared single-user e2e session makes it racy under parallel workers | "AI failure = an error page" — it must be a *non-blocking* degrade, NOT a 500, a red error, or lost input | The `ai_unavailable` flash render path on the detail view; the AI-call-OUTSIDE-transaction boundary that keeps the counter increment from rolling back | e2e with the **LLM faked at the server seam** (`force-ai-empty` title → `[]`) to force the empty path; routing / DB / transaction stay real. Exact flash wording covered deterministically at the Feature layer | Asserting only the page title / URL instead of the durable degrade signals (persisted venture + preserved title + empty plan + manual add path) |
| #2 | All 7 AI-returned steps render as 7 distinct, usable list items on the detail view after submit | "URL changed / redirect happened = success" — must assert the 7 steps are actually *visible* | A deterministic 7-step fake shape; the step-list render in the detail view | e2e with the **LLM faked at the server seam to return a fixed 7-step payload** (any non-sentinel title) | Asserting only the redirect / URL, not the rendered step count; using the real (nondeterministic, paid) provider |
| #3 | After completing a step AND reloading the page, the step is still complete (checkbox + line-through persist) | "Optimistic DOM update = persisted" — must reload to prove the fetch actually hit the DB | The completion-toggle endpoint JSON contract `{is_completed, completed, total}` and how the island applies it post-restyle | e2e, **no mocking** (real toggle endpoint + real DB), with a full reload between action and assertion | Asserting the in-memory DOM state without a reload — persistence IS the risk |
| #4 | Imminent → amber badge, overdue → red "Overdue" badge on detail; "⚠ pressure" marker on the list row; completing a pressured step drops the emphasis live | "A badge is in the DOM = correct" — must distinguish imminent vs overdue, and prove the live un-emphasis on completion | `Step::deadlinePressure()` classification + the `data-pressure-class` live class-toggle; the `IMMINENT_WINDOW_DAYS` window | e2e, **no mocking**; seed unique deadlines per run relative to today | Brittle CSS-class selector on the badge; assert by role / visible text instead |
| #5 | A key control's intended daisyUI variant utility is actually present in the compiled output (not a bare fallback) | "Looks fine in my browser ⇒ all variants compiled" — Tailwind v4 only extracts complete literal class strings from source | Which variants are built by runtime concatenation vs written literally; the compiled CSS output | **deterministic build-time CSS-grep / component-render assertion** — NOT e2e, NOT a vision model | A pixel snapshot (brittle, catches little); layering a vision model over a deterministic CSS check |

## 3. Phased Rollout

Each row is a discrete rollout phase that will open its own change folder
via `/10x-new`. Status moves left-to-right through the values below; the
orchestrator updates Status as artifacts appear on disk.

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | E2E foundation + critical-path venture creation | Stand up Playwright + a `storageState` auth-setup project + the `seed.spec.ts` exemplar, then defend the AI-graceful degrade and the happy-path 7-step render | #1, #2 | e2e + server-side AI fake | implementing | context/changes/testing-e2e-critical-path-creation/ |
| 2 | Rendered-state integrity (JS island + deadline pressure) | Prove completion persists across a real reload, and deadline badge / list marker classification surfaces correctly (incl. live un-emphasis) | #3, #4 | e2e (no mocking) | not started | — |
| 3 | daisyUI variant-compilation guard | Lock that key controls' daisyUI variant utilities actually compile (no silent bare fallback) | #5 | deterministic CSS-grep / component check | not started | — |

**Status vocabulary** (fixed — parser literals): `not started` →
`change opened` → `researched` → `planned` → `implementing` → `complete`.

Order rationale: Phase 1 MUST be first — no e2e runner exists yet, and the
`seed.spec.ts` quality determines every later test's quality (the Planner
and every generated test copy it); it also attacks the #1 fear and the
north-star render. Phase 2 targets the highest-churn rendered surfaces
(`resources/js/app.js`, `resources/views/ventures`) that the backend suite
cannot reach, freshly rewritten by the S-07 restyle. Phase 3 is the cheapest
layer, a different tool, and lowest priority — it may collapse into a single
build-time check.

## 4. Stack

The classic test base for this project. AI-native tools (if any) carry a
`checked:` date so future readers can see which lines need re-verification.

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration (backend) | PHPUnit (Laravel Feature tests) | Laravel 13.8 | Existing, meaningful: ~22 Feature tests across Ai / Auth / Expenses / Steps / Ventures. Run via `composer run test`. |
| e2e | Playwright | ^1.60.0 (in place — Phase 1); checked: 2026-06-03 | Installed. `playwright.config.ts` + `tests/e2e/` (`seed.spec.ts` exemplar, two risk specs). Dedicated server on port 8001 booted by `webServer`. Run via `npm run test:e2e`. Follows the E2E ruleset in `CLAUDE.md` / `/10x-e2e` (mirrored in `tests/e2e/README.md`). |
| API / LLM mocking | Server-side env-gated fake (`E2E_FAKE_AI` → `FakeAiStepSuggester`) | in place — Phase 1 | **The LLM call is server-side and synchronous** (`VenturesController::store` → `AiStepSuggester` → Groq, on the request thread), so browser route interception CANNOT reach it. `E2E_FAKE_AI=1` binds `FakeAiStepSuggester` (no network, no `AiCallCounter`); routing / DB / transaction stay real. Behaviour keyed on the venture title: sentinel `force-ai-empty` → `[]` (forces the degrade path); any other title → a fixed 7 steps. |
| auth for e2e | Playwright `storageState` + setup project + dedicated e2e DB | in place — Phase 1 | `auth.setup.ts` authenticates once as the seeded `rafal@test.local` to produce `storageState`; specs never log in per test. A dedicated server (port 8001) + isolated `database/e2e.sqlite` (built by `global-setup.ts` via `migrate:fresh --seed`) keep the dev DB untouched. Auth UI flows are NOT a test target (§7). |
| variant-compilation guard | CSS-grep over built output / component render assertion | none yet — see §3 Phase 3 | Deterministic check that daisyUI variant utilities appear in compiled CSS — per the `lessons.md` S-07 pitfall. |
| accessibility | none planned | n/a | Out of scope for v1; agents interact via the a11y tree (`getByRole`) regardless. |
| (optional) AI-native / vision | Playwright MCP `--caps=vision` | not available in current session | Supplement for visual-only risks only; not justified by any §2 risk (exact UI look is excluded, §7). |

**Stack grounding tools (current session):**
- Docs: Context7 available — will ground current Playwright config / `storageState` / `getByRole` / route-mocking APIs in Phase 1; checked: 2026-06-03
- Search: Exa.ai available — fallback for current Playwright-on-Laravel setup guidance; checked: 2026-06-03
- Runtime/browser: Playwright MCP not available in current session — runner not installed; Phase 1 installs the Playwright CLI; checked: 2026-06-03
- Provider/platform: none used — Render deploy + CI wiring are out of this rollout's scope (§5, §7)

## 5. Quality Gates

The full set of gates that must pass before a change reaches production.
"Required after §3 Phase N" means the gate is enforced once that rollout
phase lands; before that, the gate is `planned`.

| Gate | Where | Required? | Catches |
|---|---|---|---|
| `vendor/bin/pint` (format) | local | required | PHP style drift |
| unit + integration (PHPUnit Feature) | local + CI | required (already wired) | backend logic regressions |
| e2e on critical flows (Playwright) | CI on PR | required after §3 Phase 1 | broken critical user paths (AI-graceful create, 7-step render, toggle-persist, deadline surfacing) |
| variant-compilation guard | local + CI | required after §3 Phase 3 | daisyUI variant utilities silently failing to compile |
| visual diff (deterministic) | CI on PR | optional | rendering regressions — not adopted (no visual-only §2 risk; exact look excluded, §7) |
| multimodal visual review | CI on PR | optional | not adopted (no visual-only §2 risk) |

The e2e suite is intended to **run in CI on PR** (the gate above). *Wiring*
the CI job that runs it is an infra/CI task that belongs to an infra lesson,
not a test-rollout phase — this plan names the gate and defers the YAML.
Note the distinction with §7: keeping / running CI is wanted; *testing the
CI configuration itself* is what is out of scope.

## 6. Cookbook Patterns

How to add new tests in this project. Each sub-section is filled in once the
relevant rollout phase ships; before that, the sub-section reads "TBD — see
§3 Phase N."

### 6.1 Adding a backend Feature test (existing pattern)

- **Location**: `tests/Feature/<Domain>/<Name>Test.php`.
- **Reference test**: `tests/Feature/Ventures/CreateVentureTest.php` (AI
  faked via `$this->mock(AiStepSuggester::class, …)`), and
  `tests/Feature/Ventures/VentureIsolationTest.php` (two-user-404 isolation).
- **Run locally**: `composer run test`.
- **When NOT to add e2e instead**: if an isolated HTTP request can prove the
  risk (controller logic, validation, isolation, AI-graceful at the seam),
  a Feature test is cheaper and sufficient.

### 6.2 Adding an e2e test

- **Location**: `tests/e2e/<feature>.spec.ts`. Model it on
  [`tests/e2e/seed.spec.ts`](../../tests/e2e/seed.spec.ts) — the load-bearing
  exemplar — and read [`tests/e2e/README.md`](../../tests/e2e/README.md).
- **Auth**: rely on the `storageState` session injected by the `setup`
  project (`auth.setup.ts`); never log in through the UI per test.
- **Locators**: `getByRole` / `getByLabel` / `getByText` first (never CSS /
  XPath); `getByTestId`/`[data-*]` only when the a11y role is ambiguous (e.g.
  the page carries several lists, so step rows are located via
  `[data-step-body]`).
- **Wait for state**: `waitForURL` / `toBeVisible` / `toHaveCount` — never
  `waitForTimeout`.
- **Isolation + cleanup**: unique timestamp-suffixed title per test; an
  `afterEach` that deletes the created venture via the dashboard Delete
  button. The delete form uses a native `confirm()`, and Playwright
  auto-**dismisses** dialogs — register `page.once('dialog', d => d.accept())`
  *before* clicking, or the delete silently no-ops.
- **Harness**: the suite boots a dedicated server on **port 8001** against an
  isolated **`database/e2e.sqlite`** (git-ignored), so a run never touches the
  dev DB. Run via `npm run test:e2e` (or `npx playwright test <file>`).
- **Don't re-prove a Feature test.** Ephemeral session state (flash) is racy
  under the shared single-user e2e session — assert *durable, render-derived*
  outcomes in e2e and leave session/flash assertions to the Feature layer.

### 6.3 Mocking the LLM at the server layer in an e2e test

- **Browser route interception does NOT work here.** The LLM call is
  server-side and synchronous (`VenturesController::store` →
  `AiStepSuggester::suggestSteps` → Groq, on the request thread), so
  `page.route()` (browser→app only) cannot reach the app→Groq call.
- **The seam is in the app, env-gated.** `E2E_FAKE_AI=1` (set by
  `playwright.config.ts` → `webServer.env`) makes `AppServiceProvider` bind
  [`FakeAiStepSuggester`](../../app/Services/FakeAiStepSuggester.php) in place
  of the real suggester — no network, no `AiCallCounter`, deterministic.
- **Per-path control via the venture title**: a title containing the
  case-insensitive sentinel **`force-ai-empty`** → returns `[]` (drives the
  AI-graceful degrade, risk #1); any other title → a fixed list of **7
  distinct** steps (risk #2). Routing / DB / transaction stay real.

### 6.4 Testing JS-island behavior that must survive a reload

- TBD — see §3 Phase 2 (completion toggle persists across a full page
  reload; deadline `data-pressure-class` live toggle).

### 6.5 Guarding daisyUI variant compilation

- TBD — see §3 Phase 3 (deterministic CSS-grep / component-render assertion
  that a control's variant utility is present in built output, not a bare
  fallback).

### 6.6 Per-rollout-phase notes

(Optional. After each phase lands, `/10x-implement` appends a 2–3 line note
here capturing anything surprising the rollout phase taught.)

## 7. What We Deliberately Don't Test

Exclusions agreed during the rollout (Phase 2 interview, Q5). Future
contributors should respect these unless the underlying assumption changes.

- **Per-user isolation / IDOR and AI-ceiling abuse at the e2e layer** —
  already owned by the cheapest test (two-user-404 + `AiCallCounter` Feature
  tests). An e2e re-do adds cost, no signal. Re-evaluate only if the access
  model changes (v2 sharing / v3 co-editing). (Source: Phase 2 interview Q5;
  §2 abuse-lens cost × signal call.)
- **Auth UI flows (register / login form fields)** — low blast radius,
  rarely changes; isolation is already proven at the Feature layer. The e2e
  auth setup project authenticates once to *produce* `storageState`, it does
  NOT assert on the auth UI. Re-evaluate if auth gains new methods (OAuth /
  magic link). (Source: Phase 2 interview Q5.)
- **The CI configuration itself** — the e2e suite is *meant* to run in CI
  (§5), but we do not write tests that assert against pipeline YAML or CI
  setup. Keeping and running CI is wanted; testing its config is not.
  (Source: Phase 2 interview Q5.)
- **Exact UI look (pixel / visual snapshots)** — brittle, catch little; no
  §2 risk is visual-only (the variant-compilation guard, #5, is a
  deterministic compiled-CSS check, not a pixel snapshot). Re-evaluate only
  if a layout / z-index / animation regression becomes a real, repeated
  pain. (Source: §2 cost × signal call.)

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-06-03
- Stack versions last verified: 2026-06-03
- AI-native tool references last verified: 2026-06-03
- §4 stack rows + §6.2 / §6.3 cookbook reconciled to the shipped Phase 1
  harness (server-side `E2E_FAKE_AI` fake — NOT browser route interception):
  2026-06-03

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes.
