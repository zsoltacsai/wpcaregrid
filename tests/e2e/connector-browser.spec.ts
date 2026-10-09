import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';

type Account = {
    customer_id: number;
    user_id: number;
    subscription_id: number;
    email: string;
    password: string;
    website_url: string;
};

type FixtureState = {
    run_id: string;
    accounts: { a: Account; b: Account };
    websites: { a?: string; b?: string };
};

type WpCredentials = { wordpress_admin: string; wordpress_password: string };

const statePath = process.env.CONNECTOR_BROWSER_STATE;
const credentialsPath = process.env.WP_CAREGRID_E2E_CREDENTIALS;
const phpPath = process.env.CONNECTOR_E2E_PHP;
const phpIni = process.env.CONNECTOR_E2E_PHP_INI;
const wpPhpPath = process.env.CONNECTOR_E2E_WP_PHP;
const wpPhpIni = process.env.CONNECTOR_E2E_WP_PHP_INI;
const restartScript = process.env.CONNECTOR_E2E_RESTART_SCRIPT;
const appBase = 'http://127.0.0.1:8317';
const wpBase = 'http://127.0.0.1:8318';

function state(): FixtureState {
    if (!statePath) throw new Error('Browser fixture path is not configured.');
    return JSON.parse(readFileSync(statePath, 'utf8')) as FixtureState;
}

function wpCredentials(): WpCredentials {
    if (!credentialsPath) throw new Error('Isolated WordPress credentials path is not configured.');
    return JSON.parse(readFileSync(credentialsPath, 'utf8')) as WpCredentials;
}

function localPhp(mode: string, ...args: string[]): string {
    if (!phpPath || !phpIni || !statePath) throw new Error('Isolated Laravel fixture runtime is not configured.');
    try {
        return execFileSync(phpPath, ['-c', phpIni, 'scripts/e2e/php/connector-browser-fixture.php', mode, ...args], {
            cwd: process.cwd(),
            encoding: 'utf8',
            windowsHide: true,
        });
    } catch {
        throw new Error(`Synthetic Laravel fixture operation failed: ${mode}.`);
    }
}

function localWordPress(mode: string, ...args: string[]): string {
    if (!wpPhpPath || !wpPhpIni) throw new Error('Isolated WordPress runtime is not configured.');
    try {
        return execFileSync(wpPhpPath, ['-c', wpPhpIni, 'scripts/e2e/php/connector-browser-wordpress.php', mode, ...args], {
            cwd: process.cwd(),
            encoding: 'utf8',
            windowsHide: true,
        });
    } catch {
        throw new Error(`Synthetic WordPress fixture operation failed: ${mode}.`);
    }
}

function restartLocalService(service: 'Laravel' | 'WordPress', action: 'stop' | 'start' | 'restart'): void {
    if (!restartScript) throw new Error('Local service restart script is not configured.');
    try {
        execFileSync('pwsh.exe', ['-NoProfile', '-File', restartScript, '-Service', service, '-Action', action], {
            cwd: process.cwd(),
            stdio: 'ignore',
            timeout: 30_000,
            windowsHide: true,
        });
    } catch (error) {
        const details = error as NodeJS.ErrnoException;
        const reason = details.code === 'ETIMEDOUT' ? 'timed out after 30 seconds' : details.message;
        throw new Error(`Isolated ${service} ${action} failed: ${reason}`);
    }
}

async function loginCustomer(page: Page, account: Account): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-form input[name="email"]').fill(account.email);
    await page.locator('#login-form input[name="password"]').fill(account.password);
    await page.locator('#login-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/?$/);
}

async function loginWordPress(browser: Browser): Promise<{ page: Page; close: () => Promise<void> }> {
    const credentials = wpCredentials();
    const context = await browser.newContext({ baseURL: wpBase, viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(credentials.wordpress_admin);
    await page.locator('#user_pass').fill(credentials.wordpress_password);
    await page.locator('#wp-submit').click();
    await expect(page).toHaveURL(/\/wp-admin\//);
    await page.goto('/wp-admin/options-general.php?page=wp-caregrid');
    await expect(page.getByRole('heading', { name: 'WP CareGrid', exact: true })).toBeVisible();
    return { page, close: () => context.close() };
}

async function createWebsite(page: Page, account: Account, accountKey: 'a' | 'b'): Promise<string> {
    await page.goto('/account/websites/new');
    await expect(page.getByRole('heading', { name: /connect your website/i })).toBeVisible();
    await page.locator('#website-form input[name="url"]').fill(account.website_url);
    await page.locator('#website-form input[name="type"][value="wordpress"]').check();
    await page.locator('#website-form button[type="submit"]').click();
    await expect(page).toHaveURL(/\/account\/websites\/[0-9a-f-]{36}$/i);
    const match = new URL(page.url()).pathname.match(/([0-9a-f-]{36})$/i);
    if (!match) throw new Error('The customer UI did not open the newly registered website.');
    localPhp('browser-record-website', match[1], accountKey);
    await expect(page.locator('main')).toContainText(/not connected|never connected/i);
    await expect(page.locator('main')).toContainText(/generate a pairing code|pairing code/i);
    return match[1];
}

async function generatePairingCode(page: Page, websiteUuid: string): Promise<string> {
    await page.goto(`/account/websites/${websiteUuid}`);
    await page.locator(`form[action$="/websites/${websiteUuid}/pairing-code"] button[type="submit"]`).click();
    const code = page.locator('main p.font-mono.text-2xl');
    await expect(code).toBeVisible();
    const value = (await code.textContent())?.trim() ?? '';
    if (! /^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/.test(value)) {
        throw new Error('The customer UI did not render a valid one-time pairing code.');
    }
    return value;
}

async function connectFromWordPress(page: Page, code: string, mobile = false): Promise<void> {
    if (mobile) await page.setViewportSize({ width: 375, height: 812 });
    const input = page.getByLabel('Pairing code', { exact: true });
    const button = page.getByRole('button', { name: 'Connect', exact: true });
    await expect(input).toBeVisible();
    await expect(input).toHaveAccessibleName('Pairing code');
    await input.fill(code);

    if (mobile) {
        const bounds = await input.boundingBox();
        if (!bounds || bounds.x < 0 || bounds.x + bounds.width > 375) {
            throw new Error('The WordPress pairing input does not fit a 375-pixel viewport.');
        }
    }

    await input.press('Tab');
    expect(await button.evaluate((element) => document.activeElement === element)).toBe(true);
    const submitted = page.waitForResponse((response) => {
        const url = new URL(response.url());
        return response.request().method() === 'POST' && url.pathname.endsWith('/wp-admin/admin-post.php');
    });
    await page.keyboard.press('Enter');
    const response = await submitted;
    const redirect = response.headers()['location'] ?? '(no redirect)';
    console.log(`[browser-e2e] WordPress admin-post returned ${response.status()} -> ${redirect}`);
    await page.waitForLoadState('domcontentloaded');
    if (!page.url().includes('/options-general.php?page=wp-caregrid')) {
        await page.goto('/wp-admin/options-general.php?page=wp-caregrid');
    }
}

async function inspectWebsite(websiteUuid: string): Promise<Record<string, unknown>> {
    return JSON.parse(localPhp('browser-inspect', websiteUuid)) as Record<string, unknown>;
}

test('customer and WordPress admin UIs complete pairing, reject unsafe attempts, and require explicit re-pairing', async ({ browser }) => {
    const fixture = state();
    const customerContext = await browser.newContext({ baseURL: appBase });
    const customerPage = await customerContext.newPage();

    // An unauthenticated customer cannot see pairing controls or request a code.
    await customerPage.goto('/login');
    await customerPage.locator('#login-form input[name="email"]').fill(fixture.accounts.a.email);
    await customerPage.locator('#login-form input[name="password"]').fill(fixture.accounts.a.password);
    await customerPage.locator('#login-form button[type="submit"]').click();
    await expect(customerPage).toHaveURL(/\/account\/?$/);

    const websiteA = await createWebsite(customerPage, fixture.accounts.a, 'a');
    const anonymousContext = await browser.newContext({ baseURL: appBase });
    const anonymousPage = await anonymousContext.newPage();
    await anonymousPage.goto(`/account/websites/${websiteA}`);
    await expect(anonymousPage).toHaveURL(/\/login$/);
    await anonymousContext.close();

    // A different synthetic tenant cannot inspect or generate a code for site A.
    const otherTenantContext = await browser.newContext({ baseURL: appBase });
    const otherTenantPage = await otherTenantContext.newPage();
    await loginCustomer(otherTenantPage, fixture.accounts.b);
    const crossTenantResponse = await otherTenantPage.goto(`/account/websites/${websiteA}`);
    expect([403, 404]).toContain(crossTenantResponse?.status());
    await expect(otherTenantPage.locator('body')).not.toContainText(/pairing code/i);
    const websiteB = await createWebsite(otherTenantPage, fixture.accounts.b, 'b');
    const codeForB = await generatePairingCode(otherTenantPage, websiteB);

    const expiredCode = await generatePairingCode(customerPage, websiteA);
    localPhp('browser-expire-code', websiteA);

    // Only loopback browser traffic is used; the synthetic site's asserted home URL is reserved .example.com.
    localWordPress('set-home', fixture.accounts.a.website_url);
    const wordpress = await loginWordPress(browser);
    const wpPage = wordpress.page;
    await expect(wpPage.locator('body')).toContainText('Generate a pairing code for this website');
    await expect(wpPage.locator('body')).toContainText('No WordPress, hosting or database password is shared');

    // Expiry and wrong-site failures use the actual plugin form; malformed-code
    // validation is covered by the separate HTTP contract suite.
    await connectFromWordPress(wpPage, expiredCode);
    await expect(wpPage.locator('.wrap > .notice')).toContainText(/invalid or has expired/i);
    await expect(wpPage.locator('strong')).toContainText('Not connected');

    // A code issued to tenant B cannot pair the synthetic WordPress site registered under tenant A.
    await connectFromWordPress(wpPage, codeForB);
    await expect(wpPage.locator('.wrap > .notice')).toContainText(/different website address/i);
    await expect(wpPage.locator('strong')).toContainText('Not connected');

    // Only the authenticated account UI can issue the fresh code used for successful pairing.
    const firstCode = await generatePairingCode(customerPage, websiteA);
    await connectFromWordPress(wpPage, firstCode, true);
    await expect(wpPage.locator('.wrap > .notice')).toContainText('Connected to WP CareGrid');
    await expect(wpPage.locator('strong')).toHaveText('Connected');
    await expect(wpPage.locator('body')).not.toContainText(/cgc_[A-Za-z0-9]{48}/);

    await customerPage.goto(`/account/websites/${websiteA}`);
    await expect(customerPage.locator('main')).toContainText('Connected');
    await expect(customerPage.locator('main')).not.toContainText('Never');
    await expect.poll(async () => inspectWebsite(websiteA)).toMatchObject({
        connector_status: 'connected',
        connector_record_status: 'active',
        presence: 'connected',
    });
    let report = await inspectWebsite(websiteA);
    expect(report.last_heartbeat_at).toBeTruthy();
    expect(report.inventory_id).toBeTruthy();
    expect(report.health_checked_at).toBeTruthy();

    // A transient browser retry of the already-consumed code is refused after an explicit WordPress disconnect.
    const disconnectForm = wpPage.locator('form').filter({ has: wpPage.locator('input[name="action"][value="wp_caregrid_disconnect"]') });
    wpPage.once('dialog', (dialog) => dialog.accept());
    await disconnectForm.getByRole('button', { name: 'Disconnect', exact: true }).click();
    await expect(wpPage.locator('.wrap > .notice')).toContainText(/Disconnected\./);
    await expect(wpPage.locator('strong')).toContainText('Not connected');
    const disconnectedState = JSON.parse(localWordPress('state')) as { credential_present: boolean; connected: boolean };
    expect(disconnectedState.credential_present).toBe(false);
    expect(disconnectedState.connected).toBe(false);

    await connectFromWordPress(wpPage, firstCode);
    await expect(wpPage.locator('.wrap > .notice')).toContainText(/invalid or has expired/i);
    await expect(wpPage.locator('strong')).toContainText('Not connected');

    const freshCode = await generatePairingCode(customerPage, websiteA);
    await connectFromWordPress(wpPage, freshCode);
    await expect(wpPage.locator('.wrap > .notice')).toContainText('Connected to WP CareGrid');
    await expect(wpPage.locator('strong')).toHaveText('Connected');
    await customerPage.goto(`/account/websites/${websiteA}`);
    report = await inspectWebsite(websiteA);
    expect(report.connector_status).toBe('connected');
    expect(report.connector_record_status).toBe('active');

    await wordpress.close();
    await otherTenantContext.close();
    await customerContext.close();
    console.log('PASS browser pairing lifecycle, tenant rejection, expiration, replay refusal, and authenticated re-pair');
});

test('WordPress restart preserves its credential and syncs through the real admin UI', async ({ browser }) => {
    const fixture = state();
    const websiteUuid = fixture.websites.a;
    if (!websiteUuid) throw new Error('The first browser flow did not record its synthetic website.');
    test.skip((await inspectWebsite(websiteUuid)).connector_status !== 'connected', 'The browser pairing flow did not establish a connection.');

    restartLocalService('WordPress', 'restart');
    const wordpress = await loginWordPress(browser);
    const { page } = wordpress;
    await expect(page.locator('strong')).toHaveText('Connected');
    await page.getByRole('button', { name: 'Check connection now', exact: true }).click();
    await expect(page.locator('.wrap > .notice')).toContainText('Contacted WP CareGrid');
    const stateAfterRestart = JSON.parse(localWordPress('state')) as { connected: boolean; credential_present: boolean; website_uuid: string };
    expect(stateAfterRestart.connected).toBe(true);
    expect(stateAfterRestart.credential_present).toBe(true);
    expect(stateAfterRestart.website_uuid).toBe(websiteUuid);

    const persisted = await inspectWebsite(websiteUuid);
    expect(persisted.presence).toBe('connected');
    expect(persisted.last_heartbeat_at).toBeTruthy();
    expect(persisted.inventory_id).toBeTruthy();
    expect(persisted.health_checked_at).toBeTruthy();
    await wordpress.close();
    console.log('PASS WordPress restart retained the credential and real heartbeat/inventory/health state');
});

test('Laravel outage shows an error, preserves credentials, and recovers only after service restoration', async ({ browser }) => {
    const fixture = state();
    const websiteUuid = fixture.websites.a;
    if (!websiteUuid) throw new Error('The first browser flow did not record its synthetic website.');
    test.skip((await inspectWebsite(websiteUuid)).connector_status !== 'connected', 'The browser pairing flow did not establish a connection.');
    const before = await inspectWebsite(websiteUuid);
    const inventoryId = before.inventory_id;
    const healthTime = before.health_checked_at;
    console.log('[browser-e2e] recovery: baseline inspected');

    const wordpress = await loginWordPress(browser);
    const { page } = wordpress;
    console.log('[browser-e2e] recovery: WordPress admin ready');
    await expect(page.locator('strong')).toHaveText('Connected', { timeout: 5000 });
    const checkButton = page.getByRole('button', { name: 'Check connection now', exact: true });
    await expect(checkButton).toBeVisible({ timeout: 5000 });
    restartLocalService('Laravel', 'stop');
    await checkButton.click();
    await expect(page.locator('.wrap > .notice')).toContainText(/could not be reached/i);
    console.log('[browser-e2e] recovery: outage displayed in WordPress');
    await expect(page.locator('strong')).toHaveText('Connected');
    const offline = JSON.parse(localWordPress('state')) as { connected: boolean; credential_present: boolean };
    expect(offline.connected).toBe(true);
    expect(offline.credential_present).toBe(true);

    localPhp('browser-age-connection', websiteUuid);
    console.log('[browser-e2e] recovery: stale connector fixture prepared');
    restartLocalService('Laravel', 'start');
    console.log('[browser-e2e] recovery: Laravel restarted');
    localPhp('browser-queue-health-inventory', websiteUuid);
    console.log('[browser-e2e] recovery: health and inventory queued');

    const accountContext = await browser.newContext({ baseURL: appBase });
    const accountPage = await accountContext.newPage();
    await loginCustomer(accountPage, fixture.accounts.a);
    await accountPage.goto(`/account/websites/${websiteUuid}`);
    await expect(accountPage.locator('main')).toContainText('Not responding');
    console.log('[browser-e2e] recovery: stale state visible to customer');

    await page.reload();
    await expect(page.locator('strong')).toHaveText('Connected');
    await page.getByRole('button', { name: 'Check connection now', exact: true }).click();
    await expect(page.locator('.wrap > .notice')).toContainText('Contacted WP CareGrid');
    console.log('[browser-e2e] recovery: WordPress sync restored connection');
    await accountPage.reload();
    await expect(accountPage.locator('main')).toContainText('Connected');

    await expect.poll(async () => inspectWebsite(websiteUuid)).toMatchObject({
        connector_status: 'connected',
        connector_record_status: 'active',
        presence: 'connected',
    });
    const recovered = await inspectWebsite(websiteUuid);
    expect(recovered.inventory_id).toBe(inventoryId);
    expect(recovered.last_heartbeat_at).toBeTruthy();
    expect(recovered.health_checked_at).toBeTruthy();
    expect(Date.parse(String(recovered.health_checked_at))).toBeGreaterThan(Date.parse(String(healthTime)));

    const wpState = JSON.parse(localWordPress('state')) as { connected: boolean; credential_present: boolean };
    expect(wpState.connected).toBe(true);
    expect(wpState.credential_present).toBe(true);
    await wordpress.close();
    await accountContext.close();
    console.log('PASS Laravel outage and restart preserved credentials, exposed stale state, and recovered heartbeat/inventory/health');
});
