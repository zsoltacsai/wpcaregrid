import { expect, test, type Page } from '@playwright/test';

/*
 * Display language and currency of the public site, the customer login and
 * the customer portal, in a real browser (docs/LOCALIZATION.md). Needs a
 * running app, no Stripe and no public address:
 *
 *   E2E_BASE_URL=http://127.0.0.1:8000 E2E_CUSTOMER_EMAIL=… E2E_CUSTOMER_PASSWORD=… npx playwright test localization
 *
 * The customer is a throw-away account of the local database; the tests that
 * sign in are skipped without it.
 */

const BASE = process.env.E2E_BASE_URL ?? '';
const EMAIL = process.env.E2E_CUSTOMER_EMAIL ?? '';
const PASSWORD = process.env.E2E_CUSTOMER_PASSWORD ?? '';

test.skip(!BASE, 'Set E2E_BASE_URL to the running application.');

const htmlLang = (page: Page) => page.locator('html').getAttribute('lang');

/** The language selector that is visible at the current width (header on desktop, menu on mobile). */
async function chooseLanguage(page: Page, name: 'Magyar' | 'English'): Promise<void> {
    const toggle = page.getByRole('button', { name: /^(Open menu|Menü megnyitása)$/ });
    if (await toggle.isVisible()) {
        await toggle.click();
    }
    const group = page.getByRole('group', { name: /^(Language|Nyelv)$/ });
    const link = group.getByRole('link', { name, exact: true });
    if (await link.count()) {
        await link.first().click();
    } else {
        await group.getByRole('button', { name, exact: true }).first().click();
    }
    await expect(page.locator('html')).toHaveAttribute('lang', name === 'Magyar' ? 'hu' : 'en');
}

test('public pages: switching the language changes everything and persists', async ({ page }) => {
    await page.goto('/');
    expect(await htmlLang(page)).toBe('en');
    await expect(page.locator('h1')).toContainText('WordPress maintenance, without the hassle.');

    await chooseLanguage(page, 'Magyar');
    expect(await htmlLang(page)).toBe('hu');
    await expect(page.locator('h1')).toContainText('WordPress-karbantartás, gond nélkül.');
    await expect(page).toHaveTitle(/WordPress-karbantartás/);
    await expect(page.locator('#faq')).toContainText('Gyakran ismételt kérdések');
    await expect(page.locator('footer')).toContainText('Szerződési feltételek');

    // The choice is remembered: another page and a reload stay Hungarian.
    await page.goto('/pricing');
    expect(await htmlLang(page)).toBe('hu');
    await expect(page.locator('h1')).toContainText('Csomagok és árak');
    await page.reload();
    expect(await htmlLang(page)).toBe('hu');

    await chooseLanguage(page, 'English');
    expect(await htmlLang(page)).toBe('en');
    await expect(page.locator('h1')).toContainText('Plans and pricing');
});

test('pricing: selectable HUF prices remain correct in both languages and product tabs', async ({ page }) => {
    for (const [lang, monthly, annual, free] of [['en', '18,990', '189,900', '2 months free'], ['hu', '18 990', '189 900', '2 hónap ingyenes']] as const) {
        await page.goto('/');
        if ((await htmlLang(page)) !== lang) {
            await chooseLanguage(page, lang === 'hu' ? 'Magyar' : 'English');
        }
        await page.goto('/pricing');

        // Select the configured HUF display option; checkout still resolves its
        // charge from server-side market rules, not this presentation choice.
        const currency = page.locator('select[name="currency"]');
        await expect(currency).toBeVisible();
        await expect(currency.locator('option').evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value))).resolves.toContain('HUF');
        await currency.selectOption('HUF');
        await expect(currency).toHaveValue('HUF');

        const normal = (text: string | null) => (text ?? '').replace(/[  ]/g, ' ');
        expect(normal(await page.locator('#pricing-panel-wordpress').textContent())).toContain(monthly);
        await page.getByRole('button', { name: lang === 'hu' ? 'Éves' : 'Annual', exact: true }).click();
        await expect(page.locator('body')).toContainText(free);
        expect(normal(await page.locator('#pricing-panel-wordpress').textContent())).toContain(annual);

        await page.getByRole('tab', { name: 'WooCommerce' }).click();
        expect(normal(await page.locator('#pricing-panel-woocommerce').textContent())).toContain(lang === 'hu' ? '249 000' : '249,000');
    }
});

test('checkout: bilingual, and what was typed survives a language switch (not passwords)', async ({ page }) => {
    await page.goto('/checkout?product=wordpress&plan=business&interval=monthly');
    await expect(page.locator('h1')).toHaveText('Complete your order');

    await page.locator('input[name="email"]').fill('anna@company.example');
    await page.locator('input[name="first_name"]').fill('Anna');
    await chooseLanguage(page, 'Magyar');

    await expect(page.locator('h1')).toHaveText('Rendelés véglegesítése');
    await expect(page.locator('input[name="email"]')).toHaveValue('anna@company.example');
    await expect(page.locator('input[name="first_name"]')).toHaveValue('Anna');
    await expect(page.locator('select[name="country"]')).toContainText('Magyarország');
    await expect(page.locator('main')).toContainText('18 990');

    await chooseLanguage(page, 'English');
    await expect(page.locator('h1')).toHaveText('Complete your order');
});

test('login page is bilingual, errors included, and the secret field is never carried', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('h1')).toHaveText('Log in');

    await page.locator('input[name="email"]').fill('nobody@example.com');
    await page.locator('input[name="password"]').fill('not-a-real-password');
    await chooseLanguage(page, 'Magyar');
    await expect(page.locator('h1')).toHaveText('Belépés');
    await expect(page.locator('input[name="email"]')).toHaveValue('nobody@example.com');
    await expect(page.locator('input[name="password"]')).toHaveValue('');

    await page.locator('input[name="password"]').fill('not-a-real-password');
    await page.getByRole('button', { name: 'Belépés', exact: true }).last().click();
    await expect(page.locator('main')).toContainText('Ezek az adatok nem egyeznek a nyilvántartásunkkal.');
});

test('unknown address: the 404 page speaks the remembered language', async ({ page }) => {
    await page.goto('/');
    await chooseLanguage(page, 'Magyar');
    await page.goto('/this-page-does-not-exist');
    await expect(page.locator('body')).toContainText('Az oldal nem található');
});

test('mobile: the selector is in the menu and works with the keyboard', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    await page.getByRole('button', { name: 'Open menu' }).click();
    const group = page.getByRole('group', { name: 'Language' });
    await expect(group).toBeVisible();
    await expect(group.getByRole('link', { name: 'English' })).toHaveAttribute('aria-current', 'page');

    await group.getByRole('link', { name: 'Magyar' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('html')).toHaveAttribute('lang', 'hu');
    expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
});

test.describe('customer area', () => {
    test.skip(!EMAIL || !PASSWORD, 'Needs E2E_CUSTOMER_EMAIL and E2E_CUSTOMER_PASSWORD.');

    async function login(page: Page): Promise<void> {
        await page.goto('/login');
        await page.locator('input[name="email"]').fill(EMAIL);
        await page.locator('input[name="password"]').fill(PASSWORD);
        await page.locator('form button[type="submit"]').last().click();
        await page.waitForURL(/\/account\/?$/);
    }

    test('login, Hungarian dashboard and navigation, preference follows the customer to a new browser', async ({ page, browser }) => {
        // Chosen before signing in: it carries into the session.
        await page.goto('/login');
        await chooseLanguage(page, 'Magyar');
        await login(page);

        expect(await htmlLang(page)).toBe('hu');
        await expect(page.locator('h1')).toContainText('Üdv újra');
        await expect(page.locator('main')).toContainText('Előfizetés');

        for (const [path, title] of [['/account/websites', 'Weboldalak'], ['/account/subscription', 'Előfizetés'], ['/account/billing', 'Számlázás'], ['/account/profile', 'Profil']] as const) {
            await page.goto(path);
            expect(await htmlLang(page)).toBe('hu');
            await expect(page.locator('h1')).toContainText(title);
            expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)).toBe(false);
        }

        // Switch inside the portal: English now, saved on the customer.
        await chooseLanguage(page, 'English');
        await expect(page.locator('h1')).toContainText('Profile');

        // A new browser (no cookie): the saved preference applies after signing in.
        const fresh = await browser.newContext({ baseURL: BASE });
        const other = await fresh.newPage();
        await login(other);
        expect(await htmlLang(other)).toBe('en');
        await expect(other.locator('h1')).toContainText('Welcome back');
        await fresh.close();
    });

    test('the profile form keeps its values when the language is switched', async ({ page }) => {
        await login(page);
        await page.goto('/account/profile');
        await page.locator('input[name="customer_type"][value="company"]').check();
        await page.locator('input[name="city"]').fill('Debrecen');
        await chooseLanguage(page, 'Magyar');

        await expect(page.locator('input[name="city"]')).toHaveValue('Debrecen');
        await expect(page.locator('h1')).toContainText('Profil');
    });
});
