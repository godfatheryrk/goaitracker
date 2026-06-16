# @goaitracker/code-reviewer

Standalone code-review agent built on the **Claude Agent SDK** (`@anthropic-ai/claude-agent-sdk`).
Reads a `git diff`, scores it against the Definition-of-Done criteria in
[`.github/review/criteria.md`](../../.github/review/criteria.md), and returns a structured JSON
verdict. Wrapped for CI by the composite action at `.github/actions/ai-reviewer/`.

Part of the 10xChampion CI/CD code-review pipeline (lessons M5L2 + M5L3).

## Install

```bash
cd packages/code-reviewer
npm install
```

## Run locally

Pipe a diff to the agent (auth via a **console** `ANTHROPIC_API_KEY` — not a subscription login):

```bash
ANTHROPIC_API_KEY=sk-ant-... git diff origin/main...HEAD | npm run review
```

Or point it at a diff file:

```bash
git diff origin/main...HEAD > pr.diff
ANTHROPIC_API_KEY=sk-ant-... DIFF_FILE=pr.diff npm run review
```

The full JSON verdict is printed to **stdout**; cost/turn telemetry goes to **stderr**. Set
`REPORT_FILE=review.json` to also persist the verdict.

## Environment

| Variable | Default | Purpose |
| --- | --- | --- |
| `ANTHROPIC_API_KEY` | — (required) | Console API key. In CI it MUST be explicit (the SDK otherwise falls back to a subscription login that does not exist on a runner). |
| `DIFF_FILE` | stdin | Path to the unified diff. Falls back to stdin. |
| `CRITERIA_FILE` | `$GITHUB_WORKSPACE/.github/review/criteria.md` or repo-relative | DoD criteria (single source of truth). |
| `REPORT_FILE` | — | If set, the JSON verdict is also written here. |
| `PR_TITLE` / `PR_BODY` | — | Optional PR context folded into the prompt. |
| `AI_REVIEW_MODEL` | `claude-sonnet-4-6` | Model id. |
| `AI_REVIEW_MAX_BUDGET_USD` | `0.5` | Hard per-run budget cap (`maxBudgetUsd`). |

## Output contract

See the "Output contract" block in `.github/review/criteria.md` — the agent's
`outputFormat` schema is derived from the same shape (`src/review-schema.ts`).
