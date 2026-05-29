<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: F-02 / ai-suggestion-service

- **Plan**: `context/changes/ai-suggestion-service/plan.md`
- **Scope**: All 4 phases (Phase 1: config + env wiring; Phase 2: counter table + isolation test; Phase 3: agent + service + tests; Phase 4: cross-slice handoff docs)
- **Date**: 2026-05-28
- **Verdict**: APPROVED
- **Findings**: 0 critical, 0 warnings, 4 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS (2 minor observations) |
| Success Criteria | PASS (28/28 tests green, pint clean) |

## Documented intentional drifts (not findings)

These deviations from the plan are surfaced for the record but were validated during analysis as either improvements or explicitly documented choices. They are NOT findings and do NOT require triage.

- **Agent provider/model via methods, not attributes** — Plan §Phase-3-#1 prescribed `#[Provider(Lab::Groq)]` / `#[Model(...)]` attributes. Implementation uses `provider(): string` / `model(): string` methods reading from config. The `laravel/ai` `Promptable` trait (`vendor/laravel/ai/src/Promptable.php:247-265`) explicitly checks for these methods before falling back to attributes, and the method approach is a strict improvement: it makes the provider-swap truly env-only (the plan's stated NFR goal) instead of requiring an attribute edit.
- **No `HasStructuredOutput` / JSON-schema validation** — Plan §Phase-3-#1 prescribed `HasStructuredOutput` with a JsonSchema for the response. Implementation parses JSON text from `$response->text` and validates shape manually in `AiStepSuggester`. Documented in `docs/reference/contract-surfaces.md:98` — "the service does NOT use structured-output (`json_schema`) response format because not all provider models support it."
- **Missing-API-key counter behavior** — Plan §Phase-3-#4 stated "Default position: NO counter increment, because the call never had a real chance to fire." Implementation increments the counter (attempt-based, like every other failure mode). Plan permitted "decide at implementation time and document the choice"; the choice is codified in `lessons.md` ("AI integration: fail-open, increment-before-dispatch") and the `AiStepSuggesterTest::test_fail_missing_api_key_returns_empty_and_increments_counter` carries an inline comment naming the choice.
- **Counter mechanism: `firstOrCreate + increment` vs `upsert`** — Plan §Phase-3-#2 prescribed `upsert(...)` or "Eloquent equivalent". Implementation uses `firstOrCreate` then `->increment('count')` inside a `DB::transaction`. Codified in `lessons.md` as the canonical pattern. (See F1 below for the race-window observation.)

## Findings

### F1 — Counter increment uses firstOrCreate+increment, not upsert

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `app/Services/AiStepSuggester.php:29-35`
- **Detail**: Plan §Phase-3-#2 prescribed `upsert(...)` (or "Eloquent equivalent targeting the unique index") to be race-safe. Implementation uses `firstOrCreate` then `->increment('count')` inside `DB::transaction`. Two concurrent calls from the same user on the same UTC day could both miss the row, both INSERT, and one trip a unique-constraint `QueryException` that escapes the transaction. The outer `try/catch` in `suggestSteps` wraps only the agent call, NOT the counter block, so the `QueryException` would propagate out of the seam — breaking the unconditional "[] on any failure" contract. Practically irrelevant for a solo founder MVP (concurrent same-user requests are vanishingly unlikely), and `lessons.md` already codifies the `firstOrCreate+increment` pattern. Surfacing because the codified rule has a known race window that will matter if traffic ever grows past one user.
- **Fix**: Wrap the `DB::transaction` call inside the outer `try/catch`, OR switch the counter logic to a true upsert (`AiCallCounter::query()->upsert(...)` with `DB::raw('ai_call_counters.count + 1')`) that uses `ON CONFLICT` and never races.
  - Strength: Closes the race window and aligns with the plan's prescribed mechanism.
  - Tradeoff: Small refactor; the lessons.md entry will need an update to match.
  - Confidence: HIGH — the upsert pattern is well-documented for this exact use case.
  - Blind spot: None significant for the MVP.
- **Decision**: SKIPPED — solo MVP; concurrent same-user requests are not a realistic threat.

### F2 — Mixed source-of-truth for agent settings

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Ai/StepSuggestionAgent.php:11-12`
- **Detail**: `provider()` and `model()` are methods reading from config (good — enables env-only swap, deliberate improvement over the plan's attribute approach). But `#[Timeout(15)]` and `#[Temperature(0.7)]` are hardcoded attributes. Config already carries `ai.step_suggestion.timeout_seconds = 15` — two sources of truth. Changing the config value silently has no effect on the live timeout.
- **Fix**: Replace `#[Timeout(15)]` with a `timeout(): int` method returning `config('ai.step_suggestion.timeout_seconds', 15)`, matching the `provider()`/`model()` pattern. Add a config knob for temperature if you want it tunable too.
  - Strength: Single source of truth for all agent settings.
  - Tradeoff: Two extra methods on the agent.
  - Confidence: HIGH — `Promptable::getTimeout` (line 297-313) explicitly supports the `timeout()` method override.
  - Blind spot: None.
- **Decision**: FIXED — replaced `#[Timeout(15)]` attribute with a `timeout(): int` method reading `config('ai.step_suggestion.timeout_seconds', 15)`. Pattern now matches `provider()`/`model()`. Tests + pint green.

### F3 — Unused $user constructor param on StepSuggestionAgent

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `app/Ai/StepSuggestionAgent.php:18`
- **Detail**: `private User $user` is stored but never read in `instructions()` or anywhere else in the agent. The plan explicitly anticipated this ("available for any user-specific prompt tailoring later"), so this isn't a drift — but per the project rule "no half-finished implementations" / "don't design for hypothetical future requirements", it's dead weight today.
- **Fix**: Drop the `User $user` constructor param until a user-specific prompt tailoring need actually surfaces. Callers in `AiStepSuggester.php:39-44` lose one kwarg.
  - Strength: Trims unused parameter; aligns with "no design for hypothetical future requirements".
  - Tradeoff: Minor refactor when/if user-specific tailoring is added later.
  - Confidence: HIGH — no current consumer reads the field.
  - Blind spot: None.
- **Decision**: SKIPPED — user-specific prompt tailoring is on the near horizon; keeping the param.

### F4 — Missing-API-key test doesn't exercise the SDK cold path

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `tests/Feature/Ai/AiStepSuggesterTest.php:106-117`
- **Detail**: Plan §Phase-3-#4 said "temporarily unset `AI_API_KEY` config". Implementation throws a generic `RuntimeException('API key not provided')` from a fake — functionally identical to the timeout/4xx/5xx tests (all throw `RuntimeException`). The test proves "any throwable → []", not "missing `AI_API_KEY` → [] via the real SDK code path". Manual verification 3.6 covers the real cold path, so this is a test-fidelity gap rather than a coverage gap.
- **Fix**: Override `config(['ai.providers.groq.key' => null])` in the test setUp and let the real SDK provider raise — OR accept that the manual smoke check is the right test for this path and rename the test to `test_fail_throwable_returns_empty`.
  - Strength: Closes the test-fidelity gap or removes the misleading test name.
  - Tradeoff: Either touches real SDK config plumbing or loses the "missing-API-key" label in tests.
  - Confidence: MED — depends on whether the SDK actually raises on a null key vs returning a different shape.
  - Blind spot: Haven't validated which path the SDK actually takes when the provider key is null.
- **Decision**: FIXED — test renamed to `test_fail_throwable_returns_empty_and_increments_counter` with updated comment naming manual smoke check 3.6 as the real cold-path coverage. Tests + pint green.
