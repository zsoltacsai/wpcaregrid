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

test('customer profile update rejects a same-origin request without a CSRF token', async ({ page }) => {
    await login(page);
    await page.goto(`${APP}/account/profile`);
    await expect(page.locator('#profile-form input[name="first_name"]')).toBeVisible();

    const status = await page.evaluate(async () => {
        const response = await fetch('/account/profile', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            _method: 'PUT',
            first_name: 'CSRF-UNAUTHORIZED-CHANGE',
            last_name: 'Synthetic',
            customer_type: 'individual',
            country_code: 'HU',
        }),
        });

        return response.status;
    });
    expect(status).toBe(419);

    await page.reload();
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
