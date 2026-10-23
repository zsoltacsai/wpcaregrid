import { expect, test, type Page } from '@playwright/test';

/*
 * Real existing-account purchase in Stripe TEST MODE. Use only a disposable
 * synthetic customer and a public test webhook address (docs/STRIPE_TEST_MODE.md).
 */

const BASE = process.env.E2E_BASE_URL ?? '';
const EMAIL = process.env.E2E_EXISTING_CUSTOMER_EMAIL ?? '';
const PASSWORD = process.env.E2E_EXISTING_CUSTOMER_PASSWORD ?? '';
const CARD = { number: '4242 4242 4242 4242', expiry: '12 / 34', cvc: '123' };

test.skip(!BASE.startsWith('https://') || !EMAIL || !PASSWORD,
    'Set E2E_BASE_URL and credentials for a disposable, billing-complete synthetic customer in Stripe TEST mode.');

async function payOnStripeCheckout(page: Page): Promise<void> {
    await page.waitForURL(/checkout\.stripe\.com/);

    const cardOption = page.locator('[data-testid="card-accordion-item-button"]');
    if (await cardOption.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await cardOption.click();
    }

    await page.locator('#cardNumber').fill(CARD.number);
    await page.locator('#cardExpiry').fill(CARD.expiry);
    await page.locator('#cardCvc').fill(CARD.cvc);
    await page.locator('#billingName').fill('Synthetic Existing Customer');
    const country = page.locator('#billingCountry');
    if (await country.isVisible().catch(() => false)) await country.selectOption('HU');
    const postal = page.locator('#billingPostalCode');
    if (await postal.isVisible().catch(() => false)) await postal.fill('1011');

    const link = page.locator('#enableStripePass');
    if (await link.isChecked().catch(() => false)) await link.uncheck();
    await page.locator('[data-testid="hosted-payment-submit-button"]').click();
}

test('existing customer buys and connects another website without a second account', async ({ page }) => {
    const host = `e2e-additional-${Date.now()}.example.com`;
    const escapedBase = BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    await page.goto('/login');
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL(/\/account(\/)?$/);

    await page.goto('/account/websites');
    const existingHosts = await page.locator('main a[href^="/account/websites/"]:not([href="/account/websites/new"])').allTextContents();
    expect(existingHosts.length, 'the disposable customer fixture starts with a website').toBeGreaterThan(0);

    await page.goto('/account/websites/new');
    await page.locator('input[name="url"]').fill(host);
    await page.locator('input[name="type"][value="woocommerce"]').check();
    await page.locator('main form button[type="submit"]').click();
    await page.waitForURL(/\/account\/websites\/options$/);
    await expect(page.locator('main')).toContainText(`https://${host}`);

    await page.locator('form[action$="/account/websites/purchase"]:has(input[name="plan"][value="secure"]):has(input[name="interval"][value="annual"]) button[type="submit"]').click();
    await page.waitForURL(/\/checkout\?/);
    await expect(page.locator('main')).toContainText(`https://${host}`);
    const terms = page.locator('input[name="terms_accepted"]');
    if (await terms.count()) await terms.check();
    await page.locator('form button[type="submit"]').last().click();

    await payOnStripeCheckout(page);
    await page.waitForURL(new RegExp(`^${escapedBase}/checkout/success\\?session_id=cs_test_`), { timeout: 90_000 });
    await expect(page.locator('main')).toContainText(/connect website|weboldal csatlakoztatása/i);
    await expect(page.getByRole('link', { name: /log in|sign in|belépés/i })).toHaveCount(0);

    await page.goto('/account/websites');
    await expect(page.locator('main')).toContainText(host);
    for (const previousHost of existingHosts) {
        await expect(page.locator('main')).toContainText(previousHost.trim());
    }

    await page.goto('/account/subscription');
    await expect(page.locator('main')).toContainText(/Secure/);
    await expect(page.locator('main')).toContainText(/WooCommerce/);
});
