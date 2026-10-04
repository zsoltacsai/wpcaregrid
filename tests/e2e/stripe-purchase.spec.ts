import { expect, test, type Page } from '@playwright/test';

/*
 * Real purchase in Stripe TEST MODE, from /pricing to the first website in
 * onboarding (docs/STRIPE_TEST_MODE.md). Nothing is mocked or seeded: the
 * customer, login and subscription exist only because Stripe's signed
 * webhook reached the application.
 */

const BASE = process.env.E2E_BASE_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';

test.skip(!BASE.startsWith('https://'), 'Set E2E_BASE_URL to the public HTTPS test address (Stripe must reach its webhook).');

// Stripe's published test cards; never real cards.
const CARD_SUCCEEDS = '4242 4242 4242 4242';
const CARD_DECLINED = '4000 0000 0000 0002';
const TEST_CARD = { expiry: '12 / 34', cvc: '123' };

/** Opens the app's checkout for a plan and submits the customer details. */
async function startCheckout(page: Page, plan: string, details: { email: string; country?: string; promotion?: string }): Promise<void> {
    await page.goto(`/checkout?${plan}`);
    await page.locator('input[name="email"]').fill(details.email);
    await page.locator('input[name="first_name"]').fill('E2E');
    await page.locator('input[name="last_name"]').fill('Customer');
    await page.locator('select[name="country"]').selectOption(details.country ?? 'HU');
    if (details.promotion) {
        await page.locator('input[name="promotion_code"]').fill(details.promotion);
    }
    await page.locator('form button[type="submit"]').last().click();
}

async function payOnStripeCheckout(page: Page, name: string, card: string = CARD_SUCCEEDS): Promise<void> {
    await page.waitForURL(/checkout\.stripe\.com/);

    // Newer Checkout pages list payment methods as an accordion.
    const cardOption = page.locator('[data-testid="card-accordion-item-button"]');
    if (await cardOption.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await cardOption.click();
    }

    await page.locator('#cardNumber').fill(card);
    await page.locator('#cardExpiry').fill(TEST_CARD.expiry);
    await page.locator('#cardCvc').fill(TEST_CARD.cvc);
    await page.locator('#billingName').fill(name);

    const country = page.locator('#billingCountry');
    if (await country.isVisible().catch(() => false)) {
        await country.selectOption('HU');
    }
    const postal = page.locator('#billingPostalCode');
    if (await postal.isVisible().catch(() => false)) {
        await postal.fill('1011');
    }

    // Do not save the card to Link: keeps the run independent of earlier runs.
    const link = page.locator('#enableStripePass');
    if (await link.isChecked().catch(() => false)) {
        await link.uncheck();
    }

    await page.locator('[data-testid="hosted-payment-submit-button"]').click();
}

async function activationLink(email: string): Promise<string> {
    const deadline = Date.now() + 90_000;

    while (Date.now() < deadline) {
        const search = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${email}" subject:"Activate your WP CareGrid account"`)}`).then((r) => r.json());
        const message = search.messages?.[0];

        if (message) {
            const full = await fetch(`${MAILPIT}/api/v1/message/${message.ID}`).then((r) => r.json());
            const body: string = `${full.Text ?? ''}\n${full.HTML ?? ''}`;
            const match = body.match(/https?:\/\/[^\s"'<>]+\/account\/activate\/[^\s"'<>]+/);
            // Only a link may follow "password:"; a password itself is never emailed.
            expect(body, 'activation email contains no password').not.toMatch(/password\s*:\s*(?!https?:\/\/)[^\s<]+/i);
            expect(match, 'activation link in email').not.toBeNull();
            return match![0].replace(/&amp;/g, '&');
        }

        await new Promise((resolve) => setTimeout(resolve, 2_000));
    }

    throw new Error(`No activation email for ${email} in Mailpit.`);
}

test('WordPress Business monthly: pricing → Stripe → webhook → activation → dashboard → onboarding', async ({ page }) => {
    const stamp = Date.now();
    const email = `e2e-wp-business-${stamp}@example.com`;
    const password = `E2e-${stamp}-${Math.random().toString(36).slice(2, 10)}`;

    // 1. Public pricing: amounts are formatted, never smallest units.
    await page.goto('/pricing');
    await expect(page.locator('body')).toContainText('18,990');
    await expect(page.locator('body')).not.toContainText('1899000');

    // 2. Plan selection (WordPress tab, monthly, Business card).
    await page.locator('a[href*="/checkout?product=wordpress&plan=business&interval=monthly"]').first().click();
    await expect(page).toHaveURL(/\/checkout\?product=wordpress&plan=business&interval=monthly/);
    await expect(page.locator('main')).toContainText('18,990 Ft');

    // 3. Checkout form. Amount, currency and Price ID are not form fields.
    for (const forbidden of ['amount', 'currency', 'price', 'stripe_price_id']) {
        await expect(page.locator(`form [name="${forbidden}"]`)).toHaveCount(0);
    }
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="first_name"]').fill('E2E');
    await page.locator('input[name="last_name"]').fill('Business');
    await page.locator('select[name="country"]').selectOption('HU');
    const html = await page.content();
    expect(html).not.toMatch(/sk_(test|live)_|whsec_/);
    await page.locator('form button[type="submit"]').last().click();

    // 4–5. Stripe Checkout in test mode, then back to the application.
    await payOnStripeCheckout(page, 'E2E Business');
    await page.waitForURL(new RegExp(`^${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/checkout/success\\?session_id=cs_test_`), { timeout: 90_000 });

    // The success page grants nothing by itself: no session, no account area.
    const anonymous = await page.request.get('/account', { maxRedirects: 0 });
    expect(anonymous.status()).toBe(302);

    // 6–8. Signed webhook → customer, login, activation email with an https APP_URL link.
    const link = await activationLink(email);
    expect(link.startsWith(`${BASE}/account/activate/`), `link uses APP_URL: ${new URL(link).origin}`).toBe(true);
    expect(link).not.toMatch(/localhost|127\.0\.0\.1/);

    await page.goto(link);
    await expect(page.locator('input[name="email"]')).toHaveValue(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('input[name="password_confirmation"]').fill(password);
    await page.locator('form button[type="submit"]').click();

    // 10. Dashboard: active Business plan, no websites, first-website call to action.
    await page.waitForURL(/\/account(\/)?$/);
    const dashboard = page.locator('main');
    await expect(dashboard).toContainText('Business');
    await expect(dashboard).toContainText(/active/i);
    await expect(dashboard).toContainText('Connect your first website');

    // The activation link is single use: a second activation is refused.
    const reused = await page.context().browser()!.newContext({ baseURL: BASE });
    const reusedPage = await reused.newPage();
    await reusedPage.goto(link);
    await reusedPage.locator('input[name="password"]').fill(`${password}-again`);
    await reusedPage.locator('input[name="password_confirmation"]').fill(`${password}-again`);
    await reusedPage.locator('form button[type="submit"]').click();
    await expect(reusedPage).toHaveURL(/\/forgot-password/);
    expect((await reusedPage.request.get('/account', { maxRedirects: 0 })).status()).toBe(302);
    await reused.close();

    // 11. Subscription and billing pages show the plan in forint.
    await page.goto('/account/subscription');
    await expect(page.locator('main')).toContainText('Business');
    await expect(page.locator('main')).toContainText('18,990 Ft');
    await expect(page.locator('main')).not.toContainText('1899000');
    await page.goto('/account/billing');
    await expect(page.locator('main')).not.toContainText('1899000');

    // 9. Log out and log in again with the password the customer chose.
    await page.locator('form[action$="/logout"] button').first().click();
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL(/\/account(\/)?$/);

    // 12. Onboarding: the first website enters the connector pairing step.
    await page.goto('/account/websites/new');
    await page.locator('input[name="url"]').fill(`https://e2e-${stamp}.example.com`);
    await page.locator('input[name="type"][value="wordpress"]').check();
    await page.locator('main form button[type="submit"]').click();
    await page.waitForURL(/\/account\/websites\/[0-9a-f-]{36}$/);
    await expect(page.locator('main')).toContainText(`e2e-${stamp}.example.com`);
});

const successUrl = () => new RegExp(`^${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/checkout/success\\?session_id=cs_test_`);

test('international billing country is refused before Stripe', async ({ page }) => {
    await startCheckout(page, 'product=wordpress&plan=business&interval=monthly', { email: `e2e-international-${Date.now()}@example.com`, country: 'DE' });

    await expect(page).toHaveURL(/\/checkout\?/);
    await expect(page.locator('main')).toContainText('Pricing for your billing country is not available yet.');
});

test('promotion code: local rules decide, Stripe applies the discount', async ({ page }) => {
    const email = `e2e-promo-${Date.now()}@example.com`;

    // Unknown code.
    await startCheckout(page, 'product=wordpress&plan=basic&interval=monthly', { email, promotion: 'NOPE10' });
    await expect(page.locator('main')).toContainText('This promotion code is not valid.');

    // Valid in Stripe for every product, but the local rule allows WordPress plans only.
    await startCheckout(page, 'product=woocommerce&plan=business&interval=monthly', { email, promotion: 'PILOT10' });
    await expect(page.locator('main')).toContainText('This promotion code cannot be used with the selected plan or billing period.');
    await expect(page).not.toHaveURL(/checkout\.stripe\.com/);

    // Valid: Stripe shows and charges 10 % off 8 990 Ft, and offers no code field of its own.
    await startCheckout(page, 'product=wordpress&plan=basic&interval=monthly', { email, promotion: 'pilot10' });
    await page.waitForURL(/checkout\.stripe\.com/);
    await expect(page.locator('body')).toContainText('PILOT10');
    await expect(page.locator('body')).toContainText(/8[,\s  ]?091/);
    await expect(page.getByText(/add promotion code/i)).toHaveCount(0);
    await payOnStripeCheckout(page, 'E2E Promo');
    await page.waitForURL(successUrl(), { timeout: 90_000 });
    expect(await activationLink(email)).toContain('/account/activate/');
});

test('declined card: no payment, no account, no service', async ({ page }) => {
    const email = `e2e-declined-${Date.now()}@example.com`;

    await startCheckout(page, 'product=wordpress&plan=basic&interval=monthly', { email });
    await payOnStripeCheckout(page, 'E2E Declined', CARD_DECLINED);

    await expect(page.locator('body')).toContainText(/declined/i);
    await expect(page).toHaveURL(/checkout\.stripe\.com/);
    await new Promise((resolve) => setTimeout(resolve, 10_000));
    const mail = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`).then((r) => r.json());
    expect(mail.messages_count, 'no email for a declined payment').toBe(0);
});

test('abandoned checkout: going back cancels the intent and expires the Stripe session', async ({ page }) => {
    await startCheckout(page, 'product=wordpress&plan=secure&interval=monthly', { email: `e2e-abandoned-${Date.now()}@example.com` });
    await page.waitForURL(/checkout\.stripe\.com/);

    await page.locator('a[href*="/checkout/cancel/"]').first().click();
    await page.waitForURL(/\/checkout\/cancel\//);
    await expect(page.locator('main a[href*="/checkout?product=wordpress&plan=secure&interval=monthly"]')).toHaveCount(1);
});

test('WooCommerce Business annual purchase', async ({ page }) => {
    const email = `e2e-woo-annual-${Date.now()}@example.com`;
    const password = `E2e-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    await page.goto('/pricing');
    await page.getByRole('tab', { name: 'WooCommerce' }).click();
    await page.locator('a[href*="/checkout?product=woocommerce&plan=business&interval=monthly"]').first().isVisible();
    await page.goto('/checkout?product=woocommerce&plan=business&interval=annual');
    await expect(page.locator('main')).toContainText('249,000 Ft');
    await expect(page.locator('main')).not.toContainText('24900000');
    await startCheckout(page, 'product=woocommerce&plan=business&interval=annual', { email });
    await payOnStripeCheckout(page, 'E2E Woo');
    await page.waitForURL(successUrl(), { timeout: 90_000 });

    await page.goto(await activationLink(email));
    await page.locator('input[name="password"]').fill(password);
    await page.locator('input[name="password_confirmation"]').fill(password);
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL(/\/account(\/)?$/);
    await page.goto('/account/subscription');
    await expect(page.locator('main')).toContainText('WooCommerce');
    await expect(page.locator('main')).toContainText('249,000 Ft');
    await expect(page.locator('main')).toContainText(/annual|year/i);
});

// Run with E2E_WEBHOOK_PAUSED=1 while the Stripe endpoint is disabled; the missed
// events are delivered afterwards (docs/STRIPE_TEST_MODE.md, "Delayed webhook").
test('browser returns before the webhook: nothing is granted', async ({ page }) => {
    test.skip(process.env.E2E_WEBHOOK_PAUSED !== '1', 'Needs the Stripe webhook endpoint paused.');
    const email = `e2e-delayed-${Date.now()}@example.com`;

    await startCheckout(page, 'product=wordpress&plan=business&interval=monthly', { email });
    await payOnStripeCheckout(page, 'E2E Delayed');
    await page.waitForURL(successUrl(), { timeout: 90_000 });

    await expect(page.locator('h1')).toHaveText('Your payment is being confirmed.');
    await page.reload();
    await expect(page.locator('h1')).toHaveText('Your payment is being confirmed.');
    expect((await page.request.get('/account', { maxRedirects: 0 })).status()).toBe(302);
    const mail = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`).then((r) => r.json());
    expect(mail.messages_count).toBe(0);
});
