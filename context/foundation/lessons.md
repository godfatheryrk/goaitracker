# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Include roadmap slice ID in commit subject

- **Context**: Committing phase of a plan implementation.
- **Problem**: Without the roadmap slice ID, commit subjects only reference the change-id, leaving no shortcut back to the roadmap feature/slice the commit advances.
- **Rule**: In commit subjects, include the roadmap feature/slice ID alongside the change-id — e.g. `feat(F-01/minimal-auth-and-isolation): ...` instead of `feat(minimal-auth-and-isolation): ...`.
- **Applies to**: implement

## Per-user isolation: relationship-only access in controllers

- **Context**: Any slice adding a domain model that carries per-user data (ventures, steps, expenses, deadlines, anything user-owned).
- **Problem**: A raw global query on a per-user model — `Venture::find($id)`, `Venture::where(...)`, route-model binding via `findOrFail` on a bare model — is a privacy bug. Even when followed by `->where('owner_id', $request->user()->id)` it leaks existence (404 vs 403) and gives a future refactor a path to drop the scoping clause without any compile or test signal. The PRD's NFR(isolation) is load-bearing: a leak here is a privacy incident regardless of feature correctness.
- **Rule**: In authenticated controllers, reach per-user domain data ONLY through a relationship method on the authenticated user (today: `$request->user()->ventures()`, `$request->user()->expenses()->find($id)`). The rule is **structural** ("via a method on `$request->user()`") not **nominal** — the specific method name may evolve (e.g. `accessibleVentures()` if shared-write access is added in v3+, per `docs/reference/contract-surfaces.md#v3-co-editing-multi-user-write-access`), but going through the authenticated user is fixed. Route-model binding for owned resources MUST be ownership-scoped. Every per-user table carries an `owner_id` foreign key with cascade-on-delete — declared explicitly as `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` because Laravel's column→table inference would otherwise look up `owners`. `owner_id` deliberately differs from `user_id`: it means _"the user who created and unrestricted-owns this row"_, while `user_id` is reserved for membership-pivot semantics (_"any user with some level of access"_). The `auth` middleware is the authentication boundary — every gated route sits behind it; public-by-design routes (e.g. a future read-only share-link route) are a separate access category and do not consult `$request->user()`.
- **Why**: Privacy-incident risk. The convention is also the simplest "lint" — code review and the agent both check "did you go through `$request->user()->...`?", a structural rule that survives refactors and survives the v2 / v3 sharing evolutions in unchanged form.
- **How to apply**: (a) declare the FK as `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`; (b) declare `User::hasMany(Venture::class, 'owner_id')` and the inverse `belongsTo(User::class, 'owner_id')` (explicit FK name because the column is `owner_id`, not the `user_id` Laravel assumes by default); (c) controllers reach the model through the user relationship, never via a bare global query; (d) write a two-user feature test asserting user B gets 404 (not 403) on user A's resource — see `docs/reference/contract-surfaces.md#s-01-enforcement-checklist` for the full checklist.
- **Applies to**: plan, plan-review, implement, impl-review
