---
project: GOAITracker
version: 1
status: draft
created: 2026-05-24
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: 2026-07-04
  after_hours_only: true
---

# GOAITracker — Product Requirements Document

## Vision & Problem Statement

A person planning a personal venture (a goal or one-off project they own — learning a skill, throwing a party, renovating a room, shipping a side project) is blocked at the start by a blank page: they don't know what steps to take, so the venture often stalls before it begins. This is the primary pain. Two related pains compound it: once they push past the start, their data scatters (steps live in one place, expenses in a spreadsheet with manual summing, no unified view of "where am I and what has it cost me"), and any deadlines they've committed to drift unnoticed because no single tool nags them. The status quo — spreadsheets, notes docs, or team-oriented project tools repurposed for solo use — leaves all three pains untouched in some combination.

GOAITracker's bet is that an AI that proposes the initial step list from a short venture description is the cheapest lever against the primary pain: it converts the blank page into a starting plan the user can edit, lowering the activation cost from "design a plan" to "edit a plan". The unified per-venture view (steps, progress, expenses, deadline reminders in one surface) is what keeps the user coming back once the venture is underway. Status-quo tools are empty containers — they assume the user already has the plan. GOAITracker generates the plan, lets the user shape it, keeps plan and cost in one surface, and reminds the user when deadlines approach.

## User & Persona

Primary persona: the founder, pursuing personal ventures of their own (not team projects). They want a step plan, a per-venture cost ledger, and timely reminders all in one place. Solo use; data is per-user; no shared workspaces. The founder reaches for the product at the start of a venture (when they need help breaking the blank page into a plan) and returns to it mid-flight (to mark progress, log expenses, and check what's coming due).

### Secondary persona

Regular people planning a personal venture or one-off project — party planners, hobbyist renovators, anyone with a goal that has steps and out-of-pocket costs. They share the pain but the MVP is built and validated against the primary persona first.

## Success Criteria

### Primary

- At least 3 of the initial 7 AI-suggested steps are still present in the saved venture plan, in any state — kept verbatim OR edited. Measured against the INITIAL AI pool only (the suggestions returned at venture creation), regardless of (a) how many manual steps the user added on top or (b) how many steps the AI added via the extend-on-demand action. Steps are unordered, so position is not part of the metric. Absolute count (3) is preferred over a ratio because the cap is fixed.

### Secondary

- Users return to a venture at least once after creating it. Weak proxy for "the product became part of the user's workflow" rather than a one-off blank-page reliever.

### Guardrails

- A user can NEVER see another user's ventures, steps, expenses, or deadlines. Per-user data isolation is load-bearing; a bug here is a privacy incident even if everything else works.
- AI failures stay invisible to the user as failures. If the AI suggestion is unavailable for any reason, the user can still create the venture and add steps manually — the AI is an enhancement, not a hard dependency.
- Editing a venture, step, or expense feels instant (≤ ~1s perceived latency on the editing actions). Manual editing is the core loop after AI runs; slowness breaks the workflow-friction promise.
- No accidental data loss on destructive actions. Venture, step, and expense removal need a second explicit gesture from the user. Personal-venture data is hard to recover — a misclick that drops a 12-step plan is a product failure.
- Deadlines are OPTIONAL on every step. The user must never be forced to set a deadline; steps without deadlines are first-class. Reminders only fire for steps that have a deadline set.

## User Stories

### US-01: User creates their first venture end-to-end

- **Given** a registered user has signed in
- **When** they create a new venture by entering a title and a short description
- **Then** the app responds with an AI-suggested list of exactly 7 steps that the user can keep, edit, add to, or remove, with optional deadlines on each step

#### Acceptance Criteria

- AI returns exactly 7 steps for a single venture
- AI failure (timeout, provider error) does NOT block venture creation — the user gets an empty step list and a non-blocking notice, and can continue manually
- After AI returns, the user can add / edit / delete steps and toggle completion (directly on the step list, no confirmation) without further constraint
- Setting a deadline on a step is optional — the user can leave it blank and the step has no deadline
- The user can optionally trigger AI to extend the step list one or more times after creation; extension-added steps do not count toward the primary-metric AI pool
- All data created in this flow is visible only to the user who created it
- Steps with deadlines within 3 days or past their deadline show a visible badge on the venture detail view; the corresponding venture is marked on the venture list

## Functional Requirements

### Authentication

- FR-001: User can register an account with email + password. Priority: must-have
  > Socrates: Counter-arguments considered (email/password infrastructure is heavier than it looks; open registration + AI calls is an abuse vector; adds GDPR-shaped privacy surface). Resolution: stands as written — seed explicitly calls for multi-user accounts; abuse mitigation captured separately as NFR (registration cannot be exploited to burn AI budget).

- FR-002: User can sign in with email + password. Priority: must-have
  > Socrates: Counter-arguments considered (password recall is #1 abandonment reason; magic-link could be lighter; session-length tuning matters for solo apps). Resolution: stands as written — standard sign-in pattern; password recovery is implicit in FR-001 + FR-002 and handled in downstream design.

- FR-003: User can sign out. Priority: must-have
  > Socrates: Counter-arguments considered (rarely used on single-device personal apps; load-bearing for shared-device privacy). Resolution: stands as written — privacy guardrail is load-bearing; sign-out is cheap.

### Ventures

- FR-004: User can create a venture by entering a title and a short description. Priority: must-have
  > Socrates: Counter-argument accepted — short description is too vague; AI suggestion quality depends on input richness. Resolution: FR stands as written; UX commitment added — the create-venture surface will display a hint that better descriptions yield better AI suggestions, nudging users to be richer without imposing hard input constraints.

- FR-005: User can view a list of all their own ventures. Priority: must-have
  > Socrates: Counter-arguments considered (flat list won't scale past ~10; active vs completed should be separated; dashboard better than list). Resolution: stands as written — flat list is v1; filter/sort/dashboard are v2 once usage volume justifies them.

- FR-006: User can open a single venture and see its details (description, steps, expenses, progress, total cost, deadline status). Priority: must-have
  > Socrates: Counter-arguments considered (single-screen overload risk especially mobile; description suggests editable; conflates planning + tracking). Resolution: stands as written — the unified view IS the core insight; UX layout decisions (tabs, progressive disclosure) are downstream design.

- FR-007: User can delete a venture (with confirmation). Priority: must-have
  > Socrates: Counter-arguments considered (hard delete loses work; users want 'archive' not 'delete'; confirmation overkill vs undo). Resolution: stands as written — confirmation covers the guardrail; archive semantics are v2.

### Steps (AI + manual)

- FR-008: User can receive an AI-suggested step list of EXACTLY 7 steps when creating a venture. Priority: must-have
  > Socrates: REVISED in place — cap is now EXACTLY 7 (was 'up to 7'). Counter-arguments considered (cap is arbitrary, some ventures need 15+; cap pressures AI toward vague suggestions; one-shot = bad-first-suggestion-forever, no regenerate). Resolution: AI is contracted to always produce 7 — this rejects the implicit reading that AI could underdeliver. Extension (FR-009) covers headroom for larger ventures. Primary-metric denominator is now stably 7.

- FR-009: User can trigger AI to EXTEND the step list on demand from the venture detail view; extension-added steps are tracked as steps but do NOT count toward the primary-metric AI pool. Priority: must-have
  > Socrates: Counter-arguments considered (cost trap without rate limit; metric carve-out is invisible-to-user and confusing; risks unbounded step inflation). Resolution: stands as written — frozen-pool metric handling is clean; cost concerns can be solved by a per-day rate limit downstream; total-step soft warning is a v2 UX nice-to-have.

- FR-010: User can manually add a new step to a venture. Priority: must-have
  > Socrates: Counter-arguments considered (manual-adds dilute primary metric signal; no upper bound risks list ballooning; inconsistent fields vs AI). Resolution: stands as written — manual editing is essential; metric handles the dilution via 'kept includes edited' rule; soft warnings at high step counts are a v2 UX concern.

- FR-011: User can edit a step's text. Priority: must-have
  > Socrates: Counter-arguments considered (editing muddies metric; delete+re-add alternative loses completion; lack of original-text revertibility). Resolution: stands as written — edit preserves completion (which delete+re-add doesn't); metric definition already covers edit-as-kept; original-text versioning is a v2 audit/UX feature.

- FR-012: User can delete a step (with confirmation). Priority: must-have
  > Socrates: Counter-arguments considered (confirmation friction overkill vs undo; deleting AI steps needs tombstone for metric; undo introduces completion/deadline edge-cases). Resolution: stands as written — confirmation is consistent with FR-007; the AI pool snapshot is captured at venture creation and remains stable across deletes (an implementation concern, not an FR-level one); undo is v2.

- FR-013: User can toggle a step's completion state (complete ↔ incomplete) directly from the step list — independent of edit mode and without requiring confirmation. Priority: must-have
  > Socrates: Counter-arguments considered (no-confirmation risks misclick reset; binary done/not-done loses signal vs in-progress/blocked/done; list-as-action mixes view + mutate). Resolution: stands as written — binary is the simplest signal supporting FR-018 (proportion); richer states are v2; list-as-action is the right pattern for personal apps where the user is the only actor.

- FR-014: User can optionally set a deadline on a step. Priority: must-have
  > Socrates: Counter-arguments considered (optional deadlines mean reminders rarely fire; per-step is wrong granularity vs venture-level; date-only is too coarse for intra-day events). Resolution: stands as written — optional + per-step is the v1 starting model; venture-level deadlines and intra-day timing are v2 enhancements once usage validates them.

### Expenses (venture-level, added later in lifecycle)

- FR-015: User can add an expense to a venture (amount + short description + date). Priority: must-have
  > Socrates: Counter-arguments considered (venture-level lumps categories; missing currency/category makes data thin; cut expenses entirely if <30% usage). Resolution: stands as written — per the scope-down, expenses are optional/lite; richer expense model is v2; currency choice is downstream.

- FR-016: User can delete an expense. Priority: must-have
  > Socrates: Counter-argument accepted — delete-only means typo fix is delete + re-type both amount and description. Resolution: REVISED — new FR-017 added for expense edit (see below); FR-016 itself stays as written (delete remains an explicit capability).

- FR-017: User can edit an expense (amount, description, date). Priority: must-have
  > Socrates: Added as direct resolution of the FR-016 challenge. No standalone counter-argument round; if usage shows expense edit is rarely used, demote to nice-to-have in a future revision.

### Progress & reminders

- FR-018: User can see the proportion of completed vs total steps on a venture. Priority: must-have
  > Socrates: Counter-arguments considered (proportion is wrong shape for small-N — each step = ~14% jump; single-venture progress is detail-page noise vs cross-venture dashboard; should weight by urgency/effort). Resolution: stands as written — concrete UI shape (bar vs N-of-M) is downstream design; cross-venture roll-up and weighted progress are v2.

- FR-019: User can see the accumulated total cost of all expenses on a venture, surfaced on BOTH the venture detail view AND the venture list. Priority: must-have
  > Socrates: REVISED in place — counter-argument accepted that total cost should be visible on the venture list, not just detail (same logic as FR-021 deadline marker). FR wording now explicitly requires both surfaces. Other counter-arguments considered (no budget comparison, no multi-currency) deferred to v2.

- FR-020: User can see a visual badge on each step whose deadline is within 3 days (imminent) or past (overdue) on the venture detail view; badge persists until the step is marked complete. Priority: must-have
  > Socrates: Counter-arguments considered (3-day window is arbitrary across venture cadences; always-on + no-snooze = badge fatigue; badge-on-step buries the signal). Resolution: stands as written — 3-day window is a v1 simplification; configurability and snooze are v2; FR-021 covers the at-a-glance need at the list level.

- FR-021: User can see on the venture list which ventures contain steps with imminent or overdue deadlines. Priority: must-have
  > Socrates: Counter-arguments considered (binary marker loses signal vs count/severity; needs sorting to be useful; should balance with progress %). Resolution: stands as written — binary marker is the v1 simplification; richer prioritisation is v2.

## Non-Functional Requirements

- A user is never able to observe another user's ventures, steps, expenses, deadlines, or any other per-user data via any product surface.
- When the AI step suggestion is unavailable for any reason, venture creation completes within the same user session and the user can add steps manually without leaving the create-venture flow.
- Editing a venture, step, or expense produces visible feedback within ~1 second of the user's confirming action.
- Destructive actions on a venture, step, or expense (delete) cannot complete from a single user gesture alone — an explicit second user action is required before the deletion takes effect.
- User-supplied passwords are never observable from operator-accessible storage in any form that can be used to authenticate as the user or reversed back to plaintext.
- A single authenticated user cannot trigger more than a bounded number of AI step-suggestion calls (initial creation + on-demand extension) within any rolling 24-hour window; the exact limit is tunable downstream but the ceiling exists from v1.

## Business Logic

Given a short description of a personal venture, the app generates an actionable 7-step plan the user can extend, edit, and track — alongside its accumulated cost and per-step deadline pressure — in one unified view.

The input is what the user types when they create a venture — a free-text title and short description of what they want to accomplish ("learn welding", "renovate the bathroom", "throw a 30-person birthday party"). Richer descriptions produce better plans; the create-venture surface signals this expectation to the user (per FR-004 UX commitment) without imposing hard input constraints.

The output is an initial ordered-by-creation but logically unordered list of exactly 7 steps that the user can keep, edit, augment with manual additions, delete, or extend by re-invoking the suggestion mechanism on the venture detail view. The initial seven define the primary-metric AI pool — the snapshot against which "kept" is measured. Extension-added steps live in the same step list but do not enter the metric pool. If the initial suggestion is unavailable, the venture is still created with an empty step list and the user proceeds manually; the rule degrades to "no AI input" rather than failing venture creation entirely.

The user encounters the plan immediately on the new-venture detail view (FR-006), where it sits alongside the running expense ledger, the live progress signal (proportion of steps completed), and per-step deadline badges (steps whose deadline is within 3 days or past). Returning to the app, the venture list (FR-005) carries cross-venture signals — accumulated cost (FR-019) and a deadline-pressure marker (FR-021) — so the user can decide which venture to open without drilling into each one. The unified view, present at both list and detail levels, is the place the product lives.

## Access Control

Multi-user web app with email + password sign-in. Each user has an account; data is per-user and not shared. Flat role model — every user has identical capabilities and can only see/edit their own ventures, steps, expenses, and deadline reminders. Unauthenticated users hitting any gated route are redirected to sign-in. The MVP excludes additional authentication methods (OAuth, magic link, SSO) — see Non-Goals.

## Non-Goals

- **No file-format exports** (CSV / PDF / spreadsheet reports of venture data). Users still have spreadsheets if they want a report.
- **No sharing of ventures with other users.** Forward-compatibility note: if added in v2+, the per-user-isolation NFR must continue to hold (a user cannot observe another user's ventures unless explicitly shared).
- **No additional authentication methods** (OAuth, magic link, SSO). Email + password only for v1.
- **No breaking steps into sub-steps.** Steps are atomic in v1.
- **No external-delivery reminders** (e.g. email or push). In-app surfacing only in v1; external delivery is v2.
- **No multi-currency support for expenses.** Single currency only in v1; cross-currency conversion is v2.
- **No per-step expense allocation.** Expenses are venture-level only in v1; tying expenses to specific steps is v2.
- **No cross-venture dashboard or progress roll-up.** Each venture is viewed individually in v1; across-ventures roll-up is v2.
- **No "regenerate AI suggestion" action.** AI runs ONCE at venture creation; FR-009 extension is append-only, not replace.
- **No AI-generated step execution hints.** AI only generates the initial plan; it does not explain HOW to perform a step.

## Open Questions

_(none — all uncertainty was resolved during the shape phase; the cross-check was accepted with no gaps. New questions surfacing during tech-stack selection or implementation should be recorded here.)_
