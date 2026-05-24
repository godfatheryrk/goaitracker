---
project: goaitracker
researched_at: 2026-05-24
recommended_platform: Render (web) + Neon (Postgres)
runner_up: Railway
context_type: mvp
tech_stack:
  language: php
  framework: laravel
  runtime: php-8.x
  database: postgres-external
region: frankfurt
---

## Recommendation

**Deploy GOAITracker on Render's Frankfurt free Web Service tier, with PostgreSQL on Neon's EU-Central free tier (external).** Render is the only researched candidate with a credible $0/month path for a Laravel app once Cloudflare/Vercel/Netlify drop on the PHP-runtime hard filter and Fly.io/Railway disqualify on the "free-tier preferred" constraint. Pulling Postgres OUT of Render and onto Neon defeats Render's 30-day free-Postgres expiry trap — the single most dangerous risk surfaced in the cross-check — and converts a deadline-week DB-deletion panic into a sustainable, non-expiring data layer.

## Platform Comparison

Hard filter applied before scoring: the project's tech stack (PHP 8.x + Laravel + Composer) requires a server-side PHP runtime, dropping Cloudflare Workers, Vercel, and Netlify from the candidate pool. Persistent-connection filter not activated (no WebSockets / realtime / background jobs declared).

Soft weights applied after scoring: free-tier preferred (hard cost ceiling), familiarity with Render/Railway/Fly family, single-region traffic, co-location preferred (softened by accepting external DB to defeat the expiry trap).

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP / integration | Score |
|---|---|---|---|---|---|---|
| **Render** | Partial | Pass | Partial | Pass | **Pass (GA)** | 8 |
| **Railway** | Pass | Pass | Partial | Pass | Pass (WIP) | 9 |
| **Fly.io** | Pass | Pass | Pass (MDX/GH) | Pass | Partial (experimental) | 9 |
| Cloudflare Workers + Pages | — | — | — | — | — | DROP (no PHP runtime) |
| Vercel | — | — | — | — | — | DROP (PHP community-only, not recommended for prod) |
| Netlify | — | — | — | — | — | DROP (no PHP runtime, Composer build-time only) |

Soft-weighted free-tier reality (Laravel + Postgres, 2026-05-24):

| Platform | $0 path | Realistic monthly floor at MVP launch |
|---|---|---|
| **Render + external Postgres (Neon)** | **Yes, sustainable** | **$0/mo** (Render free web in Frankfurt + Neon free EU-Central) |
| Render with Render Postgres | 30 days only | $7/mo (Basic Postgres after free expiry) |
| Railway | Trial $5 credit (~30 days) | $5/mo Hobby min + consumption (~$5–10/mo realistic) |
| Fly.io | No | ~$41/mo (MPG Basic $38 + web ~$2 + volume ~$1) |

### Shortlisted Platforms

#### 1. Render — Recommended (web only; Postgres on Neon)

**Why it won:** the only viable $0/mo path for this stack once the Postgres expiry trap is sidestepped via an external provider. Render's MCP server is **GA** (not experimental like Fly.io's `fly mcp server`, not work-in-progress like Railway's). PHP-on-Render is Docker-only, but the official `render-examples/php-laravel-docker` reference exists and is documented (`render.com/docs/deploy-php-laravel-docker`). The Render CLI is GA at v2.18.0 with `--wait` flag returning non-zero on deploy failure — enough for agent loops, with the caveat that rollback is REST-only. Frankfurt region is free-tier eligible.

**The Render Postgres trade is the load-bearing decision:** the project's "FREE TIER PREFERRED" hard constraint plus the deadline of 2026-07-04 means the 30-day free-Postgres expiry (changed 2024-05-20 from 90→30 days per Render's official changelog) would force either (a) a paid upgrade week-of-launch that breaks the cost ceiling, or (b) a high-risk recreate-and-restore during the deadline crunch. Moving Postgres to Neon (or Supabase) collapses both options into a non-event.

#### 2. Railway — Runner-up

**Why it scored second:** Railway has the strongest *agent-readability story on paper* — Railpack auto-detects Laravel (no Dockerfile authoring tax), FrankenPHP as the bundled web server, automatic migrations on deploy (configurable via `RAILPACK_SKIP_MIGRATIONS`), Postgres 18 co-located, and a remote MCP server at `mcp.railway.com` with OAuth instead of local token files. The CLI is more complete than Render's (proper `railway redeploy`, `--ci`, `--detach`).

**Why it didn't win:** the cost story breaks the user's hard constraint. Railway removed its true free tier in 2023; the current state in 2026 is a one-time $5 trial credit (~30 days) followed by either a permanent $1/mo "Free plan" that cannot fit a Laravel + Postgres pair, or Hobby at $5/mo + consumption (~$5–10/mo realistic for this workload). Railway's MCP is also flagged as "a work in progress" by Railway itself — softer than Render's GA. **Swap to Railway if** the agent-DX of skipping the Dockerfile is worth ~$5/mo to you and you want a single vendor.

#### 3. Fly.io — Third

**Why it scored third:** technically excellent for Laravel — `fly launch --laravel` generates Dockerfile + `fly.toml` + GitHub Actions workflow with Nginx + PHP-FPM; `flyctl` is the most mature CLI in the shortlist; docs are MDX in GitHub (the only candidate that passes the agent-readable-docs criterion outright).

**Why it didn't win:** post-October-2024, Fly.io has no free path. Realistic floor is ~$41/mo (MPG Basic $38 + machine + volume). Disqualifies on the "FREE TIER PREFERRED" hard constraint. MCP server (`fly mcp server`) is explicitly flagged `[experimental]` in Fly's own docs as of 2026-05-24. **Swap to Fly.io if** budget loosens above $40/mo and the mature CLI matters more than vendor count.

## Anti-Bias Cross-Check: Render + Neon

### Devil's Advocate — Weaknesses

1. **Docker-authoring tax on a 3-week clock.** Render has no native PHP runtime — you ship a Dockerfile. Current `cwd` has `composer.json` + SQLite, no Docker yet. Expect 2–4 hours on Dockerfile + nginx/php-fpm tuning + entrypoint script (`php artisan migrate --force`, `config:cache`, `storage:link`) before the first green deploy. Railway's Railpack would absorb this to zero.
2. **15-min spin-down vs the ~1s "feels instant" NFR.** Free web cold-start is 30–60s after idle. First impression on every return visit hits a loading spinner before reaching the venture-list (FR-005) or detail (FR-006) surfaces — exactly the screens the unified-view value prop depends on. The NFR is about workflow friction, not steady-state edit latency.
3. **CLI lacks first-class rollback.** Rollback is REST-only (`POST /v1/services/{id}/rollback`). Agent-driven rollback workflows need a one-shot CLI verb, not curl + JSON parsing.
4. **MCP is read-heavy by design.** Render's MCP server explicitly excludes deploy triggers and destructive ops. Good security posture, but the agent cannot push schema changes or mutate data via MCP — only via CLI/REST. MCP becomes a discovery and observability tool, not an action tool.
5. **Cross-vendor latency on Render Frankfurt → Neon EU-Central.** Laravel makes many queries per page (Eloquent N+1 risk on FR-006's combined steps + expenses + progress view). Even within the same metro area, network hop adds ~2–5ms per round-trip vs co-located Postgres. Real measured latency in the FR-006 worst case (15 steps + 10 expenses + progress aggregate) could land at 50–150ms before any application logic — measure before assuming the ~1s NFR holds.

### Pre-Mortem — How This Could Fail

Six months later, the GOAITracker deploy on Render + Neon is a quiet disaster. Week 3 of the sprint blew up first: 8 hours on Dockerfile and nginx/php-fpm tuning that should have funded FR-008 (the AI step-suggestion that the product's primary success metric depends on) — the AI flow shipped half-tested, the deadline-badge feature got cut to v2. Week 6 post-launch, the Neon project was force-suspended after a misconfigured Render cron-job spiked Neon's compute hours past the free quota — Render's spin-down made the bug invisible until users started reporting "site is down" on a Saturday. The fix took two hours, but the trust hit was permanent: the secondary success metric (return visits) cratered because half the early adopters' first return hit a 504. By month four, the bill was $7/mo for Neon's first paid tier just to escape the compute-hour ceiling — the "free" rationale that picked the split stack quietly evaporated, and now the architecture carries the operational cost of two dashboards plus the reputational cost of one weekend outage.

### Unknown Unknowns

1. **Render free is per *workspace*, not per service.** The 750 instance-hours/month and build-minute caps pool across staging + prod. A second web service halves your free runway — keep staging on the same service via a feature-flag branch deploy, not a separate service.
2. **Free Postgres deletion (if you fall back to Render Postgres) is silent.** Email-only warning ~14 days before deletion. No CLI/MCP notification. Even if you decide to keep Render Postgres rather than Neon, set a calendar reminder for **30 days after creation** to either upgrade or recreate. (Reduced to a near-zero risk by the recommendation to use Neon, but if the user reverses that decision the risk is back.)
3. **`render-examples/php-laravel-docker` is community-maintained, not first-party support.** Render docs link to it, but updates lag Laravel releases — verify the example's PHP and Laravel pins against your `composer.json` before copying.
4. **Neon's free tier auto-suspends after idle.** First request after suspension wakes the DB in 1–10 seconds (cold-start, but on Postgres, not Render). Layered on top of Render's web cold-start, a return visit after a long idle period can see a compound cold-start (Render web wakes → connects to Neon → Neon wakes). This is the single most likely cause of a "feels broken" user report.
5. **`tech-stack.md` says PostgreSQL but `cwd` is currently SQLite.** Laravel config (`DB_CONNECTION=pgsql`, `DATABASE_URL` parsing) must be verified end-to-end locally against a real Postgres before the first deploy — Eloquent dialect quirks and SQLite-only test fixtures are the usual surprise. Run the test suite against Postgres at least once before Render's first build.
6. **Render's MCP server does not trigger deploys** (read/list operations only — service creation, env-var updates, log queries, read-only Postgres). The deploy step itself remains a CLI/REST or git-push operation. Plan the agent's role accordingly: MCP is for "tell me what's happening", CLI is for "do the thing".

## Operational Story

How the chosen platform operates day to day. One concrete answer per line.

- **Preview deploys**: Render's PR Previews are a paid-plan feature — **not available on free**. For MVP, preview is "push a branch, manually create a temporary service from the branch in the Frankfurt region, delete it after merge". Alternative: run preview against a local Docker build with `docker compose up` and Neon's database branching (Neon branches are free up to the quota — one of Neon's distinguishing features).
- **Secrets**: Render env vars set via `render env set` (CLI) or the dashboard; the Neon `DATABASE_URL` lives in Render env vars alongside `APP_KEY`, `APP_URL`, `ASSET_URL`, and the LLM provider key. Rotation = revoke at Neon → re-set on Render → trigger redeploy. The CLI command for redeploy: `render deploys create --service-id <id> --wait`.
- **Rollback**: REST API only — `POST /v1/services/{id}/rollback` against `api-docs.render.com/reference/rollback-deploy`. The Render CLI has no first-class `rollback` verb. Typical time-to-revert: ~30s for code; DB migrations do **not** roll back automatically — Laravel migrations need an explicit `php artisan migrate:rollback` step run via `render ssh` against the running service, OR roll forward with a fix migration. Plan migration rollbacks separately.
- **Approval**: human-only for (a) Postgres provider plan changes (Neon dashboard), (b) Render service tier upgrades (paid threshold), (c) primary secret rotation that touches both Render and Neon, (d) destructive Postgres operations (`DROP TABLE`, schema rewrites). Agent may perform: deploys via `render deploys create`, env-var read/write via MCP, log tailing via `render logs -t`, read-only Postgres queries via Render MCP.
- **Logs**: `render logs --service <id> --tail` for live tailing; `--since 1h` for historical; `--output json` for agent parsing. Render MCP exposes filtered log queries as a tool. Neon logs are separate — visible in the Neon dashboard or via Neon's REST API (no MCP for Neon at last check; CLI exists but is thin).

## Risk Register

| # | Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|---|
| 1 | Docker-authoring burns 2–4h of the 3-week budget before first green deploy | Devil's advocate | H | M | Use `render-examples/php-laravel-docker` verbatim as the starting point; verify Laravel/PHP version pins match `composer.json` before customizing. Budget the time explicitly in week 1, not week 3. |
| 2 | Cold-start (Render free web spin-down, 15 min idle) + Neon auto-suspend can compound on return visits, breaking the "feels instant" NFR | Devil's advocate + Unknown unknowns | H | H | Measure real cold-start once after first deploy. If unacceptable: (a) upgrade Render to Starter ($7/mo) to remove spin-down, OR (b) accept that the first-impression cost only hits the first return-visit per ~15-min window and is recoverable. Spell out the cold-start in any user-facing documentation/onboarding. |
| 3 | Render MCP cannot trigger deploys or mutate data — agent autonomy is bounded by CLI/REST fallback | Devil's advocate | M | L | Build the deploy step as a `render deploys create --wait` CLI call from agent context; treat MCP as observability-only. Document the boundary in `AGENTS.md` so the agent doesn't try to use MCP for actions it can't perform. |
| 4 | Render rollback is REST-only — no `render rollback` CLI verb | Devil's advocate | L | M | Wrap the REST call in a project-local script (`scripts/rollback.sh`) so the agent has a one-shot command. Test the rollback path BEFORE first prod deploy, not after the first incident. |
| 5 | Neon free-tier quota exhaustion (compute hours or storage) silently suspends the database | Pre-mortem | M | H | Set a Neon project alert at 80% of monthly compute quota. Plan a $0→$19/mo Launch-tier upgrade as the response, not as a v2 problem. Verify free-tier quotas at `neon.com/pricing` before sign-up (verifier blocked during research; numbers from training data are unverified for 2026). |
| 6 | Cross-vendor latency (Render Frankfurt ↔ Neon EU-Central) adds 50–150ms on Laravel pages with N+1 query patterns | Devil's advocate | M | M | Pin Neon to the closest EU-Central region. Run `php artisan debugbar:enable` locally and audit FR-006 (venture detail) for N+1 queries before deploy. Add eager-loading (`with('steps', 'expenses')`) on the venture-detail query. |
| 7 | Render free is per-workspace, not per-service — staging + prod halve the 750h pool | Unknown unknowns | M | L | Keep staging out of Render initially; preview via local Docker + Neon's free branching. If staging on Render is needed later, accept that you've used your free runway and budget for it. |
| 8 | `render-examples/php-laravel-docker` may lag latest Laravel release | Unknown unknowns | M | M | Verify PHP and Laravel pins in the example Dockerfile match `composer.json` before using; expect to bump the FROM line and re-test. |
| 9 | SQLite→Postgres dialect surprises (Eloquent quirks, test fixtures) | Unknown unknowns | M | M | Switch local dev to Postgres before first Render deploy: `composer require --dev` no change needed; update `.env` `DB_CONNECTION=pgsql` + run `php artisan migrate:fresh --seed` against a local Postgres (Docker `postgres:17` is fine). Run the full test suite against Postgres once. |
| 10 | Migration rollbacks are NOT automatic on Render rollback — code rolls, schema doesn't | Research finding | M | H | For any migration that's reversible, write the `down()` method properly. For irreversible migrations (data drops), explicit checklist: deploy code → migrate → if rollback needed, deploy a fix-forward migration, never a `migrate:rollback`. Document this in `AGENTS.md`. |
| 11 | LLM provider downstream of this contract — rate-limit + cost ceiling NFR is enforced at the app layer, not the platform | PRD-derived (FR-008 / FR-009 NFR) | M | M | This is a Laravel-side concern (cache + per-user counter), not an infra one. Flagged here as a reminder that the platform does NOT shield the AI budget — the application must. |

## Getting Started

Concrete steps to first deploy. Sequence assumes 2026-05-24 starting state: composer-installed Laravel project with SQLite, no Docker, no Render account yet.

**Verify before signing up (research had partial verifier-tool access; spot-check these URLs):**
- Render free tier and Frankfurt region availability: `render.com/docs/free`
- Render CLI install: `render.com/docs/cli`
- `render-examples/php-laravel-docker` Dockerfile + Laravel pins: `github.com/render-examples/php-laravel-docker`
- Neon free tier limits and EU-Central region: `neon.com/pricing` and `neon.com/docs/introduction/regions`
- Render MCP server install: `render.com/docs/mcp-server`

**Deploy sequence:**

1. **Sign up for Neon (EU-Central)**: create a free project, region `eu-central-1` (or closest EU option). Copy the pooled `postgres://...` connection string. Save it for step 5.
2. **Switch local Laravel to Postgres**: edit `.env` → `DB_CONNECTION=pgsql`, set `DATABASE_URL` to the Neon URL OR explicit `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`. Run `php artisan migrate:fresh --seed`. Run `composer run test`. Fix any SQLite-only assumptions in tests/fixtures before proceeding.
3. **Add Dockerfile + entrypoint**: clone `github.com/render-examples/php-laravel-docker` for reference. Copy `Dockerfile` and the `docker/` directory into the project root. Verify the `FROM php:X.X-fpm` line matches your `composer.json` `require.php` constraint. Add `php artisan config:cache && php artisan route:cache && php artisan view:cache` to the entrypoint after `composer install --no-dev`.
4. **Add `render.yaml` (Blueprint)** at project root with:
   - One Web Service (`type: web`, `env: docker`, `plan: free`, `region: frankfurt`, `branch: main`).
   - Env var stubs (`APP_KEY`, `DATABASE_URL`, `APP_URL`, `APP_ENV=production`, LLM provider key).
5. **Install Render CLI**: `winget install Render.CLI` (Windows) or per `render.com/docs/cli`. Run `render login`.
6. **Create the Render workspace + service**: push the repo to GitHub first. Then `render services create --output json --wait` against the blueprint, OR use the dashboard once to create + connect the GitHub repo (one-time). After that, every push to `main` auto-deploys.
7. **Set secrets via CLI**: `render env set DATABASE_URL=<neon-url> APP_KEY=$(php artisan key:generate --show) APP_URL=https://<your-render-domain> --service-id <id>`. Trigger redeploy: `render deploys create --service-id <id> --wait`.
8. **Verify the deploy**: `render logs --service-id <id> --tail` until the Laravel boot logs land. Hit the public URL; confirm health check returns 200.
9. **Install Render MCP server** in Claude Code: follow `render.com/docs/mcp-server` for the JSON snippet. Verify with a read-only query (`render mcp list-services` equivalent through the MCP tool).
10. **Wrap rollback** in `scripts/rollback.ps1` (PowerShell) calling Render's REST `POST /v1/services/{id}/rollback` endpoint with the previous deploy ID. Test it once against the staging or first prod deploy so the agent has a verified path before the first incident.

## Out of Scope

The following were not evaluated in this research:
- Docker image configuration beyond pointing at `render-examples/php-laravel-docker`.
- CI/CD pipeline setup beyond Render's built-in GitHub auto-deploy + the GitHub Actions workflow Render's blueprint references.
- Production-scale architecture (multi-region failover, HA, DR, dedicated support tiers).
- LLM provider selection — `tech-stack.md` defers this downstream and the platform decision does not constrain it.
- Email provider for password recovery (FR-001 / FR-002 implicit). External transactional email (Postmark, Resend) is a separate decision.
- Frontend asset hosting / CDN — Laravel asset compilation runs at Docker build; if the asset pipeline grows, evaluate Cloudflare R2 + CDN later.

## What "deploy" means downstream

When this contract is consumed by Plan Mode for the first deploy:
- The recommended platform is **Render web (Frankfurt free tier)** + **Neon Postgres (EU-Central free tier)**.
- The Plan Mode plan should explicitly call out the manual gates (Neon sign-up, Render sign-up, first GitHub repo creation, first `render login`, first `render env set` for secrets) vs the automatable steps.
- The Plan Mode plan must specify `render deploys create` (web service) vs Neon's web-only-onboarding — these are not interchangeable.
- The plan should reference this file's Getting Started section as the source-of-truth sequence and the Risk Register as the operational checklist.
