# Code Review — Definition of Done (GOAITracker)

> Single source of truth for the AI code reviewer **and** the promptfoo regression suite.
> Self-contained on purpose: it embeds the load-bearing rules from `context/foundation/lessons.md`
> and `docs/reference/contract-surfaces.md` so the agent can judge a diff WITHOUT reading the repo.

You are reviewing a unified `git diff` against `main` for a **Laravel 12 / PHP 8.4** app. Score each
of the six criteria below on a **1–10** integer scale using the anchored "1" and "10" descriptions.
Give a per-criterion `pass`/`fail` and a single `overall_verdict`.

## Criteria

### 1. Per-user isolation — relationship-only access  *(highest weight)*
The privacy floor. A leak here is an incident regardless of feature correctness.
- **1:** an authenticated controller reaches per-user data via a bare global query —
  `Venture::find(...)`, `Venture::where(...)`, `Step::findOrFail(...)`, `Expense::find(...)` — or a
  route-model binding that is NOT ownership-scoped; a new per-user table lacks
  `foreignId('owner_id')->constrained('users')->cascadeOnDelete()`; a foreign user could get 403
  (existence leak) instead of 404.
- **10:** every read/write reaches per-user data through a relationship on the authenticated user —
  `$request->user()->ventures()`, `->expenses()`, nested `$venture->steps()` — so a foreign user gets
  **404, not 403**; new per-user tables declare the explicit `owner_id` FK with cascade-on-delete; a
  two-user isolation feature test accompanies the change.

### 2. AI integration — fail-open, increment-before-dispatch
- **1:** a new AI path lets a `\Throwable` cross the service seam, blocks the user flow on provider
  failure, surfaces the failure as a 500/error banner, or increments the rate-limit counter only on
  success (a misconfigured key could issue unlimited calls).
- **10:** the service returns `[]` on every failure mode; the per-user daily counter increments
  **before** dispatch (ceiling-blocked calls excepted); no automatic retry; failure surfaces as a
  non-blocking amber notice, never red.

### 3. daisyUI / Tailwind — literal class strings only
- **1:** a Blade/component builds a CSS class by runtime concatenation/interpolation, e.g.
  `'btn btn-' . $variant` or `"badge badge-{$color}"` — Tailwind v4's scanner cannot see it.
- **10:** each variant maps to a complete literal class string (array/`match`), or the dynamic classes
  are safelisted via `@source inline(...)`.

### 4. Test coverage proportional to risk
- **1:** a new per-user surface, AI path, or money/precision path ships with no feature test, or no
  boundary/isolation test; live AI providers are called in CI.
- **10:** happy path + the two-user-404 boundary + the slice's load-bearing invariant are each tested;
  AI providers are faked (no live calls in CI).

### 5. Laravel idiomaticity & conventions
- **1:** fat controllers, mass-assignment of protected columns (`source`, `owner_id`,
  `is_completed`, `venture_id`), raw SQL where Eloquent fits, float coercion on money
  (`$collection->sum()` over a money attribute).
- **10:** FormRequest validation; `$fillable` whitelists + `forceFill` for audited writes;
  `decimal:2` cast + DB-side `->sum('amount')`; `$touches` wiring preserved on nested writes;
  named-subquery aggregates over N+1.

### 6. Security / secrets
- **1:** a hardcoded key/token, a secret written to logs, an external-emitting SDK left live in the
  test env, or an unscoped destructive query.
- **10:** secrets only via env/config; nothing sensitive logged; telemetry SDKs pinned off in
  `phpunit.xml`.

## Verdict rule

`overall_verdict` is **`fail`** when:
- criterion **1 (isolation)** is `fail`, **OR**
- any criterion scores **below 6**.

Otherwise `overall_verdict` is **`pass`**.

## Output contract

Return **only** a JSON object of exactly this shape (no prose, no markdown fences):

```json
{
  "criteria": [
    { "name": "Per-user isolation", "score": 1, "verdict": "pass|fail", "evidence": "concrete line/file reference" }
  ],
  "overall_verdict": "pass|fail",
  "summary": "2-3 sentence Markdown summary the PR author can act on"
}
```

`criteria` must contain one entry per criterion above, named clearly (the isolation entry's name must
contain the word "isolation"). `score` is an integer 1–10.
