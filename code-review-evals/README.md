# code-review-evals

promptfoo regression gate for the AI code-review **prompt + criteria**. It runs the same DoD criteria
(`.github/review/criteria.md`) that the production agent uses, across **3 free OpenRouter models**, on
two prepared fixtures — a planted per-user-isolation leak (must FAIL) and a clean idiomatic diff (must
PASS). This guards against silent prompt-quality regressions; it is NOT the production runtime.

## Run

```bash
cd code-review-evals
export OPENROUTER_API_KEY=sk-or-...
npx promptfoo@latest eval     # builds the pass/fail × model matrix
npx promptfoo@latest view     # open the results UI
```

## Files

- `promptfooconfig.yaml` — providers (free `:free` slugs), tests, assertions.
- `prompts/review.txt` — the prompt under test (`{{criteria}}` + `{{diff}}`), JSON-only output.
- `fixtures/isolation-leak.diff` — bare `Venture::with(...)->findOrFail()` instead of `$request->user()->ventures()`.
- `fixtures/clean.diff` — a FormRequest length cap + its matching feature test.

## Free-tier caveats

Free models have tight rate limits (occasional `429`) and weaker JSON discipline. If a run is flaky,
relax the threshold (`PROMPTFOO_PASS_RATE_THRESHOLD=80 npx promptfoo eval`) or swap a model for a
current `:free` slug from <https://openrouter.ai/models?max_price=0>.
