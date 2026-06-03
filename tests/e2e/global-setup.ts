import { execFile } from 'node:child_process';
import { existsSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Builds a fresh, migrated, seeded E2E SQLite database BEFORE any test (and before
 * the webServer serves a request). Runs against the isolated `database/e2e.sqlite`
 * file — never the developer's `database/database.sqlite` — so an E2E run leaves the
 * dev DB untouched. The seed includes the `rafal@test.local` auth user that
 * `auth.setup.ts` logs in as. Rejects on a non-zero exit so a bad DB fails loudly.
 */
export default async function globalSetup(): Promise<void> {
    const dbPath = path.resolve(dirname, '../../database/e2e.sqlite');

    // artisan's sqlite driver expects the file to exist before it connects.
    if (!existsSync(dbPath)) {
        writeFileSync(dbPath, '');
    }

    await execFileAsync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], {
        cwd: path.resolve(dirname, '../..'),
        env: {
            ...process.env,
            APP_ENV: 'local',
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: dbPath,
        },
    });
}
