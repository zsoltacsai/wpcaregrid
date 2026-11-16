import { defineConfig, devices } from '@playwright/test';

const configuredUrl = process.env.E2E_BASE_URL;
const target = configuredUrl ? new URL(configuredUrl) : null;

/**
 * Read-only acceptance checks for a verified marketing_only application.
 *
 * Local CI example:
 *   E2E_BASE_URL=http://127.0.0.1:8000 npx playwright test -c playwright.marketing-acceptance.config.ts
 * Remote acceptance example (only after independently verifying the target
 * release is configured with the marketing_only profile):
 *   E2E_BASE_URL=https://staging.example.test npx playwright test -c playwright.marketing-acceptance.config.ts
 *
 * This suite never submits forms or calls mutating APIs. Remote HTTPS/header
 * assertions run only when E2E_BASE_URL is HTTPS.
 */
export default defineConfig({
    testDir: 'tests/acceptance',
    testMatch: '**/*.spec.ts',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: true,
    workers: 2,
    retries: 0,
    reporter: 'list',
    outputDir: process.env.PLAYWRIGHT_OUTPUT_DIR ?? 'storage/playwright/marketing-acceptance',
    use: {
        baseURL: process.env.E2E_BASE_URL,
        locale: 'en-GB',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        // The synthetic sandbox allows IPv4 loopback only. Keep the public
        // Host header as `localhost` while ensuring Chromium does not prefer
        // the filtered ::1 address when resolving the local test server.
        launchOptions: {
            args: target?.hostname === 'localhost'
                ? ['--host-resolver-rules=MAP localhost 127.0.0.1']
                : [],
        },
        ...devices['Desktop Chrome'],
    },
});
