<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Sentry Error & Performance Monitoring

- **Plan**: context/changes/sentry-error-monitoring/plan.md
- **Scope**: Phases 1–2 of 2 (full plan)
- **Date**: 2026-06-04
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Independent dual-agent review confirmed: plan matched on every flagged point — `send_default_pii` pinned literal `false`, `traces_sample_rate` default 1.0, `captureException` self-guarded and ordered log→capture→return, the other three `return []` paths untouched, middleware attaches id-only, no secrets committed, `sql_bindings` defaulting off. The phpunit DSN-blank is a justified off-plan EXTRA (already committed in p2), serving the plan's own "tests send nothing" end-state. Automated criteria re-run at review time: `composer run analyse` 0 errors; `composer run test` 82 passed / 326 assertions.

## Findings

### F1 — SQL-bindings env toggles are an unguarded path to PII leakage

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality (Data-safety)
- **Location**: config/sentry.php:82 (breadcrumbs.sql_bindings), config/sentry.php:109 (tracing.sql_bindings)
- **Detail**: Both default to false (safe — query parameters not captured) but stay env-overridable via `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED` / `SENTRY_TRACE_SQL_BINDINGS_ENABLED`. Flipping either in production would export per-user query parameters (emails, amounts, venture text, owner_id) to Sentry — breaching NFR(isolation). Unlike `send_default_pii`, these toggles had no guard comment.
- **Fix**: Added a PRIVACY GUARD comment to the Sentry block in `.env.example` noting these must stay unset in production, mirroring the pinned `send_default_pii` rationale.
- **Decision**: FIXED (`.env.example` guard comment)

### F2 — Mixed import style in SentryContext middleware

- **Severity**: 🔍 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Middleware/SentryContext.php:7,22
- **Detail**: Imported `Sentry\State\Scope` but called `\Sentry\configureScope` fully-qualified inline. Purely cosmetic.
- **Fix**: Added `use function Sentry\configureScope;` and call it unqualified.
- **Decision**: FIXED

## Post-fix verification

- `composer run analyse` → passed, 0 errors
- `composer run test` → passed, 82 tests / 326 assertions
