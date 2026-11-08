import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const localBaseUrl = baseUrl ? new URL(baseUrl) : null;

test.skip(
    !localBaseUrl || localBaseUrl.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(localBaseUrl.hostname),
    'Set E2E_BASE_URL to a running synthetic application on localhost or 127.0.0.1.',
);

for (const locale of ['en', 'hu'] as const) {
    for (const viewport of [
        { name: 'mobile-320', width: 320, height: 780 },
        { name: 'mobile-375', width: 375, height: 812 },
        { name: 'mobile-390', width: 390, height: 844 },
        { name: 'tablet-768', width: 768, height: 1024 },
        { name: 'desktop-1440', width: 1440, height: 900 },
    ]) {
        test(`${locale} homepage ${viewport.width}px (${viewport.name}): layout, CTA and anchor regressions`, async ({ page }, testInfo) => {
            const pageErrors: string[] = [];
            const consoleErrors: string[] = [];
            const externalRequests: string[] = [];
            page.on('pageerror', (error) => pageErrors.push(error.message));
            page.on('console', (message) => {
                if (message.type() === 'error') consoleErrors.push(message.text());
            });

            // The isolated Windows QA worktree may expose public/build through a junction.
            // Serve only Vite build assets from that local directory; Laravel pages still
            // come from the running local app, and no external request is substituted.
            await page.route('**/build/assets/**', async (route) => {
                const assetName = path.basename(new URL(route.request().url()).pathname);
                if (!/^[A-Za-z0-9._-]+$/.test(assetName)) {
                    await route.abort();
                    return;
                }

                const assetRoot = path.resolve(process.cwd(), 'public/build/assets');
                const assetPath = path.resolve(assetRoot, assetName);
                if (!assetPath.startsWith(`${assetRoot}${path.sep}`)) {
                    await route.abort();
                    return;
                }

                const contentTypes: Record<string, string> = {
                    '.css': 'text/css',
                    '.js': 'application/javascript',
                    '.woff': 'font/woff',
                    '.woff2': 'font/woff2',
                };
                const contentType = contentTypes[path.extname(assetPath).toLowerCase()];
                if (!contentType) {
                    await route.abort();
                    return;
                }

                await route.fulfill({ body: await readFile(assetPath), contentType });
            });
            await page.route('**/*', async (route) => {
                const requestUrl = new URL(route.request().url());
                if (!localBaseUrl || requestUrl.origin !== localBaseUrl.origin) {
                    externalRequests.push(requestUrl.origin);
                    await route.abort();
                    return;
                }

                // Give the local build-asset shim above first chance to serve the asset.
                await route.fallback();
            });

            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await page.goto(`/${locale}/`);

            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.locator('h1')).toHaveText(
                locale === 'en' ? 'We work in the background. You see it all clearly.' : 'A háttérben dolgozunk. Ön tisztán lát.',
            );

            const hero = page.locator('section[aria-labelledby="hero-title"]');
            const heroLinks = hero.getByRole('link');
            await expect(heroLinks).toHaveCount(2);
            await expect(heroLinks.nth(0)).toContainText(locale === 'en' ? 'View plans' : 'Csomagok megtekintése');
            await expect(heroLinks.nth(0)).toHaveAttribute('href', /#pricing$/);
            await expect(heroLinks.nth(1)).toContainText(locale === 'en' ? 'How it works' : 'Hogyan működik');
            await expect(heroLinks.nth(1)).toHaveAttribute('href', /#how-it-works$/);
            await expect(hero.locator('figure')).toHaveCount(0);
            await expect(hero.locator('[data-graphite-wave]')).toHaveAttribute('aria-hidden', 'true');
            await expect(page.locator('body')).toHaveClass(/marketing-home/);
            await expect(page.locator('header a[aria-label]').first().locator('svg').first()).toBeVisible();
            await expect(page.locator('body')).toHaveCSS('font-family', /IBM Plex Sans/);

            const dashboard = page.locator('section[aria-labelledby="dashboard-title"]');
            await expect(dashboard.locator('figure')).toHaveCount(1);
            await expect(dashboard).toContainText(
                locale === 'en' ? 'Illustrative figures, not real customer data.' : 'Szemléltető adatok, nem valódi ügyféladatok.',
            );
            const sectionOrder = await page.locator('main > section').evaluateAll((sections) =>
                sections.map((section) => section.id),
            );
            const servicesIndex = sectionOrder.indexOf('services');
            expect(servicesIndex).toBeGreaterThanOrEqual(0);
            expect(sectionOrder[servicesIndex + 1]).toBe('how-it-works');

            const dimensions = await page.evaluate(() => ({
                viewport: document.documentElement.clientWidth,
                document: document.documentElement.scrollWidth,
                body: document.body.scrollWidth,
                hero: (() => {
                    const rect = document.querySelector('#hero-title').getBoundingClientRect();
                    return { left: rect.left, right: rect.right, width: rect.width };
                })(),
                cards: [...document.querySelectorAll('#pricing li > article')].map((element) => {
                    const rect = element.getBoundingClientRect();
                    return { left: rect.left, right: rect.right };
                }),
            }));
            expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
            expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport);
            expect(dimensions.hero.left).toBeGreaterThanOrEqual(0);
            expect(dimensions.hero.right).toBeLessThanOrEqual(dimensions.viewport);
            expect(dimensions.cards.every((card) => card.left >= 0 && card.right <= dimensions.viewport)).toBe(true);

            await page.screenshot({ path: testInfo.outputPath(`${locale}-${viewport.name}-after.png`), fullPage: true });
            await hero.screenshot({ path: testInfo.outputPath(`${locale}-${viewport.name}-hero.png`) });

            await page.keyboard.press('Tab');
            const activeElement = page.locator(':focus');
            await expect(activeElement).toBeVisible();
            const focusState = await activeElement.evaluate((element) => ({
                name: element.textContent?.trim(),
                visible: element.matches(':focus-visible'),
                outlineStyle: getComputedStyle(element).outlineStyle,
                outlineWidth: getComputedStyle(element).outlineWidth,
            }));
            expect(focusState.visible).toBe(true);
            expect(focusState.outlineStyle).not.toBe('none');
            expect(focusState.outlineWidth).not.toBe('0px');
            await page.screenshot({ path: testInfo.outputPath(`${locale}-${viewport.name}-keyboard.png`) });

            await page.reload();
            for (let tabPress = 0; tabPress < 20; tabPress += 1) {
                await page.keyboard.press('Tab');
                if (await heroLinks.nth(1).evaluate((element) => element === document.activeElement)) break;
            }
            await expect(heroLinks.nth(1)).toBeFocused();
            expect(await heroLinks.nth(1).evaluate((element) => element.matches(':focus-visible'))).toBe(true);
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(new RegExp(`/${locale}/#how-it-works$`));
            await expect(page.locator('#how-it-works')).toBeVisible();

            if (viewport.width === 1440) {
                const pricing = page.locator('#pricing');
                const productTabs = pricing.locator('[role="tablist"] [role="tab"]');
                await expect(productTabs).toHaveCount(2);
                await productTabs.nth(1).click();
                await expect(productTabs.nth(1)).toHaveAttribute('aria-selected', 'true');

                const billingOptions = pricing.locator('[role="group"] button[aria-pressed]');
                await expect(billingOptions).toHaveCount(2);
                await billingOptions.nth(1).click();
                await expect(billingOptions.nth(1)).toHaveAttribute('aria-pressed', 'true');
                await expect(pricing).toContainText(locale === 'en' ? '2 months free' : '2 hónap ingyenes');
                await expect(pricing.locator('[data-price="annual"]:visible')).toHaveCount(3);
                await expect(pricing.locator('[data-price="monthly"]:visible')).toHaveCount(0);

                const currencySelect = pricing.locator('#display-currency');
                await expect(currencySelect).toBeVisible();
                const currencyOptions = await currencySelect.locator('option').evaluateAll((options) =>
                    options.map((option) => (option as HTMLOptionElement).value),
                );
                expect(currencyOptions.length).toBeGreaterThan(1);
                const selectedCurrency = await currencySelect.inputValue();
                const alternateCurrency = currencyOptions.find((currency) => currency !== selectedCurrency);
                expect(alternateCurrency).toBeDefined();
                const firstAnnualPrice = pricing.locator('[data-price="annual"]:visible').first()
                    .locator('p.flex > span').first();
                const priceBeforeCurrencyChange = (await firstAnnualPrice.textContent())?.trim();
                await currencySelect.selectOption(alternateCurrency as string);
                await expect(currencySelect).toHaveValue(alternateCurrency as string);
                await expect(pricing).toContainText(alternateCurrency as string);
                // Currency selection is a server-backed display preference and
                // reloads the page, so the pricing component returns to its
                // default monthly interval. Re-select annual before comparing
                // the rendered market price.
                await billingOptions.nth(1).click();
                await expect(billingOptions.nth(1)).toHaveAttribute('aria-pressed', 'true');
                await expect(firstAnnualPrice).not.toHaveText(priceBeforeCurrencyChange ?? '');
                await expect(page).not.toHaveURL(/checkout|register/);
            }

            expect(pageErrors).toEqual([]);
            expect(consoleErrors).toEqual([]);
            expect(externalRequests).toEqual([]);

            if (viewport.width < 1024) {
                const menuButton = page.locator('header button[x-ref="menuButton"]');
                await menuButton.focus();
                await page.keyboard.press('Enter');
                await expect(page.locator('#mobile-menu')).toBeVisible();
                await page.keyboard.press('Escape');
                await expect(page.locator('#mobile-menu')).toBeHidden();
                await expect(menuButton).toBeFocused();
                expect(await menuButton.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
            }
        });
    }
}

// Decorative hero canvas: motion preference, pause behaviour and layout stability.
// The layout-shift value is a synthetic, single-run headless measurement on the
// local test application; it is not a field Core Web Vitals result.
async function heroCanvasSignature(page: import('@playwright/test').Page) {
    return page.evaluate(() => {
        const canvas = document.querySelector<HTMLCanvasElement>('[data-graphite-wave]');
        if (!canvas) return null;
        const context = canvas.getContext('2d');
        if (!context) return null;
        const data = context.getImageData(0, 0, canvas.width, canvas.height).data;
        let hash = 0;
        for (let index = 0; index < data.length; index += 97) hash = (hash * 31 + data[index]) | 0;
        return hash;
    });
}

for (const locale of ['en', 'hu'] as const) {
    test(`${locale} hero canvas honours reduced motion and animates otherwise`, async ({ browser }) => {
        const reduced = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1440, height: 900 }, baseURL: localBaseUrl?.origin });
        const reducedPage = await reduced.newPage();
        await reducedPage.goto(`/${locale}/`);
        await expect(reducedPage.locator('[data-graphite-wave]')).toHaveAttribute('aria-hidden', 'true');
        const staticFirst = await heroCanvasSignature(reducedPage);
        await reducedPage.waitForTimeout(700);
        const staticSecond = await heroCanvasSignature(reducedPage);
        expect(staticFirst).not.toBeNull();
        expect(staticSecond).toBe(staticFirst);
        await reduced.close();

        const motion = await browser.newContext({ reducedMotion: 'no-preference', viewport: { width: 1440, height: 900 }, baseURL: localBaseUrl?.origin });
        const motionPage = await motion.newPage();
        await motionPage.goto(`/${locale}/`);
        const animatedFirst = await heroCanvasSignature(motionPage);
        await motionPage.waitForTimeout(900);
        const animatedSecond = await heroCanvasSignature(motionPage);
        expect(animatedSecond).not.toBe(animatedFirst);

        await motionPage.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await motionPage.waitForTimeout(300);
        const pausedFirst = await heroCanvasSignature(motionPage);
        await motionPage.waitForTimeout(500);
        expect(await heroCanvasSignature(motionPage)).toBe(pausedFirst);
        await motion.close();
    });

    test(`${locale} hero introduces no layout shift (synthetic, single headless run)`, async ({ page }, testInfo) => {
        await page.addInitScript(() => {
            (window as unknown as { __cls: number }).__cls = 0;
            new PerformanceObserver((list) => {
                for (const entry of list.getEntries() as unknown as Array<{ value: number; hadRecentInput: boolean }>) {
                    if (!entry.hadRecentInput) (window as unknown as { __cls: number }).__cls += entry.value;
                }
            }).observe({ type: 'layout-shift', buffered: true });
        });
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(`/${locale}/`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);
        const cls = await page.evaluate(() => (window as unknown as { __cls: number }).__cls);
        testInfo.annotations.push({ type: 'synthetic-cls', description: String(cls) });
        expect(cls).toBeLessThan(0.1);
    });
}
