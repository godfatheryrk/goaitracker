import { test as setup, expect } from '@playwright/test';

const authFile = 'playwright/.auth/user.json';

/**
 * Logs in once through the real /login form (so Laravel CSRF is handled for us) and
 * saves the authenticated session to `authFile`. The chromium project depends on this
 * and reuses the saved state via `storageState`, so no test logs in through the UI.
 *
 * Requires the dev user rafal@test.local / password123 in the dev SQLite DB.
 */
setup('authenticate', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('rafal@test.local');
    await page.getByLabel('Password').fill('password123');
    await page.getByRole('button', { name: /log in/i }).click();

    // Cookies are only set after the post-login redirect completes.
    await page.waitForURL('**/dashboard');
    await expect(page).toHaveURL(/.*dashboard/);

    await page.context().storageState({ path: authFile });
});
