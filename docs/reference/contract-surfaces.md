# Contract Surfaces

> Load-bearing names and rules established by completed changes. Edits here are intentional contract changes — they ripple through every later slice that follows the rule. When a slice violates a rule, the slice changes, not the rule.

## Per-user data isolation

**Established by**: F-01 (`context/changes/minimal-auth-and-isolation/`).

**PRD anchors**: Access Control, NFR(isolation) — _"a user is never able to observe another user's ventures, steps, expenses, deadlines, or any other per-user data via any product surface."_

**Why it is load-bearing**: Wrong scoping here is a privacy incident regardless of feature correctness. Every slice that adds per-user data (S-01 onward) inherits these rules; they are the project's privacy floor.

### Rules

1. **`owner_id` foreign-key convention.** Every per-user domain table MUST carry an `owner_id` column declared as a foreign key to `users.id` with `onDelete('cascade')`. Naming is deliberate: `owner_id` means _"the user who created this row and has unrestricted access to it"_ — semantically distinct from `user_id`, which in pivot / membership contexts means _"any user with some level of access"_ (reserved for future use; see _Future evolution_). Declare the FK in the create migration, not a follow-up `alter`. Because Laravel infers the referenced table from the column prefix (`owner_id` → `owners`), the constraint MUST be written explicitly: `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`.

2. **Relationship-only access in authenticated controllers.** Authenticated controllers MUST reach per-user domain data through a relationship method on the authenticated user — today `$request->user()->ventures()`, `$request->user()->expenses()`, etc. A bare global query on a per-user model (`Venture::find(...)`, `Venture::where(...)`) is a privacy bug, even if a `where('owner_id', ...)` clause follows. The rule is **structural** ("via a method on `$request->user()`") rather than **nominal** ("via a method named `ventures()`"): the specific method name may evolve as the data model grows (e.g. an `accessibleVentures()` union of owned + shared in v3+ — see _Future evolution_), but going through the authenticated user is fixed. The audit pattern is _"did the controller go through `$request->user()->...`?"_.

3. **Ownership-scoped route-model binding.** When binding an owned model into a route parameter (`Route::get('/ventures/{venture}', ...)`), the binding MUST be ownership-scoped — either via explicit child binding (`Route::scopeBindings()` with a nested-resource shape), an explicit `Route::bind()` that filters by `$request->user()`, or by resolving the model inside the controller via the user relationship and rejecting a miss with 404. A global `findOrFail` on the route parameter leaks existence of other users' rows (404 vs 403 distinguishability) and is forbidden.

4. **The `auth` middleware is the authentication boundary.** Every per-user route MUST sit behind `auth` middleware. Unauthenticated requests to a gated route redirect to `login`. There is no second authentication boundary downstream — domain controllers assume `$request->user()` is non-null because the middleware guarantees it. (Public-by-design routes — e.g. the v2 read-only share-link route — are a separate access category that does not run under `auth` and does not consult `$request->user()`; those are not "per-user routes" in the sense of this rule.)

### What is NOT enforced by code yet

F-01 establishes the convention but does NOT install a global scope, trait, or runtime check that enforces it. The discipline is documentation + tests on each slice. The deferred enforcement proof lands in S-01 (the first domain model) per the checklist below.

---

## S-01 enforcement checklist

When S-01 (or any slice introducing the first / a new per-user domain model — Venture, Step, Expense) adds a model, the slice's plan MUST satisfy all of the following before it can merge:

- [ ] **Schema**: the new table's create migration declares `foreignId('owner_id')->constrained('users')->cascadeOnDelete()` — explicit `'users'` because the column→table inference would otherwise look up `owners`.
- [ ] **Model relationships**: `User` declares `hasMany(Venture::class, 'owner_id')` named to match the domain (`ventures()`, `expenses()`, …), and the new model declares the inverse `belongsTo(User::class, 'owner_id')` (typically named `owner()`). The explicit FK name is required because the column is `owner_id`, not the `user_id` Laravel would assume by default.
- [ ] **Controller access path**: every authenticated controller action that reads/writes the new model goes through `$request->user()->ventures()` (or equivalent). No bare `Venture::find(...)` / `Venture::where(...)` in authenticated controllers.
- [ ] **Two-user isolation feature test**: a feature test creates two users (A and B), creates a venture as A, and asserts user B cannot read, update, or delete it — expecting **404** from the relationship-scoped path (not 403, which would leak existence). The test also asserts a guest gets a 302 to `/login` (re-confirms the `auth` boundary).
- [ ] **Ownership-scoped route-model binding**: routes that take the new model as a parameter resolve it through the user relationship, not via a global `findOrFail`. The two-user test exercises this surface.
- [ ] **Plan reference**: the slice's plan links to this section and notes each item explicitly in its Progress block.

If any item is missing when S-01 reaches `/10x-plan-review`, the reviewer MUST block on it. The cost of catching a cross-user leak in F-01's downstream slice is cheap; the cost of catching it in production after multiple ventures and expenses have flowed through the wrong path is not.

---

## Authentication surface (F-01)

**Established by**: F-01.

- **Routes**: `register` (GET/POST), `login` (GET/POST), `logout` (POST), `dashboard` (GET). Named exactly. The `login` route name is referenced by the `auth` middleware redirect, the `LoginRequest` throttle response, and downstream slice tests — do not rename without a coordinated update.
- **Login throttle**: 5 failed attempts per `Str::lower(email)|ip` key, 60s decay; success clears the limiter. Mirrors Laravel Breeze's `LoginRequest` contract.
- **Session security**: regenerate on login, invalidate + regenerate token on logout.
- **Derived `name`**: registration derives `users.name` from the email local-part (portion before `@`). The `users.name` column remains NOT NULL; no UI collects it.
- **Out of scope (intentionally)**: password reset, email verification, password confirmation, profile management, OAuth/magic-link/SSO. The `password_reset_tokens` table exists but is unused.

---

## AI suggestion surface (F-02)

**Established by**: F-02 (`context/changes/ai-suggestion-service/`).

**PRD anchors**: FR-008, FR-009, NFR(ai-ceiling), NFR(ai-graceful).

### Public seam

```php
app(\App\Services\AiStepSuggester::class)
    ->suggestSteps(User $user, string $title, string $description, array $currentSteps = []): array
```

Resolve via the container (singleton-bound in `AppServiceProvider`). Callers do not instantiate directly.

### Return contract

- **Success**: exactly 7 `string` elements, each 1–200 characters. The array is ordered as the model returned it; callers MAY reorder for display.
- **Any failure**: empty array `[]`. Failure modes include: network timeout, HTTP 4xx/5xx from the provider, malformed/non-JSON response, wrong step count, over-quota, and any other `\Throwable`. The empty-array contract is unconditional — provider exceptions never cross this seam.
- Callers MUST treat `[]` as "AI unavailable, proceed manually." They MUST NOT surface the failure as an error to the end user (NFR(ai-graceful)).

### Rate-limit counter table

Table: `ai_call_counters(id, owner_id, day, count, created_at, updated_at)`

- `owner_id` FK → `users.id` cascade-on-delete (F-01 convention).
- Unique index on `(owner_id, day)`.
- Counter is incremented **BEFORE** the provider call (attempt-based). A call that fails still costs one unit toward the ceiling — this prevents a misconfigured key from issuing unlimited requests.
- **Ceiling**: `config('ai.step_suggestion.ceiling_per_day')` (default 20) calls per user per UTC calendar day.
- When the ceiling is reached, `suggestSteps()` returns `[]` immediately without dispatching the agent. The counter is NOT incremented further for ceiling-blocked calls.

### Sync-execution constraint

The AI call runs synchronously on the web request thread. Render's free tier has no Background Worker, so this is a deployment property, not a choice. The NFR(edit-latency) "≤1s perceived feedback" does NOT apply to the AI path — the 15-second HTTP timeout is the hard cap. Future v2 background-worker migration hides behind the same `suggestSteps()` seam without a signature change.

### Provider configuration

Provider and model are env-driven:
- `AI_PROVIDER` (default `groq`) → `config('ai.default')`
- `AI_API_KEY` → `config('ai.providers.<provider>.key')`
- `AI_MODEL` (default `llama-3.3-70b-versatile`) → `config('ai.step_suggestion.model')`

Swapping providers is an env-only change. Note: the provider must support plain JSON-text generation — the service does NOT use structured-output (`json_schema`) response format because not all provider models support it.

---

## Future evolution

> Both expansions below are PRD-contemplated in the Non-Goals _"forward-compatibility note: if added in v2+, the per-user-isolation NFR must continue to hold"_ but are NOT in the v1 roadmap. These notes exist so the F-01 contract does not foreclose either path — and so S-01 does not pick a structure (e.g. a `unique` constraint that assumes one venture per user) that would block them.

### v2: read-only public sharing (link / token)

A user shares a venture with a non-user (or another user) by generating a short-lived token. **Structurally orthogonal to the four rules above** — the owner remains the sole writer; no membership table is needed.

- New table: `shares(venture_id, token, expires_at, ...)` with `foreignId('venture_id')->constrained()->cascadeOnDelete()` (so deleting the venture cleans up its tokens).
- New route: `/share/{token}` mounted OUTSIDE the `auth` middleware. The controller resolves the share by token, loads the venture in **read-only** mode, and renders a stripped-down view. There is no `$request->user()` and the relationship-only rule does not apply here (the access path is token-scoped, not user-scoped — rule 4's parenthetical names this exemption).
- The two-user isolation test (S-01 checklist item 4) **continues to pass unchanged**: non-share holders still get 404 from the authenticated path; share-token holders get 200 from the public path.
- **No change to the four rules above is required for v2.**

### v3+: co-editing (multi-user write access)

Additional users can edit a venture — add steps, mark completion, etc. This is where the membership pivot actually lands.

- New pivot table: `venture_user(venture_id, user_id, role)` with `role` enumerating something like `viewer` / `editor`. Note the deliberate column naming — `user_id` on the pivot means _"any user with some level of access"_ (per rule 1's reservation), while `ventures.owner_id` retains its meaning as the canonical owner.
- Canonical access path shifts from `$request->user()->ventures()` to `$request->user()->accessibleVentures()` — a union of owned + pivot-member. Rule 2's structural form ("via a method on the authenticated user") survives unchanged; the specific method name evolves.
- Authorization graduates to Laravel's Policy primitive (`VenturePolicy@view`, `@update`, `@delete`) — the policy body becomes `owner_id === user->id OR pivot-member-with-required-role`. Controllers call `$this->authorize('update', $venture)` and stop caring whether access came via ownership or membership.
- **`owner_id` stays.** It does not collapse into the pivot. The owner has special status (cannot lose their own access; deletion cascades; UI labels them as such), and keeping `owner_id` as a first-class column on `ventures` is cheaper than reconstructing "who owns this" from the pivot on every query.
- The S-01 isolation test grows: a third assertion that a non-member of B's venture (and not a share-token holder) still gets 404.

### What this means for F-01 today

Nothing additional. The four rules and the S-01 checklist are written to survive both expansions unchanged in form; only the specific method name in rule 2 is expected to evolve, which is why rule 2 is stated structurally rather than nominally.
