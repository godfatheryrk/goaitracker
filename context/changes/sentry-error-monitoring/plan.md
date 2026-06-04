# Sentry Error & Performance Monitoring Implementation Plan

## Overview

Integrate the Sentry SDK (`sentry/sentry-laravel`) into the GOAITracker Laravel app for error tracking and performance tracing, wired into Laravel 13's `bootstrap/app.php` exception handler. Configuration is env-driven so the same code runs on local/CI (no DSN → SDK no-ops) and on Render (DSN set in the dashboard). Two project-specific concerns get handled deliberately: (1) **id-only** user context, so events are attributable to a user without exporting PII (NFR(isolation) is the project's privacy floor); (2) a **guarded** `captureException` on the `AiStepSuggester` `\Throwable` catch, so swallowed provider failures become visible in Sentry without breaking the NFR(ai-graceful) `[]`-on-failure contract.

## Current State Analysis

- **No Sentry anywhere in production code.** `composer.json` `require` block has no `sentry/*` package (`composer.json:8-12`). Stack is `laravel/framework ^13.8` on PHP `^8.4`.
- **`bootstrap/app.php:16-18`** — the `->withExceptions(function (Exceptions $exceptions): void { // })` closure is empty; clean insertion point for `Integration::handles($exceptions)`. The `->withMiddleware(...)` closure (`:13-15`) is also empty — this is where the user-context middleware is appended to the `web` group.
- **`config/sentry.php` does not exist** — `php artisan sentry:publish` creates it.
- **`AiStepSuggester.php` has four `return []` paths**, each already doing `Log::warning(...)` with a structured context array:
  - `:22-26` ceiling reached (expected behavior — **not** in scope for Sentry capture per the decision below),
  - `:51-58` unexpected step count (log-only),
  - `:60-66` invalid step value (log-only),
  - `:69-76` the `\Throwable` catch (**the only path that gets `captureException`**). The exception object `$e` is in scope here.
- **`.env.example`** documents env with section comments + prefixed keys (e.g. `# AI suggestion service (F-02)` then `AI_PROVIDER` / `AI_API_KEY` / `AI_MODEL`). New Sentry vars mirror this convention.
- **Larastan level 5, no baseline** — only `phpstan.neon.dist` exists; `paths` covers `app/`, `routes/`, `database/`. AGENTS.md forbids growing the baseline to silence new errors. New code under `app/` must pass `composer run analyse` clean.
- **`composer run test`** runs `config:clear` then `artisan test`; **`composer run analyse`** runs PHPStan at 512M.
- The AI-failure test harness exists (`tests/Feature/Ventures/CreateVentureTest.php:65-85` mocks `AiStepSuggester` to return `[]`), but per the decision this change adds **no automated test** — verification is manual.

## Desired End State

- An uncaught exception anywhere in the app is reported to Sentry (when a DSN is configured), tagged with the deployment environment and the Render git-commit release.
- Performance transactions are traced (`traces_sample_rate` default 1.0, env-overridable).
- Events carry `user.id` (no email/IP) when a user is authenticated.
- A provider/network failure inside `AiStepSuggester::suggestSteps()` produces a Sentry event AND still returns `[]` to the caller (contract intact); a failure *inside the Sentry call itself* also still returns `[]`.
- With no DSN (local/CI), the SDK initializes to a no-op — tests and `artisan` commands send nothing.

**Verification of end state:** `php artisan sentry:test` produces an event in the Sentry project; throwing inside the app shows up in Sentry with environment+release+user.id; forcing an `AiStepSuggester` provider failure shows an event while the venture still creates with 0 steps; `composer run analyse` and `composer run test` stay green with no DSN configured.

### Key Discoveries:

- Sentry supports **Laravel 11.x or higher** (covers `^13.8`) on PHP 7.2+ — confirmed against current Sentry Laravel docs via Context7. The exact published-version constraint is resolved by Composer at install (see Phase 1 risk).
- The **id-only user-context pattern is the documented Sentry approach** for confidential email: a middleware calling `\Sentry\configureScope(fn (Scope $scope) => $scope->setUser(['id' => $user->id]))`, with `send_default_pii` left `false`.
- `send_default_pii=true` would auto-attach id+email+IP — explicitly NOT what we want; the middleware gives id-only.
- The `AiStepSuggester` catch already has `$e` and `Log::warning` — adding `captureException` is additive; the existing log line stays.

## What We're NOT Doing

- **No automated test** for the AI-seam capture (decision: manual verification only). The existing `[]`-contract tests in `CreateVentureTest` remain the regression guard for the contract half.
- **No Sentry capture on the three non-exception `return []` paths** (ceiling, bad count, bad value). Ceiling-hit is expected rate-limit behavior; the validation paths stay log-only (decision: "catch block only").
- **No email/IP/PII** to Sentry (`send_default_pii=false`).
- **No Render dashboard changes performed by this plan** — setting `SENTRY_LARAVEL_DSN` and `SENTRY_RELEASE` env vars in the Render UI is a manual operator step (documented, not automated, per the production-access boundary in AGENTS.md).
- **No CI secret wiring** — CI runs with no DSN (SDK no-ops); we are not adding a Sentry DSN to GitHub Actions.
- **No release-creation / source-map upload / `sentry-cli`** — release *tagging* via env only; release management tooling is out of scope.
- **No queue/worker tracing config tuning** — Render free tier has no background worker (per F-02 contract); defaults stand.
- **No frontend/browser SDK** — backend (PHP) only.

## Implementation Approach

Two phases. Phase 1 is the standard, vendor-documented install that gets uncaught exceptions and tracing flowing, configured entirely through env so it's inert without a DSN. Phase 2 adds the two GOAITracker-specific pieces — privacy-respecting user context and the AI-seam visibility — that the generic install does not give us. Phase 1 must verify green `analyse` + `test` before Phase 2 touches the contract-sensitive service.

## Critical Implementation Details

- **The Sentry call in the AI catch must be self-contained.** The NFR(ai-graceful) contract is "`[]` = unavailable, unconditionally, on every `\Throwable`." If `\Sentry\captureException($e)` itself throws (misconfig, transport error), it must NOT propagate. Wrap it in its own `try { \Sentry\captureException($e); } catch (\Throwable) { }` placed BEFORE `return []`, leaving the existing `Log::warning(...)` line intact. The existing outer catch is for the provider call, not for Sentry — do not rely on it to swallow a Sentry throw, because order matters (log + capture both run, then return).
- **`send_default_pii` stays `false`.** The id-only guarantee depends on it. If a future change flips it to `true`, email+IP start flowing automatically and the privacy posture silently changes — keep it pinned false with a comment.
- **DSN-absent must be a no-op, not an error.** Do not add code that assumes `app()->bound('sentry')`; the middleware guards on `app()->bound('sentry')` before calling `configureScope` so a no-DSN environment (CI/local/tests) does nothing.

## Phase 1: SDK install & global configuration

### Overview

Install the package, publish and configure `config/sentry.php` entirely via env, wire the exception handler, and document the new env vars. End result: uncaught exceptions and performance traces reach Sentry when a DSN is set; nothing happens when it isn't.

### Changes Required:

#### 1. Add the Sentry Laravel package

**File**: `composer.json` (+ `composer.lock`)

**Intent**: Add `sentry/sentry-laravel` to `require` so the SDK, its config, and the `sentry:publish` / `sentry:test` Artisan commands are available.

**Contract**: Run `composer require sentry/sentry-laravel`. Composer resolves the highest version compatible with `laravel/framework ^13.8` / PHP `^8.4`. If resolution fails (see Open Risks), capture the conflict and retry with an explicit compatible constraint rather than forcing `--ignore-platform-reqs`.

#### 2. Publish and configure `config/sentry.php`

**File**: `config/sentry.php` (created by `php artisan sentry:publish --dsn=` or `vendor:publish`)

**Intent**: Generate the config file, then make every operator-facing knob env-driven with MVP-appropriate defaults. DSN, environment, release, sample rate read from env; PII stays off.

**Contract**: After publishing, ensure these keys resolve from env:
- `dsn` → `env('SENTRY_LARAVEL_DSN')` (publish default — leave as-is).
- `environment` → `env('SENTRY_ENVIRONMENT')` (falls back to Laravel env when null).
- `release` → `env('SENTRY_RELEASE')`.
- `traces_sample_rate` → `env('SENTRY_TRACES_SAMPLE_RATE', 1.0)` — default full tracing, overridable without a deploy.
- `send_default_pii` → `false` (pinned, with a comment noting the id-only middleware in Phase 2 depends on this).

#### 3. Wire the exception handler

**File**: `bootstrap/app.php`

**Intent**: Register Sentry in the empty `->withExceptions(...)` closure so unhandled exceptions are reported.

**Contract**: Inside the `withExceptions` closure (`:16-18`), call `Integration::handles($exceptions)`; add `use Sentry\Laravel\Integration;` at the top. No change to `withMiddleware` in this phase.

#### 4. Document the new env vars

**File**: `.env.example`

**Intent**: Mirror the existing section-comment convention so a fresh clone knows which Sentry vars exist (all blank by default → SDK no-ops locally).

**Contract**: Append a `# Error monitoring (Sentry)` block with `SENTRY_LARAVEL_DSN=`, `SENTRY_ENVIRONMENT=`, `SENTRY_RELEASE=`, `SENTRY_TRACES_SAMPLE_RATE=1.0`. (Operator sets DSN + release in the Render dashboard; `SENTRY_RELEASE` is fed from Render's `RENDER_GIT_COMMIT` there.)

### Success Criteria:

#### Automated Verification:

- Package installs and autoload resolves: `composer require sentry/sentry-laravel` exits 0
- Static analysis stays green: `composer run analyse`
- Full suite stays green with no DSN configured: `composer run test`
- `config/sentry.php` exists and `php artisan config:clear` then `php artisan about` shows no Sentry boot error

#### Manual Verification:

- With a real DSN in `.env`, `php artisan sentry:test` produces an event in the Sentry project
- Triggering an uncaught exception (e.g. a temporary throwing route) shows up in Sentry tagged with the environment and release
- With NO DSN set, the app boots normally and `php artisan sentry:test` reports Sentry is not configured (no crash)

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation that the `sentry:test` event landed before proceeding to Phase 2.

---

## Phase 2: Id-only user context & AI-seam visibility

### Overview

Add the two GOAITracker-specific pieces: a middleware that attaches `user.id` (only) to Sentry events, and a guarded `captureException` on the `AiStepSuggester` `\Throwable` catch so swallowed provider failures are visible without violating the `[]` contract.

### Changes Required:

#### 1. Id-only user-context middleware

**File**: `app/Http/Middleware/SentryContext.php` (new) + `bootstrap/app.php`

**Intent**: When a request is authenticated and Sentry is active, set the Sentry user scope to the numeric id only — no email, no IP — so events are attributable while honoring the privacy floor.

**Contract**: New middleware sets user context, guarded so it's inert without a DSN:

```php
if (app()->bound('sentry') && $request->user() !== null) {
    \Sentry\configureScope(fn (\Sentry\State\Scope $scope) => $scope->setUser(['id' => $request->user()->id]));
}
return $next($request);
```

Append the class to the `web` middleware group inside `->withMiddleware(...)` in `bootstrap/app.php` (`$middleware->appendToGroup('web', SentryContext::class)` or `$middleware->web(append: [SentryContext::class])`). Placement after auth resolution in the `web` group is sufficient — `$request->user()` is populated by the time the controller-bound middleware runs.

#### 2. Capture swallowed provider failures in the AI seam

**File**: `app/Services/AiStepSuggester.php`

**Intent**: In the `\Throwable` catch (`:69-76`) — the only failure path that represents a genuine provider/network error — report the exception to Sentry before returning `[]`, keeping the existing `Log::warning(...)`. The Sentry call is itself guarded so it can never break the contract.

**Contract**: In the existing `catch (\Throwable $e)` block, after the current `Log::warning(...)` and before `return []`, add a self-contained capture that cannot propagate:

```php
try {
    \Sentry\captureException($e);
} catch (\Throwable) {
    // Sentry must never break the [] = unavailable contract
}
```

No change to the other three `return []` paths (ceiling `:22-26`, bad count `:51-58`, bad value `:60-66`) — they stay log-only.

### Success Criteria:

#### Automated Verification:

- Static analysis stays green: `composer run analyse`
- Full suite stays green (the existing `CreateVentureTest` AI-failure test still asserts `[]` → 0 steps): `composer run test`

#### Manual Verification:

- With a DSN set, signing in and hitting a page, then triggering an error, shows the event in Sentry carrying `user.id` and NO email/IP
- Forcing an `AiStepSuggester` provider failure (e.g. temporarily bad `AI_API_KEY`) while creating a venture: the venture is still created with 0 steps and the `ai_unavailable` notice shows (contract holds), AND a corresponding exception appears in Sentry
- Repeating the forced-failure with no DSN: venture still creates with 0 steps, no crash, nothing sent

**Implementation Note**: After completing this phase and all automated verification passes, pause for manual confirmation that the forced-failure event landed in Sentry with id-only user context.

---

## Testing Strategy

Per the decision, this change relies on **manual verification** for the Sentry-specific behavior; no new automated tests are added. The automated suite's role here is **regression protection**: `composer run test` must continue to pass with no DSN configured, proving the integration is inert in CI and that the `AiStepSuggester` `[]`-on-failure contract (already covered by `CreateVentureTest`) is unbroken by the new `captureException` line.

### Manual Testing Steps:

1. Set a real `SENTRY_LARAVEL_DSN` locally; run `php artisan sentry:test` → confirm event in Sentry.
2. Add a temporary throwing route, hit it while signed in → confirm event in Sentry with environment, release, and `user.id` (no email/IP).
3. Set a bad `AI_API_KEY`, create a venture → confirm: 0 steps + `ai_unavailable` notice (contract holds) AND an exception event in Sentry.
4. Unset the DSN, repeat steps 1 & 3 → confirm no crashes and nothing sent.
5. Remove the temporary throwing route.

## Performance Considerations

`traces_sample_rate` defaults to 1.0 (full tracing) but is env-overridable (`SENTRY_TRACES_SAMPLE_RATE`), so if the Render free-tier transaction quota gets hot it can be lowered (e.g. 0.2) by changing an env var — no code deploy. The synchronous AI HTTP call (per F-02's sync-execution constraint) will appear as a span, which is useful for spotting slow provider responses against the 15s timeout.

## Migration Notes

No database changes. `composer.lock` updates; deploys must run `composer install`. Render env vars (`SENTRY_LARAVEL_DSN`, `SENTRY_RELEASE` ← `RENDER_GIT_COMMIT`, optionally `SENTRY_ENVIRONMENT`/`SENTRY_TRACES_SAMPLE_RATE`) are a one-time manual dashboard step.

## References

- Change identity: `context/changes/sentry-error-monitoring/change.md`
- Sentry Laravel docs (via Context7): install, `sentry:publish`, `Integration::handles`, id-only `configureScope` middleware, `send_default_pii`
- AI failure contract: `docs/reference/contract-surfaces.md` → "AI suggestion surface (F-02)" (the `[]`-on-failure seam this plan must not break)
- Integration points: `bootstrap/app.php:13-18`, `app/Services/AiStepSuggester.php:69-76`, `config/ai.php`, `.env.example`

## Open Risks & Assumptions

- **Risk: `sentry/sentry-laravel` may not yet publish a constraint matching `laravel/framework ^13.8`.** Laravel 13 is bleeding-edge; the SDK docs say "Laravel 11.x or higher," but the published `composer.json` constraint is the real gate. Mitigation: if `composer require` fails resolution, read the conflict, and either pin the latest compatible tag or check for a dev/beta release — do NOT force with `--ignore-platform-reqs`. This is the first thing Phase 1 surfaces.
- **Assumption:** Render exposes `RENDER_GIT_COMMIT` at runtime for release tagging (documented Render behavior). If absent, `SENTRY_RELEASE` is simply unset and release-grouping is degraded, not broken.
- **Assumption:** the `web` middleware group runs after auth so `$request->user()` is populated; the middleware no-ops safely when it isn't.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: SDK install & global configuration

#### Automated

- [x] 1.1 Package installs and autoload resolves: `composer require sentry/sentry-laravel` exits 0 — 29f3b82
- [x] 1.2 Static analysis stays green: `composer run analyse` — 29f3b82
- [x] 1.3 Full suite stays green with no DSN configured: `composer run test` — 29f3b82
- [x] 1.4 `config/sentry.php` exists and `config:clear` + `php artisan about` shows no Sentry boot error — 29f3b82

#### Manual

- [x] 1.5 With a real DSN, `php artisan sentry:test` produces an event in Sentry — 29f3b82
- [x] 1.6 An uncaught exception shows in Sentry tagged with environment and release — 29f3b82
- [x] 1.7 With NO DSN set, the app boots and `sentry:test` reports not-configured (no crash) — 29f3b82

### Phase 2: Id-only user context & AI-seam visibility

#### Automated

- [x] 2.1 Static analysis stays green: `composer run analyse`
- [x] 2.2 Full suite stays green (existing `CreateVentureTest` AI-failure test still passes): `composer run test`

#### Manual

- [x] 2.3 Error event in Sentry carries `user.id` with NO email/IP
- [x] 2.4 Forced AI provider failure: venture still creates with 0 steps + `ai_unavailable` notice AND an exception appears in Sentry
- [x] 2.5 Forced AI failure with no DSN: venture still creates with 0 steps, no crash, nothing sent
