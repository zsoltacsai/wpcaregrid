import { expect, test } from '@playwright/test';

const cases = [
    { locale: 'en', label: /contact us|contact/i, viewport: { width: 1440, height: 900 } },
    { locale: 'hu', label: /kapcsolatfelvétel|kapcsolat/i, viewport: { width: 1440, height: 900 } },
    { locale: 'en', label: /contact us|contact/i, viewport: { width: 390, height: 844 } },
    { locale: 'hu', label: /kapcsolatfelvétel|kapcsolat/i, viewport: { width: 390, height: 844 } },
];

for (const { locale, label, viewport } of cases) {
    test(`${locale} marketing contact CTA is localized and keyboard accessible at ${viewport.width}px`, async ({ page }) => {
        const pageErrors: string[] = [];
        page.on('pageerror', error => pageErrors.push(error.message));
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
        await expect(contactLinks).toHaveCount(4);
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
        expect(pageErrors).toEqual([]);
    });
}
