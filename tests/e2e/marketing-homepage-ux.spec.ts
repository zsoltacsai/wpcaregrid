import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;

test.skip(!baseUrl, 'Set E2E_BASE_URL to a running synthetic local application.');

for (const locale of ['en', 'hu'] as const) {
    for (const viewport of [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'mobile', width: 390, height: 844 },
    ]) {
        test(`${locale} homepage ${viewport.name}: focused layout, CTA and anchor regressions`, async ({ page }, testInfo) => {
            const pageErrors: string[] = [];
            const consoleErrors: string[] = [];
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

            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            await page.goto(`/${locale}/`);

            await expect(page.locator('html')).toHaveAttribute('lang', locale);
            await expect(page.locator('h1')).toHaveText(
                locale === 'en' ? 'WordPress maintenance, without the hassle.' : 'WordPress-karbantartás, gond nélkül.',
            );

            const hero = page.locator('section[aria-labelledby="hero-title"]');
            const heroLinks = hero.getByRole('link');
            await expect(heroLinks).toHaveCount(2);
            await expect(heroLinks.nth(0)).toContainText(locale === 'en' ? 'View plans' : 'Csomagok megtekintése');
            await expect(heroLinks.nth(0)).toHaveAttribute('href', /#pricing$/);
            await expect(heroLinks.nth(1)).toContainText(locale === 'en' ? 'How it works' : 'Hogyan működik');
            await expect(heroLinks.nth(1)).toHaveAttribute('href', /#how-it-works$/);
            await expect(hero.locator('figure')).toHaveCount(0);

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
            }));
            expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
            expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport);

            await page.screenshot({ path: testInfo.outputPath(`${locale}-${viewport.name}-after.png`), fullPage: true });

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

            expect(pageErrors).toEqual([]);
            expect(consoleErrors).toEqual([]);
        });
    }
}
