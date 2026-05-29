---
date: 2026-05-28T00:00:00+02:00
researcher: rafal.klewek
git_commit: f50874932bf0586ae63a1bd1162bb8f789a5154f
branch: main
repository: 10xdevs (GOAITracker)
topic: "Are there any obstacles to start planning and then implementing ai-suggestion-service (F-02)?"
tags: [research, readiness, F-02, ai-suggestion-service]
status: complete
last_updated: 2026-05-28
last_updated_by: rafal.klewek
---

# Research: Obstacles to planning & implementing ai-suggestion-service (F-02)

**Date**: 2026-05-28
**Researcher**: rafal.klewek
**Git Commit**: f508749
**Branch**: main
**Repository**: 10xdevs (GOAITracker)

## Research Question

Are there any obstacles to start planning and then implementing this feature?

## Summary

**No obstacles.** `ai-suggestion-service` is the **F-02 foundation** in the roadmap and is **ready for `/10x-plan`**. The single prerequisite (F-01 — auth + isolation convention) is functionally complete: 5 commits land all 4 phases plus impl review and a follow-up fix, `users.id` exists, the `owner_id` convention is codified in `docs/reference/contract-surfaces.md` and `context/foundation/lessons.md`, and the `auth` middleware boundary is in place. F-02 does **not** need any domain model (Venture / Step / Expense) to exist — those land in S-01, and F-02 is sequenced before S-01 deliberately so the rate-limited service is in place when the first slice needs to call it.

What is **not** done is not a blocker but a set of inputs the `/10x-plan` conversation must resolve: a provider lock-in (Groq is the tentative default, swap is config-only), an exact 24h ceiling number, a 7-step JSON contract, a graceful-fail trigger taxonomy, and the rate-limit counter table shape. Two cosmetic / process gaps exist (F-01 is not yet archived in the roadmap `Status` column; `render.yaml` is missing the `AI_API_KEY` slot and has a `env: docker` → `runtime: docker` drift) — both are F-02 implementation work, not planning prerequisites.

**Recommended next step**: `/10x-plan ai-suggestion-service`. Optionally archive F-01 first (`/10x-archive minimal-auth-and-isolation`) so the roadmap's Done section reflects reality, but the plan does not need to wait on that.

## Detailed Findings

### F-02 sits *before* S-01 — no domain model required

The roadmap explicitly orders F-02 as the second foundation, after F-01 and before any slice introduces a domain model:

- `context/foundation/roadmap.md:42` (At-a-glance row F-02): _"Prerequisites: F-01"_, _"Unlocks: S-01 (initial 7-step generation), S-03 (on-demand extension)"_.
- `context/foundation/roadmap.md:87-99` (F-02 entry): _"(foundation) LLM step-suggestion service wired via the framework HTTP client, with a bounded per-user 24-hour call ceiling and a graceful-failure contract so AI failure never blocks venture creation."_
- `context/foundation/roadmap.md:103-113` (S-01 entry): _"Prerequisites: F-01, F-02"_. S-01 is where Venture + Step land.

This means the absence of `Venture` / `Step` / `Expense` models (confirmed by `app/Models/` containing only `User.php`, and the grep `venture|Venture|step|Step|expense|Expense` against `app/` returning zero matches) is **expected**, not a blocker. F-02's surface is service-shaped, not domain-shaped: an injectable service class that calls the provider over HTTP, validates the response, counts calls per user per 24h, and returns either an array of 7 strings or a null/empty marker. The first slice that *needs* a Venture row to attach steps to is S-01, which consumes F-02 — at which point S-01's S-01 enforcement checklist (`docs/reference/contract-surfaces.md`) applies to whatever per-user table F-02 has added.

### F-01 prerequisite is functionally complete

- Commits in order: `121978f` (login+logout+dashboard, p1), `bd9a30d` (registration, p2), `09ce79b` (tests + lessons + gitignore, p3), `b3a8e90` (isolation convention as durable contract, p4), `acb23ce` (epilogue), `98e3e81` (impl review + F1 fix). All four planned phases plus epilogue and post-review fix.
- `context/changes/minimal-auth-and-isolation/reviews/` carries both `plan-review.md` and `impl-review.md` — the review gates are honored.
- `context/changes/minimal-auth-and-isolation/plan-brief.md` matches the shipped state (auth surface = register + login + logout + dashboard; throttle, session regeneration, derived `name`, isolation convention).
- `docs/reference/contract-surfaces.md` carries the four isolation rules and the S-01 enforcement checklist — both inputs F-02's plan will reference if it adds a per-user counter table.
- `context/foundation/lessons.md` has the "Per-user isolation: relationship-only access in controllers" rule recorded — load-bearing for F-02 if it stores per-user state.

The one cosmetic gap: `context/foundation/roadmap.md:41` still shows F-01 with status `ready` (not `done`), and the roadmap's "Done" section is still empty. That is a metadata gap `/10x-archive` would close, not a code-level prerequisite. F-02 can plan and implement without it.

### What's already wired vs. what F-02 must add

Already present:
- `render.yaml` (production deploy target) — single Render Web Service, Frankfurt, free tier, with `APP_KEY` / `DB_URL` / `APP_URL` as `sync: false` env-var slots. `render.yaml:1-26`.
- Laravel HTTP client (Guzzle) is available out of the box for the provider call — no package install needed.
- Neon Postgres pooled connection in production; SQLite locally — enough surface to add a rate-limit counter table.
- `auth` middleware boundary + `$request->user()` access pattern (F-01 outputs) — F-02 controllers / services that talk to a logged-in user can lean on these without additional wiring.

Not yet present (F-02 deliverables, **not** F-02 prerequisites):
- `AI_API_KEY` env-var slot on `render.yaml`. Per the f508749 commit message: _"render.yaml has no AI env var; the slot will be wired when F-02 lands."_ Also flagged in `context/deployment/deploy-plan.md` as part of the "A1" edit: _"Replace `env: docker` with `runtime: docker`; under `envVars` add `AI_API_KEY` with `sync: false`"_.
- `AI_API_KEY` placeholder in `.env.example` — currently has no AI key entry (confirmed by `grep -i 'ai|llm|groq|openai|key' .env.example`).
- A provider-specific config file (e.g. `config/ai.php`) holding base URL, model id, timeout, request limit, and the 7-step schema.
- The service class itself (e.g. `App\Services\AiStepSuggester` or similar — name picked at plan time).
- The 24h-counter persistence (per `context/foundation/infrastructure.md:123`, the prescribed shape is a Postgres counter table: _"Implement rate-limit as a Postgres counter table on Neon: `(user_id, day, count)` with a unique constraint. Increments happen inline in the controller before the AI call. No queue or Redis needed."_).
- Feature tests asserting: (a) success returns exactly 7 strings; (b) timeout / 5xx / non-JSON / over-quota → empty result + no thrown exception across the F-01-style auth boundary; (c) counter increments on attempt (not on success-only, to defend the ceiling); (d) two-user isolation on the counter table if it carries `owner_id`.

### Decisions the plan must lock down (not blockers — planning inputs)

These are the answers `/10x-plan ai-suggestion-service` will need to land. None blocks starting planning — they *are* the plan.

1. **Provider lock-in.** The roadmap's Open Question #1 (`roadmap.md:192`) names Groq as the tentative default (_"OpenAI-compatible endpoint, permanent free tier with 14,400 RPD / 30 RPM that comfortably fits the F-02 24h-ceiling NFR, no credit card required"_). The recent commit `f508749` renamed the placeholder env var from `OPENAI_API_KEY` to `AI_API_KEY` precisely so the swap is config-only. The plan should either confirm Groq or pick an alternative; a later swap remains config-only.
2. **The exact 24h call ceiling.** PRD NFR(ai-ceiling): _"the exact limit is tunable downstream but the ceiling exists from v1."_ The plan picks the v1 number (a few dozen per user is the natural shape — accommodates initial creation + several FR-009 extensions per day).
3. **The 7-step JSON schema.** FR-008 contracts exactly 7. Groq is OpenAI-compatible and supports JSON mode / structured output. The plan defines: response shape (array of 7 short strings vs. richer objects with metadata), max string length, the system+user prompt template, and the parser/validator that **rejects** responses that aren't exactly 7 well-formed entries (rejection → graceful-fail path, not exception).
4. **Graceful-fail trigger taxonomy.** PRD NFR(ai-graceful): _"AI failures stay invisible to the user as failures."_ The plan enumerates what counts as a failure (timeout, network error, 4xx/5xx, malformed JSON, wrong step count, over-quota, missing API key) and the contract the calling code (S-01 later) can rely on (`array of 7` on success, `null` or `[]` on failure — pick one and write it down).
5. **The rate-limit counter table shape.** Per `infrastructure.md:123`. Likely a tiny table keyed by `(owner_id, day)` — the column name **must** be `owner_id` per the F-01 isolation convention (`lessons.md` + `contract-surfaces.md` rule 1), even though this table is internal plumbing. The plan should also decide whether the counter increments before or after the call (recommendation: before, to defend the ceiling against a stampede).
6. **Sync execution.** Per `infrastructure.md:69` (Devil's Advocate finding #1): _"Free Render tier has no Background Workers, so FR-008 / FR-009 AI step suggestions run on the `sync` queue driver — synchronously in the web request handler."_ The plan acknowledges the AI call holds the request, sets a strict request-side timeout (Groq's free-tier latency is sub-second to a few seconds typically, but the timeout cap defends against tail latency), and locks the contract: the ≤1s "edit feels instant" NFR explicitly **does not** apply to the AI path; only to non-AI editing.

### Soft / cosmetic gaps (do not block planning)

- **F-01 not archived in the roadmap.** `roadmap.md:41` still shows F-01 status `ready`, "Done" section empty. Run `/10x-archive minimal-auth-and-isolation` whenever convenient.
- **`render.yaml` drift.** `env: docker` should be `runtime: docker` per `deploy-plan.md`'s A1 edit, and `AI_API_KEY` slot is missing. F-02 will fix both as part of wiring the provider — captured in F-02 scope, not a planning prerequisite.

## Code References

- `app/Models/User.php` — only Eloquent model in the repo; confirms no domain models exist yet (and shouldn't, until S-01).
- `app/Http/Controllers/Auth/` — F-01 controllers; not consumed by F-02 directly but defines the `$request->user()` access pattern F-02 will follow if it exposes any endpoints.
- `database/migrations/` — three framework migrations only (users / cache / jobs); no domain tables. F-02's rate-limit counter table would be the first non-framework migration after F-01.
- `render.yaml:1-26` — production deploy manifest; needs `AI_API_KEY` slot + `env: docker` → `runtime: docker` during F-02 implementation.
- `context/foundation/roadmap.md:42` — F-02 row in the index.
- `context/foundation/roadmap.md:87-99` — full F-02 entry.
- `context/foundation/roadmap.md:192` — Open Roadmap Question on provider (Groq tentative).
- `context/foundation/infrastructure.md:69` — sync-queue constraint (no Background Worker on free tier).
- `context/foundation/infrastructure.md:123` — Postgres counter table as the rate-limit storage decision.
- `context/foundation/infrastructure.md:113` — risk register entry on AI-path latency vs. ≤1s NFR.
- `context/foundation/lessons.md:12-19` — isolation rule that applies if F-02 adds a per-user counter table.
- `docs/reference/contract-surfaces.md` (the four isolation rules + S-01 enforcement checklist) — load-bearing for F-02's counter-table migration and feature tests.
- `context/changes/minimal-auth-and-isolation/plan-brief.md` — F-01 done-state, useful as a pattern reference (phasing, plan-brief shape, reviews subfolder).

## Architecture Insights

- **F-02 is the *only* feature in the project's MVP that runs synchronously over the network on the request thread.** Every other slice's work is in-process. This makes F-02's timeout, retry posture, and graceful-fail contract uniquely load-bearing — they're the only place a slow remote dependency can stall a user-visible action.
- **The convention that owner-scoping is *structural, not nominal* (lessons.md "via a method on `$request->user()`") survives F-02 cleanly.** If F-02 exposes an endpoint (e.g. an internal `POST /ai/extend` used later by S-03), it goes through `$request->user()->ventures()->find($id)` to resolve the venture before the AI call; if F-02 only adds a service class + counter table, the rule still applies to the counter table's `owner_id` column.
- **Provider-agnostic env var naming is a deliberate ratchet.** The f508749 commit was a documentation-only change but its purpose was to bake the abstraction in before any code references it — so the first F-02 commit reads `env('AI_API_KEY')`, not `env('GROQ_API_KEY')`, even though Groq is the working assumption. A future provider swap is a single env-value change, not a refactor.

## Historical Context (from prior changes)

- `context/changes/minimal-auth-and-isolation/plan-brief.md` — F-02 inherits the plan-brief / phases / reviews layout that worked for F-01. The hand-rolled-thin discipline (controllers + FormRequests + a service class, no kit) is a reasonable template if F-02 adds any endpoint.
- `context/changes/minimal-auth-and-isolation/reviews/impl-review.md`, `reviews/plan-review.md` — the review-gate cadence is the established pattern.
- `context/changes/bootstrap-verification/verification.md` — post-scaffold audit from the bootstrap step; unrelated to F-02.
- `context/foundation/archive/` — earlier `prd.md` / `shape-notes.md` / `tech-stack.md` / `infrastructure.md` versions. Live foundation has already absorbed everything material from these; no need to re-read for F-02.

## Related Research

- None — this is the first research artifact for `ai-suggestion-service`.

## Open Questions

None block planning. The decisions the plan itself must lock down are enumerated under "Decisions the plan must lock down" above; they are inputs to `/10x-plan`, not gaps that need a separate research pass.
