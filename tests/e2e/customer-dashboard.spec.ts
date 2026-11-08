import { expect, test, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const localBaseUrl = baseUrl ? new URL(baseUrl) : null;

test.skip(
    !localBaseUrl || localBaseUrl.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(localBaseUrl.hostname),
    'Set E2E_BASE_URL to a running synthetic application on localhost or 127.0.0.1.',
);

// Accounts created by tests/e2e/fixtures/seed-customer-dashboard.php (synthetic, testing SQLite only).
const PASSWORD = 'password-123456';
const LOGIN_BUTTON = /log in|sign in|bejelentkez|belép/i;

const labels = {
    en: { connected: 'Connected', attention: 'Attention', pending: 'Pending', active: 'Active' },
    hu: { connected: 'Csatlakoztatva', attention: 'Figyelmet igényel', pending: 'Függőben', active: 'Aktív' },
} as const;

async function guardNetwork(page: Page, externalRequests: string[]): Promise<void> {
    await page.route('**/*', async (route) => {
        const origin = new URL(route.request().url()).origin;
        if (!localBaseUrl || origin !== localBaseUrl.origin) {
            externalRequests.push(origin);
            await route.abort();
            return;
        }
        await route.fallback();
    });
}

async function login(page: Page, locale: 'en' | 'hu', account = 'dashboard'): Promise<void> {
    await page.goto('/login');
    await page.locator('input[name=email]').fill(`${account}-${locale}@example.test`);
    await page.locator('input[name=password]').fill(PASSWORD);
    await page.getByRole('button', { name: LOGIN_BUTTON }).first().click();
    await expect(page).toHaveURL(/\/account\/?$/);
}

async function noHorizontalOverflow(page: Page): Promise<void> {
    const sizes = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: document.documentElement.clientWidth,
    }));
    expect(sizes.document).toBeLessThanOrEqual(sizes.viewport);
}

test('unauthenticated visitors are redirected to the login page', async ({ page }) => {
    for (const path of ['/account', '/account/websites', '/account/subscription', '/account/billing']) {
        await page.goto(path);
        await expect(page).toHaveURL(/\/login/);
    }
});

for (const locale of ['en', 'hu'] as const) {
    test.describe(`${locale} customer dashboard`, () => {
        test(`${locale}: localized pages, status text, landmarks and no console or external requests`, async ({ page }) => {
            const consoleErrors: string[] = [];
            const pageErrors: string[] = [];
            const externalRequests: string[] = [];
            page.on('console', (message) => {
                if (message.type() === 'error') consoleErrors.push(message.text());
            });
            page.on('pageerror', (error) => pageErrors.push(error.message));
            await guardNetwork(page, externalRequests);

            await login(page, locale);

            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.locator('h1')).toHaveCount(1);
            await expect(page.locator('main#main')).toHaveCount(1);
            await expect(page.locator('nav[aria-label]')).toHaveCount(1);
            await expect(page.getByText(labels[locale].active, { exact: true })).toBeVisible();
            for (const section of await page.locator('section[aria-labelledby]').all()) {
                const target = await section.getAttribute('aria-labelledby');
                await expect(page.locator(`#${target}`)).toHaveCount(1);
            }

            await page.goto('/account/websites');
            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.locator('h1')).toHaveCount(1);
            for (const status of ['connected', 'attention', 'pending'] as const) {
                await expect(page.getByText(labels[locale][status], { exact: true })).toBeVisible();
            }
            await expect(page.getByRole('link', { name: new RegExp(`dashboard-${locale}-0\\.example`) })).toBeVisible();
            await expect(page.getByText(locale === 'en' ? 'dashboard-hu-0.example' : 'dashboard-en-0.example')).toHaveCount(0);

            expect(pageErrors).toEqual([]);
            expect(consoleErrors).toEqual([]);
            expect(externalRequests).toEqual([]);
        });

        test(`${locale}: keyboard navigation reaches content, shows focus and the skip link works`, async ({ page }) => {
            await page.setViewportSize({ width: 1440, height: 900 });
            await login(page, locale);

            await page.keyboard.press('Tab');
            await expect(page.locator(':focus')).toHaveAttribute('href', '#main');
            await page.keyboard.press('Enter');
            await expect(page.locator('main#main')).toBeFocused();

            await page.goto('/account/websites');
            const hostLink = page.getByRole('link', { name: new RegExp(`dashboard-${locale}-0\\.example`) });
            for (let step = 0; step < 40 && !(await hostLink.evaluate((element) => element === document.activeElement)); step += 1) {
                await page.keyboard.press('Tab');
            }
            await expect(hostLink).toBeFocused();
            const focus = await hostLink.evaluate((element) => {
                const style = getComputedStyle(element);
                return { visible: element.matches(':focus-visible'), style: style.outlineStyle, width: style.outlineWidth };
            });
            expect(focus.visible).toBe(true);
            expect(focus.style).not.toBe('none');
            expect(focus.width).not.toBe('0px');
        });

        for (const width of [320, 375, 768, 1024, 1440]) {
            test(`${locale}: ${width}px has no horizontal overflow and a working navigation`, async ({ page }) => {
                await page.setViewportSize({ width, height: 900 });
                await login(page, locale);
                await noHorizontalOverflow(page);

                const nav = page.locator('#account-nav');
                const menuButton = page.locator('button[aria-controls="account-nav"]');
                if (width < 1024) {
                    await expect(menuButton).toBeVisible();
                    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
                    await expect(nav).toBeHidden();
                    await menuButton.click();
                    await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
                    await expect(nav).toBeVisible();
                    await page.keyboard.press('Escape');
                    await expect(nav).toBeHidden();
                    await expect(menuButton).toBeFocused();
                    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');

                    await menuButton.click();
                    await expect(nav).toBeVisible();
                    await page.locator('main#main').click({ position: { x: 4, y: 4 } });
                    await expect(nav).toBeHidden();
                    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
                } else {
                    await expect(menuButton).toBeHidden();
                    await expect(nav).toBeVisible();
                }

                await page.goto('/account/websites');
                await noHorizontalOverflow(page);
            });
        }

        test(`${locale}: widening the window closes the mobile menu and the sidebar shows the current page`, async ({ page }) => {
            await page.setViewportSize({ width: 768, height: 900 });
            await login(page, locale);
            const menuButton = page.locator('button[aria-controls="account-nav"]');
            await menuButton.click();
            await expect(menuButton).toHaveAttribute('aria-expanded', 'true');

            await page.setViewportSize({ width: 1440, height: 900 });
            await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
            await expect(page.locator('#account-nav')).toBeVisible();

            const current = page.locator('#account-nav a[aria-current="page"]');
            await expect(current).toHaveCount(1);
            const colors = await current.evaluate((element) => {
                const channel = (value: number) => {
                    const scaled = value / 255;
                    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4;
                };
                const luminance = (rgb: string) => {
                    const [red, green, blue] = (rgb.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
                    return 0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);
                };
                const style = getComputedStyle(element);
                const [high, low] = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => b - a);
                return (high + 0.05) / (low + 0.05);
            });
            expect(colors).toBeGreaterThanOrEqual(4.5);

            await page.keyboard.press('Tab');
            await page.keyboard.press('Tab');
            const focus = await page.locator(':focus').evaluate((element) => {
                const style = getComputedStyle(element);
                return { visible: element.matches(':focus-visible'), style: style.outlineStyle, width: style.outlineWidth };
            });
            expect(focus.visible).toBe(true);
            expect(focus.style).not.toBe('none');
            expect(focus.width).not.toBe('0px');
        });

        test(`${locale}: status chips keep readable contrast and every control has an accessible name`, async ({ page }) => {
            await login(page, locale);
            await page.goto('/account/websites');

            const chips = await page.locator('[data-tone]').evaluateAll((elements) => {
                const channel = (value: number) => {
                    const scaled = value / 255;
                    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4;
                };
                const luminance = (rgb: string) => {
                    const [red, green, blue] = (rgb.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number);
                    return 0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);
                };
                return elements.map((element) => {
                    const style = getComputedStyle(element);
                    const [high, low] = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => b - a);
                    return { text: element.textContent?.trim() ?? '', ratio: (high + 0.05) / (low + 0.05) };
                });
            });
            expect(chips.length).toBe(3);
            for (const chip of chips) {
                expect(chip.text).not.toBe('');
                expect(chip.ratio, `${chip.text} contrast`).toBeGreaterThanOrEqual(4.5);
            }

            const unnamed = await page.evaluate(() =>
                [...document.querySelectorAll('a[href], button')]
                    .filter((element) => !(element.textContent?.trim() || element.getAttribute('aria-label')))
                    .map((element) => element.outerHTML.slice(0, 80)),
            );
            expect(unnamed).toEqual([]);
        });

        test(`${locale}: a customer without websites sees helpful empty states, keyboard reachable`, async ({ page }) => {
            const emptyTitle = locale === 'en' ? 'No websites yet' : 'Még nincs weboldal';
            const addLabel = locale === 'en' ? 'Add a website' : 'Weboldal hozzáadása';

            for (const width of [320, 768, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await login(page, locale, 'dashboard-empty');
                await expect(page.getByText(locale === 'en' ? '0 connected' : '0 csatlakoztatva')).toBeVisible();
                await noHorizontalOverflow(page);

                await page.goto('/account/websites');
                const empty = page.locator('.m16-empty');
                await expect(empty).toBeVisible();
                await expect(empty.getByRole('heading', { level: 2, name: emptyTitle })).toBeVisible();
                await expect(page.locator('h1')).toHaveCount(1);
                await expect(page.locator('[data-tone]')).toHaveCount(0);
                await noHorizontalOverflow(page);

                const action = empty.getByRole('link', { name: addLabel });
                await expect(action).toHaveAttribute('href', /\/account\/websites\/new$/);
                await action.focus();
                const focus = await action.evaluate((element) => {
                    const style = getComputedStyle(element);
                    return { visible: element.matches(':focus-visible'), style: style.outlineStyle, width: style.outlineWidth };
                });
                expect(focus.visible).toBe(true);
                expect(focus.style).not.toBe('none');
                expect(focus.width).not.toBe('0px');

                await page.context().clearCookies();
            }

            await login(page, locale, 'dashboard-empty');
            await page.goto('/account/websites');
            await page.getByRole('link', { name: addLabel, exact: true }).click();
            await expect(page).toHaveURL(/\/account\/websites\/new$/);
        });

        test(`${locale}: logging out protects the dashboard again`, async ({ page }) => {
            await page.setViewportSize({ width: 1440, height: 900 });
            await login(page, locale);
            await page.getByRole('button', { name: /log out|kilépés/i }).click();
            await page.goto('/account');
            await expect(page).toHaveURL(/\/login/);
        });
    });
}
