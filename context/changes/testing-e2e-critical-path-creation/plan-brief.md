# E2E Foundation + Critical-Path Venture-Creation Tests — Plan Brief

> Full plan: `context/changes/testing-e2e-critical-path-creation/plan.md`
> Strategy source: `context/foundation/test-plan.md` (§2 risks #1/#2)

## What & Why

Rollout Phase 1 of the test plan: stand up a deterministic, authenticated Playwright E2E harness and defend the two High×High venture-creation risks — **#1** AI fails on create but must degrade gracefully (venture still created, amber notice, typed input preserved, page usable — never a 500 / red error / lost input) and **#2** AI returns 7 steps that must all render as distinct list items (the north-star "AI plan lands as a usable starting plan").

## Starting Point

Playwright is installed with a working `playwright.config.ts`, a `storageState` `auth.setup.ts`, and a passing smoke test — but three things block real e2e: the LLM call is **server-side** (so browser network mocking can't reach it), the `rafal@test.local` auth user the setup logs in as **isn't seeded**, and the dev server runs against the committed dev DB with a **live Groq key**.

## Desired End State

`npm run test:e2e` boots a dedicated E2E server (own port) that always runs with a fake AI and an isolated `database/e2e.sqlite`, authenticates once, and runs four green specs (smoke + seed exemplar + two risk specs) — no real Groq call, dev DB untouched, each risk spec proven to fail when its protected behavior is deliberately broken.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| LLM-mock seam | Env-gated fake `AiStepSuggester`, title-keyed | Browser interception can't reach the server-side call; mirrors the existing `$this->mock(AiStepSuggester::class)` Feature pattern | Plan |
| E2E database | Dedicated `e2e.sqlite` via `webServer.env` + globalSetup | Full isolation from dev data and the live Groq config; repeatable | Plan |
| Test user | Add `rafal@test.local` to `DatabaseSeeder` | Simplest path to the credential `auth.setup.ts` already expects | Plan |
| Isolation | Per-test UI delete in `afterEach` | Strict per-test independence (user choice over once-per-run reset) | Plan |
| `seed.spec.ts` | Its own dedicated phase | It's load-bearing — every generated test copies it; its quality is a deliberate checkpoint | Plan |
| `test-plan.md` | Corrected in this change | §4/§6.3 assume the wrong (browser) mock mechanism; fix it so later phases don't inherit it | Plan |

## Scope

**In scope:** server-side fake-AI seam (env-gated), dedicated E2E DB + globalSetup, seeded auth user, `seed.spec.ts` exemplar + rules lever, risk #1 + risk #2 specs, `test-plan.md` §4/§6 reconciliation.

**Out of scope:** CI/YAML wiring; auth-UI assertions; isolation/IDOR & AI-ceiling e2e (Feature-owned); pixel/vision tests; rollout-phase-2/3 risks (#3 toggle-persist, #4 deadline, #5 variant compile); any change to production AI/controller logic.

## Architecture / Approach

When `E2E_FAKE_AI=1`, `AppServiceProvider` binds a `FakeAiStepSuggester` (extends the real class, overrides `suggestSteps`) that returns `[]` when the venture title contains a `force-ai-empty` sentinel, else a fixed 7 distinct steps — giving per-test control of both risk paths from one running server with no network. Playwright runs a dedicated-port `webServer` with `env` setting the flag + an isolated `DB_DATABASE`; `globalSetup` runs `migrate:fresh --seed` to build that DB (with the auth user); each test creates a unique-titled venture and deletes it via the dashboard Delete button (with `dialog.accept()`) in `afterEach`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Foundation | Fake-AI seam + dedicated DB + seeded user + globalSetup; smoke green | Server/DB/flag wiring (port reuse, migrate ordering) |
| 2. Seed exemplar | `seed.spec.ts` + rules lever, green | Exemplar quality propagates to all later tests |
| 3. Risk #1 spec | AI-graceful degrade spec + deliberate-break | Asserting URL instead of persisted+notice+title |
| 4. Risk #2 spec | 7-step render spec + deliberate-break | Asserting redirect instead of rendered step count |
| 5. Doc reconcile | `test-plan.md` §4/§6 corrected | Leaving the wrong mock mechanism for Phase 2/3 |

**Prerequisites:** Playwright installed (done); PHP/artisan runnable; the feature under test already built (it is — S-01).
**Estimated effort:** ~1–2 sessions across 5 phases (Phases 1–2 carry the plumbing; 3–5 are small).

**Handoff:** Phases 1 & 5 are app-code/config/docs → `/10x-implement`; Phases 2–4 are browser-risk specs → `/10x-e2e`. The shared `## Progress` lets each skill drive the right phases.

## Open Risks & Assumptions

- `migrate:fresh --seed` in `globalSetup` must complete before the server handles a request — verified in the Phase 1 manual check.
- The E2E server must never reuse an unflagged dev server — mitigated by a dedicated port.
- Assumes `php artisan serve` and `vendor/bin/pint`/`composer run analyse` run on the dev machine as in `AGENTS.md`.

## Success Criteria (Summary)

- Four green specs via `npm run test:e2e`; no real Groq call; dev DB untouched.
- Risk #1 spec asserts venture persisted + amber `role="alert"` notice + title preserved + page usable; risk #2 spec asserts exactly 7 distinct rendered steps.
- Each risk spec demonstrably goes red when its protected behavior is broken.
