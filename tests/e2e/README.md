# E2E tests (Playwright)

Browser-level tests that drive a **running** Laravel app. The harness is deterministic
(fake AI), isolated (own DB), and authenticated once (storageState). Model every new
spec on [`seed.spec.ts`](./seed.spec.ts) — it is the load-bearing exemplar.

## Rules (defer to the E2E block in the root `CLAUDE.md` / `/10x-e2e`)

- **Locators**: `getByRole` / `getByLabel` / `getByText` first; `getByTestId` only when
  accessibility attributes are ambiguous. Never CSS selectors, XPath, or DOM structure.
- **Never `page.waitForTimeout()`.** Wait for state: `toBeVisible()`, `waitForURL()`,
  `waitForResponse()`.
- **Per-test isolation + cleanup.** Each test owns its setup, action, assertion, and
  cleanup. Use a unique id (timestamp suffix) on created data so parallel workers and
  re-runs never collide; delete what you create in an `afterEach`.
- **Risk-named tests.** A risk spec carries a provenance header citing the
  `context/foundation/test-plan.md` risk it defends; assert the protected *behaviour*,
  not just the redirect/URL.

## The fake-AI seam (server-side — browser interception cannot reach it)

The LLM call is **server-side and synchronous** (`VenturesController::store` →
`AiStepSuggester::suggestSteps` → Groq, on the request thread). Playwright's
`page.route()` only intercepts browser→app traffic, so it **cannot** mock the app→Groq
call. The seam is therefore in the app, gated by an env flag:

- `E2E_FAKE_AI=1` (set by `playwright.config.ts` → `webServer.env`) makes
  `AppServiceProvider` bind [`FakeAiStepSuggester`](../../app/Services/FakeAiStepSuggester.php)
  in place of the real suggester. No network, no `AiCallCounter`, fully deterministic.
- Behaviour is keyed on the **venture title**:
  - a title containing the case-insensitive sentinel **`force-ai-empty`** → returns `[]`
    (drives the graceful-degrade path, risk #1);
  - any other title → returns a fixed list of **7 distinct** steps (risk #2).
- The production binding is untouched; the fake is dormant unless the flag is set.

## Conventions

- **Dedicated server + DB.** The suite boots its own server on **port 8001** against an
  isolated **`database/e2e.sqlite`** (git-ignored), so an E2E run never touches the
  developer's `database/database.sqlite`. `global-setup.ts` runs
  `php artisan migrate:fresh --seed` against that file before the suite.
- **Auth via storageState.** The `setup` project (`auth.setup.ts`) logs in once as the
  seeded `rafal@test.local / password123` and saves the session; the `chromium` project
  reuses it. No test logs in through the UI.
- **Deleting through the UI needs `dialog.accept()`.** The venture-delete form (on the
  dashboard row) uses a native `confirm()`. Playwright auto-**dismisses** dialogs, so
  register `page.once('dialog', d => d.accept())` *before* clicking Delete — see the
  `afterEach` in `seed.spec.ts`.

## Running

```bash
npm run test:e2e                       # whole suite
npx playwright test seed.spec.ts       # a single spec
```
