# S-01 / create-venture-with-ai-plan — Plan Brief

> Full plan: `context/changes/create-venture-with-ai-plan/plan.md`

## What & Why

Land the first user-visible domain surface (`Venture` + `Step`) so an authenticated user can submit a short title + optional description and immediately receive exactly 7 AI-suggested steps on a venture detail view — or an empty step list plus a non-blocking notice if AI is unavailable. This is the roadmap **north-star** slice: if AI-suggested steps don't land as a usable starting plan, the product thesis fails, so it ships as soon as F-01 (auth + isolation contract) and F-02 (AI service + ceiling) are in place.

## Starting Point

Post-F-02: `users`, `sessions`, `ai_call_counters` only. Auth + the `auth` middleware boundary are landed; `/dashboard` is a placeholder page explicitly tagged "replaceable by S-01/S-04" in F-01's brief. `AiStepSuggester::suggestSteps()` is a working seam returning 7 strings or `[]`. No `Route::resource`-style controllers exist; auth controllers are thin Blade/FormRequest skinny actions and set the style.

## Desired End State

A logged-in user lands on `/dashboard`, sees a "Start a new venture" form (title + optional description), submits, and lands on `/ventures/{id}` showing 7 read-only steps (or 0 steps + a yellow flash notice on AI failure). A second user requesting `/ventures/{first-user's-id}` gets 404 (not 403). The `docs/reference/contract-surfaces.md` S-01 enforcement checklist is satisfied with executable proof, and a new "Venture surface (S-01)" section documents the seam for S-02 / S-03 / S-04 to inherit.

## Key Decisions Made

| Decision                              | Choice                                                                                                  | Why (1 sentence)                                                                                                                                          | Source |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Create-flow shape                     | Single sync POST                                                                                        | Simplest path; `AiStepSuggester`'s 15s timeout is well under Render's request ceiling; F-02 explicitly documents sync as the deployment property.          | Plan   |
| Step schema shape                     | Minimal + forward-compatible (`source` enum + `position`)                                               | `source` anchors the FR-008 frozen AI pool so S-03 doesn't need a backfill; `position` is cheap and unblocks S-02's reorder semantics without a schema bump. | Plan   |
| Description requirement               | **Optional** (deviation from recommendation)                                                            | User chose lower friction over AI-quality on title-only ventures; success-metric risk acknowledged with the FR-004 UX hint as mitigation.                  | Plan   |
| AI-fail UX                            | Flash notice on detail view                                                                             | Matches PRD US-01 acceptance ("non-blocking notice"); zero new UI primitives; no regenerate-button slippery slope (PRD Non-Goals).                          | Plan   |
| Entry point                           | Replace `/dashboard` placeholder with the create form                                                   | F-01 brief calls the placeholder "replaceable by S-01/S-04"; new users see the value prop immediately; S-04 will reassign the `dashboard` route name later. | Plan   |
| Validation rules                      | Title required (1–120 chars); description optional (≤2000 chars)                                        | Title bounds protect list/detail rendering downstream; description bound protects prompt cost.                                                              | Plan   |
| Test matrix                           | 6 feature tests (happy / AI fail / validation / guest boundary / two-user 404 / ownership-scoped binding) | Covers US-01 acceptance + the full F-01 S-01 enforcement checklist in one pass; `AiStepSuggester` faked via container binding — no Groq in CI.              | Plan   |
| Ownership-scoping mechanism           | Controller resolves via `$request->user()->ventures()->findOrFail($id)`                                 | Maps 1:1 to F-01 lessons.md's structural rule; produces 404-not-403 automatically; survives v3 co-editing evolution by renaming one method.                | Plan   |
| Manual verification gate              | **Browser happy-path only** (deviation from recommendation)                                             | User trusts the automated AI-fail + isolation tests to carry those paths; lighter manual gate keeps the slice fast.                                         | Plan   |
| AI call positioning vs. transaction   | AI call OUTSIDE the outer DB transaction                                                                | Counter increment must persist even if Venture+Steps inserts fail; wrapping both in one outer transaction would corrupt NFR(ai-ceiling) accounting.        | Plan   |

## Scope

**In scope:**
- `ventures` + `steps` migrations (F-01 FK + cascade convention; `source` enum; `position`)
- `Venture` + `Step` models with explicit FK-named relationships; `User::ventures()` `hasMany`
- `VenturesController` (`create`, `store`, `show`); `CreateVentureRequest` FormRequest
- Replace `/dashboard` placeholder with the create form; `ventures.create` / `ventures.store` / `ventures.show` routes under `auth`
- Read-only detail view with the AI-fail flash notice
- 6 feature tests (4 in `CreateVentureTest`, 2 in `VentureIsolationTest`)
- `docs/reference/contract-surfaces.md` "Venture surface (S-01)" section + S-01 checklist items crossed off
- Roadmap S-01 status flip → `done`; `change.md` status flip → `implemented`

**Out of scope:**
- Step edit / delete / completion toggle / manual-add (→ S-02)
- Venture list / venture delete (→ S-04)
- AI extension trigger (→ S-03) — `source = ai_extension` value exists but no code writes it
- Step deadlines + badges + list marker (→ S-05)
- Expenses + total cost (→ S-06)
- Regenerate-AI button (PRD Non-Goals)
- Policies / global scopes — discipline + per-slice tests remain the F-01 enforcement model

## Architecture / Approach

```
GET /dashboard                 GET /ventures/create
        \                       /
         \                     /
          ─→ VenturesController::create ─→ resources/views/ventures/create.blade.php

POST /ventures
   │
   ▼
VenturesController::store(CreateVentureRequest)
   ├── $steps = AiStepSuggester::suggestSteps($user, $title, $description)   ◀── OUTSIDE transaction (counter must survive Venture insert failure)
   ├── DB::transaction(function () {
   │      $venture = $user->ventures()->create([…])                          ◀── F-01 rule: relationship-only access
   │      foreach ($steps as $i => $body) {
   │          $venture->steps()->create(['source' => 'ai_initial', 'position' => $i, …])
   │      }
   │   })
   ├── if (empty($steps)) session()->flash('ai_unavailable', '…')
   └── redirect('/ventures/{id}')

GET /ventures/{venture}
   │
   ▼
VenturesController::show(Request, int $venture)
   ├── $model = $request->user()->ventures()->with('steps')->findOrFail($venture)   ◀── 404-not-403 by construction
   └── view('ventures.show', ['venture' => $model])
```

## Phases at a Glance

| Phase                                                 | What it delivers                                                                                       | Key risk                                                                                                                                  |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Schema + models + relationships                    | `ventures` / `steps` tables, `Venture` / `Step` models, `User::ventures()` `hasMany`; tinker cascade   | First per-user **domain** table — getting the explicit `'users'` constrained + FK-named relationships wrong breaks the F-01 contract       |
| 2. Create-venture flow + detail view                  | `CreateVentureRequest`, `VenturesController`, routes, create + show views, retired `dashboard.blade`   | Mis-ordering the AI call vs. the outer transaction corrupts NFR(ai-ceiling) accounting on Venture-insert failure                          |
| 3. Feature-test matrix + cross-slice handoff          | 6 feature tests, `contract-surfaces.md` Venture-surface section, roadmap + change.md status flips      | Two-user test must assert 404 (not 403) or the F-01 checklist item is not satisfied                                                       |

**Prerequisites:** F-01 (auth + isolation contract), F-02 (`AiStepSuggester` seam).
**Estimated effort:** ~2 focused sessions across 3 phases; Phase 2 is the largest.

## Open Risks & Assumptions

- **Description-optional accepts a primary-metric risk.** AI-suggested steps on title-only ventures will be lower quality, which biases the "3 of 7 kept" metric downward for users who skip the description. Mitigation is the UX hint near the field, not a hard validation rule. If the metric tanks post-launch, revisit (cheap to flip to required).
- **Sync execution holds the request thread for up to 15s.** Acceptable per F-02's deployment-property documentation; Render's request idle ceiling is well above 15s; Groq typical latency is sub-second. NFR(edit-latency) explicitly does not apply to the AI path.
- **`source` enum is mildly speculative for v1.** Only `ai_initial` is written by this slice; `ai_extension` and `manual` exist on the enum but are inert until S-03 and S-02. Including them now avoids a backfill migration later — judged worth the small dead-code cost.
- **The lighter manual gate trusts the AI-fail + isolation tests.** Per Q8 deviation. If either test is wrong (e.g. asserts 403 instead of 404), the manual gate won't catch it. Plan-review will be the safety net.

## Success Criteria (Summary)

- A new user can register → land on `/dashboard` (create form) → submit a venture → see exactly 7 AI-initial steps on the detail view; an AI failure produces 0 steps + a flash notice but never a 500.
- A second user requesting another user's `/ventures/{id}` gets 404 (not 403); a guest gets a 302 to `/login`. The F-01 S-01 enforcement checklist is satisfied with executable proof.
- `composer run test` green; `vendor/bin/pint --test` clean; `docs/reference/contract-surfaces.md` has a Venture-surface section that S-02 / S-03 / S-04 can read as the canonical access path; `context/foundation/roadmap.md` S-01 row is `done`.
