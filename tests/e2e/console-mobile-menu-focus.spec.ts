import { expect, test, type Page } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';

const BASE = process.env.E2E_BASE_URL ?? '';
const OWNER_EMAIL = process.env.E2E_OWNER_EMAIL ?? '';
const OWNER_PASSWORD = process.env.E2E_OWNER_PASSWORD ?? '';
const OPERATOR_EMAIL = process.env.E2E_OPERATOR_EMAIL ?? '';
const OPERATOR_PASSWORD = process.env.E2E_OPERATOR_PASSWORD ?? '';
const EVIDENCE_DIR = process.env.M16_EVIDENCE_DIR;
const localLaunchOptions = process.env.M16_BROWSER_EXECUTABLE
    ? { launchOptions: { executablePath: process.env.M16_BROWSER_EXECUTABLE } }
    : {};
const localBaseUrl = (() => {
    try {
        return ['localhost', '127.0.0.1'].includes(new URL(BASE).hostname);
    } catch {
        return false;
    }
})();

test.use({
    baseURL: BASE,
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
    ...localLaunchOptions,
});

type ConsoleCase = {
    name: 'Owner' | 'Operator';
    loginPath: string;
    landingPath: RegExp;
    navId: string;
    email: string;
    password: string;
};

const consoles: ConsoleCase[] = [
    { name: 'Owner', loginPath: '/owner/login', landingPath: /\/owner\/?$/, navId: 'owner-nav', email: OWNER_EMAIL, password: OWNER_PASSWORD },
    { name: 'Operator', loginPath: '/operator/login', landingPath: /\/operator\/?$/, navId: 'operator-nav', email: OPERATOR_EMAIL, password: OPERATOR_PASSWORD },
];

async function signIn(page: Page, item: ConsoleCase): Promise<void> {
    await page.goto(item.loginPath);
    await page.locator('input[name="email"]').fill(item.email);
    await page.locator('input[name="password"]').fill(item.password);
    await page.locator('form button[type="submit"]').last().click();
    await page.waitForURL(item.landingPath);
}

async function capture(page: Page, filename: string): Promise<void> {
    if (!EVIDENCE_DIR) return;
    await mkdir(EVIDENCE_DIR, { recursive: true });
    await page.screenshot({ path: join(EVIDENCE_DIR, filename), fullPage: false });
}

for (const item of consoles) {
    test.describe(`${item.name} mobile navigation keyboard focus`, () => {
        test.skip(!localBaseUrl || !item.email || !item.password, `Requires a local synthetic ${item.name} account.`);

        test(`opens with Enter, tabs into navigation, and Escape returns visible focus to trigger`, async ({ page }) => {
            await signIn(page, item);

            const trigger = page.locator(`[aria-controls="${item.navId}"]`);
            const nav = page.locator(`#${item.navId}`);
            const firstLink = nav.locator('a').first();

            await trigger.focus();
            await page.keyboard.press('Enter');
            await expect(trigger).toHaveAttribute('aria-expanded', 'true');
            await expect(firstLink).toBeVisible();
            await capture(page, `${item.name.toLowerCase()}-mobile-menu-open.png`);

            await page.keyboard.press('Tab');
            await expect(firstLink).toBeFocused();
            await page.keyboard.press('Escape');

            await expect(trigger).toHaveAttribute('aria-expanded', 'false');
            await expect(firstLink).toBeHidden();
            await expect(trigger).toBeFocused();
            await expect.poll(() => trigger.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
            const indicator = await trigger.evaluate((element) => {
                const style = getComputedStyle(element);
                return { width: style.outlineWidth, style: style.outlineStyle, color: style.outlineColor };
            });
            expect(indicator.width).not.toBe('0px');
            expect(indicator.style).toBe('solid');
            expect(indicator.color).not.toBe('rgba(0, 0, 0, 0)');
            await capture(page, `${item.name.toLowerCase()}-mobile-menu-escape-focus-return.png`);

            await page.setViewportSize({ width: 1440, height: 900 });
            await expect(trigger).toBeHidden();
            await expect(nav.locator('a').first()).toBeVisible();
            await expect(trigger).toHaveAttribute('aria-expanded', 'false');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await capture(page, `${item.name.toLowerCase()}-desktop-dashboard.png`);
        });

        test('Escape while the trigger has focus leaves the menu closed and focus visible', async ({ page }) => {
            await signIn(page, item);

            const trigger = page.locator(`[aria-controls="${item.navId}"]`);
            await trigger.focus();
            await page.keyboard.press('Enter');
            await expect(trigger).toHaveAttribute('aria-expanded', 'true');
            await page.keyboard.press('Escape');

            await expect(trigger).toHaveAttribute('aria-expanded', 'false');
            await expect(trigger).toBeFocused();
            await expect.poll(() => trigger.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
        });
    });
}

if (process.env.M16_SECURITY_FIXTURE === '1') {
    test('renders the local synthetic security incident and scrollable findings table on mobile', async ({ page }) => {
        test.skip(!localBaseUrl || !OPERATOR_EMAIL || !OPERATOR_PASSWORD, 'Requires a local synthetic Operator account.');

        const item = consoles[1];
        await signIn(page, item);
        await page.goto('/operator/security');

        await expect(page.getByRole('heading', { name: 'Security' })).toBeVisible();
        await expect(page.getByRole('link', { name: /Synthetic local visual QA incident/ })).toBeVisible();
        const row = page.getByRole('row').filter({ hasText: 'm16-security-fixture-20261025.local.test' });
        await expect(row).toBeVisible();
        const tableViewport = page.getByRole('region', { name: /security findings table|biztonsági találatok táblázata/i });
        const dimensions = await tableViewport.evaluate((element) => ({
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth,
            documentWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
        }));
        expect(dimensions.scrollWidth).toBeGreaterThan(dimensions.clientWidth);
        expect(dimensions.documentWidth).toBeLessThanOrEqual(dimensions.viewportWidth);
        await expect(tableViewport).toHaveAttribute('tabindex', '0');
        await expect(page.getByRole('columnheader', { name: 'Website' })).toBeVisible();
        await capture(page, 'operator-mobile-security-table-left.png');

        await page.keyboard.press('Tab');
        let scrollRegionFocused = false;
        for (let attempt = 0; attempt < 60; attempt++) {
            scrollRegionFocused = await tableViewport.evaluate((element) => element === document.activeElement);
            if (scrollRegionFocused) break;
            await page.keyboard.press('Tab');
        }
        expect(scrollRegionFocused).toBe(true);
        await expect.poll(() => tableViewport.evaluate((element) => element.matches(':focus-visible'))).toBe(true);
        expect(await tableViewport.evaluate((element) => getComputedStyle(element).outlineWidth)).not.toBe('0px');

        for (let attempt = 0; attempt < 40; attempt++) {
            const current = await tableViewport.evaluate((element) => element.scrollLeft);
            const maximum = await tableViewport.evaluate((element) => element.scrollWidth - element.clientWidth);
            if (current >= maximum) break;
            await page.keyboard.press('ArrowRight');
        }

        const rightEdge = await tableViewport.evaluate((element) => ({
            scrollLeft: element.scrollLeft,
            maxScrollLeft: element.scrollWidth - element.clientWidth,
            bounds: element.getBoundingClientRect().toJSON(),
            lastHeader: element.querySelector('thead th:last-child')?.getBoundingClientRect().toJSON(),
        }));
        expect(rightEdge.scrollLeft).toBeGreaterThan(0);
        expect(rightEdge.scrollLeft).toBeGreaterThanOrEqual(rightEdge.maxScrollLeft - 2);
        expect(rightEdge.lastHeader?.right).toBeLessThanOrEqual(rightEdge.bounds.right + 1);
        await capture(page, 'operator-mobile-security-table-right.png');

        await page.keyboard.press('ArrowLeft');
        expect(await tableViewport.evaluate((element) => element.scrollLeft)).toBeLessThan(rightEdge.scrollLeft);

        for (let attempt = 0; attempt < 40; attempt++) {
            if (await tableViewport.evaluate((element) => element.scrollLeft) <= 0) break;
            await page.keyboard.press('ArrowLeft');
        }

        const leftEdge = await tableViewport.evaluate((element) => ({
            scrollLeft: element.scrollLeft,
            bounds: element.getBoundingClientRect().toJSON(),
            firstHeader: element.querySelector('thead th:first-child')?.getBoundingClientRect().toJSON(),
        }));
        expect(leftEdge.scrollLeft).toBe(0);
        expect(leftEdge.firstHeader?.left).toBeGreaterThanOrEqual(leftEdge.bounds.left - 1);

        await page.keyboard.press('Shift+Tab');
        await expect(tableViewport).not.toBeFocused();
        await page.keyboard.press('Tab');
        await expect(tableViewport).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(tableViewport.locator('a').first()).toBeFocused();

        await page.setViewportSize({ width: 1280, height: 900 });
        const desktopDimensions = await tableViewport.evaluate((element) => ({
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth,
            documentWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
        }));
        expect(desktopDimensions.scrollWidth).toBeLessThanOrEqual(desktopDimensions.clientWidth);
        expect(desktopDimensions.documentWidth).toBeLessThanOrEqual(desktopDimensions.viewportWidth);
    });
}
