---
project: goaitracker
researched_at: 2026-05-24
recommended_platform: Render
runner_up: Railway
context_type: mvp
tech_stack:
  language: PHP 8.4
  framework: Laravel 13+
  runtime: PHP-FPM (via custom Dockerfile on Render free tier)
  database: Neon (external Postgres, free tier, eu-central-1 / Frankfurt)
  monthly_cost_floor: "$0 (Render free Web Service + Neon free tier)"
---

## Recommendation

**Deploy on Render free tier with Neon as the external Postgres provider.**

Render is the strongest fit for a solo PHP/Laravel MVP under a 6-week deadline: GA CLI with non-interactive JSON output, GA MCP server (Aug 2025, the most mature MCP coverage of the three real candidates), and a free Web Service tier that supports a single Docker container with auto-deploy-on-merge. The user supplies their own Dockerfile for **Laravel 13+ on PHP 8.4** — the official `render-examples/php-laravel-docker` repo (Laravel 11 / PHP 8.3) is a reference, not the artifact in use. The database layer is **Neon free tier** (0.5 GB storage, 191.9 compute-hours/month, eu-central-1 to co-locate with Render Frankfurt) — Neon's free tier is truly free with no 30-day expiry, unlike Render's built-in free Postgres. **Total operating cost: $0/month** (Render free Web Service + Neon free Postgres). The decision is driven by the developer interview's cost-priority answer (Fly.io's $38/mo managed Postgres minimum was disqualifying) and Render's edge on agent-tooling maturity. The Q5 "external DB providers fine" answer is what makes Neon viable. Cloudflare, Vercel, and Netlify were hard-filtered before scoring — none run PHP server-side.

**Trade-offs accepted for the $0/mo floor**:
- **Render free tier covers Web Services only** — Background Workers and Cron Jobs are paid-only on Render. The deployment collapses to **a single web service** running PHP-FPM + nginx. Laravel queue runs as `sync` driver (inline in the request). Scheduled tasks, if ever needed, must be triggered by an external free cron pinger (GitHub Actions cron, cron-job.org) hitting a scheduler endpoint.
- **Web service sleeps after 15 minutes of inactivity** — first request after idle takes ~30-60s to wake. Acceptable for solo MVP and beta-user testing; not acceptable for a public-facing demo without an external keep-warm pinger.
- **Build minutes capped at 500/month** on free tier — Docker builds consume these faster than native runtimes. Watch the meter during sprint.

## Platform Comparison

### Hard filters applied

| Platform | Filter result | Reason |
|---|---|---|
| Cloudflare Workers/Pages | **Dropped** | No native PHP runtime. V8/Python/Rust/WASM only. The community shim `renoki-co/l1` is dormant (last release Oct 2023) and would not run Laravel anyway. |
| Vercel | **Dropped** | PHP only via community runtime (`vercel-community/php`, healthy but unofficial). 60s Hobby function timeout. No persistent processes for Laravel queue workers / scheduler. |
| Netlify | **Dropped** | Official Netlify statement: "Netlify does not run server-side languages." Functions are TypeScript/JavaScript/Go only. PHP exists only at build time for static-site generators. |

### Scoring matrix (post-filter)

| Platform | CLI-first | Managed | Agent-readable docs | Stable deploy API | MCP / Integration | Tally |
|---|---|---|---|---|---|---|
| **Render** | Pass | Pass | Partial — HTML docs, GitHub examples; no `llms.txt` | Pass | **Pass — GA MCP server (Aug 2025), open-source, Claude Code support** | **4P + 1 partial** |
| Railway | Pass | Pass | Pass — `.md` mirrors on `docs.railway.com` | Pass | Pass — local + Remote MCP, flagged "work in progress" (2026-05-24) | **5P (1 WIP caveat)** |
| Fly.io | Pass | Partial — no native rollback verb; rollback = redeploy old image tag | Pass | Pass | Partial — `flyctl mcp server` marked **experimental** (2026-05-24) | **3P + 2 partial** |

### Soft-weight adjustments (interview-driven)

- **Q2 minimize cost** → heavy penalty on Fly.io: Managed Postgres is $38/mo minimum, pushing realistic MVP cost to ~$40-45/mo. **Render on free tier + Neon free Postgres = $0/mo** (vs. Railway's $5/mo Hobby floor with 24/7 idle billing). Render is the only candidate with a genuine $0/mo path for Laravel that doesn't expire or self-delete data.
- **Q3 familiarity (Railway / Render / Fly.io)** → all three in scope; no tiebreaker applied.
- **Q4 single region** → no edge-native preference; Frankfurt or US region on any of them is fine.
- **Q5 external DB providers OK** → **load-bearing**: drives the decision to pair Render with **Neon** (truly-free Postgres, no 30-day expiry, eu-central-1 Frankfurt for co-location). Cuts the realistic floor cost from $14/mo (Render Starter web + Render Starter Postgres) to **$0/mo** (Render free Web Service + Neon free tier) and removes the highest-impact risk surfaced in the Render cross-check.

### Shortlisted Platforms

#### 1. Render + Neon (Recommended)

GA MCP server (the most mature MCP coverage among the three candidates), GA CLI with non-interactive JSON/YAML output, free Web Service tier supporting a user-supplied Dockerfile for Laravel 13+ on PHP 8.4, paired with **Neon free-tier Postgres** in eu-central-1 (Frankfurt) for co-location. **$0/mo total cost** for the MVP. Neon's database-branching feature is also a meaningful step up over any platform's built-in Postgres for preview-deploy ergonomics. The strongest signal: among the three real candidates, Render is the only one whose MCP server is GA — which materially lowers the cost of agent-driven operations during the build (`/10x-implement`) and post-launch phases. Trade-offs (single web service only, sleep after 15 min idle, 500 build-min/mo cap) are accepted as the cost of the $0/mo floor.

#### 2. Railway

Auto-detects Laravel out of the box via Railpack (or legacy Nixpacks), ships both a local CLI MCP and a Remote MCP server at `mcp.railway.com`, $5/mo Hobby plan with $5 included credit covers a low-traffic MVP. Two real risks knocked it to runner-up: (a) 24/7 idle billing — an idle Postgres alone consumes ~$10/mo of allocated RAM per multiple community reports, and (b) egress at $0.05/GB has been reported as the dominant line item in real bills. The MCP servers being "work in progress" (vs. Render's GA) is a smaller but pointing signal.

#### 3. Fly.io

First-class `fly launch` Laravel scaffolding (auto-generates Dockerfile, `fly.toml`, `.fly/` nginx+PHP-FPM config, and a GitHub Actions workflow), persistent VMs for queues and scheduler, mature `flyctl` CLI. Knocked to third by the cost answer: Managed Postgres Basic is $38/mo, and the unmanaged Postgres option is explicitly "unsupported — your problem to operate". For a cost-minimizing solo MVP, the math doesn't work. `flyctl mcp server` is also still experimental.

## Anti-Bias Cross-Check: Render

### Devil's Advocate — Weaknesses

1. **Inline AI calls block the web request.** Free Render tier has no Background Workers, so FR-008 / FR-009 AI step suggestions run on the `sync` queue driver — synchronously in the web request handler. A 10-20s OpenAI call holds the request open. The NFR "AI failure stays invisible" is fine (the user still gets the venture), but the NFR "editing feels instant (≤1s)" is **structurally unattainable on the AI path** without a background worker. Mitigation lives at the UX layer: show a skeleton/spinner for the AI path, accept that "instant" only applies to non-AI editing.
2. **Compound cold-starts on first visit after idle.** Render free web sleeps after 15 min idle (~30-60s wake-up); Neon free-tier compute auto-suspends after 5 min idle (~1-2s wake-up on first query). The worst case is the *sum*: ~31-62s for the first request after a long idle. Lethal for public demos, tolerable for solo / beta-user workflows where the user is informed.
3. **Vendor-split secret rotation: the connection string lives in two places.** Render env vars and Neon dashboard. Any Neon password rotation must update `DB_URL` on the Render web service. With only one Render service in scope (no worker, no cron), the rotation surface is smaller than before — but **if** scheduled tasks or workers are ever added (paid Starter), that surface grows.
4. **Single-region service, no edge.** Frankfurt is the EU option for both Render and Neon; users elsewhere see visible latency. The NFR "editing feels instant (≤1s)" is at the budget edge for non-EU users on non-AI paths. **Critical:** Neon region MUST match Render region (both `eu-central-1` / Frankfurt) — a US-East Neon paired with Frankfurt Render adds ~120ms to every DB query and blows the NFR immediately.
5. **Build minutes capped at 500/mo on Render free tier.** Docker builds — especially custom multi-stage Dockerfiles for Laravel 13+ / PHP 8.4 — consume these faster than native runtimes. During a 3-week sprint with frequent deploys, hitting the cap is plausible. Hitting it forces a paid upgrade or batched commits; planning for the latter is cheaper.
6. **Custom Dockerfile for Laravel 13+ / PHP 8.4 is off the well-trodden path.** The `render-examples/php-laravel-docker` repo targets Laravel 11 / PHP 8.3 — useful as a reference but not as a copy-paste artifact. Solo-maintained Dockerfile means *every* PHP extension (`pdo_pgsql`, `intl`, `mbstring`, `gd`, `imagick`) is the user's responsibility to add and pin. PHP 8.4 is still relatively new in 2026 — some Composer packages may not yet declare 8.4 compatibility, leading to `--ignore-platform-reqs` workarounds that bite later.

### Pre-Mortem — How This Could Fail (narrative)

The founder deployed Laravel 13+ on Render free tier with Neon Postgres for GOAITracker's MVP. Six months later, the decision turned out to be a complete disaster.

The first crack appeared two weeks in, when the first beta tester clicked the share-URL link the founder posted on Discord. The Render web service had been idle for two hours; the user waited 45 seconds on a white page, gave up, and tweeted that the app was broken. Three other invited testers saw the same screen over the next day and never returned. Free-tier sleep wasn't the *bug* — it was a property the founder had accepted in the cost spreadsheet but hadn't translated into a "don't share the URL cold" social rule.

The second crack appeared a month in, when an OpenAI call for FR-008 step suggestion took 28 seconds during a peak-traffic moment. With queue=sync and no background worker on free tier, the request held the connection open past Render's idle timeout. The user saw a 502, the venture was created with zero steps, and the primary success metric (≥3 of 7 AI steps kept) registered a permanent zero for that venture. The fix — moving AI to a real background queue — required a paid Render plan, contradicting the $0/mo decision.

By month six, the founder had upgraded to Render Starter ($7/mo) plus a Background Worker ($7/mo) anyway, paying $14/mo to fix problems that had been baked into the v1 architecture from day one. The 2026-07-04 deadline slipped because the queue refactor blocked launch by two weeks. The $0/mo MVP cost ended up costing two weeks of velocity.

### Unknown Unknowns

- **The Render MCP server is GA but young (Aug 2025 launch).** Twenty tools on paper, but the 6-month track record is thin. Don't assume agent operations are as reliable as direct CLI invocation — fall back to CLI on first sign of MCP weirdness.
- **The Render MCP doesn't manage Neon.** Render-MCP can read/write Render-side state but cannot inspect Neon (branch list, connection pooler status, compute suspend state). Neon ships its own MCP server (`@neondatabase/mcp-server-neon`) — install it alongside if agent-driven DB ops are wanted. Two MCP servers = two OAuth flows.
- **The Render CLI is also young (GA late 2024).** Stack Overflow / community-forum coverage still skews dashboard-first; less muscle-memory in agent training data than `wrangler` / `flyctl`.
- **Laravel 13's release status and breaking changes.** As of 2026-05-24, Laravel 13 is presumed released (Laravel ships annual majors in Q1). Verify the chosen Laravel version against the Laravel upgrade guide for any breaking changes in `config/database.php` `'url'` parsing or queue configuration — both are touched by this deployment shape.
- **PHP 8.4 Composer compatibility.** PHP 8.4 is GA but still relatively young in 2026. Some Composer packages may not have declared 8.4 compatibility in `composer.json`. Workarounds (`--ignore-platform-reqs`) tend to mask real incompatibilities until runtime. Pin a Docker base image (`php:8.4.x-fpm-alpine` with an exact patch version) to avoid silent base-image drift breaking the build.
- **Neon's connection string includes the project endpoint ID, not just a hostname.** Format is `postgres://user:pwd@ep-xxx-xxx.eu-central-1.aws.neon.tech/dbname?sslmode=require`. The `sslmode=require` segment is mandatory — Laravel's default Postgres driver respects it via the URL, but custom config that strips query params will silently break TLS.
- **Neon free-tier compute auto-suspends after 5 minutes of inactivity.** First query after idle wakes the compute (~1-2s). For a low-traffic MVP this is invisible to active users but visible to the very first request of a session. The Neon "Launch" plan ($19/mo) disables auto-suspend if it ever becomes annoying.
- **Render free-tier Preview Environments are NOT available.** Render Preview Environments require the Team plan or above. On the free Individual plan, only the `main` branch deploys. PR previews must be tested locally or against a separately-provisioned free preview service. Neon branching is still useful for *local* branch testing against an isolated DB.
- **`render.yaml` Blueprints with a single service are simpler — but `sync: false` on every env var means manual setup after Blueprint provisioning.** Render won't auto-fill `DB_URL`, `APP_KEY`, or `OPENAI_API_KEY`; the dashboard will prompt for them on first apply.
- **Frankfurt region's reliability is less battle-tested in community writeups** — most Laravel-on-Render guides assume US regions. Off-the-well-trodden path means slower troubleshooting if something breaks.

## Operational Story

- **Preview deploys**: **Not available on Render free tier.** Render Preview Environments require Team plan or above. For PR review, run the app locally against a **Neon branch** (per-PR copy-on-write clone of `main`, free on Neon Hobby) and rely on local end-to-end verification before merge. The Neon branch URL can also be pasted into a local `.env` for spot-checking.
- **Secrets**: env vars live in Render's web service environment (set via dashboard or `render env set <KEY>=<VALUE>`). `APP_KEY`, `DB_URL` (Neon pooled connection string), and the AI provider API key (FR-008 / FR-009) go here — never in the Dockerfile or `render.yaml`. **Rotation runbook**: Neon password rotation requires updating `DB_URL` on the Render web service, then `render deploys create --service <web-id>` to force pickup. With only the web service in scope on free tier, the rotation surface is exactly one service — capture this as `context/deployment/secret-rotation.md` so a future paid-tier worker/cron doesn't get forgotten.
- **Rollback**: in the Render dashboard, open the web service → "Deploys" tab → click the three-dot menu on a previous successful deploy → "Rollback to this deploy". Takes ~30-60s. **Migration caveat**: rolling back the web service does NOT roll back Postgres migrations on Neon. If a release ran `php artisan migrate`, manually `php artisan migrate:rollback` against the prior state before rolling back the image. **Neon-specific shortcut**: if the migration is irreversible (data loss), restore from a Neon **point-in-time** restore (free tier retains 24h history) before rolling back the image.
- **Approval**: the agent (you) may unattended-trigger deploys to the staging Neon branch and run read operations against production (Render logs, `render env get`, Neon read-only SQL via `neonctl`). **Human-gated**: production deploys after schema migrations, Neon password rotation, manual `php artisan db:seed --force` against production, deleting a Neon branch, upgrading from any free tier to paid (Render Starter or Neon Launch).
- **Logs**: `render logs --resources <service-id> --tail` streams live Render logs; `render logs --resources <service-id>` paginates historical. JSON output via `--output json` is the agent-readable form. The Render MCP server exposes equivalent tools (`logs_list`, `services_list`) for structured access. **DB-side observability**: Neon's dashboard surfaces slow queries, connection counts, and compute auto-suspend events — check there first when investigating a "the request is slow" report rather than tailing Render web logs.
- **Scheduled tasks**: not natively available on Render free tier. If FR scope ever requires scheduled jobs (e.g. periodic AI rate-limit reset, daily summary email), **wire an external free cron pinger** (GitHub Actions on `schedule:`, `cron-job.org`, or `easycron.com` free tier) hitting a Laravel route protected by a shared secret. The route runs `php artisan schedule:run` inline.

## Risk Register

| Risk | Source | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| Free-tier Render web cold-start (~30-60s after 15 min idle) damages first-impression demos and beta-test invites | Devil's advocate / Pre-mortem | H | H | **Accepted trade-off for $0/mo MVP.** Social rule: warm the URL with one request before sharing. If beta-user complaints surface, add an external free keep-warm pinger (GitHub Actions cron every 14 min hitting `/health`) — does not cost $. Last-resort: Render Starter ($7/mo) which eliminates sleep entirely. |
| AI step suggestion (FR-008 / FR-009) on `sync` queue blocks the web request — UX latency on AI path | Devil's advocate / Pre-mortem | H | M | Show a skeleton/spinner during AI call. Set a strict client-side timeout (e.g. 25s) and fail open per NFR ("AI failures stay invisible") — venture creates with empty step list. Document the architectural cap explicitly: NFR "editing feels instant ≤1s" applies to non-AI paths only. If background workers ever become a hard requirement, upgrade to Render Starter + Background Worker (+$14/mo). |
| Custom Dockerfile for Laravel 13+ / PHP 8.4 drift, missing PHP extensions, or 8.4 Composer incompat | Devil's advocate / Unknown unknowns | M | M | Pin base image to exact patch version (e.g. `php:8.4.3-fpm-alpine`). Vendor `composer.lock` and run `composer install --no-dev` in the Dockerfile. Track Dockerfile changes in `context/changes/`. Pre-check Composer compatibility locally with `composer install --no-dev --ignore-platform-reqs=ext-*` to surface missing extensions before deploy. |
| Neon password rotation forgets a service if scheduled tasks or workers are added later (paid tier) | Devil's advocate | L (now) / M (future) | H | While on free tier (web only), rotation surface is one service. **If** a Background Worker or Cron Job is ever added (paid Starter), update `context/deployment/secret-rotation.md` to cover all services and validate with a smoke test before declaring rotation complete. |
| Neon / Render region mismatch (US-East Neon + Frankfurt Render) = ~120ms per query, blows ≤1s NFR | Devil's advocate | M | H | **Hard rule in the deploy plan**: Neon project must be `eu-central-1`, Render web service must be `frankfurt`. Verify in both dashboards before first deploy. Add a smoke test that measures a sample query round-trip. |
| Neon free-tier compute auto-suspend (~1-2s wake) adds DB cold-start on first request after idle | Devil's advocate / Unknown unknowns | M | L | Accept for MVP — invisible to active users; compounds with Render's web cold-start only on truly cold first visits. If FR-006 first-paint latency becomes a complaint, upgrade Neon to Launch ($19/mo) to disable auto-suspend. |
| Single region (Frankfurt for EU), no edge — non-EU users see latency | Devil's advocate | M | M (NFR-tied) | Pick Frankfurt for both Render and Neon (primary user base in Poland). Document the latency budget for cross-region users as a known v1 limitation. |
| Build minutes cap (500/mo) hit during high-frequency sprint deploys | Devil's advocate | M | M | Monitor build minutes weekly in the Render dashboard. Batch commits during sprint. Multi-stage Docker builds with cached Composer/npm layers reduce per-build minutes — bake the cache strategy into the Dockerfile. If approaching cap, upgrade workspace or pause non-deploy commits. |
| Free-tier Render: no Preview Environments — PR previews tested locally only | Research finding | M | L | Develop locally with `php artisan serve` against a Neon branch URL in `.env`. End-to-end PR verification happens before merge. Render only deploys `main`. |
| Render MCP server is young (~6 months GA); reliability not battle-tested | Unknown unknowns | M | L | Default to `render` CLI for deployments and `psql` (or Neon dashboard) for DB. Use MCP for read-only inspection. Don't make MCP load-bearing on the critical path. |
| Schema rollback NOT automatic on image rollback — data divergence after revert | Research finding (rollback semantics) | L | H | Add a rollback runbook step: "before rolling back image, run `php artisan migrate:rollback --step=N` against the matching migration count." Neon's 24h point-in-time restore is an emergency backstop for irreversible migrations. Capture migration counts in the deploy log. |
| AI rate-limit NFR enforcement on free tier (no Redis) | Research finding (Laravel architecture) | M | M | Implement rate-limit as a Postgres counter table on Neon: `(user_id, day, count)` with a unique constraint. Increments happen inline in the controller before the AI call. No queue or Redis needed. |
| Neon free tier limits (0.5 GB storage, 191.9 compute-hrs/mo) silently throttled | Research finding | L | M | Monitor Neon dashboard weekly during sprint. Solo MVP traffic fits free tier comfortably; if compute-hours approach the cap, upgrade to Launch ($19/mo). Storage at 0.5 GB ≈ tens of thousands of rows — far above MVP needs. |
| Decision rollback to a paid tier if free tier blocks launch | Pre-mortem (decision rollback) | M | L | The $0/mo decision is a calculated risk. If cold-start, AI-path latency, or build minutes block launch, fall back to Render Starter ($7/mo) and Background Worker ($7/mo) — total $14/mo (matches the previous Render-Postgres recommendation). The migration is a `plan: starter` line change in `render.yaml` plus adding a worker service block. < 1 hour of work. |

## Getting Started

These steps assume Laravel 13+ on PHP 8.4 (per the user's stack pick) with a **user-written custom Dockerfile**. Target deployment: **Render free Web Service** + **Neon free-tier Postgres** = **$0/mo**. The `render-examples/php-laravel-docker` repo is a reference for Laravel 11 / PHP 8.3 — useful for shape comparison, not for copy-paste.

1. **Provision the Neon Postgres project (do this BEFORE deploying to Render).**
   - Sign up at `console.neon.tech` (GitHub OAuth is fine).
   - Create a new project — **region must be `eu-central-1` (Frankfurt)** to co-locate with Render Frankfurt. Postgres major version 16 or 17.
   - Inside the project, the default `main` branch ships a `neondb` database and a role. Copy the **pooled** connection string from the dashboard (format: `postgres://user:pwd@ep-xxx-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require`). The **pooled** endpoint (with `-pooler` in the hostname) is what Laravel should use; the direct endpoint is reserved for migrations and admin work.
   - **Save the direct (non-pooler) connection string separately** — for one-off migration commands or `psql` admin work. Mark it `DATABASE_URL_DIRECT` in local `.env` if needed.

2. **Create custom Dockerfile for Laravel 13+ on PHP 8.4.** A minimal viable shape:
   ```dockerfile
   FROM serversideup/php:8.4-fpm-nginx
   ENV APP_ENV=production
   ENV APP_DEBUG=false
   ENV LOG_CHANNEL=stderr
   ENV AUTORUN_ENABLED=true
   USER root
   COPY --chown=www-data:www-data . /var/www/html/
   WORKDIR /var/www/html
   RUN composer install --no-dev --optimize-autoloader --no-interaction \
      && chown -R www-data:www-data /var/www/html
   USER www-data
   ```

3. **Configure Laravel to read `DB_URL` and use `sync` queue driver.**
   - Verify `config/database.php` has `'url' => env('DB_URL')` on the `pgsql` connection (Laravel 11+ does this by default; verify it survived to Laravel 13+).
   - Set `QUEUE_CONNECTION=database` in production env vars.
   - Set `SESSION_DRIVER=database` and `CACHE_STORE=database` (Redis is not free on Render; Neon Postgres absorbs both).
   - Run `php artisan session:table && php artisan cache:table && php artisan queue:table` locally and commit the migrations.

4. **Write `render.yaml` Blueprint at the repo root** — single service, free tier:
   ```yaml
   services:
     - type: web
       name: goaitracker-web
       runtime: docker
       plan: free
       branch: main
       region: frankfurt
       envVars:
         - key: APP_ENV
           value: production
         - key: APP_DEBUG
           value: "false"
         - key: LOG_CHANNEL
           value: stderr
         - key: DB_CONNECTION
           value: pgsql
         - key: DB_SSLMODE
           value: require
         - key: APP_KEY
           sync: false    # generate locally with `php artisan key:generate --show`, paste in dashboard
         - key: DB_URL
           sync: false    # Neon pooled connection string (set manually)
         - key: APP_URL
           sync: false    # set manually
   ```
   No `databases:` block (Neon is external). No `worker` or `cron` services (paid-only on Render — accept the $0/mo trade-off).

5. **Install the Render CLI and authenticate** (one-time, local):
   ```powershell
   iwr -useb https://raw.githubusercontent.com/render-oss/cli/main/bin/install.ps1 | iex
   render login
   ```
   Then `render services` to confirm CLI ↔ account wiring.

6. **Connect the GitHub repo to Render** via dashboard (`New + → Blueprint`). Point at the repo + branch (`main`). Render reads `render.yaml`, provisions the web service, and waits for the `sync: false` env vars. Auto-deploy-on-merge is on by default (matches `tech-stack.md` `ci_default_flow`).

7. **Set the secrets** on the web service:
   ```powershell
   # Generate APP_KEY locally first
   php artisan key:generate --show
   # Then in PowerShell:
   $APP_KEY      = "base64:..."   # paste output of key:generate --show
   $DB_URL = "postgres://user:pwd@ep-xxx-pooler.eu-central-1.aws.neon.tech/neondb?sslmode=require"
   $OPENAI_KEY   = "sk-..."

   render env set "APP_KEY=$APP_KEY"             --service goaitracker-web
   render env set "DB_URL=$DB_URL"   --service goaitracker-web
   render env set "OPENAI_API_KEY=$OPENAI_KEY"   --service goaitracker-web
   ```

8. **Trigger the first deploy** by pushing to `main` (auto-deploy) or via `render deploys create --service goaitracker-web`. The web container starts, the start script runs `php artisan migrate --force` against Neon (creating schema), then nginx + PHP-FPM take traffic. The first build will consume ~5-15 build minutes; watch the cap.

9. **Verify**:
   - Hit the web URL Render prints. **Expect a 30-60s cold-start delay on first request** if the service has been idle (this is the accepted free-tier trade-off).
   - Tail logs with `render logs --resources <web-service-id> --tail` while testing.

10. **Optional — install MCP servers for Claude Code** to enable structured agent access on both halves of the deployment:
    ```powershell
    claude mcp add render-mcp https://mcp.render.com/mcp
    claude mcp add neon-mcp -- npx -y @neondatabase/mcp-server-neon start
    ```
    (Authenticate each via OAuth.) Use the MCPs for read-only inspection — keep deploys and writes on the CLI.

11. **Optional — add a free keep-warm pinger** if cold-start becomes a UX blocker for beta users. GitHub Actions `schedule: "*/14 * * * *"` hitting a public `/health` endpoint on the Render URL keeps the web service warm 24/7 without spending $0.

## Out of Scope

The following were not evaluated in this research:
- CI/CD pipeline setup beyond Render's built-in auto-deploy-on-merge
- Production-scale architecture (multi-region, HA, DR, read replicas — Neon supports read replicas on Scale plan, but that's $69/mo and out of MVP scope)
- Vapor / Bref on AWS Lambda as a PHP-on-serverless alternative (out of MVP cost+complexity budget)
- Supabase / Aiven as Neon alternatives (Neon is the cost-floor pick; if Neon ever blocks for a technical reason, Supabase free tier is the closest functional equivalent)
