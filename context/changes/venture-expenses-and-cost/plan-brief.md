# S-06 / venture-expenses-and-cost — Plan Brief

> Full plan: `context/changes/venture-expenses-and-cost/plan.md`

## What & Why

Land the venture-expenses surface (FR-015 / FR-016 / FR-017 / FR-019): a new per-user `Expense` domain model with venture-level scope, full CRUD via nested `ventures/{venture}/expenses/*` routes, and accumulated total cost surfaced on BOTH the venture detail view AND the venture list. The slice fights the "expenses scatter to a spreadsheet" pain the PRD names as the second-largest blocker; combined with steps it completes the unified-view promise ("where am I and what has it cost me"). S-06 runs in parallel with S-05; this plan deliberately codifies the shared-surface contracts S-05 will inherit so the parallel work has named seats instead of merge conflicts.

## Starting Point

S-01 / S-02 / S-04 are landed and stable. `VenturesController` carries `index` / `create` / `store` / `show` / `destroy` all through the `$request->user()->ventures()->...` access path; the list row layout is a flex with title + one `<p>` progress line + Delete form; `Step::$touches = ['venture']` already wires `updated_at` bubbling for step writes; S-04 explicitly anticipated S-06's obligation to do the same for expenses. No money handling exists yet — this is the project's first decimal/aggregate decision.

## Desired End State

A logged-in user on `/ventures/{v}` sees a new Expenses card appended below the Steps card: header shows `Expenses · Total: X.XX`, body shows either a "No expenses yet" CTA or a ledger of rows (date · description · amount · Edit · Delete) ordered by date DESC. Add / Edit / Delete each navigate to a small form (date pre-filled to today on create) and redirect back. On `/dashboard` each venture row carries two `<p>` metadata lines under the title — line 1 = step progress (S-04), line 2 = `Total: X.XX` (this slice). A second user gets 404 on every expense URL; F-01 isolation holds. `docs/reference/contract-surfaces.md` records the new surface AND the slot-3 reservation for S-05's deadline marker.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
|----------|--------|------------------|--------|
| Money representation | `decimal(12,2)` + Laravel `decimal:2` cast | Human-friendly schema; exact DB-side SUM; PHP-side reads return string-decimal that doesn't coerce to float. | Plan |
| Amount validation | `numeric \| gte:0` (zero allowed, no app-level upper bound) | User chose to allow zero entries (e.g., comped/free events); schema `decimal(12,2)` provides a de-facto ceiling. | Plan |
| Description | Required, 1–200 chars | PRD says "short description"; 200 matches the project's existing length-cap convention (`steps.body`). | Plan |
| List-row layout for parallel S-05 | Separate `<p>` lines under the title — line 2 = total cost (S-06), line 3 = deadline marker (reserved for S-05) | Unambiguous slot reservation kills the parallelism conflict; no shared string to merge. | Plan |
| Detail-view layout | Append Expenses card below Steps in the existing vertical stack | Preserves Steps (primary loop) above the fold; S-05 modifies only step rows so zero stack-level collision. | Plan |
| Empty-state on detail | Always render the Expenses card with "No expenses yet" + CTA | Discoverability for an optional-by-product feature; mirrors S-02's Steps empty-state pattern. | Plan |
| Date default + ordering | Default today; order `date DESC, created_at DESC` | Matches financial-ledger mental model; today is highest-frequency input; tiebreaker handles same-day flurries. | Plan |
| Test scope | Minimum F-01 checklist + happy paths (~7 tests) | Meets the F-01 enforcement-checklist mandate; mirrors S-02/S-04 matrix shape; cheap to maintain. | Plan |

## Scope

**In scope:**
- New `expenses` table (migration), `Expense` model, `ExpenseFactory`
- `User::expenses()` + `Venture::expenses()->orderByDesc('date')->orderByDesc('created_at')` relationships
- New `ExpensesController` with create / store / edit / update / destroy
- Two FormRequests (`CreateExpenseRequest`, `EditExpenseRequest`) — body-only whitelists
- Five nested routes under `auth` middleware
- Two new views (`expenses/create.blade.php`, `expenses/edit.blade.php`)
- `VenturesController::index` aggregate-chain extension (`withSum`)
- `VenturesController::show` extension (eager-load + DB-side total)
- Expenses card appended to `ventures/show.blade.php`
- Second metadata `<p>` line on `ventures/index.blade.php` + slot-reservation comment for S-05
- `Expense::$touches = ['venture']` coupling
- Seven feature tests
- "Expense surface (S-06)" section in `docs/reference/contract-surfaces.md`
- Roadmap + `change.md` status flips

**Out of scope:**
- Multi-currency, per-step expense allocation, categories, budget comparison, export, undo (all PRD §Non-Goals or v2)
- Deadline marker on the list row (S-05 owns line 3)
- Policy classes (v3+ per F-01 future-evolution)
- JS island (full-reload form POSTs throughout)
- Detail-view delete button on the venture itself (S-04 already shipped the list-row delete)
- App-level upper bound on amount (user explicitly opted out; schema `decimal(12,2)` is the ceiling)

## Architecture / Approach

```
Routes (auth-gated, nested under ventures/{v}):
  GET     /ventures/{v}/expenses/create
  POST    /ventures/{v}/expenses
  GET     /ventures/{v}/expenses/{e}/edit
  PATCH   /ventures/{v}/expenses/{e}
  DELETE  /ventures/{v}/expenses/{e}

Controller: $request->user()->ventures()->findOrFail($v)->expenses()->findOrFail($e)
            — doubly-scoped resolution → 404 on either foreign venture or foreign expense

Model: Expense { fillable=[amount,description,date], casts=decimal:2 + date,
                 $touches=['venture'], belongsTo(Venture), belongsTo(User,'owner_id') }

Schema: expenses(id, venture_id FK cascade, owner_id FK cascade, decimal(12,2) amount,
                 string(200) description, date date, timestamps, idx(venture_id, date))

Aggregates:
  - List row: withSum('expenses as total_cost', 'amount') → DB-side SUM, exact
  - Detail:   $venture->expenses()->sum('amount')         → DB-side SUM, exact
  - NEVER:    $venture->expenses->sum('amount')           → PHP array_sum, float-coerces
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|-------|------------------|----------|
| 1. Schema + model + routes + FormRequests + skeleton controller | Migration, `Expense` model with `$touches`, two relationships, factory, two FormRequests, controller skeleton, five mounted routes. No views, no visible change. | Forgetting `$touches = ['venture']` would silently break the list sort the same way S-04 named — caught by Phase 2 manual verification step 8. |
| 2. Wire actions + integrate views | Five controller methods, two new views, `VenturesController::index/show` extensions, Expenses card on `show.blade.php`, second metadata line on `index.blade.php` with slot-reservation comment for S-05. | Float coercion on the total via `Collection::sum` would silently corrupt aggregates — Critical Implementation Details block names the two correct primitives explicitly. |
| 3. Test matrix + cross-slice handoff | Seven feature tests, new "Expense surface (S-06)" section in `docs/reference/contract-surfaces.md` (with the line-3 reservation for S-05), roadmap + `change.md` status flips. | The contract-surfaces doc must record the shared-surface contracts unambiguously, or S-05's parallel work loses its named seat — Phase 3 manual verification step 3.4 is the gate. |

**Prerequisites:** S-01, S-04 (both `done` per roadmap). No env var changes, no new packages.

**Estimated effort:** ~2–3 sessions across three phases. Mechanically small (mirrors S-02/S-04 shape exactly) but contractually load-bearing — the F-01 enforcement-checklist obligation for a new per-user model and the S-05 inheritance contracts both need careful attention.

## Open Risks & Assumptions

- **Parallel S-05 hasn't been planned yet.** This plan defines slot ownership unilaterally (line-3 for the deadline marker). If the S-05 plan diverges — e.g. wants a badge near the title instead of a metadata-line entry — this slice's contract-surfaces section needs a follow-up edit. The risk is bounded: the metadata-line slot is the simplest layout that accommodates both signals; if S-05 picks something else, that's a UX conversation, not a structural rewrite.
- **No application-level cap on `amount`.** The user explicitly chose `gte:0` only; the schema `decimal(12,2)` ceiling (~9.99 billion) is the limit. A typo or paste accident could write an absurd value. Acceptable for v1 because the user controls all input on their own ventures; if abuse becomes a concern, add `max:` to both FormRequests without migration.
- **Single currency assumed but not enforced anywhere in code.** The plan does not add a `currency_code` column or any currency hint. If a future v2 adds multi-currency, the migration is additive (new column, default 'USD' or similar); no existing row needs to change.
- **`withSum` alias `total_cost` does not collide with anything today.** If S-05 also picks `total_*` for its aggregate alias, the chain silently overwrites — flagged in the plan as a coordination point. Recommended S-05 alias: `imminent_steps_count` / `overdue_steps_count` (the `*_count` naming follows the existing `steps as completed_steps_count` pattern).

## Success Criteria (Summary)

- A user can add / edit / delete venture-level expenses and see the accumulated total on both the venture detail view (`Total: X.XX` in the Expenses card header) and the venture list row (`Total: X.XX` as line 2 of the per-row metadata).
- Two-user isolation holds on all three write endpoints — a foreign user gets 404 (not 403); F-01 S-01 enforcement-checklist items 1–5 all satisfied for the new `Expense` model.
- The line-3 slot on the venture-list row is reserved for S-05's deadline marker — explicitly documented in code (Blade comment) and `docs/reference/contract-surfaces.md` so the parallel S-05 work has a named seat.
