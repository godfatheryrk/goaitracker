import { test, expect } from '@playwright/test';

/**
 * Proves the saved storageState is injected: an authenticated session reaches
 * /dashboard instead of bouncing to /login. This is the harness check for the auth
 * config itself — the real risk specs (from context/foundation/test-plan.md) come
 * next via /10x-e2e.
 */
test('authenticated session reaches the dashboard', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/.*dashboard/);
});
