import { test, expect } from '@playwright/test';

/**
 * RISK #1 (context/foundation/test-plan.md §2) — AI fails on venture-create, graceful degrade.
 *
 * When the AI step suggestion is unavailable, creating a venture must still succeed:
 * the venture is persisted, an AMBER (not red) "add them manually" notice shows, the
 * typed title is preserved, and the page stays usable (the manual add-step path is
 * offered). Never a 500, never a red error, never lost input.
 *
 * The failure is forced at the SERVER seam, not the browser: the title contains the
 * `force-ai-empty` sentinel, which makes FakeAiStepSuggester return [] (see
 * tests/e2e/README.md). Browser route interception cannot reach this synchronous
 * server-side call.
 *
 * Asserts the graceful-degrade BEHAVIOUR (notice + preserved title + usable page),
 * NOT merely the redirect/URL.
 */
test.describe('venture create — risk #1 AI-graceful degrade', () => {
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

    test('forced AI failure still creates the venture and degrades gracefully', async ({ page }) => {
        // Sentinel substring forces FakeAiStepSuggester → [] (the AI-unavailable path).
        ventureTitle = `force-ai-empty venture ${Date.now()}`;

        await page.goto('/ventures/create');

        await page.getByLabel('Title').fill(ventureTitle);
        await page
            .getByLabel('Description')
            .fill('Should still save with an empty step list when AI is down.');

        await page.getByRole('button', { name: 'Create venture' }).click();

        // Wait for STATE: the venture was created and we landed on its detail view.
        await page.waitForURL(/\/ventures\/\d+/);

        // (a) The amber notice is visible, by role + exact text.
        await expect(page.getByRole('alert')).toHaveText(
            "AI couldn't suggest steps right now — you can add them manually.",
        );

        // (b) The typed title is preserved as the page heading (input was not lost).
        await expect(page.getByRole('heading', { name: ventureTitle })).toBeVisible();

        // (c) The page is usable: the empty-list manual add-step affordance is offered.
        await expect(
            page.getByRole('link', { name: '+ Add your first step' }),
        ).toBeVisible();
    });
});
