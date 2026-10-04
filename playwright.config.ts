import { defineConfig, devices } from '@playwright/test';

/**
 * Browser E2E against a running WP CareGrid with Stripe in TEST MODE and a
 * Mailpit inbox (docs/STRIPE_TEST_MODE.md). Not part of CI: it needs a
 * public HTTPS address for Stripe webhooks and Stripe test keys.
 *
 *   E2E_BASE_URL=https://<public test address> MAILPIT_URL=http://127.0.0.1:8025 npx playwright test
 */
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 240_000,
    expect: { timeout: 20_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    outputDir: 'storage/playwright',
    use: {
        baseURL: process.env.E2E_BASE_URL,
        locale: 'en-GB',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
    },
});
