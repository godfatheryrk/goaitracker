# Minimal Auth and Per-User Isolation — Implementation Plan

## Overview

Add email+password **register / login / logout** to a bare Laravel 13.8 app and
establish the **per-user data-isolation convention** that every later venture /
step / expense slice will inherit — without building any domain tables. We
hand-roll thin controllers, FormRequests, and Blade views mapping 1:1 to the three
auth FRs, derive `name` from the email, gate a minimal `/dashboard` behind `auth`
middleware, throttle login with the framework rate limiter, and codify the isolation
convention as durable artifacts (`contract-surfaces.md`, `lessons.md`, an AGENTS.md
rule, and an S-01 enforcement checklist).

This is roadmap **F-01** — the load-bearing privacy foundation. Wrong scoping here
is a privacy incident regardless of feature correctness, so the convention is
sequenced first and anchored in writing for downstream slices to follow.

## Current State Analysis

- **No auth scaffolding installed.** `composer.json` has only
  `laravel/framework ^13.8` + tinker/pail/pint. No Breeze, Jetstream, Fortify,
  or `laravel/ui`. `routes/web.php:5` serves only the `welcome` view.
- **Password hashing already satisfied.** `app/Models/User.php:29` casts
  `'password' => 'hashed'`, so NFR(pw-hash) holds with zero extra work — passwords
  are one-way hashed on assignment.
- **Session issuance is pre-wired.** The `sessions` table exists
  (`database/migrations/0001_01_01_000000_create_users_table.php:30`) and
  `config/auth.php:40` defines the `web` guard as `session` / `eloquent` over
  `User`. Login only needs the controller + middleware; no auth-config change.
- **`users` table has `name` (NOT nullable), `email` (unique), `password`.** The
  PRD requires only email+password, so we keep the column and derive `name` from
  the email local-part rather than altering the framework migration.
- **No domain tables exist.** Per-user isolation in this slice is the *convention
  + the authentication boundary*, not scoped venture queries. Owner-scoped-query
  proof is deferred to S-01 with the first real model.
- **No `docs/reference/contract-surfaces.md` and no `context/foundation/lessons.md`
  yet** — both are created in Phase 4 as the durable home for the convention.
- **Frontend is Blade + Vite + Tailwind v4**, only `welcome.blade.php` and an empty
  `app.js`. We hand-roll thin Blade views on top; do NOT introduce Livewire,
  Inertia, or a JS framework.

### Key Discoveries:

- `app/Models/User.php:29` — `'password' => 'hashed'` cast → NFR(pw-hash) done.
- `config/auth.php:40-45` — `web` session guard ready; login is pure wiring.
- `...create_users_table.php:30-37` — `sessions` table present (DB session driver works).
- `routes/web.php:5` — only the welcome route exists; auth routes are net-new.

## Desired End State

A visitor can register with email+password, sign in, and sign out. Authenticated
users land on a minimal `/dashboard`; unauthenticated users hitting any gated
route are redirected to `/login`. The auth surface is exactly the three FRs — no
password reset, email verification, password confirmation, or profile pages. The
login form is brute-force throttled by the framework's rate limiter. The per-user
isolation convention is written down and load-bearing: a new
`docs/reference/contract-surfaces.md`, a `context/foundation/lessons.md` rule, an
AGENTS.md entry, and an explicit S-01 checklist that makes the first domain model
land with owner-scoping proven by test.

Verification: `composer run test` is green (auth flow, negative, throttle, and
guest-redirect feature tests pass); `vendor/bin/pint --test` is clean; `php artisan
route:list` shows only `register`/`login`/`logout` + `dashboard`; the four
convention artifacts exist and name the `user_id` FK rule, the relationship-access
rule, and the S-01 checklist.

## What We're NOT Doing

- **No domain tables** (ventures / steps / expenses) — those are built in their
  owning slices (S-01+). This slice only establishes the convention they inherit.
- **No password reset, email verification, or password confirmation** flows —
  explicitly out per the scope decision; the `password_reset_tokens` table is left
  unused.
- **No profile management** — no profile controller/views in this slice.
- **No owner-scoped-query enforcement test** — there's no domain model to scope yet;
  that proof lands in S-01. F-01 tests the *authentication boundary* only.
- **No AI / rate-budget ceiling** — NFR(ai-ceiling) is F-02's job. F-01 keeps only
  a standard login throttle.
- **No registration rate limit** — F-01 throttles *login* only; open-registration
  rate-limiting rides with F-02's per-user AI ceiling (registration is only an abuse
  vector once it can burn AI budget, and no AI is wired until F-02). Do not assume
  F-01 covers it.
- **No OAuth / magic-link / SSO** — PRD Non-Goals; email+password only.
- **No starter kit / no Livewire / Inertia / JS framework** — hand-rolled thin Blade
  on the existing Vite + Tailwind pipeline.
- **No global-scope / trait-based isolation primitive** — the chosen approach is the
  relationship-only convention, documented rather than enforced by a global scope.

## Implementation Approach

Hand-roll thin controllers + FormRequests + Blade views mapping 1:1 to the three
FRs, leaning on framework primitives — the `Auth` facade for the session guard, the
existing `password => hashed` cast for hashing, `Illuminate\Support\Facades\RateLimiter`
for the login throttle, and `$request->session()->regenerate()` for session-fixation
defense. No starter-kit dependency.

Build the **login / logout + protected dashboard** vertical first (verifiable in the
browser with a `tinker`-seeded user, with no forward dependency on a register route),
then **registration**, then lock everything with feature tests. Finally, because the
team chose the discipline-based isolation approach, compensate by writing the
convention into load-bearing, agent-readable artifacts so every later slice (and
every AI agent working the repo) inherits it explicitly.

## Critical Implementation Details

- **Hand-rolled login throttle (mirror Breeze's `LoginRequest` verbatim).** No starter
  kit supplies it, so implement throttling explicitly in the login FormRequest via
  `RateLimiter`, copying Laravel Breeze's known-good `LoginRequest` contract rather than
  re-deriving it. Pinned contract:
  - **Throttle key**: `Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip())`.
  - **Limit**: lock after **5** failed attempts (`RateLimiter::tooManyAttempts($key, 5)`),
    with a 60s decay.
  - **On failed auth**: `RateLimiter::hit($key)` (increment the counter).
  - **On successful auth**: `RateLimiter::clear($key)` (reset — without this the lockout
    persists after a valid login; this is the easiest step to forget).
  - **When locked**: fire the `Illuminate\Auth\Events\Lockout` event and throw a
    `ValidationException` whose `email` message is the `auth.throttle` translation
    carrying `seconds`/`minutes` remaining (`RateLimiter::availableIn($key)`).
- **Session security.** Regenerate the session on successful login
  (`$request->session()->regenerate()`); on logout, log out the `web` guard,
  `invalidate()` the session, and `regenerateToken()`.
- **No new migrations.** Existing `users` / `sessions` / `password_reset_tokens`
  tables are reused; do not generate or run new schema migrations in this slice.
- **Derived name.** `name` is NOT nullable, so registration sets it from the email
  local-part on create rather than collecting it or altering the migration.
- **Isolation = boundary here, scoping later.** The only isolation behavior testable
  in F-01 is "guests can't reach gated routes." Do not invent a throwaway domain
  model to test query-scoping; that proof is S-01's, per the convention checklist.

## Phase 1: Login + logout + protected dashboard

### Overview

Stand up the authenticated session vertical: shared Blade layouts, a throttled
login flow, logout, and a minimal `/dashboard` gated by `auth` with guests redirected
to `/login`. Verifiable end-to-end in the browser using a user seeded via `tinker`
(registration arrives in Phase 2), so this phase has no forward dependency.

### Changes Required:

#### 1. Shared Blade layouts + navigation

**File**: `resources/views/layouts/guest.blade.php`,
`resources/views/layouts/app.blade.php`,
`resources/views/layouts/navigation.blade.php` (or a nav partial),
`resources/css/app.css` / `resources/js/app.js` (existing, wired via `@vite`).

**Intent**: Provide a minimal guest layout (centered card for auth pages) and an
authenticated app shell with a nav that carries a sign-out control — the chrome the
auth views and dashboard render into.

**Contract**: Layouts load the existing Vite assets via `@vite`; the nav contains a
logout `<form>` POSTing to the `logout` route. No Livewire/Inertia/JS-framework
dependency.

#### 2. Login + logout controller, request, and routes

**File**: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`,
`app/Http/Requests/Auth/LoginRequest.php`, `routes/web.php`.

**Intent**: Render the login form, authenticate credentials with brute-force
throttling, regenerate the session on success, and tear the session down on logout.

**Contract**: `create` renders the login view; `store` validates via `LoginRequest`,
authenticates the `web` guard, regenerates the session, and redirects to the intended
target (default `/dashboard`); `destroy` logs out, invalidates the session, and
regenerates the CSRF token, redirecting to `/`. Routes: `GET /login` (name `login`)
and `POST /login` behind `guest` middleware; `POST /logout` (name `logout`) behind
`auth`. `LoginRequest` throttles per `Str::lower(email)|ip` (~5 attempts) and throws
a `ValidationException` with the lockout message when exceeded; clears the limiter on
success.

#### 3. Login view

**File**: `resources/views/auth/login.blade.php`.

**Intent**: A minimal Tailwind login form (email, password, optional remember) using
the guest layout, surfacing validation errors and the throttle lockout message.

**Contract**: Form POSTs to the `login` route; renders field-level validation errors
and any session status message.

#### 4. Protected dashboard + guest boundary

**File**: `routes/web.php`, `resources/views/dashboard.blade.php`
(and a `DashboardController` or route closure).

**Intent**: Add a minimal authenticated `/dashboard` that proves the protected/guest
boundary; unauthenticated access redirects to `/login`.

**Contract**: `GET /dashboard` (name `dashboard`) is behind `auth` middleware (NOT
`verified`); the view greets the signed-in user by **`auth()->user()->name`** (the
derived local-part — this is the surface Phase 2 Manual 2.6 verifies), optionally
alongside the email; a guest requesting `/dashboard` is redirected to the `login` route.

### Success Criteria:

#### Automated Verification:

- [ ] Routes registered: `php artisan route:list` shows `login` (GET/POST), `logout` (POST), `dashboard` (GET)
- [ ] Test suite green (smoke): `composer run test`
- [ ] Assets build: `npm run build` exits 0
- [ ] Formatting clean: `vendor/bin/pint --test`

#### Manual Verification:

- [ ] Seed a user via `php artisan tinker`, then log in via the browser and land on `/dashboard`
- [ ] Sign out returns to a public page; revisiting `/dashboard` redirects to `/login`
- [ ] Repeated bad logins trip the throttle (lockout message)

**Implementation Note**: After completing this phase and all automated verification
passes, pause for manual confirmation before proceeding to Phase 2.

---

## Phase 2: Registration (email+password, derived name)

### Overview

Add the registration vertical on top of the working auth shell: an email+password
form that creates the user with a `name` derived from the email, logs them in, and
redirects to `/dashboard`.

### Changes Required:

#### 1. Registration controller, request, and routes

**File**: `app/Http/Controllers/Auth/RegisteredUserController.php`,
`app/Http/Requests/Auth/RegisterRequest.php`, `routes/web.php`.

**Intent**: Render the register form and create a user from email+password, deriving
`name` from the email local-part, then auto-login and redirect to the dashboard.

**Contract**: `RegisterRequest` validates `email` (required, valid, lowercase,
`unique:users`) and `password` (required, `confirmed`, framework default strength).
`store` creates the user with `name` = the email local-part (portion before `@`),
relies on the `password => hashed` cast for hashing, `Auth::login`s the new user,
regenerates the session, and redirects to `/dashboard`. Routes: `GET /register`
(name `register`) and `POST /register` behind `guest` middleware.

#### 2. Register view + cross-links

**File**: `resources/views/auth/register.blade.php`,
`resources/views/auth/login.blade.php` (add a link to register; reciprocal link on
register → login).

**Intent**: A minimal Tailwind registration form collecting only email + password
(+ confirmation), using the guest layout, with a link between login and register.

**Contract**: Form POSTs to the `register` route; there is NO `name` input; field
validation errors render inline.

### Success Criteria:

#### Automated Verification:

- [ ] Register routes present: `php artisan route:list` shows `register` (GET/POST)
- [ ] Test suite green (smoke): `composer run test`
- [ ] Assets build: `npm run build` exits 0
- [ ] Formatting clean: `vendor/bin/pint --test`

#### Manual Verification:

- [ ] Registering with only email+password creates a user, logs in, and lands on `/dashboard`
- [ ] The derived name shows where the UI greets the user
- [ ] Registering a duplicate email shows an inline validation error

**Implementation Note**: Pause for manual confirmation before proceeding to Phase 3.

---

## Phase 3: Auth feature tests (flows + negatives + boundary)

### Overview

Lock the auth behavior with executable tests covering the happy paths, the negative
cases, the login throttle, and the guest boundary — the highest-risk area gets
coverage before any later slice builds on it.

### Changes Required:

#### 1. Registration tests

**File**: `tests/Feature/Auth/RegistrationTest.php`.

**Intent**: Assert registration behavior end-to-end including the derived name and the
negative validation cases.

**Contract**: Tests assert — register with email+password succeeds, authenticates the
user, redirects to `/dashboard`, and stores a `name` derived from the email; register
fails on duplicate email, invalid email, and too-short / unconfirmed password.

#### 2. Authentication + boundary tests

**File**: `tests/Feature/Auth/AuthenticationTest.php` (and a small dashboard/guard
test).

**Intent**: Assert login/logout behavior, the throttle, and the protected/guest
boundary.

**Contract**: Tests assert — login succeeds with valid creds and fails with bad creds;
repeated failed logins trip the throttle (lockout response); a successful login
**clears the limiter** (a valid login after some failed attempts is not locked out);
logout ends the session; a guest requesting `/dashboard` is redirected to `/login`; an
authenticated user reaches `/dashboard`.

### Success Criteria:

#### Automated Verification:

- [ ] Registration tests pass (success + derived name + negatives): `composer run test`
- [ ] Login success/failure + throttle-after-N-attempts tests pass: `composer run test`
- [ ] Logout + guest-redirect-to-login + authed-can-reach-dashboard tests pass: `composer run test`
- [ ] Formatting clean: `vendor/bin/pint --test`

#### Manual Verification:

- [ ] `composer run test` output shows the new auth test files executing and passing
- [ ] Test names read as a clear spec of the auth surface (flows, negatives, throttle, boundary)

**Implementation Note**: Pause for manual confirmation before proceeding to Phase 4.

---

## Phase 4: Isolation convention as durable contract

### Overview

Write the per-user isolation convention into load-bearing, agent-readable artifacts
so every later slice and every AI agent inherits it explicitly — and define the
S-01 enforcement checklist that makes the first domain model land with owner-scoping
proven by test.

### Changes Required:

#### 1. Create the contract-surfaces registry

**File**: `docs/reference/contract-surfaces.md` (new).

**Intent**: Register the load-bearing names and rules this slice establishes so they
aren't silently broken later: the `auth` middleware boundary, the
relationship-access convention, and the `user_id` foreign-key naming rule for all
future per-user tables.

**Contract**: The doc states (a) every per-user domain table MUST carry a `user_id`
foreign key with cascade-on-delete; (b) user-facing controllers MUST reach domain
data via the authenticated-user relationship (`$request->user()->ventures()`), never
via a global query (`Venture::find(...)`); (c) route-model binding for owned
resources MUST be ownership-scoped; (d) the `auth` middleware is the authentication
boundary.

#### 2. Record the isolation lesson

**File**: `context/foundation/lessons.md` (new).

**Intent**: Capture the convention as a recurring rule that `/10x-plan` and
`/10x-implement` treat as a prior for future slices.

**Contract**: A lesson entry titled around per-user isolation stating: "A raw global
query on a per-user model is a privacy bug — always scope domain queries through the
authenticated user relationship," with a one-line why (privacy-incident risk) and how
to apply (use the relationship; add a two-user cross-access test).

#### 3. Add the isolation rule to AGENTS.md

**File**: `AGENTS.md` (above the `<!-- BEGIN @przeprogramowani/10x-cli -->` marker).

**Intent**: Surface the convention in the always-loaded agent guide so any agent
working the repo applies it without rediscovering it.

**Contract**: A short "Per-user isolation" rule added ABOVE the toolkit-managed BEGIN
marker, pointing at `docs/reference/contract-surfaces.md` for the full contract.

#### 4. Write the S-01 enforcement checklist

**File**: `docs/reference/contract-surfaces.md` (a section) or a sibling note linked
from it.

**Intent**: Make the deferred owner-scoping proof concrete so S-01 can't skip it.

**Contract**: A checklist S-01 must satisfy when adding the first domain model
(Venture): (1) `user_id` FK on the table; (2) `User hasMany` relationship defined;
(3) controllers access via `$request->user()->ventures()`; (4) a feature test
creating two users that asserts user A cannot read/update/delete user B's venture
(404/403); (5) ownership-scoped route-model binding.

### Success Criteria:

#### Automated Verification:

- [ ] Convention doc exists: `test -f docs/reference/contract-surfaces.md`
- [ ] Lessons file exists: `test -f context/foundation/lessons.md`
- [ ] AGENTS.md carries the isolation rule above the BEGIN marker (grep for the rule heading)
- [ ] No code/test regressions: `composer run test`

#### Manual Verification:

- [ ] The convention doc names the `user_id` FK rule, the relationship-access rule, and the `auth` boundary
- [ ] The S-01 checklist is concrete enough that a reader can implement S-01's first model with isolation proven by a two-user test
- [ ] AGENTS.md edit is above the toolkit BEGIN marker (won't be overwritten by toolkit updates)

**Implementation Note**: Final phase — after automated + manual verification, the
F-01 foundation is complete; record the commit SHA in Progress and the change is
ready for `/10x-archive` once merged.

---

## Testing Strategy

### Unit Tests:

- None required beyond framework defaults; auth logic is exercised via feature tests.

### Integration / Feature Tests:

- Registration: success (email+password, derived name), duplicate email, invalid
  email, weak/unconfirmed password.
- Authentication: login success, login failure, throttle after repeated failures,
  logout.
- Boundary: guest → `/dashboard` redirects to `/login`; authenticated user reaches
  `/dashboard`.

### Manual Testing Steps:

1. Register with only email + password → land on `/dashboard`.
2. Sign out → returned to a public page; revisit `/dashboard` → redirected to `/login`.
3. Enter wrong credentials repeatedly → throttled/lockout message.
4. Confirm there is no `/forgot-password` or `/profile` route (none were built).

## Performance Considerations

Negligible. Auth is session-backed (DB session driver, existing `sessions` table).
The NFR ~1s edit-latency guardrail does not bind here (no domain editing in this slice).

## Migration Notes

No new migrations. Existing `users`, `sessions`, and `password_reset_tokens` tables
are reused; `password_reset_tokens` is left unused (recovery is out of scope).

## References

- Roadmap item: `context/foundation/roadmap.md` (F-01)
- Change identity: `context/changes/minimal-auth-and-isolation/change.md`
- PRD refs: FR-001, FR-002, FR-003, Access Control, NFR(isolation), NFR(pw-hash)
- Existing baseline: `app/Models/User.php:29` (hashed cast), `config/auth.php:40`
  (web guard), `database/migrations/0001_01_01_000000_create_users_table.php:30`
  (sessions table)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Login + logout + protected dashboard

#### Automated

- [x] 1.1 Routes registered: `php artisan route:list` shows login (GET/POST), logout (POST), dashboard (GET) — 121978f
- [x] 1.2 Test suite green (smoke): `composer run test` — 121978f
- [x] 1.3 Assets build: `npm run build` exits 0 — 121978f
- [x] 1.4 Formatting clean: `vendor/bin/pint --test` — 121978f

#### Manual

- [x] 1.5 Seed a user via `php artisan tinker`, log in via browser, land on `/dashboard` — 121978f
- [x] 1.6 Sign out → public page; revisit `/dashboard` → redirect to `/login` — 121978f
- [x] 1.7 Repeated bad logins trip the throttle (lockout message) — 121978f

### Phase 2: Registration (email+password, derived name)

#### Automated

- [x] 2.1 Register routes present: `php artisan route:list` shows register (GET/POST) — bd9a30d
- [x] 2.2 Test suite green (smoke): `composer run test` — bd9a30d
- [x] 2.3 Assets build: `npm run build` exits 0 — bd9a30d
- [x] 2.4 Formatting clean: `vendor/bin/pint --test` — bd9a30d

#### Manual

- [x] 2.5 Registering with email+password creates a user, logs in, lands on `/dashboard` — bd9a30d
- [x] 2.6 Derived name shows in the UI greeting — bd9a30d
- [x] 2.7 Duplicate email shows an inline validation error — bd9a30d

### Phase 3: Auth feature tests (flows + negatives + boundary)

#### Automated

- [x] 3.1 Registration tests pass (success + derived name + negatives): `composer run test`
- [x] 3.2 Login success/failure + throttle-after-N-attempts tests pass
- [x] 3.3 Logout + guest-redirect-to-login + authed-can-reach-dashboard tests pass
- [x] 3.4 Formatting clean: `vendor/bin/pint --test`

#### Manual

- [x] 3.5 `composer run test` output shows the new auth test files executing and passing
- [x] 3.6 Test names read as a clear spec of the auth surface

### Phase 4: Isolation convention as durable contract

#### Automated

- [ ] 4.1 Convention doc exists: `test -f docs/reference/contract-surfaces.md`
- [ ] 4.2 Lessons file exists: `test -f context/foundation/lessons.md`
- [ ] 4.3 AGENTS.md carries the isolation rule above the BEGIN marker
- [ ] 4.4 No code/test regressions: `composer run test`

#### Manual

- [ ] 4.5 Convention doc names the user_id FK rule, the relationship-access rule, and the auth boundary
- [ ] 4.6 S-01 checklist is concrete enough to implement the first model with a two-user isolation test
- [ ] 4.7 AGENTS.md edit sits above the toolkit BEGIN marker
