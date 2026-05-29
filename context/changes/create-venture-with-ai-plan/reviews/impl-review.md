<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: S-01 / create-venture-with-ai-plan

- **Plan**: `context/changes/create-venture-with-ai-plan/plan.md`
- **Scope**: All 3 phases
- **Date**: 2026-05-28
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical · 4 warnings · 3 observations
- **Automated checks**: 34 tests / 122 assertions PASS, `vendor/bin/pint --test` PASS, `php artisan route:list` shows exactly the four planned routes (`dashboard`, `ventures.create`, `ventures.store`, `ventures.show`).

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — Step::$fillable allows mass-assigning owner_id and is_completed

- **Severity**: WARNING
- **Impact**: MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Models/Step.php:9`
- **Detail**: `#[Fillable(['owner_id', 'body', 'is_completed', 'source', 'position'])]` exposes `owner_id` and `is_completed` to mass assignment. The plan specified `['body', 'is_completed', 'source', 'position']` — `owner_id` was added to make `VenturesController.php:46-47` work via `$venture->steps()->create([..., 'owner_id' => $user->id, ...])`. Today this is safe because the controller sources `owner_id` from `$user->id` (never request input). But S-02 (manual add-step) and the FR-013 completion toggle will pass user input into `create()`/`fill()`; if those callers ever take raw request data, a user could spoof `owner_id` (cross-user write) or flip `is_completed` past the toggle surface. This is the F-01 isolation contract leaking through Eloquent.
- **Fix A ⭐ Recommended**: Drop `owner_id` from Fillable; set it via `forceFill()` inside the controller loop. Optionally drop `is_completed` too so S-02 has to use a targeted update.
  - Strength: Removes the mass-assignment escape entirely. Keeps the F-01 contract structural rather than relying on caller discipline.
  - Tradeoff: Touches the steps()->create() loop in store(); a few extra lines.
  - Confidence: HIGH — owner_id is always server-set across S-01/S-02/S-03.
  - Blind spot: None significant.
- **Fix B**: Leave as-is and rely on S-02 plan discipline.
  - Strength: Zero change today.
  - Tradeoff: Future S-02 reviewer must catch what could have been prevented structurally.
  - Confidence: MED — depends on S-02 plan-review catching it.
  - Blind spot: Haven't audited what other slices' FormRequests pass to `$venture->steps()->create()`.
- **Decision**: FIXED via Fix A

### F2 — steps.source has no DB or enum-level guard; typo silently breaks FR-008 metric

- **Severity**: WARNING
- **Impact**: MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Models/Step.php:12-16`, `database/migrations/2026_05_28_210001_create_steps_table.php:17`
- **Detail**: `source` is a plain string column with three `Step::SOURCE_*` constants and no DB CHECK constraint, no cast. The contract-surfaces.md "Venture surface (S-01)" section names `source` as load-bearing for the FR-008 primary metric. A future slice writing `'manaul'` or `'aiextension'` silently corrupts the metric denominator with no compile or test signal.
- **Fix A ⭐ Recommended**: Backed PHP enum + Eloquent cast.
  - Strength: Hard type error at any write site that uses the wrong string. Composes with `$casts`; no DB migration.
  - Tradeoff: New `App\Enums\StepSource` file; constants become enum cases; contract-surfaces.md prose needs minor updates.
  - Confidence: HIGH — Laravel 11 supports this natively.
  - Blind spot: None significant.
- **Fix B**: Database CHECK constraint.
  - Strength: Catches malformed writes from any code path including raw SQL/seeds.
  - Tradeoff: SQLite/Postgres syntax differs; migration-shaped change with cross-DB testing.
  - Confidence: MED — SQLite dev / Postgres prod split.
  - Blind spot: Haven't verified Laravel schema-builder syntax for CHECK across both drivers.
- **Decision**: FIXED via Fix A (backed enum `App\Enums\StepSource` + Eloquent cast on `Step::$casts`; contract-surfaces.md updated)

### F3 — Mock in success test doesn't pin suggester arguments

- **Severity**: WARNING
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `tests/Feature/Ventures/CreateVentureTest.php:25-27`
- **Detail**: `$mock->shouldReceive('suggestSteps')->once()->andReturn($expected);` pins call count but not arguments. A silent refactor swapping `$title`/`$description` would still pass.
- **Fix**: Add `->with(Mockery::on(fn($u) => $u->id === $user->id), 'Learn welding', 'MIG and TIG basics', [])` to the chain.
- **Decision**: FIXED (pinned to the 3 actual call args; the 4th-default-`[]` parameter is not visible to Mockery so was dropped from the expectation)

### F4 — Whitespace-only title bypasses 'required'

- **Severity**: WARNING
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `app/Http/Requests/Ventures/CreateVentureRequest.php:20`
- **Detail**: Laravel's `required` rejects null/empty-string/empty-array but accepts `"   "`. A whitespace-only title persists, renders blank in `show.blade.php`'s `<h2>`, and ships a space-only title to AiStepSuggester.
- **Fix**: Add `prepareForValidation()` that trims `title` (and `description`) before rules run.
- **Decision**: SKIPPED

### F5 — No VentureFactory / StepFactory; isolation test inlines data

- **Severity**: OBSERVATION
- **Impact**: MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: `database/factories/` (only `UserFactory` present), `tests/Feature/Ventures/VentureIsolationTest.php:23-26`
- **Detail**: F-01 established `UserFactory` + `HasFactory` as the data-setup pattern. Neither new model uses `HasFactory`; the isolation test inlines `$userA->ventures()->create([…])`. S-02–S-06 will need factories anyway.
- **Fix**: Add `VentureFactory` and `StepFactory`, plus `HasFactory` on both models.
- **Decision**: FIXED (VentureFactory + StepFactory added; HasFactory traits on both models)

### F6 — Empty-string-to-null normalization in controller, not FormRequest

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: `app/Http/Controllers/VenturesController.php:42`
- **Detail**: `'description' => $description !== '' ? $description : null` is load-bearing for `show.blade.php`'s `{{ $venture->description ?? '—' }}` but sits in the controller. Moving to `prepareForValidation()` co-locates the rule with validation.
- **Fix**: Add `prepareForValidation()` to coerce empty description → null; drop the ternary from `store()`.
- **Decision**: FIXED (prepareForValidation coerces empty description → null; controller drops the ternary)

### F7 — steps.body length couples to ai.step_suggestion.max_step_length without documentation

- **Severity**: OBSERVATION
- **Impact**: LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: `database/migrations/2026_05_28_210001_create_steps_table.php:15`
- **Detail**: `$table->string('body', 200)` matches F-02's `max_step_length` default. If that config later bumps, Postgres throws on insert AFTER counter increment — breaks NFR(ai-graceful) "[] = unavailable" contract.
- **Fix**: Document the coupling in `contract-surfaces.md` "AI suggestion surface" section — `max_step_length` and `steps.body` must move together.
- **Decision**: FIXED (added "Length cap coupling" subsection to the AI suggestion surface)
