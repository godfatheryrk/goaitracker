---
change_id: testing-e2e-critical-path-creation
title: E2E foundation and critical-path venture-creation tests
status: impl_reviewed
created: 2026-06-03
updated: 2026-06-03
archived_at: null
---

## Notes

Rollout Phase 1 of `context/foundation/test-plan.md`: "E2E foundation + critical-path venture creation".

Risks covered (from §2 Risk Map):
- **#1** — AI times out / fails on venture-create, but instead of a clean amber "add steps manually" notice (venture still created, typed title + description preserved, page usable), the user gets a broken page or loses their input.
- **#2** — AI returns 7 steps, but the detail view does not render all 7 as a usable, distinct list (north-star "AI plan lands as a usable starting plan" fails at the render layer).

Test types planned: e2e + network-layer LLM mock (routing / DB / transaction stay real; mock only the external LLM HTTP call).

Risk response intent (from §2 Risk Response Guidance):
- **#1**: prove the venture row is persisted, the amber `ai_unavailable` notice is visible (by role/text), the typed title is preserved, and the page stays usable (user can add steps manually) — on the forced AI-empty-array path. NOT a 500, NOT a red error, NOT lost input. Assert "venture persisted + amber notice + title preserved", not the page title/URL.
- **#2**: prove all 7 AI-returned steps render as 7 distinct, usable list items on the detail view after submit, using an LLM mock returning a fixed 7-step payload. Assert the rendered step count — not just the redirect/URL; never use the real (nondeterministic, paid) provider.

Also stands up the e2e foundation (no runner exists yet): Playwright + `playwright.config.ts`, a `storageState` auth-setup project, and the `seed.spec.ts` exemplar. Follow the E2E ruleset in `CLAUDE.md` / `/10x-e2e`: `getByRole`/`getByLabel`/`getByText` locators, per-test isolation, wait-for-state (never `waitForTimeout`), unique ids (timestamp suffix) + cleanup, risk-named tests. The `seed.spec.ts` quality is load-bearing — the Planner and every generated test copy it.
