import { test, expect } from '@playwright/test';

/**
 * RISK #1 (context/foundation/test-plan.md §2) — AI fails on venture-create, graceful degrade.
 *
 * When the AI step suggestion is unavailable, creating a venture must still succeed and
 * land the user on a USABLE detail view: the venture is persisted, the typed title is
 * preserved, the step list is empty, and the manual add-step path is offered. Never a
 * 500, never lost input.
 *
 * This spec asserts the DURABLE, render-derived consequences of graceful degrade — all
 * derived from the venture's own persisted state, so they are deterministic under
 * parallel workers. The ephemeral `ai_unavailable` FLASH notice is deliberately NOT
 * asserted here: flash lives for one request in the (shared, single-user) E2E session,
 * so a concurrent spec could age it out. The flash — including its exact amber copy — is
 * guarded deterministically at the Feature layer
 * (tests/Feature/Ventures/CreateVentureTest::test_creating_a_venture_succeeds_with_empty_steps_on_ai_failure).
 *
 * The failure is forced at the SERVER seam, not the browser: the title contains the
 * `force-ai-empty` sentinel, which makes FakeAiStepSuggester return [] (see
 * tests/e2e/README.md). Browser route interception cannot reach this synchronous
 * server-side call.
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

    test('forced AI failure still creates a usable venture with an empty plan', async ({ page }) => {
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

        // The typed title is preserved as the page heading (input was not lost).
        await expect(page.getByRole('heading', { name: ventureTitle })).toBeVisible();

        // The plan is empty (AI returned nothing) — the durable signal of the degrade.
        await expect(page.getByText('No steps yet.')).toBeVisible();
        await expect(page.getByText('0 of 0 completed')).toBeVisible();

        // The page stays usable: the manual add-step path is offered.
        await expect(
            page.getByRole('link', { name: '+ Add your first step' }),
        ).toBeVisible();
    });
});
