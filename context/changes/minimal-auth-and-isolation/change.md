---
change_id: minimal-auth-and-isolation
title: Minimal auth and per-user isolation
status: plan_reviewed
created: 2026-05-27
updated: 2026-05-27
archived_at: null
---

## Notes

Seeded from roadmap F-01 (`context/foundation/roadmap.md`).

- **Outcome:** email+password register / login / logout in place, sessions issued, and every domain query scoped to the owning user.
- **PRD refs:** FR-001, FR-002, FR-003, Access Control, NFR(isolation), NFR(pw-hash).
- **Unlocks:** S-01 and every venture / step / expense slice (all data is per-user).
- **Prerequisites:** none. Sequenced first so every later slice inherits correct scoping rather than retrofitting it.
- **Risk:** Per-user isolation is the load-bearing privacy guardrail — wrong scoping here is a privacy incident regardless of feature correctness.
- **Baseline note:** Auth is absent today — no starter kit (Breeze/Jetstream/Fortify), only the framework `User` model. Domain tables (venture/step/expense) are built inside their owning slices, not here.
