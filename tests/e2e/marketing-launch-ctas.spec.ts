import { expect, test } from '@playwright/test';

const cases = [
    { locale: 'en', label: /contact us|contact/i, viewportName: 'desktop', viewport: { width: 1440, height: 900 } },
    { locale: 'hu', label: /kapcsolatfelvétel|kapcsolat/i, viewportName: 'desktop', viewport: { width: 1440, height: 900 } },
    { locale: 'en', label: /contact us|contact/i, viewportName: 'mobile', viewport: { width: 390, height: 844 } },
    { locale: 'hu', label: /kapcsolatfelvétel|kapcsolat/i, viewportName: 'mobile', viewport: { width: 390, height: 844 } },
];

for (const { locale, label, viewportName, viewport } of cases) {
    test(`${locale} marketing contact CTA is localized and keyboard accessible at ${viewport.width}px`, async ({ page }) => {
        const pageErrors: string[] = [];
        const consoleErrors: string[] = [];
        const externalRequests: string[] = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        page.on('console', message => {
            if (message.type() === 'error') consoleErrors.push(message.text());
        });
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            const local = ['127.0.0.1', 'localhost'].includes(url.hostname);
            if (!local) {
                externalRequests.push(url.origin);
                await route.abort();
                return;
            }
            await route.continue();
        });
        await page.addInitScript(() => {
            document.addEventListener('click', event => {
                const target = event.target as Element | null;
                const link = target?.closest<HTMLAnchorElement>('a[href^="mailto:"]');
                if (link) {
                    event.preventDefault();
                    document.documentElement.dataset.mailtoActivated = link.href;
                }
            });
        });
        await page.setViewportSize(viewport);
        await page.goto(`/${locale}/`);

        const contactLinks = page.locator('a[href="mailto:contact@zsoltacsai.com"]');
        await expect(contactLinks.first()).toBeVisible();
        expect(await contactLinks.count()).toBeGreaterThanOrEqual(4);
        await expect(page.locator('a[href="/contact"], a[href="https://wpcaregrid.com/contact"]')).toHaveCount(0);

        const localizedContact = page.getByRole('link', { name: label }).first();
        await expect(localizedContact).toHaveAttribute('href', 'mailto:contact@zsoltacsai.com');
        await localizedContact.evaluate(element => element.setAttribute('data-e2e-contact-target', 'true'));
        await page.evaluate(() => document.activeElement instanceof HTMLElement && document.activeElement.blur());
        let targetFocused = false;
        for (let index = 0; index < 100 && !targetFocused; index++) {
            await page.keyboard.press('Tab');
            targetFocused = await page.evaluate(() => document.activeElement === document.querySelector('[data-e2e-contact-target="true"]'));
        }
        expect(targetFocused).toBeTruthy();
        await expect(localizedContact).toBeFocused();
        const focusVisible = await localizedContact.evaluate(element => {
            const style = getComputedStyle(element);
            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        });
        expect(focusVisible).toBeTruthy();
        await page.keyboard.press('Enter');
        await expect(page.locator('html')).toHaveAttribute('data-mailto-activated', 'mailto:contact@zsoltacsai.com');

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
        expect(overflow).toBeFalsy();
        expect(externalRequests).toEqual([]);
        expect(pageErrors).toEqual([]);
        expect(consoleErrors).toEqual([]);
    });

    test(`${locale} pricing ${viewportName} exposes contact-only plan CTAs and billing controls`, async ({ page }, testInfo) => {
        const pageErrors: string[] = [];
        const consoleErrors: string[] = [];
        const externalRequests: string[] = [];
        page.on('pageerror', error => pageErrors.push(error.message));
        page.on('console', message => {
            if (message.type() === 'error') consoleErrors.push(message.text());
        });
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (!['127.0.0.1', 'localhost'].includes(url.hostname)) {
                externalRequests.push(url.origin);
                await route.abort();
                return;
            }
            await route.continue();
        });
        await page.addInitScript(() => {
            document.addEventListener('click', event => {
                const target = event.target as Element | null;
                const link = target?.closest<HTMLAnchorElement>('a[href^="mailto:"]');
                if (link) {
                    event.preventDefault();
                    document.documentElement.dataset.mailtoActivated = link.href;
                }
            });
        });
        await page.setViewportSize(viewport);
        await page.goto(`/${locale}/pricing`);
        await expect(page.locator('html')).toHaveAttribute('lang', locale);

        const pricing = page.locator('#pricing');
        await expect(pricing).toBeVisible();
        const planActions = pricing.locator('a[data-marketing-plan]');
        expect(await planActions.count()).toBeGreaterThan(0);
        await expect(planActions.first()).toHaveAttribute('href', 'mailto:contact@zsoltacsai.com');
        const checkoutAttributeReferences = await pricing.evaluate(element => {
            const nodes = [element, ...Array.from(element.querySelectorAll('*'))];
            return nodes.flatMap(node => Array.from(node.attributes)
                .filter(attribute => attribute.value.includes('/checkout'))
                .map(attribute => `${attribute.name}=${attribute.value}`));
        });
        expect(checkoutAttributeReferences).toEqual([]);
        const firstCard = planActions.first().locator('xpath=ancestor::article[1]');
        const monthlyPrice = firstCard.locator('[data-price="monthly"]');
        const annualPrice = firstCard.locator('[data-price="annual"]');

        const billingGroup = page.getByRole('group', { name: locale === 'en' ? 'Billing period' : 'Számlázási időszak' });
        const monthly = billingGroup.getByRole('button', { name: locale === 'en' ? 'Monthly' : 'Havi' });
        const annual = billingGroup.getByRole('button', { name: locale === 'en' ? 'Annual' : 'Éves' });
        await monthly.click();
        await expect(monthly).toHaveAttribute('aria-pressed', 'true');
        await expect(planActions.first()).toHaveAttribute('data-billing-interval', 'monthly');
        await expect(monthlyPrice).toBeVisible();
        await expect(annualPrice).toBeHidden();
        await annual.click();
        await expect(annual).toHaveAttribute('aria-pressed', 'true');
        await expect(planActions.first()).toHaveAttribute('data-billing-interval', 'annual');
        await expect(annualPrice).toBeVisible();
        await expect(monthlyPrice).toBeHidden();

        await page.keyboard.press('Tab');
        await expect(planActions.first()).toBeFocused();
        expect(await planActions.first().evaluate(element => element.matches(':focus-visible'))).toBe(true);
        await page.keyboard.press('Enter');
        await expect(page.locator('html')).toHaveAttribute('data-mailto-activated', 'mailto:contact@zsoltacsai.com');

        if (viewport.width < 1024) {
            const menuButton = page.locator('header button[x-ref="menuButton"]');
            await menuButton.focus();
            await page.keyboard.press('Enter');
            await expect(page.locator('#mobile-menu')).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(page.locator('#mobile-menu')).toBeHidden();
            await expect(menuButton).toBeFocused();
            expect(await menuButton.evaluate(element => element.matches(':focus-visible'))).toBe(true);
        }

        const dimensions = await page.evaluate(() => ({
            viewport: document.documentElement.clientWidth,
            document: document.documentElement.scrollWidth,
            body: document.body.scrollWidth,
        }));
        expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
        expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport);
        await page.screenshot({ path: testInfo.outputPath(`${locale}-pricing-${viewportName}.png`), fullPage: true });
        expect(externalRequests).toEqual([]);
        expect(pageErrors).toEqual([]);
        expect(consoleErrors).toEqual([]);
    });
}
