---
change_id: sentry-error-monitoring
title: Wire Sentry error & performance monitoring into the Laravel app
status: implementing
created: 2026-06-04
updated: 2026-06-04
archived_at: null
---

## Notes

Add Sentry (sentry.io) for error tracking + performance monitoring on the Laravel
app deployed to Render.

Scope sketch (from the design conversation, confirmed against current Sentry docs via Context7):

- `composer require sentry/sentry-laravel`; `php artisan sentry:publish` to create `config/sentry.php`.
- DSN via `SENTRY_LARAVEL_DSN` — set in Render dashboard env, NOT committed.
- Register `Sentry\Laravel\Integration::handles($exceptions)` in `bootstrap/app.php`
  (Laravel 11/12 exception-handling shape).
- `environment` + `release` env-driven; map Render's `RENDER_GIT_COMMIT` → `SENTRY_RELEASE`
  so deploys are tagged for regression tracking.
- `traces_sample_rate` — 1.0 acceptable for the MVP's low traffic; revisit downward later.
- Verify with `php artisan sentry:test`.

AI-path visibility (the load-bearing bit): `AiStepSuggester::suggestSteps()` deliberately
swallows every `\Throwable` and returns `[]` per the NFR(ai-graceful) contract
(`docs/reference/contract-surfaces.md` → AI suggestion surface F-02). Those failures will
therefore NEVER reach Sentry as exceptions. To keep operational visibility without breaking
the "user never sees the failure" contract, add a `\Sentry\captureMessage(..., Severity::warning())`
inside the failure `catch` BEFORE returning `[]` — warning level (degradation, not app error),
and the Sentry call itself must not leak an exception past the seam.
