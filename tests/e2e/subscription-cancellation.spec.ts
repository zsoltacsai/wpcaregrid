import { readFileSync } from 'node:fs';
import { expect, test, type Page } from '@playwright/test';

type CancellationFixtures = {
    base_url: string;
    password: string;
    customers: Record<string, { email: string; locale: 'en' | 'hu' }>;
    subscriptions: Record<string, string>;
};

const fixturePath = process.env.M16_CANCELLATION_FIXTURES_PATH
    ?? 'storage/framework/testing/subscription-cancellation.json';
const fixtures = JSON.parse(readFileSync(fixturePath, 'utf8')) as CancellationFixtures;

const messages = {
    en: {
        pendingCancellation: 'We received your cancellation request',
        confirmedCancellation: 'Stripe confirmed that this subscription will end',
        pendingReactivation: 'We received your request to keep this subscription active',
        confirmedReactivation: 'Stripe confirmed that this subscription will remain active',
        reconciliation: 'status needs to be checked',
        cancel: 'Cancel at period end',
        reactivate: 'Keep subscription active',
    },
    hu: {
        pendingCancellation: 'Megkaptuk a lemondási kérelmet',
        confirmedCancellation: 'A Stripe visszaigazolta, hogy az előfizetés',
        pendingReactivation: 'Megkaptuk az előfizetés megtartására vonatkozó kérelmet',
        confirmedReactivation: 'A Stripe visszaigazolta, hogy az előfizetés aktív marad',
        reconciliation: 'állapot egyeztetést igényel',
        cancel: 'Lemondás az időszak végén',
        reactivate: 'Előfizetés megtartása',
    },
} as const;

async function signIn(page: Page, customerKey: 'en' | 'hu'): Promise<void> {
    const customer = fixtures.customers[customerKey];
    await page.goto('/login');
    await page.locator('input[name="email"]').fill(customer.email);
    await page.locator('input[name="password"]').fill(fixtures.password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/account(?:\/)?$/);
}

async function watchReadOnlyPage(page: Page): Promise<{ external: string[]; mutations: string[]; errors: string[] }> {
    const evidence = { external: [] as string[], mutations: [] as string[], errors: [] as string[] };
    page.on('pageerror', error => evidence.errors.push(error.message));
    page.on('request', request => {
        const url = new URL(request.url());
        if (url.origin !== new URL(fixtures.base_url).origin) evidence.external.push(url.origin);
        if (request.method() !== 'GET' && request.method() !== 'HEAD') evidence.mutations.push(`${request.method()} ${url.pathname}`);
    });
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.origin !== new URL(fixtures.base_url).origin) {
            await route.abort();
            return;
        }
        await route.continue();
    });
    return evidence;
}

async function openSubscription(page: Page, key: string) {
    return page.goto(`/account/subscriptions/${fixtures.subscriptions[key]}`);
}

async function expectNoCancellationActions(page: Page): Promise<void> {
    await expect(page.locator('form[action$="/cancel"]')).toHaveCount(0);
    await expect(page.locator('form[action$="/reactivate"]')).toHaveCount(0);
}

test('pending cancellation persists after refresh, suppresses repeats, and stays read-only', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await signIn(page, 'en');
    const evidence = await watchReadOnlyPage(page);

    await openSubscription(page, 'pending_cancellation');
    const status = page.getByRole('status');
    await expect(status).toContainText(messages.en.pendingCancellation);
    await expect(status).toHaveAttribute('aria-live', 'polite');
    await expectNoCancellationActions(page);
    await page.screenshot({ path: testInfo.outputPath('pending-cancellation-en-desktop.png'), fullPage: true });

    await page.reload();
    await expect(page.getByRole('status')).toContainText(messages.en.pendingCancellation);
    await expectNoCancellationActions(page);
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('confirmed cancellation offers only keyboard-accessible reactivation in English', async ({ page }, testInfo) => {
    await signIn(page, 'en');
    const evidence = await watchReadOnlyPage(page);
    await openSubscription(page, 'confirmed_cancellation');

    await expect(page.getByRole('status')).toContainText(messages.en.confirmedCancellation);
    const cancelForm = page.locator('form[action$="/cancel"]');
    const reactivateForm = page.locator('form[action$="/reactivate"]');
    await expect(cancelForm).toHaveCount(0);
    await expect(reactivateForm).toHaveCount(1);
    const reactivateButton = reactivateForm.getByRole('button', { name: messages.en.reactivate });
    await expect(reactivateButton).toBeVisible();

    let focused = false;
    for (let i = 0; i < 40 && !focused; i++) {
        await page.keyboard.press('Tab');
        focused = await reactivateButton.evaluate(element => element === document.activeElement);
    }
    expect(focused, 'keyboard Tab reaches the available reactivation action').toBeTruthy();
    const visibleFocus = await reactivateButton.evaluate(element => {
        const style = getComputedStyle(element);
        return element.matches(':focus-visible') && style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) >= 2;
    });
    expect(visibleFocus).toBeTruthy();
    await page.screenshot({ path: testInfo.outputPath('confirmed-cancellation-en-focus.png'), fullPage: true });
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('new pending reactivation supersedes old cancellation in Hungarian on mobile', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, 'hu');
    const evidence = await watchReadOnlyPage(page);
    await openSubscription(page, 'pending_reactivation');

    await expect(page.getByRole('status')).toContainText(messages.hu.pendingReactivation);
    await expect(page.getByRole('status')).not.toContainText(messages.hu.confirmedCancellation);
    await expectNoCancellationActions(page);
    await page.screenshot({ path: testInfo.outputPath('pending-reactivation-hu-mobile.png'), fullPage: true });
    const hasOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(hasOverflow).toBeFalsy();
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('confirmed reactivation in Hungarian enables a later cancellation request', async ({ page }) => {
    await signIn(page, 'hu');
    const evidence = await watchReadOnlyPage(page);
    await openSubscription(page, 'confirmed_reactivation');

    await expect(page.getByRole('status')).toContainText(messages.hu.confirmedReactivation);
    await expect(page.locator('form[action$="/reactivate"]')).toHaveCount(0);
    await expect(page.locator('form[action$="/cancel"]')).toHaveCount(1);
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('reconciliation-required operation never claims success or offers duplicate lifecycle actions', async ({ page }) => {
    await signIn(page, 'en');
    const evidence = await watchReadOnlyPage(page);
    await openSubscription(page, 'reconciliation_required');

    const status = page.getByRole('status');
    await expect(status).toContainText(messages.en.reconciliation);
    await expect(status).not.toContainText(messages.en.confirmedReactivation);
    await expectNoCancellationActions(page);
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('confirmed reactivation with a stale Stripe mirror fails closed', async ({ page }) => {
    await signIn(page, 'en');
    const evidence = await watchReadOnlyPage(page);
    await openSubscription(page, 'stale_provider_mirror');

    const status = page.getByRole('status');
    await expect(status).toContainText(messages.en.reconciliation);
    await expect(status).not.toContainText(messages.en.confirmedReactivation);
    await expectNoCancellationActions(page);
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});

test('foreign subscription URL is denied without revealing its cancellation status', async ({ page }) => {
    await signIn(page, 'en');
    const evidence = await watchReadOnlyPage(page);
    const response = await openSubscription(page, 'foreign_tenant');

    expect(response?.status()).toBe(403);
    await expect(page.getByRole('status')).toHaveCount(0);
    expect(evidence.mutations).toEqual([]);
    expect(evidence.external).toEqual([]);
    expect(evidence.errors).toEqual([]);
});
