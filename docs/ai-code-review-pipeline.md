# AI Code-Review Pipeline — setup & operations

The PR code-review pipeline (lessons M5L2 + M5L3). It runs **on demand** — when the `ai-cr:review`
label is added to a pull request to `main` — computes the diff, runs the Claude Agent SDK reviewer
against the Definition-of-Done in [`.github/review/criteria.md`](../.github/review/criteria.md), posts
a sticky comment, and applies a pass/fail label. It is **advisory: it does not block merge** (the job
stays green regardless of verdict and is not a required status check).

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

### Branch protection — intentionally NOT required

By design this check is **advisory and on-demand**, so `ai-code-review` is **not** added to the
branch's required status checks — leaving it out is what keeps it non-blocking. To turn it into a
hard merge gate later: (1) in `ai-review.yml` restore the failing gate (`test "$verdict" = pass`),
(2) re-add `synchronize`/`opened` triggers if you want it automatic, and (3) add `ai-code-review` to
`main`'s required status checks (the check appears in the picker only after the job has run once).

## Failure behaviour

If the review cannot run — a missing/empty `ANTHROPIC_API_KEY` (caught by a preflight step before
checkout), an invalid key, an agent error, or the 10-minute timeout — the job ends **red**, posts an
`error` sticky comment on the PR pointing at the run logs, and **removes the `ai-cr:review` label** so
it does not silently stick. No verdict label is set, and merge is unaffected (the check is advisory).
Re-add `ai-cr:review` to retry once the cause is fixed.

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
