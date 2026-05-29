# S-02 / edit-and-track-steps — Plan Brief

> Full plan: `context/changes/edit-and-track-steps/plan.md`

## What & Why

Add the four step write-actions (manual add, body edit, delete, completion toggle) plus a "X of Y completed" progress display on top of S-01's read-only step list. This closes the primary success-metric loop — "≥3 of 7 AI-initial steps kept" is only measurable once edit/keep/delete exist (and "kept" includes edited) — and the NFR(edit-latency) "feels instant" rule applies to every action shipped here.

## Starting Point

S-01 landed the venture detail view with a read-only `<ol>` of step bodies, the `steps` table with `body`/`is_completed`/`source`/`position` columns and a cascade-on-delete chain, the `StepSource` enum (`AiInitial`/`AiExtension`/`Manual`), and the F-01 access-path discipline (every controller resolves owned data through `$request->user()->ventures()->...`). What's missing: any UI to mutate a step, the progress signal, and the FormRequests + tests for those mutations.

## Desired End State

A logged-in user on `/ventures/{v}` sees the venture, an "X of Y completed" line, and the step list with per-row checkbox + Edit link + Delete button. Toggling the checkbox flips completion and updates progress without a reload (vanilla JS fetch); Edit and Add navigate to single-textarea pages that PATCH/POST and redirect back; Delete fires a native confirm dialog before DELETE. Source-immutability is structurally enforced: even if a malicious POST includes a `source` field on the edit endpoint, the FormRequest whitelist drops it and the model's `$fillable` rejects it — so the FR-008 metric snapshot survives every edit. A second user visiting any of the new URLs gets 404.

## Key Decisions Made

| Decision                          | Choice                                                                                          | Why (1 sentence)                                                                                                                                | Source |
| --------------------------------- | ----------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Interaction model                 | Hybrid: server-rendered forms for add/edit/delete; tiny vanilla-JS fetch for toggle             | Zero new dependency, no build-tool friction, polished feel exactly where the high-frequency FR-013 toggle needs it; graceful form-fallback.     | Plan   |
| URL shape                         | Nested under venture: `/ventures/{v}/steps/{s}[/completion]`                                    | The URL shape structurally enforces the F-01 access path — the venture id in every URL means the controller MUST chain through the relationship. | Plan   |
| `Step::source` mass-assignment    | Drop from `$fillable` AND body-only `EditStepRequest`                                           | Defense in depth — the FR-008 metric snapshot survives any future controller that forwards `$request->all()` to `update()`.                     | Plan   |
| Toggle endpoint shape             | Dedicated `PATCH /ventures/{v}/steps/{s}/completion`                                            | Clean separation from body-edit; safe for the JS fetch handler; cheap test; mirrors FR-013's "directly from list, independent of edit mode."    | Plan   |
| Edit UX                           | Separate `/edit` page, server-rendered form, full reload on save                                | "JS only where it matters" boundary set by Q1; full reload meets NFR(edit-latency) for this stack; zero JS, trivial test.                       | Plan   |
| Add UX                            | Separate `/create` page, single textarea, server-rendered                                       | Consistent with the edit UX choice; the "modal + AI cherry-pick" alternative explored in conversation was reverted as S-03-shaped scope creep.   | Plan   |
| Progress UI                       | Text only: "X of Y completed"                                                                   | PRD FR-018 flags that small-N percentages mislead (each step on a 7-step plan ≈ 14% jump); exact count is the honest signal.                    | Plan   |
| Test coverage                     | Essentials matrix — ~9 feature tests                                                            | Per-action happy + isolation + the source-immutability invariant; matches the F-01 enforcement checklist applied to each new endpoint.          | Plan   |

## Scope

**In scope:**

- `POST /ventures/{v}/steps` (FR-010: manual add, `StepSource::Manual`, position appended)
- `GET/PATCH /ventures/{v}/steps/{s}/edit` (FR-011: body-only edit, source preserved)
- `DELETE /ventures/{v}/steps/{s}` (FR-012: delete with native-confirm second-gesture)
- `PATCH /ventures/{v}/steps/{s}/completion` (FR-013: no-confirmation toggle, JSON for AJAX, redirect for form fallback)
- Progress text "X of Y completed" on the venture detail view (FR-018)
- Drop `source` from `Step::$fillable` + patch the S-01 AI seed loop in `VenturesController::store` to forceFill source
- Two new views: `steps.create`, `steps.edit`; one rework: `ventures.show`
- ~20-line vanilla-JS toggle handler in `resources/js/app.js` with `<noscript>` form fallback
- ~9 feature tests; new "Step surface (S-02)" section in `docs/reference/contract-surfaces.md`

**Out of scope:**

- AI extension trigger (FR-009 → S-03)
- Step deadlines + imminent/overdue badges (FR-014 / FR-020 / FR-021 → S-05)
- Venture list / venture delete UI (FR-005 / FR-007 → S-04)
- Expenses (FR-015 / FR-016 / FR-017 / FR-019 → S-06)
- Drag-and-drop reorder (PRD doesn't require it; v2)
- Bulk actions ("clear completed", multi-select) (not in PRD)
- Richer step states (in-progress / blocked) (PRD FR-013 binary; v2)
- Modal-based add/edit (the planning conversation reverted this in favour of separate pages)
- New authorization layer (Policies) (v3+ co-editing per `docs/reference/contract-surfaces.md`)
- Progress on the venture list (the list itself is S-04; cross-venture roll-up is a PRD non-goal)

## Architecture / Approach

```
ventures.show (rework)
  ├── progress text (computed in VenturesController::show)
  ├── empty-state CTA → steps.create
  └── per-step row:
        ├── toggle form (POST /...completion) ─── JS fetch upgrade ─── DOM update
        ├── Edit link → steps.edit page → PATCH /steps/{s} → redirect to show
        └── Delete form (DELETE /...) — guarded by native confirm()

StepsController (six actions, all chained through $request->user()->ventures())
  ├── create / store  (CreateStepRequest body-only, source=Manual via forceFill, position=max+1)
  ├── edit / update   (EditStepRequest body-only — defense layer 1)
  ├── destroy
  └── toggleCompletion (writes via direct attribute, JSON for AJAX, redirect otherwise)

Step model: $fillable = ['body', 'position']  (defense layer 2 — source removed)
```

## Phases at a Glance

| Phase                                                                                | What it delivers                                                                                                                                                              | Key risk                                                                                                                                       |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Defensive tweak + route surface + skeleton                                        | `Step::$fillable` minus `source`; `VenturesController::store` patched in lockstep; `StepsController` stubs; six routes registered; two FormRequests                           | Forgetting to patch the S-01 AI seed loop in lockstep would silently corrupt every new venture's `source` to NULL (metric-snapshot bug)        |
| 2. Wire the five actions + rework the show view + JS island                          | All four user-visible features land; toggle feels instant; graceful-degradation tested                                                                                        | JS island's two response shapes (JSON vs redirect) — getting the `Accept`-header branching right matters for the form-fallback path             |
| 3. Essentials test matrix + cross-slice handoff                                      | ~9 feature tests; "Step surface (S-02)" added to contract-surfaces; roadmap + change.md flipped to done                                                                       | None substantial — tests follow the S-01 template; docs update is mechanical                                                                   |

**Prerequisites:** S-01 (`create-venture-with-ai-plan`, status: done). F-01 access-path discipline (rule from `context/foundation/lessons.md:14` and `docs/reference/contract-surfaces.md`). No new dependencies.

**Estimated effort:** ~2-3 evening sessions across 3 phases; ~9 new test methods, ~6 new files, ~3 edited files.

## Open Risks & Assumptions

- **Native `confirm()` dialog is the v1 second-gesture for delete.** Meets the NFR strictly; can be swapped for a Tailwind modal later without changing the route shape. If usage testing shows it's jarring, that's a Phase 2 follow-up not a Phase 1 blocker.
- **The toggle's two response shapes (JSON vs redirect) need careful `Accept`-header branching.** The JS island always sends `Accept: application/json`; the form fallback never does. Worth manually walking through the JS-disabled path during Phase 2 verification.
- **Append-only position with no DB-level uniqueness.** Concurrent adds from the same user could theoretically race to the same position, but the (venture_id, position) index is non-unique and `orderBy('position')` survives duplicates with undefined-but-stable order. Solo-user app; not a real concern for v1.
- **`StepFactory` assumption.** Phase 1 relies on Laravel factories bypassing `$fillable` via `Model::unguarded(...)`. Verified against Laravel 13.8 `Factory::makeInstance`; if a future Laravel rev changes this, the factory would need a forceFill tweak — caught immediately by the existing test suite.

## Success Criteria (Summary)

- A user can add, edit, delete, and toggle the completion of steps on their own ventures; progress text reflects the live state.
- The FR-008 primary-metric AI-pool snapshot is preserved across every edit — `source = ai_initial` after a body change, regardless of input shape.
- A second user gets 404 (not 403, never 200) on every step endpoint pointing at a foreign venture or step; guests redirect to `/login`.
