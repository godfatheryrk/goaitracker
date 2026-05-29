# S-03 / extend-plan-with-ai — Plan Brief

> Full plan: `context/changes/extend-plan-with-ai/plan.md`

## What & Why

FR-009: let the user trigger AI to extend the step plan on demand from the venture detail view. The 7 candidate steps come back as a server-rendered preview where the user picks which ones to keep with a checkbox per row; only the chosen rows persist, tagged `source = ai_extension` so they stay off the FR-008 "3 of 7 initial AI steps kept" primary metric. The slice closes the on-demand-AI loop F-02 was built for and the S-01 Socrates resolution called out ("Extension covers headroom for larger ventures").

## Starting Point

F-02 already exposes the public seam — `AiStepSuggester::suggestSteps(User, $title, $description, array $currentSteps = [])` accepts the existing step bodies, passes them into `StepSuggestionAgent` which appends an "Existing steps:" block to the prompt and asks the model for 7 new non-overlapping steps. Same return contract: 7 strings on success, `[]` on any failure (timeout / 4xx / 5xx / over-quota). S-01 has the precedent for the AI-outside-transaction + amber `ai_unavailable` flash pattern; S-02 has the nested `steps.*` route surface, the doubly-scoped `$request->user()->ventures()->...` access path, and the source-immutability hardening (`source` removed from `Step::$fillable`). `StepSource::AiExtension` is already declared and cast.

## Desired End State

On the venture detail view, a "Suggest more with AI" button sits next to "+ Add step" (and as a secondary CTA on the empty state). Clicking it POSTs to the new suggest endpoint, calls the AI suggester with the current step bodies, and either renders a preview page with the 7 candidates as checkbox rows (default checked) OR — on AI failure — redirects back with the amber `ai_unavailable` notice ("AI couldn't suggest more steps right now — try again later or add steps manually"). On preview, the user can keep all, keep a subset, keep none, or cancel; submitting the form persists only the chosen rows at `max(position) + 1 .. + N` with `source = StepSource::AiExtension` via `forceFill` inside a transaction. Cross-user posts to either endpoint return 404 before the AI is dispatched — user B can never burn user A's 24h ceiling.

## Key Decisions Made

| Decision                                                                                       | Choice                                                                                                                                            | Why (1 sentence)                                                                                                                                                                                                       | Source |
| ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Where the trigger sits on the venture detail view                                              | Secondary button next to "+ Add step" (plus an empty-state CTA twin)                                                                              | Mirrors the manual-add affordance the user already knows; minimal diff to `ventures.show`.                                                                                                                             | Plan   |
| What the user sees between the AI call and persistence                                         | A server-rendered preview page with 7 checkbox rows (default checked), no inline body editing, hidden fields carry the candidate bodies          | Preview-then-confirm gives the user agency over which suggestions land; user explicitly asked to "decide which steps to store before persisting them".                                                                 | Plan   |
| How the failure path surfaces                                                                  | Reuse S-01's amber `ai_unavailable` flash, extension-specific wording                                                                             | One visual pattern for both AI surfaces; matches NFR(ai-graceful); the over-ceiling and provider-failure paths share the same `[]` return contract from `AiStepSuggester` so distinguishing them at the seam is moot. | Plan   |
| Persistence atomicity on the confirm endpoint                                                  | All-or-nothing via `DB::transaction(...)` around the per-chosen-row loop                                                                          | Matches the S-01 venture-creation precedent; partial-persistence after the user already chose what to keep is a real bug, not a product question; resolves Q3 by-design via the user-choice step.                     | Plan   |
| Counter-charging on cancel                                                                     | AI call already counted (counter increments BEFORE dispatch per F-02); Cancel does NOT roll it back                                              | Matches the F-02 contract; rollback-on-cancel would let a flapping client thrash the counter; the per-day ceiling absorbs the cost.                                                                                    | Plan   |
| Source tagging on persisted suggestions                                                        | `forceFill(['source' => StepSource::AiExtension])` per row; `source` stays out of `$fillable`                                                     | Defense in depth alongside the FormRequest whitelist — a tampered form payload carrying `source: ai_initial` cannot pollute the FR-008 metric pool.                                                                    | Plan   |
| Test matrix scope                                                                              | Phase 2 ships 8 tests: 4 on suggest (happy/AI-unavailable/two-user-404-with-suggester-never-called/guest-redirect) + 4 on confirm (keep-all/subset/none/two-user-404) | Q4 selected happy-path only; AI-unavailable + two-user-404 are non-negotiable per the Q2 flash decision and the F-01 S-01 enforcement checklist respectively (these are project-rule requirements, not S-03 choices).  | Plan   |

## Scope

**In scope:**
- Two new POST routes on the existing `steps.*` nested surface: `steps.suggestions.preview` (renders preview), `steps.suggestions.store` (persists chosen).
- Two new `StepsController` actions wired to those routes.
- One new FormRequest (`SuggestStepsRequest`) validating the array-shaped confirm payload (`max:7` rows, `max:200` per body).
- One new view (`steps/suggestions/preview.blade.php`) — server-rendered, no JS island.
- A "Suggest more with AI" button on `ventures.show` (two surface positions: bottom action bar + empty-state CTA).
- 8 feature tests, "Step extension surface (S-03)" section in `docs/reference/contract-surfaces.md`, roadmap + `change.md` flips.

**Out of scope:**
- AI regenerate / re-roll on the preview page (PRD §Non-Goals: "No 'regenerate AI suggestion' action").
- Inline body editing on the preview (v2 UX concern; user can keep then edit via S-02's existing edit endpoint).
- Soft-cap warning at high step counts (deferred to v2 per FR-009 Socrates resolution).
- Service / agent / enum / migration changes (F-02 + S-01 + S-02 already shipped everything S-03 consumes).
- A counter-status surface ("you have 3 AI calls left today"); v2 if usage justifies.
- New auth layer; ownership stays enforced by the doubly-scoped relationship chain.

## Architecture / Approach

```
ventures.show
   │
   │ click "Suggest more with AI" (POST)
   ▼
StepsController::suggest(Request, int $venture)
   ├─ resolve venture via $request->user()->ventures()->findOrFail($venture)  ← 404 BEFORE AI on user-B path
   ├─ collect current step bodies via $ventureModel->steps()->pluck('body')->toArray()
   ├─ call AiStepSuggester::suggestSteps($user, $title, $description, $currentSteps)
   │    ├─ over-ceiling   → []
   │    ├─ provider fail  → []   (counter still incremented per F-02 increment-before-dispatch)
   │    └─ success        → 7 strings
   ├─ on []           → session()->flash('ai_unavailable', '…') + redirect to ventures.show
   └─ on success      → view('steps.suggestions.preview', [venture, suggestions])

steps/suggestions/preview.blade.php
   │ 7 rows with checkbox + hidden body field; default-checked
   │ Cancel ↦ ventures.show
   │ Submit ↦ POST steps.suggestions.store
   ▼
StepsController::storeSuggestions(SuggestStepsRequest, int $venture)
   ├─ resolve venture via $request->user()->ventures()->findOrFail($venture)
   ├─ filter $rows by !empty($row['keep'])
   ├─ compute $nextPosition = ($ventureModel->steps()->max('position') ?? -1) + 1
   └─ DB::transaction(fn () => foreach chosen row: $ventureModel->steps()
            ->make([body, position=$nextPosition])
            ->forceFill([owner_id, source = StepSource::AiExtension])
            ->save(); $nextPosition++)
   redirect to ventures.show
```

## Phases at a Glance

| Phase                                       | What it delivers                                                                                  | Key risk                                                                                                                                                          |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Backend + view + button                  | Two routes, two controller actions, FormRequest, preview view, show-view button; manual walkthrough | Forgetting the `forceFill(['source' => StepSource::AiExtension])` would silently break the FR-009 metric carve-out (steps would persist with `source = null`).    |
| 2. Test matrix + cross-slice handoff        | 8 feature tests, `contract-surfaces.md` section, roadmap + `change.md` flips                       | The suggester-not-called assertion under the two-user-404 test is what closes the "B can't burn A's ceiling" loop; a test that doesn't assert this is incomplete. |

**Prerequisites:** F-01 (auth + isolation, shipped), F-02 (AI service + counter, shipped), S-01 (venture surface, shipped), S-02 (step surface, shipped). Run `composer run dev`; have `AI_API_KEY` populated locally (or mock the suggester for purely-local manual testing).

**Estimated effort:** ~1 session per phase, ~2 sessions total. Smaller than S-02 because there's no model change, no JS island, and the AI service is already in place.

## Open Risks & Assumptions

- **The "Existing steps" prompt block** in `StepSuggestionAgent::instructions()` is currently unbounded. A venture with dozens of steps will produce a longer prompt and risk model output quality dropping (the prompt asks for "7 NEW non-overlapping steps" — the more context, the more chance the model paraphrases). Not blocking for v1; if a user reports degraded quality after multiple extensions, the prompt can later cap to the latest N steps without a contract change.
- **Refresh on the preview-page POST result** will trigger the browser's "resubmit form?" prompt, which on confirm re-fires the AI call (and counts another ceiling unit). Documented in the plan as "annoying but bounded by the per-day ceiling; not a correctness bug." A POST-redirect-GET pattern would fix this but requires session-storing the suggestions, which has its own lifecycle quirks; the hidden-field round-trip is simpler.
- **Hidden-field tampering** is bounded by the FormRequest validation (`max:7` rows, `max:200` per body) and the controller's forceFill of `source`. A user editing a hidden body before submit gets their tampered text persisted as `ai_extension` — which is fine, because (a) data is their own, (b) length cap is enforced, (c) the metric pool tag is still correct.
- **Counter-charging on Cancel** assumes the user understands "clicking Suggest More costs one AI call against your daily limit even if you cancel." The preview page does not warn the user of this; if usage shows confusion, a small note can be added to the preview page in a v2 follow-up without a contract change.

## Success Criteria (Summary)

- A user on a venture clicks "Suggest more with AI", picks any subset (or all) of the 7 candidates, submits, and sees those rows appended to the step list with `source = ai_extension`, owner = themselves, contiguous positions at the end.
- A second user posting to either of the new endpoints with the first user's venture parameter gets 404, the AI suggester is not called, and the second user's daily counter is not incremented.
- On AI failure or over-ceiling, the user sees the same amber `ai_unavailable` notice S-01 uses on venture creation — the user is never shown a 5xx page or a raw error.
