import { defineConfig, devices } from '@playwright/test';

const root = process.cwd();

export default defineConfig({
    testDir: './tests/e2e',
    testMatch: ['connector-browser.spec.ts', 'localization.spec.ts', 'security-boundaries.spec.ts'],
    timeout: 180_000,
    expect: { timeout: 12_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    outputDir: `${root}/storage/e2e/browser-artifacts`,
    use: {
        baseURL: 'http://127.0.0.1:8317',
        locale: 'en-GB',
        trace: 'off',
        screenshot: 'off',
        video: 'off',
        ...devices['Desktop Chrome'],
        launchOptions: {
            executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
            headless: true,
        },
    },
});
