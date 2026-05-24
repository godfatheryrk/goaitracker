# Pierwsze wdrożenie GOAITracker → Render + Neon

> Plan-mode deploy plan per lesson 5 (`AGENTS.md`). Authored 2026-05-24. To be approved before execution; this file is the audit trail of "what was supposed to happen."

## Context

First production deploy of the Laravel 13.8 / PHP 8.4 GOAITracker skeleton onto the platform chosen in `context/foundation/infrastructure.md` (Render free Web Service + Neon free Postgres, both in `eu-central-1`/Frankfurt, total cost $0/mo). The Dockerfile, `render.yaml`, `.dockerignore`, `.env.example`, Laravel `pgsql`/`database`-queue/`database`-session/`database`-cache configuration and the required `sessions`/`cache`/`jobs` migrations are already in place. The Neon project has also been provisioned (its direct URL is sitting commented in `.env:67`).

What this first deploy delivers: a publicly reachable Laravel welcome page on a Render URL, schema migrated into Neon, auto-deploy-on-push wired to `main`. It does NOT deliver FR-008/FR-009 AI features — the AI provider hasn't been picked yet (`tech-stack.md` defers that downstream); we only stub the env-var slot so the wiring is ready when the integration lands.

Three small drifts from `infrastructure.md` need fixing before push: `render.yaml` uses legacy `env: docker` instead of current `runtime: docker`, lacks the production env vars that match local `.env` (SESSION/CACHE/QUEUE drivers, locale, log level), and omits the `OPENAI_API_KEY` slot. The Neon URL in `.env:67` is the direct (non-pooled) endpoint — Laravel must use the pooled one (`-pooler` in hostname) to survive Render's concurrency.

## Already done — skip during execution

- Laravel 13.8 + PHP 8.4 app bootstrapped (`composer.json`, `artisan`, full `app/`/`bootstrap/`/`config/`/`routes/`/`public/`)
- `Dockerfile` (uses `serversideup/php:8.4-fpm-nginx`, `AUTORUN_ENABLED=true` runs migrations on container start)
- `render.yaml`, `.dockerignore`, `.env.example` present
- Laravel config: `config/database.php` pgsql connection uses `env('DB_URL')` + `env('DB_SSLMODE', 'prefer')`; queue/session/cache default to `database` driver via `.env`
- Migrations present: `users`+`sessions`, `cache`+`cache_locks`, `jobs`+`job_batches`+`failed_jobs`, `password_reset_tokens`
- Neon project exists in `eu-central-1` (visible from URL host in `.env:67`)
- Render account + Render CLI authenticated locally (per user confirmation)
- GitHub repo exists (URL provided by user at execution time)

## Plan

### A. Local edits (the only code touched by this deploy)

**A1. Update `./render.yaml`** to fix syntax drift and add missing prod env vars. Replace `env: docker` with `runtime: docker`; under `envVars` add `OPENAI_API_KEY` with `sync: false`. Keep `healthCheckPath: /` — the welcome view returns 200; a dedicated `/health` JSON endpoint is a v2 polish item, not blocking.

**A2. Update the comment in `./.env:67`** to use the pooled Neon URL format as documentation (the `-pooler` hostname). This is comment-only; local dev stays on SQLite (`DB_CONNECTION=sqlite` at line 23). Format reference:

```
# postgresql://neondb_owner:<pwd>@ep-falling-paper-al0aibtw-pooler.c-3.eu-central-1.aws.neon.tech/neondb?sslmode=require
```

**A3. Do not modify `Dockerfile`, `.dockerignore`, `.env.example`, or any Laravel config** — they are correct for this deploy shape.

### B. Local Postgres smoke test (optional, recommended)

Run migrations against Neon **once locally** to catch any SQLite→Postgres dialect quirks before they explode in Render build logs:

1. In Neon dashboard, create a branch off `main` named `local-smoketest` (free, copy-on-write — keeps prod schema clean).
2. Temporarily flip local `.env`: comment out `DB_CONNECTION=sqlite`, set `DB_CONNECTION=pgsql`, paste pooled URL of the smoketest branch as `DB_URL=...`.
3. Run `php artisan migrate --pretend` first (dry-run), then `php artisan migrate`.
4. Revert `.env` to SQLite. Optionally delete the Neon branch (human gate — destructive on Neon).

This step covers the "Local Postgres testing gap" surfaced in the audit and risk #5 from `infrastructure.md`.

### C. Git init + first push (project is not yet under git)

1. `git -C "." init`
2. `git -C "." branch -M main`
3. `git -C "." add .` (`.env` is gitignored at `.gitignore:3`, safe)
4. `git -C "." status` — VERIFY `.env` is NOT in the staged list before commit (human gate)
5. `git -C "." commit -m "initial commit: Laravel 13.8 skeleton, Render+Neon deployment artifacts"`
6. `git -C "." remote add origin <github-repo-url>` — user supplies URL at execution time
7. `git -C "." push -u origin main` (human runs this; agent does not push without explicit go-ahead)

### D. Grab the pooled Neon connection string (manual, ~1 min)

Open `console.neon.tech` → project → Connection Details → toggle "Pooled connection" → copy. Confirm region is `eu-central-1`. Save for step F.

### E. Generate production APP_KEY (one-time)

```powershell
php artisan key:generate --show
```

Copy the `base64:...` output. Do NOT modify local `.env` (different key for prod is normal and fine).

### F. Render Blueprint creation (manual gate — Render dashboard)

1. Render dashboard → **New +** → **Blueprint**.
2. Select the GitHub repo `goaitracker` → branch `main`.
3. Render parses `render.yaml`, prepares the `goaitracker` web service, prompts for the four `sync: false` env vars:
    - `APP_KEY` = base64 string from step E
    - `DB_URL` = pooled Neon URL from step D
    - `APP_URL` = placeholder `https://goaitracker.onrender.com` for now; update after first deploy with the real assigned URL if it differs
    - `OPENAI_API_KEY` = leave blank for now (will be set when FR-008/009 AI work begins)
4. Click **Apply**. Render queues the first build.

### G. First deploy — automatic after Apply

Render builds the Docker image (~5–15 build minutes — watch the 500-min/mo cap), pushes to its registry, starts the container. `AUTORUN_ENABLED=true` in the serversideup base image triggers `php artisan migrate --force` on boot before nginx+PHP-FPM take traffic. No manual deploy command needed for this first one.

### H. Verification

1. `render services` — confirm `goaitracker` exists; copy its service ID.
2. `render logs --resources <service-id> --tail --output text` — watch boot. Expect: composer cached, migrations announced + run cleanly against Neon, nginx ready on port 8080.
3. Open the assigned URL in a browser. **Expect 30–60s cold start** (free-tier sleep — documented trade-off). Should render Laravel welcome page (200 OK).
4. Neon dashboard → Tables — confirm `users`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`, `password_reset_tokens` exist.
5. Smoke test: visit `/` → 200; visit `/does-not-exist` → 404 (not 500 — confirms exception handler works without DEBUG).
6. Update `APP_URL` in Render dashboard if the assigned URL differs from the placeholder; force a redeploy (`render deploys create --service <service-id>`) for the new value to be picked up.
7. Re-deploy regression test: push a no-op commit to `main` (e.g. update a comment), confirm auto-deploy triggers within ~30s and completes successfully.

### I. Finalize this file as the audit trail

After execution, append a brief "Execution log" section to this file capturing:

- Actual service ID, assigned URL, Neon project ID
- Build duration + first cold-start duration measured
- Any deviations from the plan above and the reason
- Explicit "secret-rotation surface" note: only the `goaitracker` web service holds `DB_URL` and `APP_KEY`; if a Background Worker is ever added (paid Starter), revisit `infrastructure.md` Risk Register row "Neon password rotation forgets a service"

## Critical files

- `./render.yaml` — edits in A1 (runtime syntax + missing prod env vars + OPENAI_API_KEY slot)
- `./.env` — line 67 comment update only (A2)
- `./deployment\deploy-plan.md` — this file (extended in I post-execution)

No Laravel code, no Dockerfile, no migrations, no composer.json changes for this deploy.

## Human gates (per AGENTS.md production-access boundary)

- Step B step 4 — deleting a Neon branch (destructive on Neon)
- Step C step 4 — verifying `git status` before commit (catch any accidental `.env` staging)
- Step C step 7 — `git push origin main` (the user runs this; agent does not push for them)
- Step F — Render dashboard Blueprint apply + env var paste (one-time, browser)
- Step H step 6 — Render env var update + force redeploy

## Verification (end-to-end happy path)

The deploy is successful when **all** of the following are true:

1. `git push origin main` triggers a Render build that completes without error.
2. Browser request to the assigned Render URL returns the Laravel welcome page (200 OK) after the cold-start wait.
3. Neon dashboard shows the nine tables listed in H4.
4. `render logs --tail` shows no error-level lines during boot.
5. A subsequent no-op `git push` triggers auto-deploy within ~30s and completes successfully.
6. Section I of this file is filled in with the execution log.

## Out of scope for this first deploy

- Wiring an OpenAI / LLM provider for FR-008/009 (deferred — AI provider not picked yet)
- Implementing the AI usage NFR rate-limit table (deferred — no AI calls yet)
- Building a dedicated `/health` JSON endpoint (v2 polish; `/` welcome is fine for now)
- Adding a GitHub Actions keep-warm pinger (only needed if/when cold-start becomes a UX blocker for beta users — `infrastructure.md` risk register row 1 mitigation)
- Installing Render/Neon MCP servers (`infrastructure.md` step 10 — optional follow-up; CLI is sufficient for v1)
- Capturing `context/deployment/secret-rotation.md` runbook (write it when a paid-tier service is added; rotation surface is exactly one service on free tier)
