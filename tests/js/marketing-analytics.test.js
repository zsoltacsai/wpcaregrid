import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MarketingAnalytics,
    readAnalyticsConsent,
    validateMarketingEvent,
} from '../../resources/js/marketing-analytics-contract.js';
import { GoogleAnalyticsTransport } from '../../resources/js/marketing-analytics.js';

const pricingContext = { page_family: 'pricing', locale: 'en', canonical_path: '/en/pricing' };

class MemoryStorage {
    values = new Map();

    getItem(key) { return this.values.get(key) ?? null; }

    setItem(key, value) { this.values.set(key, value); }
}

class FakeTransport {
    events = [];
    initializeCount = 0;
    withdrawn = 0;
    available = true;

    async initialize() {
        this.initializeCount += 1;
        return this.available;
    }

    track(name, parameters) { this.events.push({ name, parameters }); }

    withdraw() { this.withdrawn += 1; }
}

function analyticsHarness({ enabled = true, context = pricingContext, consent = null } = {}) {
    const storage = new MemoryStorage();
    if (consent) {
        storage.setItem('wp-caregrid.analytics-consent', JSON.stringify({ state: consent, version: 'technical-v1' }));
    }
    const transport = new FakeTransport();
    const analytics = new MarketingAnalytics({
        configuration: { enabled, consentVersion: 'technical-v1' },
        context,
        storage,
        transport,
    });

    return { analytics, storage, transport };
}

test('only allowlisted marketing events and enum values validate', () => {
    assert.deepEqual(validateMarketingEvent('plan_selected', {
        product_type: 'woocommerce', tier: 'business', billing_interval: 'annual',
    }), {
        name: 'plan_selected',
        parameters: { product_type: 'woocommerce', tier: 'business', billing_interval: 'annual' },
    });
    assert.equal(validateMarketingEvent('plan_selected', {
        product_type: 'wordpress', tier: 'basic', billing_interval: 'monthly', email: 'person@example.test',
    }), null);
    assert.equal(validateMarketingEvent('plan_selected', {
        product_type: 'wordpress', tier: 'basic', billing_interval: 'monthly', website_url: 'https://private.test',
    }), null);
    assert.equal(validateMarketingEvent('checkout_completed', { value: '12.00' }), null);
    assert.equal(validateMarketingEvent('page_view', {
        page_family: 'pricing', locale: 'en', canonical_path: '/en/pricing?email=secret@example.test',
    }), null);
    assert.equal(validateMarketingEvent('page_view', {
        page_family: 'pricing', locale: 'hu', canonical_path: '/en/pricing',
    }), null);
});

test('unknown, malformed and stale consent versions fail closed', () => {
    const storage = new MemoryStorage();
    assert.equal(readAnalyticsConsent(storage, 'technical-v1'), 'UNKNOWN');
    storage.setItem('wp-caregrid.analytics-consent', '{broken');
    assert.equal(readAnalyticsConsent(storage, 'technical-v1'), 'UNKNOWN');
    storage.setItem('wp-caregrid.analytics-consent', JSON.stringify({ state: 'GRANTED', version: 'old-version' }));
    assert.equal(readAnalyticsConsent(storage, 'technical-v1'), 'UNKNOWN');
});

test('unknown and denied consent do not initialize the transport', async () => {
    for (const consent of [null, 'DENIED', 'WITHDRAWN']) {
        const { analytics, transport } = analyticsHarness({ consent });
        await analytics.start();
        await analytics.track('plan_selected', {
            product_type: 'wordpress', tier: 'basic', billing_interval: 'monthly',
        });
        assert.equal(transport.initializeCount, 0);
        assert.deepEqual(transport.events, []);
    }
});

test('grant sends one page view, one pricing view and bounded pricing events', async () => {
    const { analytics, transport } = analyticsHarness();
    await analytics.start();
    await analytics.choose('GRANTED');
    await analytics.track('plan_selected', {
        product_type: 'wordpress', tier: 'basic', billing_interval: 'monthly',
    });
    await analytics.track('plan_selected', {
        product_type: 'wordpress', tier: 'basic', billing_interval: 'monthly',
    });

    assert.deepEqual(transport.events.map(({ name }) => name), [
        'page_view', 'pricing_view', 'plan_selected', 'plan_selected',
    ]);
    assert.equal(transport.events.filter(({ name }) => name === 'page_view').length, 1);
    assert.equal(transport.events.filter(({ name }) => name === 'pricing_view').length, 1);
    assert.equal(transport.initializeCount, 1);
    assert.equal(transport.events.some(({ parameters }) => Object.hasOwn(parameters, 'email')), false);
});

test('withdrawal prevents future analytics events', async () => {
    const { analytics, transport } = analyticsHarness();
    await analytics.choose('GRANTED');
    const countBeforeWithdrawal = transport.events.length;
    await analytics.choose('WITHDRAWN');
    await analytics.track('plan_selected', {
        product_type: 'woocommerce', tier: 'secure', billing_interval: 'annual',
    });

    assert.equal(transport.withdrawn, 1);
    assert.equal(transport.events.length, countBeforeWithdrawal);
});

test('missing or disabled analytics configuration never reaches the fake sink', async () => {
    const { analytics, transport } = analyticsHarness({ enabled: false });
    await analytics.choose('GRANTED');
    await analytics.track('pricing_view', { locale: 'en' });
    assert.equal(transport.initializeCount, 0);
    assert.deepEqual(transport.events, []);
});

test('Google transport loads only on initialization and cancels a late load after withdrawal', async () => {
    const scripts = [];
    const windowObject = { dataLayer: [] };
    const documentObject = {
        head: {
            appendChild(script) { scripts.push(script); },
        },
        createElement() {
            return { remove() { this.removed = true; } };
        },
    };
    const transport = new GoogleAnalyticsTransport({
        measurementId: 'G-ABC1234567',
        canonicalLocation: 'https://wpcaregrid.com/en/pricing',
        documentObject,
        windowObject,
    });

    assert.equal(scripts.length, 0);
    const pending = transport.initialize();
    assert.equal(scripts.length, 1);
    assert.match(scripts[0].src, /^https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=/);
    transport.withdraw();
    const retry = transport.initialize();
    assert.equal(scripts.length, 2);
    scripts[0].onload();
    assert.equal(await pending, false);
    assert.equal(transport.loaded, false);
    scripts[1].onload();
    assert.equal(await retry, true);
    assert.equal(transport.loaded, true);
});

test('Google transport disables automatic page views and advertising consent', async () => {
    let script;
    const windowObject = { dataLayer: [] };
    const documentObject = {
        head: { appendChild(element) { script = element; } },
        createElement() { return { remove() {} }; },
    };
    const transport = new GoogleAnalyticsTransport({
        measurementId: 'G-ABC1234567',
        canonicalLocation: 'https://wpcaregrid.com/hu/',
        documentObject,
        windowObject,
    });
    const pending = transport.initialize();
    script.onload();
    assert.equal(await pending, true);

    const calls = windowObject.dataLayer.map((args) => Array.from(args));
    assert.ok(calls.some(([command, state, values]) => command === 'consent' && state === 'default'
        && values.analytics_storage === 'denied' && values.ad_storage === 'denied'));
    assert.ok(calls.some(([command, id, options]) => command === 'config' && id === 'G-ABC1234567'
        && options.send_page_view === false && options.page_referrer === ''));
    assert.equal(calls.some(([command]) => command === 'event'), false);
});

test('Google consent can be withdrawn and granted again without reloading the script', async () => {
    let script;
    const windowObject = { dataLayer: [] };
    const documentObject = {
        head: { appendChild(element) { script = element; } },
        createElement() { return { remove() {} }; },
    };
    const transport = new GoogleAnalyticsTransport({
        measurementId: 'G-ABC1234567',
        canonicalLocation: 'https://wpcaregrid.com/hu/',
        documentObject,
        windowObject,
    });
    const pending = transport.initialize();
    script.onload();
    await pending;
    transport.withdraw();
    await transport.initialize();

    const consentUpdates = windowObject.dataLayer
        .map((args) => Array.from(args))
        .filter(([command, state]) => command === 'consent' && state === 'update');
    assert.equal(consentUpdates.length, 3);
    assert.equal(consentUpdates[0][2].analytics_storage, 'granted');
    assert.equal(consentUpdates[1][2].analytics_storage, 'denied');
    assert.equal(consentUpdates[2][2].analytics_storage, 'granted');
    assert.equal(consentUpdates.every(([, , value]) => value.ad_storage === 'denied'
        && value.ad_user_data === 'denied' && value.ad_personalization === 'denied'), true);
});
