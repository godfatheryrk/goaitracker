import { test, expect } from '@playwright/test';

/**
 * RISK #2 (context/foundation/test-plan.md §2) — a successful 7-step AI response lands
 * as a usable starting plan.
 *
 * When the AI returns 7 steps, the venture detail view must render all 7 as distinct,
 * usable list items — the north-star "AI plan lands as a usable starting plan." Assert
 * the rendered STEP COUNT (not just the redirect/URL), and never hit the real provider.
 *
 * Determinism comes from the SERVER seam: a title WITHOUT the `force-ai-empty` sentinel
 * makes FakeAiStepSuggester return its fixed 7 distinct steps (see tests/e2e/README.md).
 * This couples the two body assertions below to that fixed fake payload by design.
 */
test.describe('venture create — risk #2 seven-step render', () => {
    let ventureTitle: string;

    test.afterEach(async ({ page }) => {
        if (!ventureTitle) {
            return;
        }

        await page.goto('/dashboard');

        const row = page.getByRole('listitem').filter({ hasText: ventureTitle });
        const deleteButton = row.getByRole('button', { name: 'Delete' });

        if ((await deleteButton.count()) === 0) {
            return;
        }

        page.once('dialog', (dialog) => dialog.accept());
        await deleteButton.click();
        await expect(row).toHaveCount(0);
    });

    test('a successful AI response renders all 7 steps as distinct rows', async ({ page }) => {
        // No sentinel → FakeAiStepSuggester returns its fixed list of 7 distinct steps.
        ventureTitle = `Seven step venture ${Date.now()}`;

        await page.goto('/ventures/create');

        await page.getByLabel('Title').fill(ventureTitle);
        await page
            .getByLabel('Description')
            .fill('Expect a full 7-step starting plan on the detail view.');

        await page.getByRole('button', { name: 'Create venture' }).click();

        // Wait for STATE: landed on the venture detail view.
        await page.waitForURL(/\/ventures\/\d+/);
        await expect(page.getByRole('heading', { name: ventureTitle })).toBeVisible();

        // Exactly 7 step rows render. [data-step-body] marks each step body uniquely;
        // the page carries multiple <ul>/listitems (expenses, layout), so the role
        // would be ambiguous here — this attribute is the unambiguous step locator.
        await expect(page.locator('[data-step-body]')).toHaveCount(7);

        // At least two distinct step bodies are actually visible (not just counted).
        await expect(
            page.getByText('[E2E] Define the goal and success criteria'),
        ).toBeVisible();
        await expect(
            page.getByText('[E2E] Review progress and adjust the plan'),
        ).toBeVisible();
    });
});
