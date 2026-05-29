# S-04 / list-and-delete-ventures — Plan Brief

> Full plan: `context/changes/list-and-delete-ventures/plan.md`

## What & Why

FR-005 (venture list) + FR-007 (venture delete) — the return surface S-04's PRD anchors call for, plus the destructive-action endpoint that completes the venture-level CRUD. The list is the surface the **secondary success metric** ("users return to a venture at least once after creating it") depends on; without it, returning users have no portfolio screen and the secondary metric has no surface to even measure against. The slice also closes the documented `dashboard` route name reassignment S-01 explicitly deferred: post-login lands on the list, not the create form.

## Starting Point

S-01 + S-02 are landed. `VenturesController` implements `create` / `store` / `show` through `$request->user()->ventures()->...` and the seven step write-actions are nested under `/ventures/{venture}/steps/...`. The `dashboard` route name currently aliases `ventures.create` — a placeholder S-01 introduced specifically so this slice can reassign without editing the two `route('dashboard')` callsites in F-01's auth controllers. The schema-level cascade chain (`ventures.owner_id`, `steps.venture_id`, `steps.owner_id` — all `cascadeOnDelete`) is already wired, so `$venture->delete()` drops the venture + its step subtree in one DB statement; no application-level loop needed.

## Desired End State

A logged-in user lands on `/dashboard` (post-login, post-register, or via the nav home link) and sees their ventures ordered `updated_at DESC` — the most recently touched venture sits at the top. Each row carries the venture title (link to detail), a small `X of Y steps completed` progress string (or `—` when total is 0), and a Delete button that fires a native `confirm()` and POSTs DELETE to `/ventures/{n}`. Empty state replaces the list with a primary CTA pointing at `ventures.create`. A second user posting DELETE to a foreign venture URL gets 404, not 403. The S-02 step write surface still works unchanged.

## Key Decisions Made

| Decision                                                            | Choice                                                                                  | Why (1 sentence)                                                                                                                                                          | Source |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Default list sort order                                             | `updated_at DESC` (most-recently-touched first)                                          | Matches the secondary success metric ("users return") — the venture the user last touched is what they want to find again on return.                                       | Plan   |
| What bumps `ventures.updated_at` so the sort actually surfaces edits | Add `protected $touches = ['venture']` to `Step` (S-02 didn't set this)                  | Without it, editing steps on a venture leaves the venture stale at the bottom of the list — the chosen sort would be a lie for the most common return-engagement pattern. | Plan   |
| Delete confirmation strength                                        | Native `confirm()` form, same shape as S-02's step-delete                                | One pattern across delete surfaces; meets NFR("explicit second user action") cheaply; type-the-title modal is overkill for a solo MVP on a deadline.                      | Plan   |
| `dashboard` route name reassignment                                 | Yes — reassign to `index` in this slice; add parallel `ventures.index` name             | Contract-surfaces.md explicitly tagged S-04 as the hand-off point; F-01 callsites (`route('dashboard')`) and the nav link work without an edit.                            | Plan   |
| Row content (today, leaving slots for S-05/S-06)                    | Title (linked) + "X of Y steps completed" + Delete; no description excerpt              | Surfaces the FR-018 progress signal users already see on detail; leaves natural right-side slots for S-05's deadline pill and S-06's cost cell without churn.              | Plan   |
| Test matrix scope                                                   | 4 tests — `ListVenturesTest` (empty-state + own-only) + `DeleteVentureTest` (happy + two-user 404) | Each PRD ref has at least one happy-path assertion; F-01 enforcement-checklist item 4 is proven on the new endpoint; guest boundary not re-asserted (same `auth` group as `ventures.show` already covers it). | Plan   |
| Cascade-delete approach                                             | Schema-level `cascadeOnDelete` via `$venture->delete()`; NO application loop            | The migration already declares the chain; a `foreach ($venture->steps as ...) $step->delete()` would be slower AND fragile under concurrency.                              | Plan   |

## Scope

**In scope:**
- Two new actions on the existing `VenturesController`: `index` (list, `updated_at DESC`, `withCount` step aggregates) + `destroy` (resolve via user relationship, `->delete()`, redirect to list).
- Route surface: reassign `dashboard` route name to `index`, add `ventures.index` (parallel `/ventures` URL same action), add `ventures.destroy` (`DELETE /ventures/{venture}`).
- One new view: `resources/views/ventures/index.blade.php` (empty state + non-empty rows).
- One model touch: `protected $touches = ['venture']` on `Step` so step writes bubble the parent's `updated_at`.
- 4 feature tests, "Venture list + destroy surface (S-04)" section in `docs/reference/contract-surfaces.md` (plus edits to the F-01 + S-01 sections to record the dashboard reassignment), roadmap row + `change.md` flips.

**Out of scope:**
- Total-cost cell on the list row (FR-019 — S-06's job; no slot pre-built).
- Deadline marker on the list row (FR-021 — S-05's job; no slot pre-built).
- Description excerpt on the list row.
- Pagination, sort/filter, active-vs-completed UI (FR-005 Socrates: v1 is a flat list).
- Delete button on the venture detail view (v2 ergonomic; list row is enough for v1).
- Heavier confirmation (type-the-title, modal) (v2 ergonomic).
- Soft-delete / archive semantics (PRD §Non-Goals + FR-007 Socrates).
- Undo on delete (PRD §Non-Goals).
- JS island (the surface is read + a form POST; no fetch).
- Schema / migration / factory / `$fillable` / `Policy` changes.

## Architecture / Approach

```
GET /dashboard or GET /ventures
   │
   ▼
VenturesController::index(Request $request)
   ├─ $request->user()->ventures()
   │      ->withCount(['steps', 'steps as completed_steps_count' => fn($q) => $q->where('is_completed', true)])
   │      ->orderByDesc('updated_at')
   │      ->get()                                          ← single query + two count subqueries; no N+1
   └─ view('ventures.index', ['ventures' => $ventures])

ventures.index view
   │ empty?  → "Create your first venture" CTA → ventures.create
   │ else    → <ul> rows: title-link + "N of M steps completed" + Delete form (native confirm)
   ▼
POST DELETE /ventures/{venture}
   ▼
VenturesController::destroy(Request $request, int $venture)
   ├─ $request->user()->ventures()->findOrFail($venture)   ← 404 (not 403) for foreign owners
   ├─ ->delete()                                            ← schema cascade drops step rows atomically
   └─ redirect()->route('ventures.index')

Step::$touches = ['venture']                                ← S-02 step writes bubble ventures.updated_at
```

## Phases at a Glance

| Phase                                  | What it delivers                                                                                | Key risk                                                                                                                                                                                  |
| -------------------------------------- | ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Backend + view + nav rewire         | Two controller actions, three route edits, one new view, one model touch (`Step::$touches`)     | Forgetting `Step::$touches = ['venture']` makes the chosen `updated_at DESC` sort surface stale data — a venture the user is actively editing the steps of would stay buried.              |
| 2. Test matrix + cross-slice handoff   | 4 feature tests, contract-surfaces "Venture list + destroy surface (S-04)" section, roadmap + `change.md` flips | The destroy happy-path assertion MUST verify the step-row cascade (not just the venture row), otherwise a future schema change that breaks the cascade would silently corrupt the data.    |

**Prerequisites:** F-01 (auth + isolation, shipped), F-02 (AI service, shipped — orthogonal to this slice), S-01 (venture surface, shipped), S-02 (step surface, shipped). `composer run dev` for local; no new env vars; no migration needed.

**Estimated effort:** ~1 session per phase, ~2 sessions total. Smaller than S-03 because there's no AI call, no FormRequest, no JS, no preview round-trip — just CRUD-shape extensions of an existing controller.

## Open Risks & Assumptions

- **`Step::$touches = ['venture']` is the single point of failure for the sort promise.** If a future contributor refactors `Step` and drops the property without realizing the list depends on it, the sort silently becomes "venture-row-edited first" (which is almost-never for v1) and the return-surface UX degrades without a visible failure. Captured as a "if you remove this, the sort lies" caveat in `docs/reference/contract-surfaces.md`'s S-04 section so reviewers reading the contract see it before touching the model.
- **No guest-redirect test on the destroy endpoint** in the scoped 4-test matrix. The `auth` middleware boundary is structurally identical to the boundary already proven for `ventures.show`, so adding a fifth test would be ritualistic. Decision is recorded in both the plan and contract-surfaces.md so a future reviewer doesn't read the absence as an oversight.
- **`withCount` runs two subqueries per index render** — fine at v1 volumes but not infinite. The right answer when the portfolio reaches ~100 ventures is pagination (deferred to v2 by FR-005 Socrates), not removing the counts. The list-thin design leaves natural slots for S-05/S-06 to add their own per-row aggregates; if any of those force a different query shape (e.g. expense joins), the count query can move to a service method without changing the view contract.
- **`$venture->delete()` doesn't refund `ai_call_counters`** — calls really happened against the user's 24h budget regardless of whether the resulting venture survives. Documented in the contract-surfaces section so reviewers don't expect rollback logic.

## Success Criteria (Summary)

- A logged-in user lands on the venture list at `/dashboard`, sees their ventures ordered `updated_at DESC`, and can open any row to its detail view or delete it after confirming the native `confirm()` dialog. Deleting a venture removes the row from the list and drops its step rows via schema cascade.
- Editing a step on a venture and returning to the list shows that venture bubbled to the top (`Step::$touches` wiring works).
- A second user POSTing DELETE to a foreign venture URL gets 404, never 403, and the venture stays in the DB. A brand-new user (zero ventures) sees the empty-state CTA pointing at `ventures.create`.
