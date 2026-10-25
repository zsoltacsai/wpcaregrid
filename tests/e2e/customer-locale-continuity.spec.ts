import { expect, test, type Page } from '@playwright/test';

const BASE = process.env.E2E_BASE_URL ?? '';
const CUSTOMER_EMAIL = process.env.E2E_CUSTOMER_EMAIL ?? '';
const CUSTOMER_PASSWORD = process.env.E2E_CUSTOMER_PASSWORD ?? '';
const localBrowser = process.env.M16_BROWSER_EXECUTABLE
    ? { launchOptions: { executablePath: process.env.M16_BROWSER_EXECUTABLE } }
    : {};

test.use(localBrowser);
test.skip(!BASE, 'Set E2E_BASE_URL to the locally running synthetic Laravel application.');

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

    test('a saved customer preference follows login into the account and onboarding', async ({ browser }) => {
        test.skip(!CUSTOMER_EMAIL || !CUSTOMER_PASSWORD, 'Needs a local synthetic customer.');

        for (const locale of ['hu', 'en'] as const) {
            const context = await browser.newContext({ baseURL: BASE });
            const page = await context.newPage();

            await page.goto('/login');
            await page.locator('input[name="email"]').fill(CUSTOMER_EMAIL);
            await page.locator('input[name="password"]').fill(CUSTOMER_PASSWORD);
            await page.locator('form button[type="submit"]').last().click();
            await page.waitForURL(/\/account(?:\/)?$/);

            await chooseLanguage(page, locale);
            await context.clearCookies();
            await page.goto('/login');
            await page.locator('input[name="email"]').fill(CUSTOMER_EMAIL);
            await page.locator('input[name="password"]').fill(CUSTOMER_PASSWORD);
            await page.locator('form button[type="submit"]').last().click();
            await page.waitForURL(/\/account(?:\/)?$/);
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await page.goto('/account/websites');
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.locator('h1')).toContainText(locale === 'hu' ? 'Weboldalak' : 'Websites');
            await context.close();
        }
    });
});
