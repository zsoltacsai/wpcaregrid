import { expect, test, type Page } from '@playwright/test';

const APP = 'http://127.0.0.1:8317';
const WORDPRESS = 'http://127.0.0.1:8318';
const EMAIL = process.env.E2E_CUSTOMER_EMAIL ?? '';
const PASSWORD = process.env.E2E_CUSTOMER_PASSWORD ?? '';

test.skip(!EMAIL || !PASSWORD, 'Requires the disposable browser-fixture customer account.');

async function login(page: Page): Promise<void> {
    await page.goto(`${APP}/login`);
    await page.locator('#login-form input[name="email"]').fill(EMAIL);
    await page.locator('#login-form input[name="password"]').fill(PASSWORD);
    await page.locator('#login-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/?$/);
}

test('customer session cannot enter Operator or Owner areas', async ({ page }) => {
    await login(page);

    for (const area of ['operator', 'owner']) {
        await page.goto(`${APP}/${area}`);
        await expect(page).toHaveURL(new RegExp(`/${area}/login$`));
        await expect(page.locator('body')).not.toContainText(/operator dashboard|owner dashboard|manage operators/i);
    }
});

test('customer profile update rejects a cross-origin same-site request without a CSRF token', async ({ page }) => {
    await login(page);
    await page.goto(WORDPRESS);

    // A different local origin on the same host lets the browser send the
    // synthetic session cookie, while Fetch Metadata reports same-site rather
    // than same-origin. The simple form-encoded POST avoids a preflight and
    // deliberately omits both the CSRF form field and headers.
    const responsePromise = page.waitForResponse((candidate) => candidate.url() === `${APP}/account/profile` && candidate.request().method() === 'POST');
    await page.evaluate(async (url) => {
        await fetch(url, {
            method: 'POST',
            mode: 'cors',
            credentials: 'include',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                _method: 'PUT',
                first_name: 'CSRF-UNAUTHORIZED-CHANGE',
                last_name: 'Synthetic',
                customer_type: 'individual',
                country_code: 'HU',
            }),
        }).catch(() => undefined); // The browser may hide the rejection response when CORS denies reading it.
    }, `${APP}/account/profile`);
    const response = await responsePromise;
    expect((await response.request().headers()).origin).toBe(WORDPRESS);
    expect(response.status()).toBe(419);

    await page.goto(`${APP}/account/profile`);
    await expect(page.locator('#profile-form input[name="first_name"]')).not.toHaveValue('CSRF-UNAUTHORIZED-CHANGE');
});

test('cross-origin browser request still requires Connector authentication', async ({ page }) => {
    await page.goto(WORDPRESS);
    const result = await page.evaluate(async (apiUrl) => {
        const response = await fetch(apiUrl, { mode: 'cors', credentials: 'omit' });

        return { origin: window.location.origin, type: response.type, status: response.status };
    }, `${APP}/api/v1/connector/jobs`);

    expect(result.origin).toBe(WORDPRESS);
    expect(result).toMatchObject({ type: 'cors', status: 401 });
});
