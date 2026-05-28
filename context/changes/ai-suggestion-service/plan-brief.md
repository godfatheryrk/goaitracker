# F-02 / ai-suggestion-service — Plan Brief

> Full plan: `context/changes/ai-suggestion-service/plan.md`
> Research: `context/changes/ai-suggestion-service/research.md`

## What & Why

Wire a provider-agnostic LLM step-suggestion service into the app so later slices (S-01, S-03) can generate the initial 7-step plan and on-demand extensions without each slice carrying its own provider integration, rate-limit ceiling, or graceful-failure plumbing. F-02 is service-shaped (no UI, no domain model) and ships before S-01 so the rate-limited service is in place when the first user-visible AI surface lands.

## Starting Point

Post-F-01: email+password auth, `users` table, the `owner_id` isolation convention in `docs/reference/contract-surfaces.md`, the `auth` middleware boundary. `app/Models/` carries only `User.php`; no domain tables; `render.yaml` has a `env: docker` drift to fix and no `AI_API_KEY` slot; provider env var was pre-renamed to a generic `AI_API_KEY` in commit `f508749` so F-02 is the first commit that actually consumes it.

## Desired End State

`App\Services\AiStepSuggester::suggestSteps($user, $title, $description, $currentSteps = [])` is callable from the container and ALWAYS returns `array<int, string>`: exactly 7 strings on success, `[]` on every failure mode. A `(owner_id, day, count)` counter on Neon enforces 20 calls / user / calendar day. `render.yaml` carries an `AI_API_KEY` slot; `.env.example` documents the three AI env vars; the contract-surfaces doc and `lessons.md` carry the convention forward so S-01 / S-03 inherit it.

## Key Decisions Made

| Decision                              | Choice                                                                                                  | Why (1 sentence)                                                                                                                | Source   |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | -------- |
| Provider                              | Groq (OpenAI-compatible)                                                                                | Free-forever permanent tier, sub-second latency, comfortably fits the 24h ceiling NFR; OpenAI-shape keeps the swap config-only.  | Research |
| HTTP client / SDK                     | `laravel/ai` pinned to a tight minor                                                                    | Laravel first-party, Groq is first-class, `HasStructuredOutput` replaces a hand-rolled 7-strings validator, native `Agent::fake()`. | Plan     |
| 24h ceiling                           | 20 calls / user / calendar day (UTC)                                                                    | Well above realistic single-user usage; well below Groq's 14,400 RPD; PRD says the limit is downstream-tunable.                  | Plan     |
| Return contract on failure            | `[]` (empty array) on every failure mode                                                                | One return shape for the caller; aligns with NFR(ai-graceful); user-facing behaviour is identical across failure causes.        | Plan     |
| Counter table shape                   | `(owner_id, day, count)` daily bucket with unique `(owner_id, day)`                                     | Matches `infrastructure.md:123` prescription; cheap to enforce; row growth bounded to ~365 rows per user per year.              | Research |
| Counter increment timing              | BEFORE the call (attempt-based)                                                                         | Defends the ceiling against retry storms; failed attempts still count, which is what the ceiling is for.                        | Plan     |
| HTTP timeout                          | 15 seconds                                                                                              | ~5x headroom over Groq's typical latency; well under Render's request idle ceiling; fails fast on tail-latency outliers.        | Plan     |
| JSON response shape                   | `{"steps": ["…", …]}` — object wrapping a 7-element string array (max 200 chars)                       | Matches OpenAI/Groq JSON-mode requirement; self-documenting; strings keep storage / DTO simple.                                  | Plan     |
| Service signature                     | `suggestSteps(User, string, string, array $currentSteps = []): array<int, string>`                       | One seam for S-01 (empty `$currentSteps` → initial) and S-03 (non-empty → extension with non-duplication prompt instruction).   | Plan     |

## Scope

**In scope:**
- `config/ai.php`, `.env.example` slots, `render.yaml` AI key slot + `env: docker` → `runtime: docker` fix
- `composer require laravel/ai` (pinned minor)
- `ai_call_counters(owner_id, day, count)` migration, `AiCallCounter` model, `User::aiCallCounters()` relationship
- `StepSuggestionAgent` (laravel/ai Agent with `HasStructuredOutput`) and `AiStepSuggester` wrapper service
- Feature tests for all seven graceful-fail modes + ceiling enforcement + two-user counter isolation
- Cross-slice handoff: `contract-surfaces.md` entry, `lessons.md` entry, roadmap status flip

**Out of scope:**
- Venture / Step / Expense models (S-01)
- Any HTTP route or controller (S-01, S-03)
- Retry / backoff logic
- Background queue / async dispatch (no Background Worker on Render free tier)
- Prompt-engineering iteration loop or A/B testing
- Rate-limit reset endpoint or admin tooling
- Request-side caching of AI responses
- Live Groq calls in CI (feature tests use `StepSuggestionAgent::fake([...])`)

## Architecture / Approach

```
Caller (S-01 / S-03)
        │
        ▼
AiStepSuggester::suggestSteps(User, title, desc, currentSteps=[])
   ├── ceiling check: SELECT count FROM ai_call_counters WHERE owner_id=… AND day=today
   ├── if >= 20  → log + return []
   ├── upsert ai_call_counters: count++ (BEFORE the call)
   ├── StepSuggestionAgent::make(...)->prompt(...)     ◀── laravel/ai SDK, Lab::Groq, Timeout 15s
   │     ▲                                                  HasStructuredOutput → {steps: string[7]}
   │     │ all exceptions caught here
   ├── validate: steps is array of exactly 7 strings (≤200 chars)
   └── return string[7]  OR  [] on any failure
```

The service is the only public seam. The Agent is concerned with prompting + provider; the service owns the counter, graceful-fail, and provider-agnostic discipline. If laravel/ai 0.x ever breaks, only the service file changes.

## Phases at a Glance

| Phase                                                | What it delivers                                                                                       | Key risk                                                                                                                  |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------- |
| 1. Config + env wiring + render.yaml fix             | `config/ai.php`, `.env.example` slots, render.yaml `runtime: docker` + `AI_API_KEY`, composer-pinned SDK | Pinning the wrong laravel/ai minor — too loose risks a breaking 0.x release before 2026-07-04 deadline                    |
| 2. Counter table + model + isolation test            | `ai_call_counters` table, `AiCallCounter` model, `User::aiCallCounters()`, two-user isolation test     | First per-user table — F-01 S-01 enforcement checklist must be fully satisfied or merge is blocked                        |
| 3. Agent + Service + validator + graceful-fail tests | `StepSuggestionAgent` (laravel/ai), `AiStepSuggester` service, 7 failure-mode tests, ceiling test       | laravel/ai 0.x API shape; `Agent::fake` may not model every failure mode → tests may need `Http::fake` fallback for some  |
| 4. Cross-slice handoff documentation                 | `contract-surfaces.md` entry, `lessons.md` rule, roadmap status flip                                   | Documentation drift — the contract section must stay accurate when S-01 / S-03 land or readers get a stale picture        |

**Prerequisites:** F-01 (functionally complete; `users` table, `auth` middleware, isolation convention codified). No code prerequisites beyond the project baseline.

**Estimated effort:** ~2-3 focused sessions across the four phases; Phase 3 is the largest (~half the work). Each phase ends in a commit and a manual-verification gate.

## Open Risks & Assumptions

- **laravel/ai is 0.x.** Pinned to a tight minor in composer.json defends the production deploy against a breaking release between merge and the 2026-07-04 deadline; the service wrapper is the single seam to absorb any future API change.
- **Calendar-day buckets ≠ true rolling 24h.** A user could fire 20 calls at 23:00 UTC and 20 more at 00:01 UTC and get 40 in two hours. Acceptable because the PRD NFR says the exact ceiling is downstream-tunable, and abuse mitigation can tighten later without changing the table shape.
- **Sync execution holds the request thread.** No Background Worker on Render free tier (`infrastructure.md:69`). Phase 4 documents this explicitly so future readers don't expect the ≤1s NFR to apply to the AI path.
- **The agent's prompt is "good enough for the 7-step contract"** — primary-metric tuning (≥3 of 7 AI steps kept) is a downstream concern measured against the initial-AI pool, not an F-02 deliverable.

## Success Criteria (Summary)

- `AiStepSuggester::suggestSteps()` returns 7 strings on a real Groq call and `[]` on every documented failure mode, verified by the test matrix in Phase 3.
- The 21st call from one user in the same UTC day returns `[]` without dispatching a request; a second user is unaffected.
- The `ai_call_counters` table passes the two-user isolation test per the S-01 enforcement checklist — user B cannot see user A's counter rows; deleting user A removes their counters via cascade.
