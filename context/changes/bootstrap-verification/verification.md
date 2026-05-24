---
bootstrapped_at: 2026-05-24T13:19:00Z
starter_id: laravel
starter_name: Laravel
project_name: goaitracker
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: verified
phase_3_status: ok
audit_command: null
---

## Hand-off

```yaml
starter_id: laravel
package_manager: composer
project_name: goaitracker
hints:
  language_family: php
  team_size: solo
  deployment_target: render
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: verified
  path_taken: standard
  quality_override: false
  self_check_answers: null
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: true
  has_background_jobs: false
```

### Why this stack

A solo builder shipping GOAITracker on a 3-week after-hours budget with a hard deadline of 2026-07-04 needs a batteries-included framework that absorbs auth (FR-001 / FR-002 / FR-003), per-user data isolation, venture/step/expense CRUD, and a small AI integration without forcing scaffolding work. Laravel is the recommended default for `(web, php)` and clears three of the four agent-friendly gates (convention-based, popular in training data, well-documented); the typed gate fails at the language level — PHP is dynamically typed — which is the one known friction point and surfaces as a heads-up rather than a blocker for a solo MVP. Bootstrapper confidence is verified. Render is the deployment target (Heroku-style DX, PostgreSQL add-on, clean GitHub Actions integration); auto-deploy-on-merge keeps the loop tight. AI step suggestion (FR-008 / FR-009) is wired up via Laravel's HTTP client to an external LLM provider — picked downstream, not in PRD.

## Pre-scaffold verification

| Signal      | Value   | Severity | Notes                                                                              |
| ----------- | ------- | -------- | ---------------------------------------------------------------------------------- |
| npm package | not run | n/a      | Laravel's cmd_template invokes `composer create-project`, not an npm CLI           |
| GitHub repo | not run | n/a      | card.docs_url is `https://laravel.com/docs`, not a `github.com/<owner>/<repo>` URL |

No automated recency signal was available for this starter. Laravel's `bootstrapper_confidence: verified` in the registry already indicates the card is battle-tested.

## Scaffold log

**Resolved invocation**: `composer create-project laravel/laravel .bootstrap-scaffold --no-interaction --prefer-dist`
**Strategy**: subdir-then-move
**Exit code**: 0
**Files moved**: 23
**Conflicts (.scaffold siblings)**: none
**.gitignore handling**: moved silently (no pre-existing `.gitignore` in cwd)
**.bootstrap-scaffold cleanup**: deleted

**Moved entries** (alphabetical, dotfiles first):
`.editorconfig`, `.env`, `.env.example`, `.gitattributes`, `.gitignore`, `.npmrc`, `README.md`, `app/`, `artisan`, `bootstrap/`, `composer.json`, `composer.lock`, `config/`, `database/`, `package.json`, `phpunit.xml`, `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `vendor/`, `vite.config.js`

**Preserved cwd entries** (not overwritten by scaffold; none collided): `.claude/`, `.idea/`, `CLAUDE.md`, `context/`, `idea-notes.md`.

**Toolchain prerequisite resolved mid-run**: the first `composer create-project` invocation exited with status 0 but printed a fatal "openssl extension is required" error to stderr and produced no `.bootstrap-scaffold/` directory — a silent failure caused by every dynamic PHP extension being disabled in `F:\web\server\php\php.ini`. With the user's explicit consent, bootstrapper uncommented the following lines in that file before retrying:

- `extension_dir = "ext"` (line 766) — required so PHP can locate DLLs in `F:\web\server\php\ext\`
- `extension=curl` (919), `extension=fileinfo` (922), `extension=intl` (926), `extension=mbstring` (928), `extension=openssl` (932), `extension=pdo_pgsql` (936), `extension=pdo_sqlite` (937), `extension=sodium` (947), `extension=zip` (951)

The retry then succeeded. Composer-side telemetry from the successful run: Laravel framework v13.11.2 installed, application key generated, `database/database.sqlite` created, 3 initial migrations applied (`create_users_table`, `create_cache_table`, `create_jobs_table`), 78 packages soliciting funding (informational only).

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool for php in bootstrapper v1 (`audit_commands[php] = null` in `references/bootstrapper-config.yaml`)
**Recommended external tool**: `composer audit` (built into Composer 2.4+). To run manually: `composer audit --locked` from the project root. Composer's own create-project security check ran inline during install and reported "No security vulnerability advisories found." for the installed dependency tree.

## Hints recorded but not acted on

| Hint                       | Value             |
| -------------------------- | ----------------- |
| bootstrapper_confidence    | verified          |
| quality_override           | false             |
| path_taken                 | standard          |
| self_check_answers         | null              |
| team_size                  | solo              |
| deployment_target          | render            |
| ci_provider                | github-actions    |
| ci_default_flow            | auto-deploy-on-merge |
| has_auth                   | true              |
| has_payments               | false             |
| has_realtime               | false             |
| has_ai                     | true              |
| has_background_jobs        | false             |

These were copied verbatim from the hand-off. v1 surfaces them in this log; the future M1L4 ("Memory Architecture") skill will consume them to generate agent-context files (`CLAUDE.md`, `AGENTS.md`) and CI scaffolding.

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git init` (if you have not already) to start your own repo history.
- Review any `.scaffold` siblings the conflict policy created and decide which version of each file to keep. (This run created none.)
- Address audit findings per your project's risk tolerance — the full breakdown is in this log. (This run had no programmatic audit; Composer's inline advisory check found nothing.)
- Run `composer audit --locked` periodically as a manual replacement for the missing PHP audit slot.
- Since the deployment target is Render with PostgreSQL: when you're ready, swap `DB_CONNECTION=sqlite` in `.env` for the Render-provided Postgres credentials (the `pdo_pgsql` extension is already enabled).
