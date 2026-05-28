# Minimal Auth and Per-User Isolation — Plan Brief

> Full plan: `context/changes/minimal-auth-and-isolation/plan.md`

## What & Why

Add email+password register / login / logout to the bare Laravel 13.8 app and
establish the per-user data-isolation convention every later slice inherits. This
is roadmap **F-01**, the load-bearing privacy foundation — wrong scoping here is a
privacy incident regardless of feature correctness, so it ships first and the
convention is written down for downstream slices to follow.

## Starting Point

A fresh Laravel 13.8 install with no auth scaffolding (no Breeze/Jetstream/Fortify).
Password hashing is already on (`User` casts `password => hashed`), the `web`
session guard and `sessions` table are pre-wired, and `routes/web.php` serves only
the welcome page. No domain tables exist yet.

## Desired End State

A visitor can register with email+password, sign in, and sign out. Authenticated
users land on a minimal `/dashboard`; guests hitting gated routes are redirected to
`/login`. The auth surface is exactly the three FRs (no reset / verification /
profile). Login is brute-force throttled. The isolation convention lives in
`docs/reference/contract-surfaces.md`, `context/foundation/lessons.md`, and AGENTS.md,
with an S-01 checklist that forces the first domain model to land with owner-scoping
proven by a two-user test.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Auth delivery | Hand-rolled minimal Blade | Thin controllers + FormRequests + views mapping 1:1 to the 3 FRs; no starter-kit dependency or scaffold churn | Plan |
| Isolation enforcement | Relationship-only convention (documented) | Reach domain data via `$user->ventures()`; no global-scope magic | Plan |
| Auth scope | register/login/logout only | Truly minimal foundation; no v1 FR needs recovery/verification | Plan |
| `name` field | Derive from email local-part | Satisfies the not-null column with no migration change and no extra form field | Plan |
| Post-login landing | Minimal `/dashboard` placeholder | Concrete gated route that proves the auth/guest boundary, replaceable by S-01/S-04 | Plan |
| Throttling | Hand-rolled login throttle via `RateLimiter` | Free brute-force mitigation; AI-budget ceiling is F-02's job | Plan |
| Testing | Flows + negatives + guest-boundary | Lock the auth behavior and the boundary with executable proof | Plan |
| Isolation proof timing | Docs now, query-scoping test in S-01 | No domain model exists to scope; F-01 proves only the auth boundary | Plan |

## Scope

**In scope:** email+password registration (derived name), login, logout, login
throttle, gated `/dashboard` with guest redirect, the isolation convention as durable
artifacts + S-01 checklist, feature tests for flows/negatives/boundary.

**Out of scope:** domain tables (ventures/steps/expenses), password reset, email
verification, password confirmation, profile management, owner-scoped-query test
(→ S-01), AI/rate-budget ceiling (→ F-02), OAuth/magic-link/SSO, starter kit /
Livewire / Inertia / JS framework, global-scope isolation primitive.

## Architecture / Approach

Hand-roll thin controllers + FormRequests + Blade views on the existing Vite +
Tailwind pipeline, leaning on framework primitives — the `Auth` facade for the
session guard, the `password => hashed` cast for hashing, `RateLimiter` for the login
throttle, and `session()->regenerate()` for fixation defense. Build the
login/logout + protected dashboard vertical first (browser-verifiable with a
`tinker`-seeded user, no forward dependency on a register route), then registration,
then the test suite. Codify the discipline-based isolation convention in
agent-readable docs. No new migrations — existing users/sessions tables are reused.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Login + logout + dashboard | Throttled session auth + protected `/dashboard` with guest redirect | Throttle / session-fixation correctness; Phase-1 manual check needs a tinker-seeded user |
| 2. Registration | Email+password register with derived name + auto-login | Derived-name collisions; validation edge cases |
| 3. Auth tests | Flows + negatives + throttle + boundary coverage | Brittle throttle-test timing |
| 4. Isolation contract | contract-surfaces.md + lessons.md + AGENTS.md rule + S-01 checklist | Convention too vague to be enforceable downstream |

**Prerequisites:** none (F-01 has no upstream slices).
**Estimated effort:** ~1-2 sessions across 4 small phases.

## Open Risks & Assumptions

- **Hand-rolled throttle must be correct.** The login lockout is implemented by hand
  via `RateLimiter` (key = `lower(email)|ip`, ~5 attempts, clear on success) rather
  than inherited from a kit — get the key and the reset-on-success right.
- **Login-first / registration-second ordering.** Phase 1 builds login before
  registration, so its manual verification seeds a user via `php artisan tinker`;
  this avoids a half-wired register form but is a slightly unconventional build order.
- **Discipline-based isolation.** With convention-only enforcement, the guardrail's
  strength depends on the docs being load-bearing and the S-01 checklist being honored;
  the owner-scoping proof is deferred to S-01.
- **Derived name** may collide / read oddly (e.g. two `jane@` local-parts); acceptable
  for MVP since `name` is display-only and `email` is the unique key.

## Success Criteria (Summary)

- A user can register (email+password), sign in, sign out; guests are redirected from
  `/dashboard` to `/login`; the auth surface is exactly the three FRs.
- `composer run test` green (flows + negatives + throttle + boundary),
  `vendor/bin/pint --test` clean, `route:list` free of password/verification/profile routes.
- The isolation convention exists in `contract-surfaces.md` + `lessons.md` + AGENTS.md
  with a concrete S-01 enforcement checklist.
