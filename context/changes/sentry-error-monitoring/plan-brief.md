# Sentry Error & Performance Monitoring — Plan Brief

> Full plan: `context/changes/sentry-error-monitoring/plan.md`

## What & Why

Wire the Sentry SDK into the Laravel app so backend errors and performance traces are visible in one place instead of buried in Render logs. The load-bearing reason: the `AiStepSuggester` deliberately swallows every provider failure and returns `[]` (NFR(ai-graceful)) — so today those failures are invisible to *everyone*, including the operator. Sentry restores that visibility without changing what the user sees.

## Starting Point

Laravel `^13.8` / PHP `^8.4`, no Sentry anywhere. `bootstrap/app.php` has an empty `->withExceptions()` closure (clean wiring point); `AiStepSuggester.php` has four `return []` paths, each log-only; Larastan runs at level 5 with no baseline; `.env.example` documents env via section comments + prefixed keys.

## Desired End State

Uncaught exceptions and performance transactions flow to Sentry when a DSN is set (and the SDK no-ops when it isn't, so CI/local send nothing). Events carry `user.id` only — no email/IP. A provider failure inside the AI seam produces a Sentry event *and* still returns `[]` to the caller, contract intact.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| AI-failure capture scope | `\Throwable` catch only | The catch is the only genuine provider/network error; the other three `return []` paths (ceiling, bad count, bad value) are expected/validation noise. | Plan |
| User context / PII | `user.id` only, `send_default_pii=false` | Attributable for debugging without exporting PII — respects NFR(isolation), the project's privacy floor. | Plan |
| Performance tracing | Full (`traces_sample_rate=1.0`) but env-driven | Immediate trace data including the AI HTTP span; env override lets you dial down on Render without a code deploy. | Plan |
| Release tagging | `SENTRY_RELEASE` ← Render `RENDER_GIT_COMMIT` | Regression-by-release view for cheap. | Plan |
| Testing | Manual verification only | No automated test for the seam; existing `CreateVentureTest` guards the `[]` contract half. | Plan |
| Capture call | `captureException($e)`, self-guarded | Catch has the exception (full stack trace); its own try/catch means a Sentry hiccup can never break the `[]` contract. | Plan |

## Scope

**In scope:** install `sentry/sentry-laravel`; publish + env-configure `config/sentry.php`; wire `Integration::handles()`; document env vars; id-only user-context middleware; guarded `captureException` in the AI catch.

**Out of scope:** automated tests for Sentry behavior; capture on the non-exception `[]` paths; email/IP/PII; Render dashboard changes (manual operator step); CI secret wiring; release-creation/source-maps/`sentry-cli`; queue tracing tuning; frontend SDK.

## Architecture / Approach

Two phases. Phase 1 is the vendor-documented install, configured entirely through env so the same code is inert without a DSN. Phase 2 adds the two GOAITracker-specific pieces the generic install doesn't give: a `SentryContext` middleware (id-only `configureScope`→`setUser`) appended to the `web` group, and a self-contained `captureException` in the `AiStepSuggester` `\Throwable` catch before `return []`.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. SDK install & global config | Uncaught exceptions + tracing reach Sentry; env-driven; inert without DSN | `sentry/sentry-laravel` may not yet publish a constraint matching Laravel `^13.8` |
| 2. User context & AI-seam visibility | `user.id`-only events; swallowed AI failures captured without breaking `[]` | The capture call must never propagate past the `[]` seam |

**Prerequisites:** A Sentry account + project (for the DSN, used only in manual verification); nothing blocks writing the code.
**Estimated effort:** ~1 session, 2 phases.

## Open Risks & Assumptions

- Laravel 13 is bleeding-edge — `composer require` resolution against the SDK is the first thing Phase 1 surfaces; pin a compatible tag rather than forcing platform reqs if it conflicts.
- Assumes Render exposes `RENDER_GIT_COMMIT` for release tagging (degrades gracefully if absent).
- Assumes the `web` group runs after auth so `$request->user()` is populated; the middleware no-ops otherwise.

## Success Criteria (Summary)

- `sentry:test` lands an event in Sentry; an uncaught exception shows up tagged with environment + release.
- A forced AI provider failure produces a Sentry event while the venture still creates with 0 steps and shows the `ai_unavailable` notice — the contract holds.
- `composer run analyse` and `composer run test` stay green with no DSN configured.
