# S-06 / venture-expenses-and-cost — Implementation Plan

## Overview

Land the venture-expenses surface (FR-015 / FR-016 / FR-017 / FR-019) on top of the foundations S-01 / S-02 / S-04 already built. The slice introduces a **brand-new per-user domain model** (`Expense` — venture-level, single-currency, amount + description + date) with the full F-01 S-01 enforcement-checklist obligation, a new `ExpensesController` under nested `ventures/{venture}/expenses/*` routes, and the FR-019 total-cost surface on **both** the venture detail view (a new Expenses card appended below the Steps card) and the venture list row (a new second-line metadata slot under the title).

Because S-05 (`step-deadlines-and-pressure-signals`) is planned to run in parallel on the same return-surface page and list, this plan deliberately **codifies the shared-surface contracts** S-05 will inherit: the venture-list row's per-row metadata is laid out as a stack of `<p>` lines under the title, with `line 2 = S-06 total cost` and `line 3 = S-05 deadline marker` — explicit slot ownership that lets S-05 land its marker without rebasing the row markup. The detail view's `Expenses` card is appended **below** the Steps card; S-05 modifies step rows inside the Steps card only, so the outer stack ordering has zero collision surface.

No code path in this slice rolls back the F-02 `ai_call_counters` budget on venture or expense deletion — the AI calls really happened (the S-04 contract surface already established this for venture delete). No multi-currency, no per-step expense allocation, no symbol/locale formatting (PRD §Non-Goals).

## Current State Analysis

- **S-01 + S-02 + S-04 are landed and stable.** `VenturesController` carries `index` / `create` / `store` / `show` / `destroy` all reached through `$request->user()->ventures()->...` (`app/Http/Controllers/VenturesController.php:17-96`). The `dashboard` route name resolves to `index` (the list), the `/dashboard` URL persists for the F-01 intended-redirect contract, and the list row layout is a `<li class="py-3 flex items-start justify-between gap-4">` with title link + a single `<p class="mt-1 text-xs text-gray-500">` metadata line + a right-aligned Delete form (`resources/views/ventures/index.blade.php:37-61`).
- **`Step::$touches = ['venture']` is already wired** (`app/Models/Step.php:21`). The S-04 contract-surfaces section explicitly anticipates this: _"S-06 if/when expenses move per-step\] MUST not bypass `save()` / `delete()` without re-touching the parent. If you remove `$touches`, the list sort lies silently."_ — `Expense` must declare the same `$touches = ['venture']` so editing an expense bubbles the venture toward the top of the `updated_at DESC` list sort.
- **The list row aggregate chain is already in shape** to receive a `withSum` addition (`app/Http/Controllers/VenturesController.php:19-25`): `$request->user()->ventures()->withCount([...])->orderByDesc('updated_at')->get()`. S-06 inserts `->withSum('expenses as total_cost', 'amount')` between the existing `withCount` and `orderByDesc`.
- **`Venture::steps()` is `hasMany(Step::class)->orderBy('position')`** (`app/Models/Venture.php:21-24`). The S-06 `Venture::expenses()` relationship MUST mirror the explicit ordering, but by date: `hasMany(Expense::class)->orderByDesc('date')->orderByDesc('created_at')` so eager-loaded expenses arrive in ledger-natural order without a per-render `sortByDesc` call.
- **`User::ventures()` declares `hasMany(Venture::class, 'owner_id')`** with the explicit FK (`app/Models/User.php:39-42`) — the F-01 rule that the column→table inference would otherwise look up `owners`. `User::expenses()` follows the same explicit-FK shape.
- **The 200-char convention is already coupled** across `steps.body` column width (`database/migrations/2026_05_28_210001_create_steps_table.php:15`), F-02's `config('ai.step_suggestion.max_step_length')`, S-02's `CreateStepRequest` / `EditStepRequest` (both `max:200`). The expense `description` adopts the same 200-char cap so the convention is uniform; should `max_step_length` ever move, the expense description need NOT move with it (it's not coupled to AI), but the symmetry costs nothing.
- **F-01 access-path discipline is set in stone.** Every new per-user endpoint MUST resolve via `$request->user()->...` — for nested step actions today, that's `$request->user()->ventures()->findOrFail($v)->steps()->findOrFail($s)` (`app/Http/Controllers/StepsController.php:22-23, 29-30, 46-47, 54-55, 63-64, 74-75`). The new expenses endpoints follow the same doubly-scoped resolution: `$request->user()->ventures()->findOrFail($v)->expenses()->findOrFail($e)`.
- **`StepFactory` is the local factory pattern** (`database/factories/StepFactory.php`). Factories bypass `$fillable` via `Model::unguarded(...)` so the `$fillable` whitelist plays no role in fixture creation — the new `ExpenseFactory` can set `owner_id` / `venture_id` directly without forceFill ceremony.
- **No money handling exists today.** No `Brick\Money` package, no `bcadd`/`bcmath` usage anywhere in `app/`. The project's first money decision happens here; it must be precision-safe (`decimal(12,2)` column + Laravel `decimal:2` cast + DB-side SUM via `withSum` / `->sum('amount')`) and it must avoid PHP float coercion (which `Collection::sum('amount')` would silently introduce via `array_sum`).
- **`resources/views/ventures/show.blade.php` is a `<div class="...space-y-6">` vertical stack** (`resources/views/ventures/show.blade.php:8-114`): AI-unavailable flash card (conditional) → Description card → Steps card. Appending an Expenses card to the end is a single insert at the closing `</div>` of the inner container — zero edits to the existing Description and Steps cards.
- **No JS on the venture-list page**; the S-02 toggle island is the only JS island in the codebase (`resources/js/app.js`). The expenses surface adds no JS — all five actions are server-rendered form POSTs with full-reload redirects, mirroring S-02's three non-toggle actions and S-04's destroy.

## Desired End State

A logged-in user on `/ventures/{v}` sees the venture title, description, steps with progress, and — below the Steps card — a new Expenses card showing: a header "`Expenses · X.XX`" (where `X.XX` is the venture's total expense amount as a `decimal:2`-cast string), then either a "No expenses yet" empty state with an `+ Add expense` CTA, or a ledger of expense rows ordered by `date DESC, created_at DESC`, each row showing date · description · amount · Edit · Delete. Clicking `+ Add expense` navigates to `/ventures/{v}/expenses/create` (a form with amount input, description textarea, and date input pre-filled to today). Clicking Edit on a row navigates to `/ventures/{v}/expenses/{e}/edit` (same form, pre-populated). Submitting either form redirects back to the venture detail view. Clicking Delete fires `confirm('Delete this expense?')` and DELETEs on confirm. A second user visiting any of these URLs gets 404, never 403; guests redirect to `/login` via the existing `auth` boundary.

On `/dashboard` (and `/ventures`) the user's venture list rows each render two `<p>` metadata lines under the title link: line 1 = "`X of Y steps completed`" (or "—" when there are no steps — unchanged from S-04), line 2 = "`Total: X.XX`" (or "`Total: 0.00`" when there are no expenses). The plan formally records that **line 3 is reserved for S-05's deadline marker** — so S-05's parallel work has a named seat without conflict. The list ordering is unchanged: `updated_at DESC` already in place; `Expense::$touches = ['venture']` makes expense writes bubble the parent venture upward in the sort exactly as S-04 set up for step writes.

The F-01 S-01 enforcement checklist is satisfied for the `Expense` model: `owner_id` FK with cascade-on-delete, `User::expenses()` / `Venture::expenses()` / `Expense::owner()` relationships with the explicit FK, every authenticated controller action goes through `$request->user()->ventures()->...->expenses()->...`, no global `Expense::find($id)`, no `Route::bind('expense', …)`, and a two-user isolation test on each of the three write endpoints (store / update / destroy) proves the 404-not-403 boundary. `docs/reference/contract-surfaces.md` gains an "Expense surface (S-06)" section that captures the routes, the access-path realization, the `decimal:2` + DB-aggregate precision discipline, the `Expense::$touches = ['venture']` coupling, and **the shared-surface metadata-line contract S-05 inherits**.

### Key Discoveries:

- **`withSum` on the index aggregate chain is the precision-safe path for the list-row total.** `$ventures = …->withSum('expenses as total_cost', 'amount')->…` builds a SQL subquery `(SELECT SUM(amount) FROM expenses WHERE …)` that PG / SQLite execute as exact-decimal arithmetic. The application code reads `$venture->total_cost` (string-like cast, NULL when no rows) and renders via `number_format($venture->total_cost ?? 0, 2)`. No PHP-side accumulation, no float coercion. Verified Laravel 13.x supports `withSum` against a `hasMany` exactly like `withCount`.
- **For the detail view, `$venture->expenses()->sum('amount')` is the right primitive** — NOT `$venture->expenses->sum('amount')` on the eager-loaded collection (that one calls `array_sum` and coerces to float). The detail action issues one extra DB query for the SUM (`SELECT SUM(amount) FROM expenses WHERE venture_id = ?`); cost is sub-millisecond against the new `(venture_id, date)` index and trivially under NFR(edit-latency). The list view already gets the per-row SUM via `withSum` (no extra query per row — it's a single subquery).
- **`Collection::sum('amount')` on `decimal:2`-cast attributes silently coerces to float.** This is the gotcha the plan defends against. Phase 2 explicitly uses the DB-side `->expenses()->sum('amount')` for the show view's total, NOT `$model->expenses->sum('amount')`. The `Critical Implementation Details` block names this so the implementer doesn't drift.
- **`withSum` returns NULL when no related rows match.** `$venture->total_cost` will be `null` for a venture with zero expenses; the list view renders `{{ number_format($venture->total_cost ?? 0, 2) }}` so the empty case shows `"Total: 0.00"`. (Showing `"—"` like the zero-step case would be louder visually but breaks the contract that line 2 always carries the total label so S-05's line 3 maintains stable layout below it.)
- **`Expense::$touches = ['venture']` is the same lesson S-04 codified for `Step`** (`docs/reference/contract-surfaces.md` — Venture list + destroy surface). Without it, adding/editing/deleting an expense does NOT bump the venture's `updated_at`, and the list `ORDER BY updated_at DESC` would not bubble actively-edited ventures. The S-04 contract surface anticipated this: _"Downstream slices that add new step writers (S-05's deadline editor, S-06 if/when expenses move per-step) MUST not bypass `save()` / `delete()` … without re-touching the parent."_ — applies verbatim to S-06 even though expenses are venture-level, not per-step.
- **Cascade chain handles venture deletion correctly.** When a user deletes a venture (S-04), the schema-level `expenses.venture_id ON DELETE CASCADE` will drop the expense rows in the same transaction. The new migration MUST declare both `foreignId('venture_id')->constrained()->cascadeOnDelete()` AND `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`. The S-04 destroy action does not need to change — cascade carries the new table for free. (Phase 3's `DeleteVentureTest` from S-04 doesn't need an update because it asserts on the venture+steps chain; we add an analogous check in `VentureTotalCostTest` if convenient.)
- **The `decimal:2` cast returns a stringified decimal**, not a float. `$expense->amount` will be `"12.50"` (string) when accessed. The amount input in the create/edit form uses `type="number" step="0.01" min="0"`; the FormRequest's `numeric|gte:0` rule coerces and validates safely. Output via `{{ $expense->amount }}` renders the stringified decimal as-is (no `number_format` needed on per-row display since the cast already enforces 2 decimal places); the list-row aggregate `total_cost` from `withSum` is NOT cast (it's a raw query alias), so it MUST go through `number_format(..., 2)` on render.
- **No `Route::scopeBindings()` is needed.** The expense endpoints take `{venture}` + `{expense}` integer params; resolution happens manually in the controller via the chained `$request->user()->ventures()->findOrFail()->expenses()->findOrFail()` pattern — same shape S-02 set for `StepsController`. No route-model binding configured.
- **The metadata-line contract S-05 will inherit lives in `Critical Implementation Details` and is mirrored verbatim into `docs/reference/contract-surfaces.md`** so it survives this slice's merger. The two write surfaces (this plan + the contract-surfaces doc) keep the S-05 obligation visible without depending on a reviewer remembering.

## What We're NOT Doing

- **No multi-currency support.** PRD §Non-Goals: "No multi-currency support for expenses. Single currency only in v1." No `currency_code` column, no currency symbol in display, no locale-aware formatting. The amount renders as a plain stringified decimal (`12.50`).
- **No per-step expense allocation.** PRD §Non-Goals: "Expenses are venture-level only in v1." The `Expense` model has `venture_id` only — no `step_id` column.
- **No expense categories.** PRD does not mention categories; the FR-015 Socrates resolution explicitly defers richer-expense-model concerns to v2.
- **No budget comparison or expense-vs-budget view.** PRD does not mention budget; v2 enhancement.
- **No export / report.** PRD §Non-Goals: "No file-format exports."
- **No undo for delete.** PRD §Guardrails accepts the confirm dialog as the second-gesture requirement; undo is v2 (same posture as venture-delete and step-delete).
- **No detail-view delete button on the list row's expense row.** Edit and Delete on each ledger row sit inline; the row is the surface for both, mirroring S-02's step row pattern.
- **No JS island.** Five form POSTs + full-reload redirects. No fetch handler, no DOM diffing.
- **No policy classes / authorization layer.** Ownership is enforced by the user-relationship access path, same as S-01 / S-02 / S-04. Policies graduate in v3+ per `docs/reference/contract-surfaces.md#v3-co-editing-multi-user-write-access`.
- **No application-level upper bound on amount.** The user explicitly chose `gte:0` with no cap during planning; the schema `decimal(12,2)` ceiling (~9,999,999,999.99) is the de-facto limit. If absurd values become a real problem, a `max:` rule can be added without a migration.
- **No deadline marker on the list row.** S-05 owns line 3 of the metadata stack; this plan reserves the slot but does NOT render the marker.
- **No total-cost roll-up across ventures.** PRD §Non-Goals: "No cross-venture dashboard or progress roll-up."
- **No background recompute / cache for the total.** Per-render DB aggregates are exact and trivially fast at v1 scale.

## Implementation Approach

Three phases, each ending in a verifiable gate. Mirrors the S-02 + S-04 three-phase shape (skeleton → wire → handoff).

1. **Schema + model + routes + FormRequests + skeleton controller.** Migration creates the `expenses` table with explicit `owner_id` + `venture_id` FKs (both cascade-on-delete), `decimal(12,2)` amount, 200-char description, `date` column, and a `(venture_id, date)` index. The `Expense` model carries the `decimal:2` + `date` casts and the `$touches = ['venture']` coupling. `User::expenses()` and `Venture::expenses()` relationships land in lockstep. `ExpenseFactory` is the test fixture. `CreateExpenseRequest` and `EditExpenseRequest` carry the body-only whitelist (no `owner_id` / `venture_id`). `ExpensesController` skeleton has five `abort(404)` stubs; five new routes mount under `auth` middleware. No view changes; existing tests stay green; `php artisan route:list` confirms the new routes.

2. **Wire actions + integrate views.** Implement all five controller methods through the doubly-scoped access path; persist via `make([...validated])->forceFill(['owner_id' => $user->id])->save()`. Build `expenses/create.blade.php` (with date pre-filled to today via `value="{{ old('date', now()->toDateString()) }}"`) and `expenses/edit.blade.php`. Extend `VenturesController::index` with `->withSum('expenses as total_cost', 'amount')`; extend `show` with `->with('expenses')` and pass `$totalCost = $model->expenses()->sum('amount')` to the view. Append the Expenses card to `ventures/show.blade.php` (below Steps card) with empty-state CTA. Add the second metadata `<p>` line to `ventures/index.blade.php` with the formatted total (and codify the line-1 / line-2 / line-3 slot contract for S-05 via a Blade-level comment).

3. **Test matrix + cross-slice handoff.** Seven feature tests: `AddExpenseTest` (happy + two-user 404 = 2 tests), `EditExpenseTest` (happy + two-user 404 = 2 tests), `DeleteExpenseTest` (happy + two-user 404 = 2 tests), and `VentureTotalCostTest` (renders `Total: X.XX` on both detail and list with the same value, with `0.00` when empty = 1 test). `docs/reference/contract-surfaces.md` gains an "Expense surface (S-06)" section — explicit on the metadata-line contract S-05 inherits. Roadmap S-06 row flips to `done`; `change.md` flips to `implemented`. Guest-302 deferred (same `auth` boundary already proven, same justification S-04 used).

## Critical Implementation Details

- **Precision-safe money discipline.** The amount column is `decimal(12,2)`; the model casts `amount` as `decimal:2` so reads return a stringified-decimal that never coerces to float. **Two places where float coercion would silently corrupt aggregation must be avoided:** (a) on the venture-list row, use `withSum('expenses as total_cost', 'amount')` so PG / SQLite compute the SUM in exact decimal arithmetic — NOT `->withCount('expenses')` + per-render `$venture->expenses->sum(...)`; (b) on the venture-detail view, use `$model->expenses()->sum('amount')` (DB-side query) — NOT `$model->expenses->sum('amount')` (PHP-side `array_sum`, coerces to float). The list-row aggregate `total_cost` is a query alias and is NOT auto-cast by Eloquent; render it via `number_format($venture->total_cost ?? 0, 2)` so NULL (no expenses) renders as `0.00` and the line layout stays stable for S-05's next-line slot.

- **`Expense::$touches = ['venture']` is load-bearing for the venture-list sort.** Without it, `Expense::save()` / `Expense::delete()` do NOT update the parent venture's `updated_at`, and a venture whose only recent activity is expense edits stays buried beneath a never-touched venture created later. The S-04 contract surface anticipated this for any future step-or-child-of-venture writer; this slice satisfies the obligation. Any future code path that writes an `Expense` outside `save()`/`delete()` (e.g., a raw `DB::table('expenses')->...` update) MUST call `$venture->touch()` explicitly, or the sort lies silently. Same caveat the S-04 contract surface gives for `Step`.

- **Owner-immutability via forceFill.** `Expense::$fillable = ['amount', 'description', 'date']` — `owner_id` and `venture_id` are deliberately absent. The store action sets them via `->forceFill(['owner_id' => $request->user()->id])` (and `venture_id` is set by the relationship's `make()` call via `$ventureModel->expenses()->make(...)`). This makes a tampered payload carrying `owner_id: 99999` or `venture_id: 99999` a no-op at the model layer (defense in depth alongside the FormRequest whitelist). Same shape S-02 uses for `Step::source`.

- **Shared-surface ownership for the parallel S-05.** Three contracts S-05 will inherit are codified here AND mirrored into `docs/reference/contract-surfaces.md` Phase 3 so they survive the merger:
  1. **Venture-list row metadata is a stack of `<p>` lines** under the title link. Line 1 = `"X of Y steps completed"` (or `"—"` when no steps — owned by S-04). Line 2 = `"Total: X.XX"` (always rendered, `0.00` when empty — owned by S-06). **Line 3 = the deadline marker — reserved for S-05 to fill.** S-05 SHOULD insert its `<p>` immediately after S-06's total line, never above. A Blade comment in `ventures/index.blade.php` flags the slot explicitly.
  2. **Venture-detail view stack ordering** (the outermost `<div class="...space-y-6">` in `ventures/show.blade.php`): AI-unavailable flash → Description → Steps → **Expenses** (S-06, appended below Steps). S-05 modifies the Steps card's inner `<li>` rows only (adding a deadline badge to each step); the outer stack ordering is OWNED by this slice. S-05 MUST NOT insert a new card above Steps or below Expenses; if S-05's badge surface needs container changes, that's a separate plan-revision conversation, not a silent rebase.
  3. **`VenturesController::index` aggregate chain ordering** (`app/Http/Controllers/VenturesController.php:19-25`): `->withCount([...])->withSum('expenses as total_cost', 'amount')->orderByDesc('updated_at')->get()`. S-05 adds its own `withCount` (or `withMax`) into the same chain for the deadline-pressure marker. The plan declares S-06's `withSum` lands BEFORE the `orderByDesc`; S-05 SHOULD insert its aggregate into the same `withCount([...])` array as the existing `'steps'` / `'completed_steps_count'` entries (e.g. `'steps as imminent_steps_count' => fn ($q) => $q->where('deadline', '<=', now()->addDays(3))->where('is_completed', false)`), preserving the existing `withSum` and `orderByDesc` lines unchanged.

- **`withSum` is only useful when its aggregate alias is unique.** The `'expenses as total_cost'` alias avoids colliding with the `withCount(['steps', ...])` aliases (`steps_count`, `completed_steps_count`). If S-05 also picks `total_*` for any reason, it MUST not collide; documented to avoid the silent-overwrite scenario.

- **Native `confirm()` is the v1 second-gesture for expense delete.** Mirrors S-02's step-delete and S-04's venture-delete. Wording: `"Delete this expense?"`. The blast radius is much smaller than venture-delete (one row, no cascade); no need for the heavier-guard treatment.

- **Date input in the create form** uses `<input type="date" name="date" value="{{ old('date', now()->toDateString()) }}">`. `now()->toDateString()` returns `YYYY-MM-DD` which is what `type="date"` expects. The FormRequest's `required|date` rule coerces and validates. On edit, the pre-filled value is `$expense->date->toDateString()` (the `date` cast returns a `Carbon\CarbonImmutable`, so `->toDateString()` is the right serializer).

## Phase 1: Schema + model + routes + FormRequests + skeleton controller

### Overview

Land the `expenses` table, the `Expense` model with its casts and `$touches` coupling, the `User` and `Venture` relationships, the factory, the two FormRequests, the skeleton controller, and the five nested routes. No view changes, no user-visible change. Existing tests must stay green; the F-01 isolation tests on existing surfaces are untouched.

### Changes Required:

#### 1. Create the `expenses` migration

**File**: `database/migrations/2026_05_30_120000_create_expenses_table.php` (or the next available timestamp on the implementation day)

**Intent**: Establish the per-user `expenses` table with the F-01 contract's explicit FK shape on both `venture_id` and `owner_id` (both cascade-on-delete), a precision-safe `decimal(12,2)` amount column, a 200-char description column matching the project's convention, a `date` column for the spend date, and a `(venture_id, date)` index supporting the ordered-by-date eager-load on the detail view.

**Contract**: A standard Laravel migration creating the `expenses` table inside `Schema::create(...)`:
- `$table->id();`
- `$table->foreignId('venture_id')->constrained()->cascadeOnDelete();` — column→table inference is `ventures` (correct).
- `$table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();` — explicit `'users'` because the inference would look up `owners` (F-01 rule).
- `$table->decimal('amount', 12, 2);`
- `$table->string('description', 200);` — same 200-char convention as `steps.body`.
- `$table->date('date');` — required spend date; user-set, defaulted to today in the create form.
- `$table->timestamps();`
- `$table->index(['venture_id', 'date'], 'expenses_venture_date_index');` — supports the ordered-by-date eager-load on `Venture::expenses()`.

`down()` is `Schema::dropIfExists('expenses');`.

#### 2. Create the `Expense` model

**File**: `app/Models/Expense.php`

**Intent**: Declare the new model with the explicit `$fillable` whitelist (no `owner_id` / `venture_id` — those are forceFill-only, defense in depth alongside the FormRequest whitelist), the precision-safe `decimal:2` cast on `amount`, the `date` cast on `date`, the `$touches` coupling so expense writes bubble the parent venture's `updated_at`, and the inverse `venture()` + `owner()` relationships.

**Contract**: `class Expense extends Model`. `use HasFactory`. PHP-attribute `#[Fillable(['amount', 'description', 'date'])]`. Casts: `protected $casts = ['amount' => 'decimal:2', 'date' => 'date'];`. `protected $touches = ['venture'];`. Two relationship methods: `public function venture(): BelongsTo { return $this->belongsTo(Venture::class); }` and `public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }`. Mirrors the `Step` model's structure 1:1 except for the absence of `source` (no metric snapshot) and `is_completed` (not a stateful row).

#### 3. Extend `User` with the `expenses()` relationship

**File**: `app/Models/User.php`

**Intent**: Make `$request->user()->expenses()` the canonical access path for the new domain. F-01 contract requires the explicit FK because the column is `owner_id`, not Laravel's default `user_id`.

**Contract**: Add one new public method to the existing `User` class:
```php
public function expenses(): HasMany
{
    return $this->hasMany(Expense::class, 'owner_id');
}
```
Add the import `use App\Models\Expense;` and (if not already inferred) `use Illuminate\Database\Eloquent\Relations\HasMany;`. No other edits to `User`.

#### 4. Extend `Venture` with the `expenses()` relationship

**File**: `app/Models/Venture.php`

**Intent**: Expose the venture-scoped expenses with the FR-019 ledger ordering baked in (date descending, then `created_at` descending as the same-date tiebreaker), so eager-load (`->with('expenses')`) returns the collection in detail-view render order with no per-render sort.

**Contract**: Add one new public method to the existing `Venture` class:
```php
public function expenses(): HasMany
{
    return $this->hasMany(Expense::class)->orderByDesc('date')->orderByDesc('created_at');
}
```
The existing `steps()` relationship and `owner()` relationship stay unchanged.

#### 5. Create the `ExpenseFactory`

**File**: `database/factories/ExpenseFactory.php`

**Intent**: Provide the test fixture for the Phase 3 feature tests. Mirrors `StepFactory`'s shape — factory bypasses `$fillable` via `Model::unguarded(...)`, so `owner_id` and `venture_id` can be set directly without forceFill ceremony. Critically, the default `definition()` pre-creates the Venture and reuses `$venture->owner_id` for ownership-coherent fixtures — using `Venture::factory()` + `User::factory()` as two independent paths would create an expense whose owner != its venture's owner, a confusing fixture state that could mask isolation bugs in any test that omits an explicit `owner_id` override.

**Contract**: `class ExpenseFactory extends Factory`. `protected $model = Expense::class;`. `definition(): array` mirrors `StepFactory::definition()` 1:1:
```php
$venture = Venture::factory()->create();

return [
    'venture_id' => $venture->id,
    'owner_id' => $venture->owner_id,
    'amount' => $this->faker->randomFloat(2, 0, 9999.99),
    'description' => $this->faker->sentence(3),
    'date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
];
```
Tests that need a specific user/venture combination override both keys explicitly (e.g., `Expense::factory()->for($venture, 'venture')->create(['owner_id' => $user->id])`).

#### 6. Create `CreateExpenseRequest`

**File**: `app/Http/Requests/Expenses/CreateExpenseRequest.php`

**Intent**: Centralize FR-015 validation. The body-only whitelist is the FormRequest layer of owner-immutability defense — any payload field outside `amount` / `description` / `date` is dropped by `validated()` before reaching the model.

**Contract**: `extends FormRequest`. `authorize(): bool { return true; }` (auth middleware is the boundary). `rules(): array`:
```php
return [
    'amount' => ['required', 'numeric', 'gte:0'],
    'description' => ['required', 'string', 'min:1', 'max:200'],
    'date' => ['required', 'date'],
];
```
No `prepareForValidation` needed.

#### 7. Create `EditExpenseRequest`

**File**: `app/Http/Requests/Expenses/EditExpenseRequest.php`

**Intent**: Identical rule set to `CreateExpenseRequest` today; kept separate so future divergence (e.g., an audit-only field on edit, or a different floor for edits) doesn't churn the create-side rules. Same pattern S-02 used for `CreateStepRequest` / `EditStepRequest`.

**Contract**: Same shape as `CreateExpenseRequest` — `authorize(): bool { return true; }`, `rules()` returns the same three-field array.

#### 8. `ExpensesController` skeleton

**File**: `app/Http/Controllers/ExpensesController.php`

**Intent**: Plumb all five method signatures so `php artisan route:list` resolves the five new routes. Each method body is `abort(404)` in Phase 1; real bodies arrive in Phase 2. The skeleton is what lets routes mount before any view exists, so `route:list` and basic guest-redirect checks work.

**Contract**: `class ExpensesController extends Controller`. Five public methods accepting integer route params and the appropriate Request type:
- `create(Request $request, int $venture): View`
- `store(CreateExpenseRequest $request, int $venture): RedirectResponse`
- `edit(Request $request, int $venture, int $expense): View`
- `update(EditExpenseRequest $request, int $venture, int $expense): RedirectResponse`
- `destroy(Request $request, int $venture, int $expense): RedirectResponse`

Each body in Phase 1 is `abort(404);` — placeholder only. Imports: `Request`, `RedirectResponse`, `View`, `CreateExpenseRequest`, `EditExpenseRequest`.

#### 9. Five new routes mounted under `auth`

**File**: `routes/web.php`

**Intent**: Mount the expense surface nested under `ventures/{venture}/expenses/...`. All carry `whereNumber()` constraints on `venture` (and `expense` where applicable); naming follows `expenses.*` for namespace cleanliness. Mirrors S-02's `steps.*` route block shape.

**Contract**: Inside the existing `Route::middleware('auth')->group(...)` block in `routes/web.php`, add (after the existing `steps.suggestions.*` block):
```php
Route::get('ventures/{venture}/expenses/create', [ExpensesController::class, 'create'])
    ->whereNumber('venture')
    ->name('expenses.create');
Route::post('ventures/{venture}/expenses', [ExpensesController::class, 'store'])
    ->whereNumber('venture')
    ->name('expenses.store');
Route::get('ventures/{venture}/expenses/{expense}/edit', [ExpensesController::class, 'edit'])
    ->whereNumber('venture')
    ->whereNumber('expense')
    ->name('expenses.edit');
Route::patch('ventures/{venture}/expenses/{expense}', [ExpensesController::class, 'update'])
    ->whereNumber('venture')
    ->whereNumber('expense')
    ->name('expenses.update');
Route::delete('ventures/{venture}/expenses/{expense}', [ExpensesController::class, 'destroy'])
    ->whereNumber('venture')
    ->whereNumber('expense')
    ->name('expenses.destroy');
```
Import `use App\Http\Controllers\ExpensesController;` at the top of the file.

### Success Criteria:

#### Automated Verification:

- Migration applies cleanly: `php artisan migrate` (or `composer run test` which runs `migrate:fresh` via `RefreshDatabase`)
- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test`
- `php artisan route:list --name=expenses` shows the five new `expenses.*` routes under the `auth` middleware

#### Manual Verification:

- Visiting `/ventures/{v}/expenses/create` while authenticated returns a placeholder 404 (skeleton stub fires)
- Visiting any of the five routes while unauthenticated redirects to `/login` (`auth` middleware boundary intact)
- `php artisan tinker` quick check: `\App\Models\Expense::factory()->create()` succeeds and returns an `Expense` with `amount` as a stringified `decimal:2` value and `date` as a Carbon instance

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the route surface + skeleton work and existing tests are green before proceeding to Phase 2.

---

## Phase 2: Wire actions + integrate views

### Overview

Implement all five controller methods, build the two new views, extend the existing `VenturesController::index` aggregate chain with `withSum`, extend `VenturesController::show` with the expenses eager-load + total computation, append the Expenses card to `ventures/show.blade.php`, and add the total-cost metadata line to `ventures/index.blade.php` (with the explicit slot-reservation comment for S-05). End state: a user can add / edit / delete expenses, see the per-venture total on both surfaces, and see the metadata-line layout S-05 will slot into.

### Changes Required:

#### 1. `ExpensesController::create` and `ExpensesController::store`

**File**: `app/Http/Controllers/ExpensesController.php`

**Intent**: `create` renders the add-expense form bound to the venture (resolved via the user relationship). `store` persists ONE expense, with `owner_id` set via forceFill (defense in depth — never trust mass-assignment for ownership), and redirects to `ventures.show`.

**Contract**:
- `create` body: `$ventureModel = $request->user()->ventures()->findOrFail($venture); return view('expenses.create', ['venture' => $ventureModel]);`
- `store` body: resolve venture via `$request->user()->ventures()->findOrFail($venture)`; persist via:
  ```php
  $ventureModel->expenses()
      ->make($request->validated())
      ->forceFill(['owner_id' => $request->user()->id])
      ->save();
  ```
  Then `return redirect()->route('ventures.show', $ventureModel);`. The relationship `make()` automatically sets `venture_id`; the validated array sets the three whitelisted fields; the `forceFill` sets `owner_id`. No DB transaction needed — single INSERT.

#### 2. `ExpensesController::edit` and `ExpensesController::update`

**File**: `app/Http/Controllers/ExpensesController.php`

**Intent**: `edit` renders the form pre-populated with the expense's current values. `update` writes ONLY `amount` / `description` / `date` — the EditExpenseRequest whitelist + the `$fillable` whitelist ensure `owner_id` / `venture_id` cannot move via input tampering.

**Contract**:
- `edit` body: resolve venture then expense via the doubly-scoped chain (`$expenseModel = $ventureModel->expenses()->findOrFail($expense);`); return `view('expenses.edit', ['venture' => $ventureModel, 'expense' => $expenseModel]);`.
- `update` body: resolve venture then expense via the chain; `$expenseModel->update($request->validated());` (validated returns only the three whitelisted fields); return `redirect()->route('ventures.show', $ventureModel);`.

#### 3. `ExpensesController::destroy`

**File**: `app/Http/Controllers/ExpensesController.php`

**Intent**: Delete one expense row. Confirmation is the frontend's job (native `confirm()` on the show view's ledger row). Redirect back to the venture detail view so the total recomputes for the user's next render.

**Contract**: Resolve venture then expense via the chain; `$expenseModel->delete();`; `return redirect()->route('ventures.show', $ventureModel);`.

#### 4. `expenses/create.blade.php`

**File**: `resources/views/expenses/create.blade.php`

**Intent**: Three-field form (amount + description + date) following the styling primitives `ventures/create.blade.php` and `steps/create.blade.php` set: dark `bg-gray-800` submit button, `block mt-1 w-full border-gray-300 rounded-md shadow-sm` inputs, `@error` blocks under each field, Cancel link returning to `ventures.show`. Date input is pre-filled to today via `now()->toDateString()`.

**Contract**: Extends `layouts.app`. `@section('header')` renders `Add expense to: {{ $venture->title }}`. Form `<form method="POST" action="{{ route('expenses.store', $venture) }}">` with `@csrf`, then three labelled inputs:
- `amount`: `<input type="number" step="0.01" min="0" name="amount" required autofocus value="{{ old('amount') }}">` plus its `@error('amount')` block.
- `description`: `<textarea name="description" rows="2" maxlength="200" required>{{ old('description') }}</textarea>` plus its `@error('description')` block.
- `date`: `<input type="date" name="date" required value="{{ old('date', now()->toDateString()) }}">` plus its `@error('date')` block.
- Submit "Add" button (dark `bg-gray-800` ...) and a Cancel `<a href="{{ route('ventures.show', $venture) }}">`.

#### 5. `expenses/edit.blade.php`

**File**: `resources/views/expenses/edit.blade.php`

**Intent**: Same shape as `expenses/create.blade.php` but pre-populated with the expense's current values and POSTing as PATCH. Cancel returns to `ventures.show`.

**Contract**: Extends `layouts.app`. `@section('header')` renders `Edit expense in: {{ $venture->title }}`. Form posts to `route('expenses.update', [$venture, $expense])` with `@csrf @method('PATCH')`. Inputs pre-filled via `old('amount', $expense->amount)` / `old('description', $expense->description)` / `old('date', $expense->date->toDateString())`. Submit "Save" button. Cancel link to `ventures.show`.

#### 6. `VenturesController::index` — extend aggregate chain with `withSum`

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: Add the FR-019 per-row total-cost aggregate to the existing list query so the index view can render `Total: X.XX` without N+1. The `withSum` alias `total_cost` does not collide with `steps_count` / `completed_steps_count`. Insert before the existing `orderByDesc('updated_at')` so the chain reads in the canonical "aggregates then sort then materialize" order.

**Contract**: In the existing `index(Request $request): View` method, between the `withCount([...])` call and the `orderByDesc('updated_at')` call, insert `->withSum('expenses as total_cost', 'amount')`. The final chain reads:
```php
$ventures = $request->user()->ventures()
    ->withCount([
        'steps',
        'steps as completed_steps_count' => fn ($q) => $q->where('is_completed', true),
    ])
    ->withSum('expenses as total_cost', 'amount')
    ->orderByDesc('updated_at')
    ->get();
```

#### 7. `VenturesController::show` — eager-load expenses + compute total

**File**: `app/Http/Controllers/VenturesController.php`

**Intent**: The show view needs the expense collection (ordered, per the `Venture::expenses()` relationship) for the ledger render, and the total cost as a DB-aggregated value (NOT a PHP collection sum — float coercion hazard). Pass both to the view alongside the existing `$venture` / `$completed` / `$total` payload.

**Contract**: In the existing `show(Request $request, int $venture): View` method:
- Change the eager-load: `$model = $request->user()->ventures()->with(['steps', 'expenses'])->findOrFail($venture);`.
- After the existing `$completed` / `$total` computation, add: `$totalCost = $model->expenses()->sum('amount');` — this issues a `SELECT SUM(amount) FROM expenses WHERE venture_id = ?` against the `(venture_id, date)` index; returns a stringified-decimal value (or `0` when the venture has no expenses).
- Extend the view payload: `return view('ventures.show', ['venture' => $model, 'completed' => $completed, 'total' => $total, 'totalCost' => $totalCost]);`.

#### 8. `ventures/show.blade.php` — append Expenses card below Steps

**File**: `resources/views/ventures/show.blade.php`

**Intent**: Append a new Expenses card to the bottom of the existing `<div class="...space-y-6">` container, BELOW the Steps card. Zero edits to the Description card or the Steps card — the existing markup S-02 set stays intact. Empty state shows "No expenses yet" + a primary CTA; non-empty state shows a ledger of rows ordered by date (the relationship already enforces the order).

**Contract**: Inside `@section('content')`, after the closing `</div>` of the Steps panel (the one wrapping the `<ol>` and `<form method="POST" action="{{ route('steps.suggestions.preview', $venture) }}">` block) and BEFORE the closing `</div>` of the inner container `<div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">`, add:
```blade
<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 text-gray-900">
        <div class="flex items-baseline justify-between">
            <h3 class="font-medium text-sm text-gray-500 uppercase tracking-wide">Expenses</h3>
            <p class="text-sm text-gray-600">Total: {{ number_format($totalCost ?? 0, 2) }}</p>
        </div>

        @if ($venture->expenses->isEmpty())
            <div class="mt-4 space-y-3">
                <p class="text-sm text-gray-600">No expenses yet.</p>
                <a href="{{ route('expenses.create', $venture) }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent
                          rounded-md font-semibold text-xs text-white uppercase tracking-widest
                          hover:bg-gray-700">
                    + Add expense
                </a>
            </div>
        @else
            <ul class="mt-3 divide-y divide-gray-100">
                @foreach ($venture->expenses as $expense)
                    <li class="py-2 flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-gray-800">{{ $expense->description }}</p>
                            <p class="text-xs text-gray-500">{{ $expense->date->toDateString() }} · {{ $expense->amount }}</p>
                        </div>
                        <a href="{{ route('expenses.edit', [$venture, $expense]) }}"
                           class="text-xs text-gray-600 hover:text-gray-900">Edit</a>
                        <form method="POST"
                              action="{{ route('expenses.destroy', [$venture, $expense]) }}"
                              onsubmit="return confirm('Delete this expense?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:text-red-800">Delete</button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">
                <a href="{{ route('expenses.create', $venture) }}"
                   class="text-sm text-gray-700 hover:text-gray-900">+ Add expense</a>
            </div>
        @endif
    </div>
</div>
```

Per-row amount renders as the raw stringified `decimal:2` value (`12.50`) — no `number_format` needed because the cast already enforces 2 decimal places. The header total uses `number_format(..., 2)` because the controller passes the SUM result (NOT a cast attribute), and the function safely handles NULL-coerced 0.

#### 9. `ventures/index.blade.php` — add total-cost metadata line + slot-reservation comment for S-05

**File**: `resources/views/ventures/index.blade.php`

**Intent**: Insert a second `<p>` line under the existing "X of Y steps completed" line, rendering "Total: X.XX" (or "Total: 0.00" when the venture has no expenses). Add a Blade comment above the metadata-line block that codifies the slot contract S-05 will inherit (`line 3 reserved for the deadline marker`).

**Contract**: Inside the `@foreach ($ventures as $venture)` loop, after the existing `<p class="mt-1 text-xs text-gray-500">...</p>` that renders the progress text, insert:
```blade
{{-- Per-row metadata slot contract (set by S-06):
     line 1 = step progress (S-04)
     line 2 = total cost (S-06)
     line 3 = deadline marker (reserved for S-05)
     S-05 should append its `<p>` immediately after this line, not above. --}}
<p class="mt-1 text-xs text-gray-500">
    Total: {{ number_format($venture->total_cost ?? 0, 2) }}
</p>
```

The existing progress `<p>` stays unchanged. The Delete form stays unchanged. The flex container layout stays unchanged.

### Success Criteria:

#### Automated Verification:

- Linting / formatting passes: `vendor/bin/pint --test`
- Existing test suite still green: `composer run test` — the prior `ListVenturesTest`, `DeleteVentureTest`, and all step/venture tests must not regress
- `php artisan route:list --name=expenses` still shows the five `expenses.*` routes (no accidental removal)

#### Manual Verification:

- On any venture: click `+ Add expense` from the empty-state CTA → land on the create form with date pre-filled to today → enter `12.50` / `"Coffee for kickoff meeting"` / today → submit → land on `ventures.show` with the new expense in the ledger and the header showing `Total: 12.50`
- Add a second expense `5.00` / `"Tea"` / yesterday → land on `ventures.show` with both expenses in the ledger (today's first, then yesterday's) and the header showing `Total: 17.50`
- Click Edit on the first row → land on the edit form pre-filled with the current values → change amount to `15.00` → Save → land on `ventures.show` with `Total: 20.00`
- Click Delete on a row → confirm dialog `"Delete this expense?"` → confirm → row gone, total recomputes
- Navigate to `/dashboard` (the venture list) → the venture row now shows two metadata lines under the title: line 1 "X of Y steps completed", line 2 "Total: X.XX"
- Create a brand-new venture (zero expenses) → its list-row line 2 reads `Total: 0.00` (NOT `—`); the detail view's Expenses card shows the empty-state CTA
- After editing an expense → return to `/dashboard` → confirm the venture has bubbled to the top of the list (`Expense::$touches = ['venture']` wiring verified end-to-end)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that all five interactions work AND the slot-reservation comment is in place before proceeding to Phase 3.

---

## Phase 3: Test matrix + cross-slice handoff

### Overview

Ship the seven feature tests (covering the F-01 S-01 enforcement-checklist obligations + happy paths + the FR-019 cross-surface total render). Document the Expense surface in `docs/reference/contract-surfaces.md` — explicitly capturing the shared-surface contracts S-05 will inherit. Flip the roadmap + `change.md` status markers. No production code edits in this phase.

### Changes Required:

#### 1. `tests/Feature/Expenses/AddExpenseTest.php`

**File**: `tests/Feature/Expenses/AddExpenseTest.php`

**Intent**: Cover FR-015 happy path (persists with the right `owner_id` / `venture_id` / cast values) + the two-user 404 isolation that the F-01 S-01 enforcement-checklist item 4 requires for every new per-user write route.

**Contract**: PHPUnit test class extending `Tests\TestCase`, uses `RefreshDatabase`. Two `#[Test]` methods:
- `expense_persists_with_owner_and_venture_set()` — create user A and a venture for A; authenticate as A; POST to `route('expenses.store', $venture)` with `['amount' => '12.50', 'description' => 'Coffee', 'date' => '2026-05-15']`; assert 302 redirect to `route('ventures.show', $venture)`; assert one `Expense` row exists with `owner_id = A->id`, `venture_id = $venture->id`, `amount` as stringified `12.50`, `description = 'Coffee'`, `date->toDateString() === '2026-05-15'`.
- `user_b_gets_404_adding_expense_to_user_a_venture()` — create users A and B; create a venture for A; authenticate as B; POST to `route('expenses.store', $A_venture)`; assert 404 response; assert `Expense::count()` is `0`.

#### 2. `tests/Feature/Expenses/EditExpenseTest.php`

**File**: `tests/Feature/Expenses/EditExpenseTest.php`

**Intent**: Cover FR-017 happy path + two-user 404 isolation on the nested `{venture}/{expense}` resource (proves the doubly-scoped resolution rejects either a foreign venture OR a foreign expense under an owned venture, with 404 not 403).

**Contract**: Two `#[Test]` methods:
- `expense_updates_successfully()` — create user A, venture, one expense via `Expense::factory()->for($venture, 'venture')->create(['owner_id' => $user->id, 'amount' => '5.00', 'description' => 'Old', 'date' => '2026-04-01'])`; authenticate as A; PATCH `route('expenses.update', [$venture, $expense])` with `['amount' => '8.25', 'description' => 'New', 'date' => '2026-05-15']`; assert 302 redirect to `route('ventures.show', $venture)`; assert the expense row now has the three new values; assert `owner_id` and `venture_id` unchanged.
- `user_b_gets_404_editing_user_a_expense()` — create A and B, A's venture and expense; authenticate as B; PATCH `route('expenses.update', [$A_venture, $A_expense])` with a legitimate-shaped payload (`['amount' => '8.25', 'description' => 'New', 'date' => '2026-05-15']`); assert 404 (the auth/relationship boundary in the controller throws BEFORE the FormRequest's whitelist would matter); assert A's expense is unchanged (amount, description, date, owner_id). The test proves the cross-user 404 boundary; owner-immutability under tampering by the rightful owner is a separate defense layer (Fillable + FormRequest whitelist + forceFill) covered structurally, not by a dedicated test in v1.

#### 3. `tests/Feature/Expenses/DeleteExpenseTest.php`

**File**: `tests/Feature/Expenses/DeleteExpenseTest.php`

**Intent**: Cover FR-016 happy path + isolation. The "with confirmation" requirement is a frontend concern (native `confirm()`); the backend just deletes — that's what the test asserts.

**Contract**: Two `#[Test]` methods:
- `expense_is_removed()` — create A, venture, one expense; authenticate as A; DELETE `route('expenses.destroy', [$venture, $expense])`; assert 302 redirect to `route('ventures.show', $venture)`; assert `Expense::count() === 0`.
- `user_b_gets_404_deleting_user_a_expense()` — create A and B, A's venture and expense; authenticate as B; DELETE `route('expenses.destroy', [$A_venture, $A_expense])`; assert 404; assert A's expense still exists.

#### 4. `tests/Feature/Ventures/VentureTotalCostTest.php`

**File**: `tests/Feature/Ventures/VentureTotalCostTest.php`

**Intent**: Cover FR-019 in one test: the total cost renders on BOTH the venture detail view AND the venture list row, with the same value, computed from the DB SUM (NOT from a PHP-side collection sum). Implicitly proves the `withSum` aggregate alias works and that the per-render display format is `Total: X.XX`.

**Contract**: One `#[Test]` method `total_cost_renders_on_detail_and_list()`:
- Create user A, one venture for A, three expenses with amounts `'12.50'`, `'7.25'`, `'5.00'` (sum: `24.75`); authenticate as A.
- GET `route('ventures.show', $venture)`; assert response 200; assert sees the string `Total: 24.75`.
- GET `route('ventures.index')`; assert response 200; assert sees `Total: 24.75`.
- (No separate zero-expenses sub-test — the empty-state path renders `Total: 0.00`, but the empty-state copy is already exercised by manual verification and the show view's empty-state CTA does not require an automated test for v1.)

#### 5. `docs/reference/contract-surfaces.md` — new "Expense surface (S-06)" section

**File**: `docs/reference/contract-surfaces.md`

**Intent**: Add an "## Expense surface (S-06)" section immediately after the existing "## Venture list + destroy surface (S-04)" section. Capture: the routes, the doubly-scoped access path realization, the precision-safe money discipline (`decimal:2` + DB-side aggregation), the `Expense::$touches = ['venture']` coupling (mirroring the S-04 anticipation note), the shared-surface contracts S-05 will inherit (metadata-line slot ownership, detail-view stack ordering, `VenturesController::index` aggregate chain ordering), the test proofs, and the "Out of scope for S-06" sub-section. Also add a back-reference from the S-04 "Out of scope for S-04" item that previously said "Total-cost cell on the list row → S-06" — flip the bullet to past tense with a section link.

**Contract**: New `## Expense surface (S-06)` section with sub-sections:
- **Established by**: S-06 (`context/changes/venture-expenses-and-cost/`).
- **PRD anchors**: FR-015, FR-016, FR-017, FR-019, NFR(isolation), NFR(edit-latency).
- **Routes (all under the `auth` middleware, nested under `ventures/{venture}/expenses/...`)**: `expenses.create` (GET), `expenses.store` (POST), `expenses.edit` (GET), `expenses.update` (PATCH), `expenses.destroy` (DELETE) — full URL shapes listed.
- **Access path rule**: every action resolves via `$request->user()->ventures()->findOrFail($venture)->expenses()->findOrFail($expense)` — produces 404 (not 403) for foreign venture OR foreign expense. No global `Expense::find()`, no `Route::bind('expense', …)`.
- **Money precision discipline**: `amount` is `decimal(12,2)` with Eloquent `decimal:2` cast; list-row total via `withSum('expenses as total_cost', 'amount')` (DB-side SUM, NULL when empty, rendered via `number_format($value ?? 0, 2)`); detail-view total via `$venture->expenses()->sum('amount')` (DB-side SUM, NOT `$venture->expenses->sum('amount')` which is PHP `array_sum` and float-coerces).
- **`Expense::$touches = ['venture']` coupling**: bubble expense writes into the venture's `updated_at` so the venture-list `ORDER BY updated_at DESC` sort honors recent expense activity. Same lesson S-04 codified for `Step`.
- **Owner-immutability**: `Expense::$fillable = ['amount', 'description', 'date']` (no `owner_id` / `venture_id`); store action uses `forceFill(['owner_id' => …])`. Two-layer defense alongside the FormRequest whitelist.
- **Shared-surface contracts (S-05 inherits)**:
  - Venture-list per-row metadata: stack of `<p>` lines under the title. Line 1 = step progress (S-04). Line 2 = total cost (S-06). **Line 3 reserved for the deadline marker (S-05)**.
  - Venture-detail outer stack: AI flash → Description → Steps → Expenses. S-05 modifies only the Steps card's inner rows.
  - `VenturesController::index` aggregate chain: `withCount([...])->withSum('expenses as total_cost', 'amount')->orderByDesc('updated_at')`. S-05 SHOULD add its aggregate into the `withCount([...])` array as another named subquery.
- **Test proof**: file:line references to `AddExpenseTest`, `EditExpenseTest`, `DeleteExpenseTest`, `VentureTotalCostTest`. Guest-302 deferred (same `auth` boundary already proven by `VentureIsolationTest::test_guest_gets_redirect_on_venture_show` — same justification S-04 used).
- **Out of scope for S-06**: multi-currency, per-step allocation, categories, budget comparison, export, undo, deadline marker (S-05), policies.

Update the existing "## Venture list + destroy surface (S-04)" section's "Out of scope" sub-section: flip the `Total-cost cell on the list row → S-06` bullet to `Total-cost cell on the list row → satisfied by S-06 — see [Expense surface (S-06)](#expense-surface-s-06).`.

#### 6. Roadmap status flip + `change.md` flip

**File**: `context/foundation/roadmap.md`, `context/changes/venture-expenses-and-cost/change.md`

**Intent**: Reflect the completed slice. Roadmap "At a glance" table row for S-06 flips `Status: proposed` → `Status: done`. The `### S-06: Venture expenses and total cost` block's `Status:` line flips the same way. Backlog Handoff row's `Ready for /10x-plan: no` flips to `done`. `change.md` frontmatter: `status: planned` → `status: implemented`, `updated:` bumped to the implementation date.

**Contract**: Markdown edits only. Mirror the pattern S-04 used.

### Success Criteria:

#### Automated Verification:

- All new feature tests pass: `composer run test`
- Linting / formatting passes: `vendor/bin/pint --test`
- `php artisan route:list --name=expenses` still shows the five `expenses.*` routes

#### Manual Verification:

- `docs/reference/contract-surfaces.md` reads sensibly: the new "Expense surface (S-06)" section has working file:line references; the shared-surface contracts (especially the line-3 reservation) are unambiguous to a future S-05 implementer; the S-04 back-reference works
- `context/foundation/roadmap.md` S-06 row is `done` in the table, the slice block, and the Backlog Handoff column
- `context/changes/venture-expenses-and-cost/change.md` is `status: implemented`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the contract-surfaces section, the S-05 inheritance contracts, and the roadmap edits all read sensibly before considering the slice complete.

---

## Testing Strategy

### Unit Tests:

None — this slice has no pure-logic seams that warrant a unit test. Owner-immutability lives at the model + FormRequest layer and is exercised by `EditExpenseTest::user_b_gets_404_editing_user_a_expense` (which sends a tampered `owner_id`).

### Integration Tests (Feature tests):

Seven tests total. Breakdown: `AddExpenseTest` (2), `EditExpenseTest` (2), `DeleteExpenseTest` (2), `VentureTotalCostTest` (1).

### Manual Testing Steps:

1. Sign in → land on `/dashboard` → list shows existing ventures with `Total: 0.00` lines.
2. Open a venture → see new Expenses card at the bottom with "No expenses yet" + CTA.
3. Click `+ Add expense` → land on create form with date = today → enter `12.50` / `Coffee` / today → Add → land on `ventures.show` with `Total: 12.50` and the row in the ledger.
4. Click Edit on the row → change amount to `15.00` → Save → land on `ventures.show` with `Total: 15.00`.
5. Add another expense `5.00` / `Tea` / yesterday → ledger now shows today's first, yesterday's second; `Total: 20.00`.
6. Return to `/dashboard` → the venture has bubbled to the top of the list (`$touches` verified); its line 2 reads `Total: 20.00`.
7. Click Delete on a ledger row → confirm dialog → confirm → row gone; total recomputes; venture stays at the top.
8. Open a second browser as user B → expect 404 on every `/ventures/{A's id}/expenses/...` URL (store, edit, update, destroy).

## Performance Considerations

- The list-row aggregate uses `withSum('expenses as total_cost', 'amount')` — one extra subquery per index render, executed in the DB with the `(venture_id, date)` index. Sub-millisecond at v1 scale; even at 100x v1 scale (~1000 users, ~10 ventures + ~50 expenses each) the aggregate stays well under the 1s NFR(edit-latency) budget.
- The detail-view total uses `$venture->expenses()->sum('amount')` — one extra DB query per show render (in addition to the eager-load). Could be folded into a single `withSum` if profiling ever shows it matters; the explicit separate query is preferred for v1 because it keeps the cast-discipline reasoning visible at the controller level.
- Per-row eager-load of expenses on the show view is fine — a single venture with even 100 expenses is sub-100KB on the wire.
- No JS work; full-reload redirects on five actions. NFR(edit-latency) is satisfied trivially.

## Migration Notes

- One new table (`expenses`); no existing data. No backfill, no down-migration concerns beyond the standard `Schema::dropIfExists('expenses')`.
- The migration is additive; no existing rows touched. Render's deploy pipeline runs migrations on push to `main`; the new table appears on the next deploy.
- No env var changes; the slice does not introduce any new config.

## References

- F-01 contract (per-user isolation rules + S-01 enforcement checklist): `docs/reference/contract-surfaces.md`
- S-02 plan + brief (the pattern this slice mirrors most closely for a fresh nested resource): `context/changes/edit-and-track-steps/plan.md`, `context/changes/edit-and-track-steps/plan-brief.md`
- S-04 plan + brief (the source of the `$touches` discipline and the row-layout slot anticipation): `context/changes/list-and-delete-ventures/plan.md`, `context/changes/list-and-delete-ventures/plan-brief.md`
- S-04 contract surface (anticipates S-06's `Expense::$touches` obligation): `docs/reference/contract-surfaces.md#venture-list--destroy-surface-s-04`
- S-01 controller (the `$request->user()->ventures()->...` access pattern S-06 nests): `app/Http/Controllers/VenturesController.php:17-96`
- S-02 controller (the doubly-scoped nested-resource resolution S-06 mirrors): `app/Http/Controllers/StepsController.php:20-89`
- Existing isolation test pattern: `tests/Feature/Ventures/VentureIsolationTest.php`
- Existing form-styling reference: `resources/views/ventures/create.blade.php`, `resources/views/steps/create.blade.php`
- Lessons: `context/foundation/lessons.md` (per-user isolation, AI fail-open — neither directly applies to S-06 actions, but the isolation rule is the foundation of every change required here)
- Tech stack: `context/foundation/tech-stack.md`
- Roadmap entry for S-06: `context/foundation/roadmap.md` (At a glance → S-06 row; slice block § "S-06: Venture expenses and total cost")

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Schema + model + routes + FormRequests + skeleton controller

#### Automated

- [ ] 1.1 Migration applies cleanly: `php artisan migrate` (or `composer run test` via `RefreshDatabase`)
- [ ] 1.2 Linting / formatting passes: `vendor/bin/pint --test`
- [ ] 1.3 Existing test suite still green: `composer run test`
- [ ] 1.4 `php artisan route:list --name=expenses` shows the five new `expenses.*` routes under the `auth` middleware

#### Manual

- [ ] 1.5 Visiting `/ventures/{v}/expenses/create` while authenticated returns the placeholder 404 (skeleton stub fires)
- [ ] 1.6 Visiting any of the five routes while unauthenticated redirects to `/login`
- [ ] 1.7 `php artisan tinker` quick check: `\App\Models\Expense::factory()->create()` succeeds with `amount` as stringified `decimal:2` and `date` as Carbon

### Phase 2: Wire actions + integrate views

#### Automated

- [ ] 2.1 Linting / formatting passes: `vendor/bin/pint --test`
- [ ] 2.2 Existing test suite still green: `composer run test`
- [ ] 2.3 `php artisan route:list --name=expenses` still shows the five `expenses.*` routes

#### Manual

- [ ] 2.4 Add an expense from the empty-state CTA → lands in the ledger; detail-view total updates
- [ ] 2.5 Edit an expense → values update; detail-view total recomputes
- [ ] 2.6 Add a second expense with an earlier date → ledger orders by date DESC, created_at DESC
- [ ] 2.7 Delete an expense → native confirm dialog → row gone; total recomputes
- [ ] 2.8 Navigate to `/dashboard` → venture-list row shows two metadata lines (line 1 = progress, line 2 = total cost)
- [ ] 2.9 After editing an expense, return to `/dashboard` → the venture has bubbled to the top (`Expense::$touches = ['venture']` wiring verified end-to-end)
- [ ] 2.10 Create a venture with zero expenses → list row reads `Total: 0.00`; detail-view shows empty-state CTA
- [ ] 2.11 The `ventures/index.blade.php` Blade comment names the line-1 / line-2 / line-3 slot contract for S-05 explicitly

### Phase 3: Test matrix + cross-slice handoff

#### Automated

- [ ] 3.1 All new feature tests pass: `composer run test`
- [ ] 3.2 Linting / formatting passes: `vendor/bin/pint --test`
- [ ] 3.3 `php artisan route:list --name=expenses` still shows the five `expenses.*` routes

#### Manual

- [ ] 3.4 `docs/reference/contract-surfaces.md` "Expense surface (S-06)" reads sensibly with working file:line references and the line-3 reservation for S-05 unambiguous
- [ ] 3.5 `context/foundation/roadmap.md` S-06 row is `done` in the table, the slice block, and the Backlog Handoff column
- [ ] 3.6 `context/changes/venture-expenses-and-cost/change.md` flipped to `status: implemented`, `updated` bumped to the implementation date
