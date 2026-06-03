import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * E2E config for GOAITracker.
 * - The `setup` project logs in once and saves the session to
 *   `playwright/.auth/user.json`; every other project reuses it via `storageState`,
 *   so tests never log in through the UI per-test.
 * - `globalSetup` builds a fresh, seeded `database/e2e.sqlite` before anything runs.
 * - `webServer` boots a DEDICATED server on its own port (8001) that always runs with
 *   the fake AI (`E2E_FAKE_AI`) and the isolated E2E DB. The dedicated port keeps it
 *   from ever reusing a developer's `composer run dev` server (which lacks the flag
 *   and the isolated DB → real Groq + dev-DB pollution).
 */

// Absolute path so the booting server and globalSetup agree on the same DB file.
const e2eDatabase = path.resolve(dirname, 'database/e2e.sqlite');

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: 'html',

    use: {
        // 127.0.0.1 (not localhost): `php artisan serve` binds IPv4 only, while Node
        // resolves `localhost` to IPv6 (::1) first on Windows, so the readiness probe
        // and tests must hit the IPv4 address explicitly.
        baseURL: 'http://127.0.0.1:8001',
        trace: 'on-first-retry',
    },

    projects: [
        { name: 'setup', testMatch: /.*\.setup\.ts/ },

        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/user.json',
            },
            dependencies: ['setup'],
        },
    ],

    webServer: {
        command: 'php artisan serve --port=8001',
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        // Surface artisan errors (e.g. a 500 on the readiness probe) in CI logs
        // instead of an opaque "Timed out waiting from config.webServer".
        stderr: 'pipe',
        env: {
            E2E_FAKE_AI: '1',
            APP_ENV: 'local',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: e2eDatabase,
        },
    },
});
