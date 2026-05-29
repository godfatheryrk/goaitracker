---
project: GOAITracker
version: 1
status: draft
created: 2026-05-27
updated: 2026-05-28
prd_version: 1
main_goal: speed
top_blocker: time
---

# Roadmap: GOAITracker

> Derived from `context/foundation/prd.md` (v1) + auto-researched codebase baseline.
> Edit-in-place; archive when superseded.
> Slices below are listed in dependency order. The "At a glance" table is the index.

## Vision recap

GOAITracker fights the blank page at the start of a personal venture: the user types a short
title and description, and the app proposes an editable starting plan instead of an empty list.
The product's core bet is that turning "design a plan" into "edit a plan" is the cheapest lever
against start-stall; a unified per-venture view (steps, progress, expenses, deadline pressure)
is what keeps the user coming back once the venture is underway. AI is an enhancement, never a
hard dependency — if it fails, the user still creates the venture and works manually.

## North star

**S-01: User creates a venture and immediately sees exactly 7 AI-suggested steps** — this is the
validation milestone, because if AI-suggested steps don't land as a usable starting plan, the
entire product thesis fails; everything else only matters once this works.

> "North star" here means the smallest end-to-end slice whose successful delivery would prove the
> core product hypothesis — placed as early as its prerequisites allow, because the rest of the
> roadmap is only worth building if this slice works.

## At a glance

| ID    | Change ID                          | Outcome (user can …)                                                    | Prerequisites | PRD refs                                              | Status   |
| ----- | ---------------------------------- | ----------------------------------------------------------------------- | ------------- | ----------------------------------------------------- | -------- |
| F-01  | minimal-auth-and-isolation         | (foundation) email+password auth + every query scoped to the owner      | —             | FR-001, FR-002, FR-003, Access Control, NFR(isolation), NFR(pw-hash) | ready    |
| F-02  | ai-suggestion-service              | (foundation) LLM step service wired, per-user 24h ceiling, graceful fail | F-01          | FR-008, FR-009, NFR(ai-ceiling), NFR(ai-graceful)     | ready    |
| S-01  | create-venture-with-ai-plan        | create a venture and see exactly 7 AI-suggested steps                   | F-01, F-02    | US-01, FR-004, FR-008, FR-006                         | done     |
| S-02  | edit-and-track-steps               | add / edit / delete / complete steps and see progress                   | S-01          | FR-010, FR-011, FR-012, FR-013, FR-018, NFR(edit-latency) | done     |
| S-03  | extend-plan-with-ai                | trigger AI to append more steps (append-only, off-metric)               | S-01, F-02    | FR-009                                                | done     |
| S-04  | list-and-delete-ventures           | view a list of own ventures, open or delete one                         | S-01          | FR-005, FR-006, FR-007                                | done     |
| S-05  | step-deadlines-and-pressure-signals | set optional step deadlines; see imminent/overdue badges + list marker  | S-02, S-04    | FR-014, FR-020, FR-021                                | proposed |
| S-06  | venture-expenses-and-cost          | add / edit / delete expenses; see total cost on detail + list           | S-01, S-04    | FR-015, FR-016, FR-017, FR-019                        | done |

## Streams

Navigation aid — groups items that share a Prerequisites chain. Canonical ordering still lives in the dependency graph below; this table is the proposed reading order across parallel tracks.

| Stream | Theme                  | Chain                                           | Note                                                                         |
| ------ | ---------------------- | ----------------------------------------------- | ---------------------------------------------------------------------------- |
| A      | Plan & AI core         | `F-01` → `S-01` → `S-02`                        | The north-star spine; the must-have path under the `speed` goal.             |
| B      | AI generation service  | `F-02` → `S-03`                                 | Joins Stream A at `S-01` (F-02 also unlocks the initial generation).         |
| C      | Portfolio & signals    | `S-04` → `S-06`; `S-05`                          | Return-surface + cost/deadline signals; `S-05` joins Stream A at `S-02`.     |

## Baseline

What's already in place in the codebase as of `2026-05-27` (auto-researched + user-confirmed).
Foundations below assume these are present and do NOT re-scaffold them.

- **Frontend:** present — Vite + Tailwind v4 (`vite.config.js`); only `resources/views/welcome.blade.php` and an empty `app.js`. Tooling is wired; no application UI yet.
- **Backend / API:** partial — Laravel 13.8; single welcome route in `routes/web.php`, empty base `Controller.php`, no domain logic.
- **Data:** present — SQLite locally / Neon Postgres in prod; only framework migrations exist (users, sessions, cache, jobs, password_reset_tokens). No domain tables — venture/step/expense schemas are built inside their owning slices, not as a standalone foundation.
- **Auth:** absent — no starter kit (Breeze/Jetstream/Fortify), no login/register routes or controllers; only the `User` model as the framework contract.
- **Deploy / infra:** present — `Dockerfile` + `render.yaml` (Render Web Service + Neon Postgres, `eu-central-1`), auto-deploy on push to `main`; deploy plan recorded at `context/deployment/deploy-plan.md`. No GitHub Actions / CI.
- **Observability:** present — log channels configured (`config/logging.php`), Pail in dev, stderr transport for prod. No external APM (Sentry/Datadog/OTel).

## Foundations

### F-01: Minimal auth and per-user isolation

- **Outcome:** (foundation) email+password register / login / logout in place, sessions issued, and every domain query scoped to the owning user.
- **Change ID:** minimal-auth-and-isolation
- **PRD refs:** FR-001, FR-002, FR-003, Access Control, NFR(isolation), NFR(pw-hash)
- **Unlocks:** S-01 (and every venture / step / expense slice — all data is per-user); reduces the privacy-incident risk named in the isolation guardrail.
- **Prerequisites:** —
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Per-user isolation is the load-bearing privacy guardrail — wrong scoping here is a privacy incident regardless of feature correctness. Sequenced first so every later slice inherits correct scoping rather than retrofitting it.
- **Status:** ready

### F-02: AI suggestion service

- **Outcome:** (foundation) LLM step-suggestion service wired via the framework HTTP client, with a bounded per-user 24-hour call ceiling and a graceful-failure contract so AI failure never blocks venture creation.
- **Change ID:** ai-suggestion-service
- **PRD refs:** FR-008, FR-009, NFR(ai-ceiling), NFR(ai-graceful)
- **Unlocks:** S-01 (initial 7-step generation), S-03 (on-demand extension).
- **Prerequisites:** F-01
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:**
  - Confirm the LLM provider — Groq tentatively assumed (free-forever permanent tier, OpenAI-compatible API, fits the F-02 graceful-fail + 24h-ceiling NFRs cleanly). Generic `AI_API_KEY` slot will be wired into `render.yaml` when F-02 lands. Owner: user. Block: no.
- **Risk:** Provider not yet formally chosen; treated as non-blocking because the key slot is stubbed and swapping providers behind the HTTP client is cheap. Sequenced before the AI slices so both S-01 and S-03 consume one rate-limited service instead of duplicating the integration and the 24h ceiling.
- **Status:** ready

## Slices

### S-01: Create a venture with an AI plan

- **Outcome:** user can create a venture (title + short description) and immediately see exactly 7 AI-suggested steps on the venture detail view — or an empty step list plus a non-blocking notice if AI is unavailable.
- **Change ID:** create-venture-with-ai-plan
- **PRD refs:** US-01, FR-004, FR-008, FR-006
- **Prerequisites:** F-01, F-02
- **Parallel with:** —
- **Blockers:** —
- **Unknowns:** —
- **Risk:** This is the validation milestone — if AI-suggested steps don't land as a usable starting plan, the product thesis fails. Sequenced as early as its two foundations allow; the graceful-failure path (FR-008 acceptance) must ship with it, not after.
- **Status:** done

### S-02: Edit and track steps

- **Outcome:** user can add, edit, and delete steps (delete confirmed) and toggle completion directly on the list, and see the proportion of completed vs total steps.
- **Change ID:** edit-and-track-steps
- **PRD refs:** FR-010, FR-011, FR-012, FR-013, FR-018, NFR(edit-latency)
- **Prerequisites:** S-01
- **Parallel with:** S-03, S-04
- **Blockers:** —
- **Unknowns:** —
- **Risk:** Closes the primary success-metric loop — "3 of 7 AI steps kept" is only measurable once edit/keep/delete exist, and "kept" includes edited. The ~1s perceived-latency guardrail applies to every edit here, so the interaction model is load-bearing.
- **Status:** done

### S-03: Extend the plan with AI

- **Outcome:** user can trigger AI from the venture detail view to append more steps to an existing plan (append-only; extension steps do NOT count toward the primary-metric AI pool; the call shares the per-user 24h ceiling).
- **Change ID:** extend-plan-with-ai
- **PRD refs:** FR-009
- **Prerequisites:** S-01, F-02
- **Parallel with:** S-02, S-04
- **Blockers:** —
- **Unknowns:** —
- **Risk:** The frozen-pool carve-out must be implemented so extension steps don't pollute the "3 of 7" measurement. Low risk because F-02 already owns the provider call and the rate ceiling — this slice only adds the trigger and the off-metric tag.
- **Status:** done

### S-04: List and delete ventures

- **Outcome:** user can view a list of all their own ventures and open or delete one (delete confirmed).
- **Change ID:** list-and-delete-ventures
- **PRD refs:** FR-005, FR-006, FR-007
- **Prerequisites:** S-01
- **Parallel with:** S-02, S-03
- **Blockers:** —
- **Unknowns:** —
- **Risk:** The list is the return surface that the secondary success metric (users come back at least once) depends on. Its cross-venture signals — total cost (S-06) and deadline marker (S-05) — are added by those slices rather than blocking this one, so the list ships thin and gains signals incrementally.
- **Status:** proposed

### S-05: Step deadlines and pressure signals

- **Outcome:** user can set an optional deadline per step, see imminent (≤3 days) / overdue badges on the venture detail view, and see on the venture list which ventures carry imminent or overdue steps.
- **Change ID:** step-deadlines-and-pressure-signals
- **PRD refs:** FR-014, FR-020, FR-021
- **Prerequisites:** S-02, S-04
- **Parallel with:** S-06
- **Blockers:** —
- **Unknowns:** —
- **Risk:** FR-020's badge needs steps with completion state (S-02); FR-021's list marker needs the list surface (S-04). Sequenced after both so deadline data and both surfaces (detail badge + list marker) are populated in one coherent pass. Deadlines are optional throughout — reminders only fire for steps that set one.
- **Status:** proposed

### S-06: Venture expenses and total cost

- **Outcome:** user can add, edit, and delete expenses on a venture (delete confirmed) and see the accumulated total cost on both the venture detail view and the venture list.
- **Change ID:** venture-expenses-and-cost
- **PRD refs:** FR-015, FR-016, FR-017, FR-019
- **Prerequisites:** S-01, S-04
- **Parallel with:** S-05
- **Blockers:** —
- **Unknowns:** —
- **Risk:** FR-019 requires total cost on two surfaces, so this slice touches both detail and list (hence the S-04 prerequisite). Lower product-thesis priority than the AI loop, so sequenced after the core wedge under the `speed` goal. Edit/delete carry the destructive-action confirm guardrail.
- **Status:** done

## Backlog Handoff

| Roadmap ID | Change ID                          | Suggested issue title                                    | Ready for `/10x-plan` | Notes                                  |
| ---------- | ---------------------------------- | -------------------------------------------------------- | --------------------- | -------------------------------------- |
| F-01       | minimal-auth-and-isolation         | Auth (email+password) + per-user data isolation          | yes                   | Run `/10x-plan minimal-auth-and-isolation` |
| F-02       | ai-suggestion-service              | AI step-suggestion service + 24h call ceiling            | no                    | Needs F-01; confirm LLM provider       |
| S-01       | create-venture-with-ai-plan        | Create venture → 7 AI-suggested steps                    | done                  | Needs F-01, F-02 (north star)          |
| S-02       | edit-and-track-steps               | Edit / add / delete / complete steps + progress          | done                  | Needs S-01                             |
| S-03       | extend-plan-with-ai                | Extend step plan via AI (append-only)                    | done                  | Needs S-01, F-02                       |
| S-04       | list-and-delete-ventures           | Venture list + delete venture                            | done                  | Needs S-01                             |
| S-05       | step-deadlines-and-pressure-signals | Step deadlines + imminent/overdue badges & list marker   | no                    | Needs S-02, S-04                       |
| S-06       | venture-expenses-and-cost          | Venture expenses + total cost on detail & list           | done                  | Needs S-01, S-04                       |

This table is the clean handoff to Jira/Linear or any MCP-backed backlog. One row per `F-NN` / `S-NN`.

## Open Roadmap Questions

1. **Which LLM provider backs the AI step-suggestion service?** — Owner: user. Block: none (proceeding on Groq as the tentative default: OpenAI-compatible endpoint, permanent free tier with 14,400 RPD / 30 RPM that comfortably fits the F-02 24h-ceiling NFR, no credit card required. F-02 will wire a generic `AI_API_KEY` env var so a later swap to OpenRouter / OpenAI / Anthropic / Gemini is a config change, not a refactor). Gates the implementation detail of F-02, S-01, and S-03 if the answer changes.

## Parked

- **File-format exports (CSV / PDF / spreadsheet reports)** — Why parked: PRD §Non-Goals; users keep spreadsheets for reports.
- **Sharing ventures with other users** — Why parked: PRD §Non-Goals; v2+ must preserve the per-user-isolation NFR if added.
- **Additional auth methods (OAuth, magic link, SSO)** — Why parked: PRD §Non-Goals; email+password only for v1.
- **Breaking steps into sub-steps** — Why parked: PRD §Non-Goals; steps are atomic in v1.
- **External-delivery reminders (email / push)** — Why parked: PRD §Non-Goals; in-app surfacing only in v1.
- **Multi-currency expenses** — Why parked: PRD §Non-Goals; single currency in v1.
- **Per-step expense allocation** — Why parked: PRD §Non-Goals; expenses are venture-level only in v1.
- **Cross-venture dashboard / progress roll-up** — Why parked: PRD §Non-Goals; each venture viewed individually in v1.
- **"Regenerate AI suggestion" action** — Why parked: PRD §Non-Goals; AI runs once at creation, FR-009 extension is append-only.
- **AI-generated step execution hints** — Why parked: PRD §Non-Goals; AI generates the initial plan only, not how-to guidance.

## Done

(Empty on first generation. `/10x-archive` appends an entry here — and flips that item's `Status` to `done` — when a change whose `Change ID` matches the item is archived. Do NOT pre-populate.)
