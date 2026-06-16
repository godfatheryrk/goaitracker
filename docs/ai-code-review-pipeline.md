# AI Code-Review Pipeline — setup & operations

The PR code-review pipeline (lessons M5L2 + M5L3). On every pull request to `main` it
computes the diff, runs the Claude Agent SDK reviewer against the Definition-of-Done in
[`.github/review/criteria.md`](../.github/review/criteria.md), posts a sticky comment, applies a
pass/fail label, and **blocks merge on a `fail` verdict**.

Components:

- `.github/workflows/ai-review.yml` — the consumer workflow (trigger, diff, comment, labels, gate).
- `.github/actions/ai-reviewer/` — composite action wrapping the agent.
- `packages/code-reviewer/` — the standalone Claude Agent SDK agent.
- `code-review-evals/` — promptfoo regression gate for the prompt (local; optional CI job).

## Required GitHub configuration

These are **manual, one-time** steps in the GitHub UI (Settings) — they are not, and cannot be,
created from files in the repo.

### Secrets (Settings → Secrets and variables → Actions → **Secrets**)

| Secret | Used by | Required? | Notes |
| --- | --- | --- | --- |
| `ANTHROPIC_API_KEY` | the PR review workflow (`ai-review.yml` → composite action → `review.ts`) | **Yes** — the workflow does nothing without it | Use a **console API key** (commercial terms: Anthropic does not train on your code). A subscription login does NOT work on a runner — the SDK would have no credentials. |
| `OPENROUTER_API_KEY` | the **promptfoo eval suite only** (`code-review-evals/`) | Only if you run the evals (locally, or via an optional evals CI job) | The committed PR workflow does **not** consume this. Needed when comparing models with `npx promptfoo eval`. |
| `GITHUB_TOKEN` | comment + labels in `ai-review.yml` | Auto-provided by Actions | No manual step. Scoped by the workflow's `permissions:` block (`contents: read`, `pull-requests: write`). |

### Variables (Settings → Secrets and variables → Actions → **Variables**)

| Variable | Used by | Required? | Notes |
| --- | --- | --- | --- |
| `AI_REVIEW_MODEL` | the review agent (`AI_REVIEW_MODEL` env) | Optional | Override the model **without editing files**. Unset → defaults to `claude-sonnet-4-6`. Example override: `claude-haiku-4-5`. ⚠️ See _Model choice_ below before switching — Haiku was empirically flaky at the current `maxTurns: 2` and less calibrated. |

### Labels (Issues → Labels, or `gh label create`)

`ai-cr:passed` (green), `ai-cr:failed` (red), `ai-cr:review` (blue — adding it re-triggers an
on-demand review). Create them up front so the first `gh pr edit` does not fail.

### Branch protection (Settings → Branches → Add rule for `main`)

Require the status check **`ai-code-review`** to pass before merge. This is what turns the agent's
verdict into a real merge gate. The check only appears in the picker **after** the job has run at
least once on the repo, so: push the pipeline + open a PR first, then add the rule.

## Model choice

The agent runs on **Claude Agent SDK = Anthropic models only**. "Cheaper model" therefore means a
cheaper Anthropic tier (Haiku), not the free OpenRouter models — those live exclusively in the
promptfoo eval layer.

Default is `claude-sonnet-4-6`. A local eval of `claude-haiku-4-5` on the two fixtures found it
**unreliable at `maxTurns: 2`** (intermittent `error_max_turns` — no verdict emitted) and noisier in
per-criterion scoring. The dominant cost lever is **excluding generated files from the diff** (the
workflow already drops `package-lock.json`, `*.lock`, `dist/**`, `node_modules/**`), not the model.
Keep Sonnet unless a re-validation (with a higher `maxTurns`) proves Haiku reliable.

## Local usage

```bash
# Run the agent on your working diff (uses a console key, or your Claude Code session if unset).
cd packages/code-reviewer && npm install
ANTHROPIC_API_KEY=sk-ant-... git diff origin/main...HEAD | npm run review

# Unit tests (SDK fully mocked — no live calls).
npm test

# Compare models on the fixtures (free OpenRouter models → $0).
cd ../code-review-evals && OPENROUTER_API_KEY=sk-or-... npx promptfoo@latest eval
```
