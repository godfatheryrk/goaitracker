---
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
---

## Why this stack

A solo builder shipping GOAITracker on a 3-week after-hours budget with a hard deadline of 2026-07-04 needs a batteries-included framework that absorbs auth (FR-001 / FR-002 / FR-003), per-user data isolation, venture/step/expense CRUD, and a small AI integration without forcing scaffolding work. Laravel is the recommended default for `(web, php)` and clears three of the four agent-friendly gates (convention-based, popular in training data, well-documented); the typed gate fails at the language level — PHP is dynamically typed — which is the one known friction point and surfaces as a heads-up rather than a blocker for a solo MVP. Bootstrapper confidence is verified. Render is the deployment target (Heroku-style DX, PostgreSQL add-on, clean GitHub Actions integration); auto-deploy-on-merge keeps the loop tight. AI step suggestion (FR-008 / FR-009) is wired up via Laravel's HTTP client to an external LLM provider — picked downstream, not in PRD.
