import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const BASE = process.env.E2E_BASE_URL ?? '';
const LOCAL_FIXTURE_ENABLED = process.env.M16_LOCAL_E2E === '1';
const LOCAL_PHP = process.env.M16_PHP_BINARY ?? 'php';
const LOCAL_SQLITE = resolve('.local-dev/m16-e2e.sqlite');
const LOCAL_FIXTURE = resolve('tests/e2e/fixtures/seed-m16-local-customer.php');
const CUSTOMER_EMAIL = 'm16-locale-customer@caregrid.test';
const CUSTOMER_PASSWORD = 'M16-Synthetic-Only-2026!';
const localBrowser = process.env.M16_BROWSER_EXECUTABLE
    ? { launchOptions: { executablePath: process.env.M16_BROWSER_EXECUTABLE } }
    : {};

test.use(localBrowser);
test.skip(!BASE, 'Set E2E_BASE_URL to the locally running synthetic Laravel application.');

function seedLocalCustomer(savedLocale: 'hu' | 'en'): void {
    const host = new URL(BASE).hostname;
    if (!LOCAL_FIXTURE_ENABLED || !['127.0.0.1', 'localhost', '[::1]'].includes(host)) {
        throw new Error('The M16 synthetic customer fixture is restricted to an explicitly enabled loopback test server.');
    }

    const output = execFileSync(LOCAL_PHP, ['-d', 'display_errors=0', LOCAL_FIXTURE], {
        encoding: 'utf8',
        env: {
            ...process.env,
            M16_LOCAL_E2E: '1',
            M16_FIXTURE_LOCALE: savedLocale,
            M16_LOCAL_SQLITE_PATH: LOCAL_SQLITE,
            E2E_BASE_URL: BASE,
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    if (!output.includes('M16_FIXTURE=READY')) {
        throw new Error('The local M16 synthetic customer fixture did not report ready.');
    }
}

async function chooseLanguage(page: Page, locale: 'hu' | 'en'): Promise<void> {
    const name = locale === 'hu' ? 'Magyar' : 'English';
    const group = page.getByRole('group', { name: /^(Language|Nyelv)$/ });
    const link = group.getByRole('link', { name, exact: true });

    if (await link.count()) {
        await link.first().click();
    } else {
        await group.getByRole('button', { name, exact: true }).first().click();
    }

    await expect(page.locator('html')).toHaveAttribute('lang', locale);
}

test.describe('customer HU/EN continuity without payment', () => {
    test('fresh cookie-less Hungarian visitor keeps Hungarian from homepage to login', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/hu/');
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await page.getByRole('link', { name: 'Belépés', exact: true }).first().click();
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await expect(page.getByRole('heading', { name: 'Belépés' })).toBeVisible();
    });

    test('fresh cookie-less English visitor keeps English from homepage to login', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/en/');
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await page.getByRole('link', { name: 'Login', exact: true }).first().click();
        await expect(page).toHaveURL(/\/login(?:\?|$)/);
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
        await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible();
    });

    test('chosen language continues through login, recovery, activation, and checkout display', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto('/login');
        await chooseLanguage(page, 'hu');

        await page.getByRole('link', { name: 'Elfelejtette a jelszavát?' }).click();
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await expect(page.getByRole('heading', { name: 'Jelszó visszaállítása' })).toBeVisible();

        // Synthetic invalid tokens exercise only read-only page rendering.
        await page.goto('/reset-password/m16-synthetic-invalid-token');
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await expect(page.getByRole('heading', { name: 'Új jelszó beállítása' })).toBeVisible();

        await page.goto('/account/activate/m16-synthetic-invalid-token');
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await expect(page.getByRole('heading', { name: 'Fiók aktiválása' })).toBeVisible();

        // The guest checkout is the account-registration entry point. Do not
        // submit the form or create a Stripe Checkout session.
        await page.goto('/checkout?product=wordpress&plan=basic&interval=monthly');
        await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
        await expect(page.locator('main')).toContainText('Rendelés');
        await expect(page.locator('form button[type="submit"]').last()).toBeVisible();
    });

    test('a guest marketing choice survives login while the authenticated account keeps its saved preference', async ({ browser }) => {
        test.skip(!LOCAL_FIXTURE_ENABLED, 'Enable M16_LOCAL_E2E=1 to seed the isolated local SQLite fixture.');

        for (const locale of ['hu', 'en'] as const) {
            const savedLocale = locale === 'hu' ? 'en' : 'hu';
            seedLocalCustomer(savedLocale);
            const context = await browser.newContext({ baseURL: BASE });
            const page = await context.newPage();

            await context.clearCookies();

            // The guest's explicit marketing choice carries through the
            // unprefixed login page, despite the synthetic account having a
            // different saved locale.
            await page.goto(locale === 'hu' ? '/hu/' : '/en/');
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await page.getByRole('link', { name: locale === 'hu' ? 'Belépés' : 'Login', exact: true }).first().click();
            await expect(page).toHaveURL(/\/login(?:\?|$)/);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await page.locator('input[name="email"]').fill(CUSTOMER_EMAIL);
            await page.locator('input[name="password"]').fill(CUSTOMER_PASSWORD);
            await page.locator('form button[type="submit"]').last().click();
            await page.waitForURL(/\/account(?:\/)?$/);
            await expect(page.locator('html')).toHaveAttribute('lang', savedLocale);

            // Authenticated marketing pages may use their explicit URL locale,
            // but returning to the account must use the saved preference.
            await page.goto(locale === 'hu' ? '/hu/' : '/en/');
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await page.goto('/account/websites');
            await expect(page.locator('html')).toHaveAttribute('lang', savedLocale);
            await expect(page.locator('h1')).toContainText(savedLocale === 'hu' ? 'Weboldalak' : 'Websites');

            const persistedLocale = execFileSync(LOCAL_PHP, ['-d', 'display_errors=0', '-r', '$p=new PDO("sqlite:".getenv("M16_LOCAL_SQLITE_PATH")); echo $p->query("SELECT locale FROM users WHERE email = \'m16-locale-customer@caregrid.test\'")->fetchColumn();'], {
                encoding: 'utf8',
                env: { ...process.env, M16_LOCAL_SQLITE_PATH: LOCAL_SQLITE },
                stdio: ['ignore', 'pipe', 'pipe'],
            }).trim();
            expect(persistedLocale).toBe(savedLocale);

            await context.close();
        }
    });
});
