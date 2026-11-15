import { expect, test } from '@playwright/test';

const configuredUrl = process.env.E2E_BASE_URL;
const target = configuredUrl ? new URL(configuredUrl) : null;

test.skip(!target, 'Set E2E_BASE_URL to a running local app or an explicitly chosen acceptance target.');

test('HU and EN public pages render, link to information, and switch language', async ({ page }) => {
    const unexpectedRequests: string[] = [];
    if (target) {
        await page.route('**/*', async (route) => {
            const request = new URL(route.request().url());
            if (request.origin !== target!.origin) {
                unexpectedRequests.push(request.origin);
                await route.abort();
                return;
            }
            await route.continue();
        });
    }

    for (const width of [375, 1440]) {
      for (const locale of ['en', 'hu'] as const) {
        await page.setViewportSize({ width, height: width < 500 ? 812 : 900 });
        const response = await page.goto(`/${locale}/`);
        expect(response?.status()).toBe(200);
        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        await expect(page.locator('main h1')).toBeVisible();

        const links = await page.locator('a[href]').evaluateAll((elements) => elements.map((element) => ({
            href: (element as HTMLAnchorElement).getAttribute('href') ?? '',
            text: element.textContent?.trim() ?? '',
        })));
        expect(links.some(({ href, text }) => /pricing|#pricing/i.test(href + ' ' + text))).toBeTruthy();
        expect(links.some(({ href, text }) => /contact|mailto:/i.test(href + ' ' + text))).toBeTruthy();
        // Informational CTAs may lead to pricing, but no public registration surface is linked.
        expect(links.some(({ href }) => /\/register(?:[/?#]|$)|\/signup(?:[/?#]|$)/i.test(href))).toBe(false);

        const menuButton = page.locator('header button[x-ref="menuButton"]');
        if (await menuButton.isVisible()) await menuButton.click();
        const alternate = locale === 'en' ? 'hu' : 'en';
        const languageLink = page.locator(`a[href^="/${alternate}/"]`).first();
        await expect(languageLink).toBeVisible();
        await languageLink.click();
        await expect(page.locator('html')).toHaveAttribute('lang', alternate);
        await expect(page).toHaveURL(new RegExp(`/${alternate}/`));
      }
    }
    expect(unexpectedRequests).toEqual([]);
});

test('public home and pricing pages fit desktop and mobile viewports', async ({ page }) => {
    for (const width of [375, 1440]) {
        await page.setViewportSize({ width, height: width < 500 ? 812 : 900 });
        for (const locale of ['en', 'hu'] as const) {
            for (const path of [`/${locale}/`, `/${locale}/pricing`]) {
                const response = await page.goto(path);
                expect(response?.status(), path).toBe(200);
                await expect(page.locator('html')).toHaveAttribute('lang', locale);
                const dimensions = await page.evaluate(() => ({
                    viewport: document.documentElement.clientWidth,
                    document: document.documentElement.scrollWidth,
                    body: document.body.scrollWidth,
                }));
                expect(dimensions.document, `${path} at ${width}px`).toBeLessThanOrEqual(dimensions.viewport);
                expect(dimensions.body, `${path} at ${width}px`).toBeLessThanOrEqual(dimensions.viewport);
            }
        }
    }
});

test('marketing pages do not expose registration, provisioning, or paid SaaS actions', async ({ page }) => {
    const publicPaths = ['/en/', '/hu/', '/en/pricing', '/hu/pricing'];
    const forbidden = /\/(?:register|signup|checkout|account|connector|api\/v1\/connector|subscriptions?)(?:[/?#]|$)|stripe\/webhook|provision/i;
    for (const path of publicPaths) {
        await page.goto(path);
        const hrefs = await page.locator('a[href]').evaluateAll((elements) =>
            elements.map((element) => (element as HTMLAnchorElement).getAttribute('href') ?? ''),
        );
        expect(hrefs.filter((href) => forbidden.test(href)), `${path} contains a transactional/service link`).toEqual([]);
        // Avoid activating controls that could create an account, charge, or provision anything.
        const forms = await page.locator('form').evaluateAll((elements) => elements.map((form) => ({
            action: (form as HTMLFormElement).action,
            method: (form as HTMLFormElement).method.toUpperCase(),
        })));
        expect(forms.filter(({ action, method }) => forbidden.test(action) && method !== 'GET')).toEqual([]);
    }

    // GET-only probes cover the marketing-only route boundary without invoking
    // checkout, Stripe, pairing, API writes, or provisioning handlers.
    for (const path of [
        '/register', '/signup', '/checkout', '/checkout/success',
        '/account/websites/new', '/api/v1/connector/pair',
    ]) {
        const response = await page.request.get(path, { maxRedirects: 0 });
        expect([404, 405, 410, 419, 422]).toContain(response.status());
    }
    const webhook = await page.request.get('/stripe/webhook', { maxRedirects: 0 });
    expect([404, 405, 419]).toContain(webhook.status());
    const connectorJobs = await page.request.get('/api/v1/connector/jobs', { maxRedirects: 0 });
    expect([401, 403, 404, 405]).toContain(connectorJobs.status());
    const account = await page.request.get('/account', { maxRedirects: 0 });
    expect([302, 303, 401, 403, 404]).toContain(account.status());
    const paidSaaS = await page.request.get('/account/subscription', { maxRedirects: 0 });
    expect([302, 303, 401, 403, 404]).toContain(paidSaaS.status());
});

test('HTTPS target redirects and sends baseline security headers', async ({ page }) => {
    test.skip(!target || target.protocol !== 'https:', 'HTTPS/header acceptance applies only to an explicitly configured HTTPS target.');
    const root = await page.request.get('/', { maxRedirects: 0 });
    expect([301, 302, 307, 308]).toContain(root.status());
    expect(root.headers().location).toMatch(/^https:\/\//i);

    const response = await page.request.get('/en/');
    expect(response.status()).toBe(200);
    const headers = response.headers();
    expect(headers['strict-transport-security']).toBeTruthy();
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-frame-options'] ?? headers['content-security-policy']).toBeTruthy();
});
