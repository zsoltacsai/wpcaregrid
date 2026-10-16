const locales = new Set(['en', 'hu']);
const productTypes = new Set(['wordpress', 'woocommerce']);
const tiers = new Set(['basic', 'business', 'secure']);
const intervals = new Set(['monthly', 'annual']);
const pagePaths = new Map([
    ['/en/', ['home', 'en']],
    ['/hu/', ['home', 'hu']],
    ['/en/pricing', ['pricing', 'en']],
    ['/hu/pricing', ['pricing', 'hu']],
]);

const contracts = {
    page_view: {
        allowed: ['page_family', 'locale', 'canonical_path'],
        required: ['page_family', 'locale', 'canonical_path'],
    },
    pricing_view: {
        allowed: ['locale'],
        required: ['locale'],
    },
    plan_selected: {
        allowed: ['product_type', 'tier', 'billing_interval'],
        required: ['product_type', 'tier', 'billing_interval'],
    },
    billing_cycle_selected: {
        allowed: ['product_type', 'billing_interval'],
        required: ['product_type', 'billing_interval'],
    },
};

/** Return only a complete allowlisted payload; malformed or unknown data is rejected. */
export function validateMarketingEvent(name, input) {
    const contract = contracts[name];
    if (!contract || input === null || typeof input !== 'object' || Array.isArray(input)) {
        return null;
    }

    const keys = Object.keys(input);
    if (keys.some((key) => !contract.allowed.includes(key))
        || contract.required.some((key) => !Object.hasOwn(input, key))) {
        return null;
    }

    const values = {};
    for (const key of contract.required) {
        if (typeof input[key] !== 'string') {
            return null;
        }
        values[key] = input[key];
    }

    if (name === 'page_view') {
        const pathContext = pagePaths.get(values.canonical_path);
        if (!pathContext || pathContext[0] !== values.page_family || pathContext[1] !== values.locale) {
            return null;
        }
    }
    if (name === 'pricing_view' && !locales.has(values.locale)) {
        return null;
    }
    if (name === 'plan_selected'
        && (!productTypes.has(values.product_type) || !tiers.has(values.tier) || !intervals.has(values.billing_interval))) {
        return null;
    }
    if (name === 'billing_cycle_selected'
        && (!productTypes.has(values.product_type) || !intervals.has(values.billing_interval))) {
        return null;
    }

    return { name, parameters: values };
}

export function readAnalyticsConsent(storage, version) {
    if (!storage) {
        return 'UNKNOWN';
    }

    try {
        const stored = storage.getItem('wp-caregrid.analytics-consent');
        if (!stored) {
            return 'UNKNOWN';
        }
        const choice = JSON.parse(stored);
        if (choice.version !== version || !['GRANTED', 'DENIED', 'WITHDRAWN'].includes(choice.state)) {
            return 'UNKNOWN';
        }

        return choice.state;
    } catch {
        return 'UNKNOWN';
    }
}

export class MarketingAnalytics {
    constructor({ configuration, context, storage, transport }) {
        this.configuration = configuration;
        this.context = context;
        this.storage = storage;
        this.transport = transport;
        this.consent = 'UNKNOWN';
        this.readyPromise = null;
        this.pageEventsSent = false;
        this.onConsentChange = () => {};
    }

    async start() {
        this.consent = readAnalyticsConsent(this.storage, this.configuration.consentVersion);
        this.onConsentChange(this.consent);
        if (this.consent === 'GRANTED') {
            await this.activate();
        }

        return this.consent;
    }

    async choose(state) {
        if (!['GRANTED', 'DENIED', 'WITHDRAWN'].includes(state) || !this.storage) {
            return false;
        }

        try {
            this.storage.setItem('wp-caregrid.analytics-consent', JSON.stringify({
                state,
                version: this.configuration.consentVersion,
            }));
        } catch {
            return false;
        }

        this.consent = state;
        this.onConsentChange(this.consent);

        if (state === 'GRANTED') {
            await this.activate();
        } else {
            this.transport.withdraw();
            this.readyPromise = null;
        }

        return true;
    }

    async track(name, parameters) {
        const event = validateMarketingEvent(name, parameters);
        if (!event || !this.configuration.enabled || this.consent !== 'GRANTED') {
            return false;
        }

        const ready = await this.activate();
        if (!ready || this.consent !== 'GRANTED') {
            return false;
        }

        this.sendInitialPageEvents();
        this.transport.track(event.name, event.parameters);

        return true;
    }

    async activate() {
        if (!this.configuration.enabled || this.consent !== 'GRANTED') {
            return false;
        }
        if (!this.readyPromise) {
            this.readyPromise = Promise.resolve(this.transport.initialize()).catch(() => false);
        }
        const ready = await this.readyPromise;
        if (!ready || this.consent !== 'GRANTED') {
            return false;
        }

        this.sendInitialPageEvents();

        return true;
    }

    sendInitialPageEvents() {
        if (this.pageEventsSent || this.consent !== 'GRANTED' || !this.configuration.enabled) {
            return;
        }

        const pageView = validateMarketingEvent('page_view', this.context);
        if (!pageView) {
            return;
        }

        this.transport.track(pageView.name, pageView.parameters);
        this.pageEventsSent = true;
        if (this.context.page_family === 'pricing') {
            this.transport.track('pricing_view', { locale: this.context.locale });
        }
    }
}
