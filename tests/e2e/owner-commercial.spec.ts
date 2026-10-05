import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';

/*
 * Owner commercial operations in a real browser with Stripe in TEST MODE
 * (docs/COUPONS.md, docs/PROBONO.md, docs/RECOVERY_OFFERS.md): coupons created
 * in the owner area, a normal and a 100% coupon bought on Stripe's hosted
 * Checkout, PROBONO, and a custom recovery offer paid once, in English and in
 * Hungarian. Payment is confirmed only by Stripe's signed webhook, so the
 * application needs a public HTTPS address (docs/STRIPE_TEST_MODE.md):
 *
 *   E2E_BASE_URL=https://<public test address> MAILPIT_URL=http://127.0.0.1:8025 \
 *   E2E_OWNER_EMAIL=… E2E_OWNER_PASSWORD=… E2E_CUSTOMER_EMAIL=… E2E_CUSTOMER_PASSWORD=… \
 *   npx playwright test owner-commercial
 *
 * The owner and the customer are throw-away accounts of the local test database.
 */

const BASE = process.env.E2E_BASE_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';
const OWNER_EMAIL = process.env.E2E_OWNER_EMAIL ?? '';
const OWNER_PASSWORD = process.env.E2E_OWNER_PASSWORD ?? '';
const CUSTOMER_EMAIL = process.env.E2E_CUSTOMER_EMAIL ?? '';
const CUSTOMER_PASSWORD = process.env.E2E_CUSTOMER_PASSWORD ?? '';

test.skip(!BASE.startsWith('https://') || !OWNER_EMAIL || !CUSTOMER_EMAIL, 'Needs E2E_BASE_URL (public https), E2E_OWNER_* and E2E_CUSTOMER_*.');
test.describe.configure({ mode: 'serial' });

// Stripe's published test card; never a real card.
const CARD = { number: '4242 4242 4242 4242', expiry: '12 / 34', cvc: '123' };
const stamp = Date.now().toString(36).toUpperCase();
const CODE_TEN = `E2E10${stamp}`;
const CODE_FREE = `E2EFREE${stamp}`;
const ONE_HUNDRED_EMAIL = `e2e-free-${stamp.toLowerCase()}@example.com`;
const TEN_EMAIL = `e2e-ten-${stamp.toLowerCase()}@example.com`;

const noHorizontalScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

async function ownerLogin(page: Page): Promise<void> {
    await page.goto('/owner/login');
    await page.locator('input[name="email"]').fill(OWNER_EMAIL);
    await page.locator('input[name="password"]').fill(OWNER_PASSWORD);
    await page.locator('form button[type="submit"]').last().click();
    await page.waitForURL(/\/owner\/?$/);
}

async function customerLogin(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(CUSTOMER_EMAIL);
    await page.locator('input[name="password"]').fill(CUSTOMER_PASSWORD);
    await page.locator('form button[type="submit"]').last().click();
    await page.waitForURL(/\/account\/?$/);
}

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
    const context = await browser.newContext({ baseURL: BASE });
    return { context, page: await context.newPage() };
}

async function chooseLanguage(page: Page, name: 'Magyar' | 'English'): Promise<void> {
    await page.waitForLoadState('load');
    // Decided by the viewport, not by whether the toggle looks visible: right after a reload the stylesheet may not have applied yet.
    if ((page.viewportSize()?.width ?? 1280) < 1024) {
        await page.getByRole('button', { name: /^(Open menu|Menü megnyitása)$/ }).click();
    }
    await page.getByRole('group', { name: /^(Language|Nyelv)$/ }).getByRole('button', { name, exact: true }).first().click();
    await expect(page.locator('html')).toHaveAttribute('lang', name === 'Magyar' ? 'hu' : 'en');
}

/** The hosted Checkout page: a card when one is asked for, nothing when the total is zero and no card is needed. */
async function payOnStripe(page: Page, name: string, options: { card: boolean }): Promise<void> {
    await page.waitForURL(/checkout\.stripe\.com/);

    if (options.card) {
        const cardOption = page.locator('[data-testid="card-accordion-item-button"]');
        if (await cardOption.isVisible({ timeout: 5_000 }).catch(() => false)) {
            await cardOption.click();
        }
        await page.locator('#cardNumber').fill(CARD.number);
        await page.locator('#cardExpiry').fill(CARD.expiry);
        await page.locator('#cardCvc').fill(CARD.cvc);
    } else {
        await expect(page.locator('#cardNumber'), 'a zero-total checkout asks for no card').toHaveCount(0);
    }

    const billingName = page.locator('#billingName');
    if (await billingName.isVisible().catch(() => false)) {
        await billingName.fill(name);
    }
    const country = page.locator('#billingCountry');
    if (await country.isVisible().catch(() => false)) {
        await country.selectOption('HU');
    }
    const postal = page.locator('#billingPostalCode');
    if (await postal.isVisible().catch(() => false)) {
        await postal.fill('1011');
    }
    const link = page.locator('#enableStripePass');
    // isChecked() waits for the element; a zero-total page has none.
    if ((await link.isVisible().catch(() => false)) && (await link.isChecked().catch(() => false))) {
        await link.uncheck();
    }

    if (options.card) {
        await page.locator('[data-testid="hosted-payment-submit-button"]').click();
    } else {
        // Zero total, no card: Stripe offers "Start for free" with only the email and a subscribe button.
        await page.getByRole('button', { name: /subscribe/i }).click();
    }
}

async function mailSubjects(email: string): Promise<string[]> {
    const search = await fetch(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`).then((r) => r.json());
    return (search.messages ?? []).map((m: { Subject: string }) => m.Subject);
}

async function waitForMail(email: string, subject: string | RegExp, timeoutMs = 90_000): Promise<void> {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        const subjects = await mailSubjects(email);
        if (subjects.some((s) => (typeof subject === 'string' ? s === subject : subject.test(s)))) {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 2_000));
    }
    throw new Error(`No email "${subject}" for ${email} in Mailpit.`);
}

/** Reload until the text appears: the webhook may arrive a moment after the browser. */
async function reloadUntil(page: Page, text: string | RegExp, timeoutMs = 90_000): Promise<void> {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        if (await page.locator('main').filter({ hasText: text }).count()) {
            return;
        }
        if (Date.now() > deadline) {
            throw new Error(`"${text}" did not appear on ${page.url()}`);
        }
        await new Promise((resolve) => setTimeout(resolve, 3_000));
        await page.reload();
    }
}

async function startCheckout(page: Page, email: string, code: string): Promise<void> {
    await page.goto('/checkout?product=wordpress&plan=business&interval=monthly');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="first_name"]').fill('E2E');
    await page.locator('input[name="last_name"]').fill('Coupon');
    await page.locator('select[name="country"]').selectOption('HU');
    await page.locator('input[name="promotion_code"]').fill(code);
    await page.locator('form button[type="submit"]').last().click();
}

async function ownerCouponUses(page: Page, code: string): Promise<number> {
    await page.goto(`/owner/coupons/${code}`);
    const text = await page.locator('#coupon-discount dl').innerText();
    const match = text.match(/Used\s*\n?\s*(\d+)/i);
    return match ? Number(match[1]) : -1;
}

// -------------------------------------------------------------------------------------------------

test('public site: PROBONO is nowhere, and cannot be reached or bought', async ({ page }) => {
    for (const language of ['English', 'Magyar'] as const) {
        await page.goto('/');
        await chooseLanguage(page, language);
        for (const path of ['/', '/pricing']) {
            await page.goto(path);
            expect((await page.content()).toLowerCase()).not.toContain('probono');
            if (path === '/pricing') {
                await expect(page.locator('body')).toContainText(language === 'English' ? /18,990/ : /18[\s  ]990/);
            }
        }
    }

    await page.goto('/checkout?product=wordpress&plan=probono&interval=monthly');
    await expect(page).toHaveURL(/\/pricing$/);
    expect((await page.request.get('/probono')).status()).toBe(404);

    // A forged plan in the form is refused by the server.
    await page.goto('/checkout?product=wordpress&plan=business&interval=monthly');
    await page.evaluate(() => {
        const input = document.querySelector('input[name="plan"]') as HTMLInputElement;
        input.value = 'probono';
    });
    await page.locator('input[name="email"]').fill('forged@example.com');
    await page.locator('input[name="first_name"]').fill('F');
    await page.locator('input[name="last_name"]').fill('Orged');
    await page.locator('select[name="country"]').selectOption('HU');
    await page.locator('form button[type="submit"]').last().click();
    await expect(page).not.toHaveURL(/stripe\.com/);
});

test('owner: coupons are created, edited and switched in the owner area', async ({ page }) => {
    await ownerLogin(page);
    await expect(page.getByRole('link', { name: 'Coupons' })).toBeVisible();
    await page.goto('/owner/coupons');
    await expect(page.locator('h1')).toHaveText('Coupons');

    // The percentage is validated: above 100 and zero are refused, nothing reaches Stripe.
    await page.goto('/owner/coupons/create');
    for (const bad of ['101', '0', '-5', 'abc']) {
        await page.locator('input[name="code"]').fill(`BAD${stamp}`);
        await page.locator('input[name="percent_off"]').fill(bad);
        await page.locator('form button[type="submit"]').last().click();
        await expect(page.locator('main')).toContainText('Enter a percentage above 0 and up to 100');
    }

    // 10% once.
    await page.goto('/owner/coupons/create');
    await page.locator('input[name="code"]').fill(CODE_TEN);
    await page.locator('input[name="percent_off"]').fill('10');
    await page.locator('textarea[name="note"]').fill('E2E internal note, never shown to customers');
    await page.locator('form button[type="submit"]').last().click();
    await expect(page.locator('main')).toContainText(`Coupon ${CODE_TEN} created.`);
    await expect(page.locator('h1')).toHaveText(CODE_TEN);

    // 100% for ever: the hint explains that no payment method is asked for.
    await page.goto('/owner/coupons/create');
    await page.locator('input[name="code"]').fill(CODE_FREE);
    await page.locator('input[name="percent_off"]').fill('100');
    await page.locator('select[name="duration"]').selectOption('forever');
    await expect(page.locator('main')).toContainText('does not ask for a payment method');
    await page.locator('form button[type="submit"]').last().click();
    await expect(page.locator('main')).toContainText(`Coupon ${CODE_FREE} created.`);

    // Rules are editable; the discount is fixed.
    await page.goto(`/owner/coupons/${CODE_TEN}`);
    await page.locator('input[name="max_redemptions"]').fill('5');
    await page.locator('#coupon-rules button[type="submit"]').click();
    await expect(page.locator('main')).toContainText('Coupon rules saved.');
    await expect(page.locator('#coupon-discount')).toContainText('Redemptions left');

    await page.goto('/owner/coupons');
    await expect(page.locator('main')).toContainText(CODE_TEN);
    await expect(page.locator('main')).toContainText(CODE_FREE);
    await expect(page.locator('main')).not.toContainText('E2E internal note');

    // Deactivate and activate (Stripe switches the promotion code too).
    await page.goto(`/owner/coupons/${CODE_TEN}`);
    await page.getByRole('button', { name: 'Deactivate' }).click();
    await expect(page.locator('main')).toContainText('The checkout refuses it');
    await page.getByRole('button', { name: 'Activate' }).click();
    await expect(page.locator('main')).toContainText(`Coupon ${CODE_TEN} is active.`);
});

test('a normal coupon: 10% off on Stripe, paid with a card, confirmed by the webhook', async ({ page, browser }) => {
    await startCheckout(page, TEN_EMAIL, CODE_TEN.toLowerCase());

    await page.waitForURL(/checkout\.stripe\.com/);
    // 18,990 Ft less 10%.
    await expect(page.locator('body')).toContainText(/17[\s,.\u00a0\u202f]?091/);
    await payOnStripe(page, 'E2E Ten', { card: true });
    await page.waitForURL(new RegExp(`^${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/checkout/success`), { timeout: 90_000 });

    await waitForMail(TEN_EMAIL, 'Activate your WP CareGrid account');

    const { context, page: owner } = await newContext(browser);
    await ownerLogin(owner);
    await expect.poll(() => ownerCouponUses(owner, CODE_TEN), { timeout: 30_000 }).toBe(1);
    await context.close();
});

test('a 100% coupon for ever: zero total, no card, still provisioned once by the webhook', async ({ page, browser }) => {
    await startCheckout(page, ONE_HUNDRED_EMAIL, CODE_FREE);

    await page.waitForURL(/checkout\.stripe\.com/);
    // 18,990 less 100%: exactly zero due today, never negative, and no payment details are asked for.
    await expect(page.locator('body')).toContainText(/100% off/i);
    await expect(page.locator('body')).toContainText(/Total due today\s*HUF\s*0\.00/i);
    await payOnStripe(page, 'E2E Free', { card: false });
    await page.waitForURL(new RegExp(`^${BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/checkout/success`), { timeout: 90_000 });

    await waitForMail(ONE_HUNDRED_EMAIL, 'Activate your WP CareGrid account');
    await new Promise((resolve) => setTimeout(resolve, 8_000));
    const activations = (await mailSubjects(ONE_HUNDRED_EMAIL)).filter((s) => s === 'Activate your WP CareGrid account');
    expect(activations, 'exactly one account is provisioned').toHaveLength(1);
    expect((await mailSubjects(ONE_HUNDRED_EMAIL)).filter((s) => s === 'Payment received'), 'a zero invoice is not a payment').toHaveLength(0);

    const { context, page: owner } = await newContext(browser);
    await ownerLogin(owner);
    await expect.poll(() => ownerCouponUses(owner, CODE_FREE), { timeout: 30_000 }).toBe(1);
    await owner.goto(`/owner/customers?q=${encodeURIComponent(ONE_HUNDRED_EMAIL)}`);
    await expect(owner.locator('main')).toContainText(ONE_HUNDRED_EMAIL);
    await expect(owner.locator('main')).toContainText('1 results');
    await context.close();
});

test('PROBONO: assigned by the owner, visible to the customer in both languages, never public', async ({ browser }) => {
    const owner = await newContext(browser);
    await ownerLogin(owner.page);

    await owner.page.goto(`/owner/customers?q=${encodeURIComponent(CUSTOMER_EMAIL)}`);
    await owner.page.getByRole('link', { name: /E2E Customer/ }).first().click();
    await expect(owner.page.locator('#customer-internal-plan')).toContainText('PROBONO is an internal plan that is never sold');

    // Re-runnable: end a PROBONO plan left by an earlier run (the owner confirms the dialog).
    owner.page.on('dialog', (dialog) => void dialog.accept());
    while (await owner.page.getByRole('button', { name: 'End PROBONO now' }).count()) {
        await owner.page.getByRole('button', { name: 'End PROBONO now' }).first().click();
        await expect(owner.page.locator('main')).toContainText('PROBONO ended.');
    }

    // Needs the confirmation.
    await owner.page.locator('#customer-internal-plan form').last().locator('button[type="submit"]').click();
    await expect(owner.page.locator('main')).toContainText('I confirm that this customer receives PROBONO');
    await owner.page.locator('#customer-internal-plan input[name="confirm"]').check();
    await owner.page.locator('#customer-internal-plan form').last().locator('button[type="submit"]').click();
    await expect(owner.page.locator('main')).toContainText('PROBONO assigned to');
    await expect(owner.page.locator('#customer-internal-plan')).toContainText('End PROBONO now');
    await owner.context.close();

    const customer = await newContext(browser);
    await customerLogin(customer.page);
    await chooseLanguage(customer.page, 'English');
    await customer.page.goto('/account/subscription');
    await expect(customer.page.locator('main')).toContainText('PROBONO');
    await expect(customer.page.locator('main')).toContainText('Not included in this plan');
    await expect(customer.page.locator('main')).toContainText('Backups: none are made or restored');
    await chooseLanguage(customer.page, 'Magyar');
    await expect(customer.page.locator('main')).toContainText('A csomag nem tartalmazza');
    await expect(customer.page.locator('main')).toContainText('Mentés: nem készül');
    await chooseLanguage(customer.page, 'English');

    // The customer's backup page says so too.
    await customer.page.goto('/account/websites');
    expect(await noHorizontalScroll(customer.page)).toBe(true);
    await customer.context.close();

    const anonymous = await newContext(browser);
    await anonymous.page.goto('/pricing');
    expect((await anonymous.page.content()).toLowerCase()).not.toContain('probono');
    await anonymous.context.close();
});

async function createAndSendOffer(owner: Page, title: string, amount: string): Promise<string> {
    await owner.goto('/owner/offers/create');
    await owner.locator('select[name="customer"]').selectOption({ label: `E2E Customer · ${CUSTOMER_EMAIL}` });
    await owner.getByRole('button', { name: 'Continue' }).click();
    await expect(owner.locator('select[name="currency"] option')).toHaveText(['HUF']);
    await owner.locator('input[name="title"]').fill(title);
    await owner.locator('textarea[name="scope"]').fill('Investigation of the suspicious files\nCleanup of confirmed files\nA scan afterwards\nA short report');
    await owner.locator('input[name="amount"]').fill(amount);
    await owner.locator('input[name="valid_until"]').fill(new Date(Date.now() + 7 * 864e5).toISOString().slice(0, 16));
    await owner.locator('textarea[name="internal_notes"]').fill('E2E owner-only note');
    await owner.getByRole('button', { name: 'Create draft' }).click();
    await expect(owner.locator('main')).toContainText('Draft created. Nothing has been sent to the customer.');
    const url = owner.url();

    await owner.locator('#offer-actions input[name="confirm"]').check();
    await owner.getByRole('button', { name: 'Send to customer' }).click();
    await expect(owner.locator('main')).toContainText('Offer sent.');
    return url;
}

test('recovery offer in English: owner sends, customer accepts and pays once, the webhook marks it paid', async ({ browser }) => {
    const owner = await newContext(browser);
    await ownerLogin(owner.page);
    const ownerOfferUrl = await createAndSendOffer(owner.page, `Malware cleanup EN ${stamp}`, '45000');

    await waitForMail(CUSTOMER_EMAIL, 'An offer for your website');

    const customer = await newContext(browser);
    await customerLogin(customer.page);
    await chooseLanguage(customer.page, 'English'); // the saved language of the customer, set explicitly so the run does not depend on the previous one
    await expect(customer.page.locator('main')).toContainText('You have 1 offer waiting for your answer.');
    await customer.page.getByRole('link', { name: 'Offers' }).first().click();
    await customer.page.getByRole('link', { name: `Malware cleanup EN ${stamp}` }).click();

    const page = customer.page;
    await expect(page.locator('main')).toContainText('Investigation of the suspicious files');
    await expect(page.locator('main')).toContainText('45,000');
    await expect(page.locator('main')).toContainText('one-time payment');
    await expect(page.locator('main')).not.toContainText('E2E owner-only note');
    expect(await noHorizontalScroll(page)).toBe(true);

    // The price is not a form field: there is nothing to tamper with.
    for (const field of ['amount', 'price', 'currency', 'scope', 'website']) {
        await expect(page.locator(`form [name="${field}"]`)).toHaveCount(0);
    }

    await page.getByRole('button', { name: 'Accept and pay' }).click();
    await page.waitForURL(/checkout\.stripe\.com/);
    await expect(page.locator('body')).toContainText(/45[\s,. ]?000/);
    await expect(page.locator('body')).toContainText(`Malware cleanup EN ${stamp}`);
    await payOnStripe(page, 'E2E Customer', { card: true });

    // Back in the application: confirmation comes from the webhook, never from this redirect.
    await page.waitForURL(/\/account\/offers\/[0-9a-f-]{36}/, { timeout: 90_000 });
    await reloadUntil(page, 'Payment confirmed');
    await expect(page.locator('main')).toContainText('Paid');
    await waitForMail(CUSTOMER_EMAIL, 'Payment received for your offer');

    // The owner sees it paid and completes it.
    await owner.page.goto(ownerOfferUrl);
    await expect(owner.page.locator('main')).toContainText('Paid');
    await owner.page.getByRole('button', { name: 'Mark work as completed' }).click();
    await expect(owner.page.locator('main')).toContainText('Marked as completed.');
    await page.reload();
    await expect(page.locator('main')).toContainText('The work described in this offer has been completed.');

    await owner.context.close();
    await customer.context.close();
});

test('recovery offer in Hungarian: the whole customer flow and the emails are Hungarian', async ({ browser }) => {
    const customer = await newContext(browser);
    await customerLogin(customer.page);
    await chooseLanguage(customer.page, 'Magyar');   // saved on the customer's own login

    const owner = await newContext(browser);
    await ownerLogin(owner.page);
    await createAndSendOffer(owner.page, `Kártevő-mentesítés HU ${stamp}`, '60000');

    await waitForMail(CUSTOMER_EMAIL, 'Ajánlat a weboldalára');

    const page = customer.page;
    await page.goto('/account');
    await expect(page.locator('main')).toContainText('1 ajánlat vár az Ön válaszára.');
    await page.getByRole('link', { name: 'Ajánlatok' }).first().click();
    await page.getByRole('link', { name: `Kártevő-mentesítés HU ${stamp}` }).click();

    await expect(page.locator('main')).toContainText('Mit tartalmaz az ajánlat');
    await expect(page.locator('main')).toContainText('egyszeri fizetés');
    await expect(page.locator('main')).toContainText(/60[\s  ]?000/);
    await expect(page.locator('main')).toContainText('Válaszra vár');
    expect(await noHorizontalScroll(page)).toBe(true);

    await page.getByRole('button', { name: 'Elfogadás és fizetés' }).click();
    await payOnStripe(page, 'E2E Customer', { card: true });
    await page.waitForURL(/\/account\/offers\/[0-9a-f-]{36}/, { timeout: 90_000 });
    await reloadUntil(page, 'A fizetés megerősítve');
    await expect(page.locator('main')).toContainText('Kifizetve');
    await waitForMail(CUSTOMER_EMAIL, 'Megérkezett az ajánlat díja');

    await owner.context.close();
    await customer.context.close();
});

test('mobile: owner coupon screens, the offer form and the customer offer page fit a 375px screen', async ({ browser }) => {
    const owner = await browser.newContext({ baseURL: BASE, viewport: { width: 375, height: 812 } });
    const ownerPage = await owner.newPage();
    await ownerLogin(ownerPage);
    for (const path of ['/owner/coupons', '/owner/coupons/create', `/owner/coupons/${CODE_TEN}`, '/owner/offers', '/owner/offers/create']) {
        await ownerPage.goto(path);
        expect(await noHorizontalScroll(ownerPage), `${path} has no horizontal scroll`).toBe(true);
        await expect(ownerPage.locator('h1')).toBeVisible();
    }
    await owner.close();

    const customer = await browser.newContext({ baseURL: BASE, viewport: { width: 375, height: 812 } });
    const page = await customer.newPage();
    await customerLogin(page);
    await page.goto('/account/offers');
    expect(await noHorizontalScroll(page)).toBe(true);
    await page.getByRole('link').filter({ hasText: /Malware cleanup EN|Kártevő/ }).first().click();
    expect(await noHorizontalScroll(page)).toBe(true);
    await expect(page.locator('main')).toContainText(/(45|60)[\s,.\u00a0\u202f]?000/);

    // The language control works on a phone and the page follows it.
    await chooseLanguage(page, 'English');
    await expect(page.locator('main')).toContainText('What the offer includes');
    await chooseLanguage(page, 'Magyar');
    await expect(page.locator('main')).toContainText('Mit tartalmaz az ajánlat');
    expect(await noHorizontalScroll(page)).toBe(true);
    await customer.close();
});
