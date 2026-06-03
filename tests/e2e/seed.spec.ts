import { test, expect } from '@playwright/test';

/**
 * SEED EXEMPLAR — copy this file's shape for every new E2E spec.
 *
 * It demonstrates the four non-negotiable patterns (see tests/e2e/README.md and the
 * E2E block in the root CLAUDE.md):
 *   1. Locators by role / label / text — never CSS, XPath, or DOM structure.
 *   2. Per-test isolation + cleanup — a unique timestamp-suffixed title, and an
 *      afterEach that deletes the row it created so re-runs and parallel workers
 *      never collide.
 *   3. Wait for STATE, never time — page.waitForURL / toBeVisible, never
 *      page.waitForTimeout.
 *   4. Auth via storageState — the session is injected by the `setup` project
 *      (auth.setup.ts); no test logs in through the UI.
 *
 * Deleting a venture goes through the dashboard row's Delete button, whose form uses
 * a native confirm(). Playwright auto-DISMISSES dialogs, so we must register a
 * one-shot dialog.accept() BEFORE clicking, or the delete silently no-ops.
 */
test.describe('venture create — seed exemplar', () => {
    // Unique per test run so parallel workers and re-runs never share a title.
    let ventureTitle: string;

    test.afterEach(async ({ page }) => {
        if (!ventureTitle) {
            return;
        }

        await page.goto('/dashboard');

        const row = page.getByRole('listitem').filter({ hasText: ventureTitle });
        const deleteButton = row.getByRole('button', { name: 'Delete' });

        // Guard: if the test bailed before creating the venture, there's nothing to clean.
        if ((await deleteButton.count()) === 0) {
            return;
        }

        page.once('dialog', (dialog) => dialog.accept());
        await deleteButton.click();

        // Wait for STATE: the row is gone once the delete redirect re-renders the list.
        await expect(row).toHaveCount(0);
    });

    test('creates a venture and lands on its detail view', async ({ page }) => {
        ventureTitle = `Seed venture ${Date.now()}`;

        await page.goto('/ventures/create');

        await page.getByLabel('Title').fill(ventureTitle);
        await page
            .getByLabel('Description')
            .fill('A short description so the venture saves cleanly.');

        await page.getByRole('button', { name: 'Create venture' }).click();

        // Wait for STATE: redirect to the venture detail URL (id is server-assigned).
        await page.waitForURL(/\/ventures\/\d+/);

        // The detail view renders the title as the page heading.
        await expect(page.getByRole('heading', { name: ventureTitle })).toBeVisible();
    });
});
