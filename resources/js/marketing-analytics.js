import { MarketingAnalytics } from './marketing-analytics-contract.js';

const deniedConsent = {
    analytics_storage: 'denied',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
};

const grantedAnalyticsConsent = {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
};

export class GoogleAnalyticsTransport {
    constructor({ measurementId, canonicalLocation, documentObject, windowObject }) {
        this.measurementId = measurementId;
        this.canonicalLocation = canonicalLocation;
        this.document = documentObject;
        this.window = windowObject;
        this.script = null;
        this.pending = null;
        this.resolvePending = null;
        this.loaded = false;
        this.cancelled = false;
        this.generation = 0;
    }

    initialize() {
        if (this.loaded) {
            this.window.gtag('consent', 'update', grantedAnalyticsConsent);
            return Promise.resolve(true);
        }
        if (this.pending) {
            return this.pending;
        }
        if (!/^G-[A-Z0-9]{5,20}$/.test(this.measurementId)) {
            return Promise.resolve(false);
        }

        this.cancelled = false;
        const generation = ++this.generation;
        this.window.dataLayer = this.window.dataLayer || [];
        this.window.gtag = this.window.gtag || function gtag() {
            this.dataLayer.push(arguments);
        }.bind(this.window);
        this.window.gtag('consent', 'default', deniedConsent);

        this.pending = new Promise((resolve) => {
            this.resolvePending = resolve;
            const script = this.document.createElement('script');
            script.async = true;
            script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(this.measurementId)}`;
            script.onload = () => {
                if (this.cancelled || generation !== this.generation) {
                    resolve(false);
                    return;
                }
                this.loaded = true;
                this.window.gtag('consent', 'update', grantedAnalyticsConsent);
                this.window.gtag('js', new Date());
                this.window.gtag('config', this.measurementId, {
                    send_page_view: false,
                    page_location: this.canonicalLocation,
                    page_referrer: '',
                    allow_google_signals: false,
                    allow_ad_personalization_signals: false,
                    url_passthrough: false,
                });
                resolve(true);
            };
            script.onerror = () => resolve(false);
            this.script = script;
            this.document.head.appendChild(script);
            this.resolvePending = resolve;
        });

        this.pending = this.pending.then((result) => {
            if (generation === this.generation) {
                this.resolvePending = null;
                this.pending = null;
                if (!result) {
                    this.script = null;
                }
            }
            return result;
        });

        return this.pending;
    }

    track(name, parameters) {
        if (this.loaded && typeof this.window.gtag === 'function') {
            this.window.gtag('event', name, { ...parameters, transport_type: 'beacon' });
        }
    }

    withdraw() {
        this.cancelled = true;
        this.generation += 1;
        if (!this.loaded) {
            this.script?.remove();
            this.resolvePending?.(false);
            this.resolvePending = null;
            this.pending = null;
            this.script = null;
            return;
        }

        this.window.gtag('consent', 'update', deniedConsent);
    }

}

function getStorage(windowObject) {
    try {
        return windowObject.localStorage;
    } catch {
        return null;
    }
}

function mountConsentControls(analytics, documentObject) {
    const panel = documentObject.querySelector('[data-analytics-preferences]');
    const openButtons = documentObject.querySelectorAll('[data-open-analytics-preferences]');
    if (!panel) {
        return;
    }

    const accept = panel.querySelector('[data-analytics-choice="grant"]');
    const reject = panel.querySelector('[data-analytics-choice="deny"]');
    const withdraw = panel.querySelector('[data-analytics-choice="withdraw"]');
    let manuallyOpened = false;

    const render = (state) => {
        const visible = state === 'UNKNOWN' || manuallyOpened;
        panel.classList.toggle('hidden', !visible);
        for (const button of openButtons) {
            button.setAttribute('aria-expanded', visible ? 'true' : 'false');
        }
        accept.classList.toggle('hidden', state === 'GRANTED');
        withdraw.classList.toggle('hidden', state !== 'GRANTED');
        reject.classList.toggle('hidden', state === 'GRANTED');
    };

    analytics.onConsentChange = render;
    for (const button of openButtons) {
        button.addEventListener('click', () => {
            manuallyOpened = !manuallyOpened;
            render(analytics.consent);
            if (manuallyOpened) {
                panel.querySelector('#analytics-preferences-title')?.focus();
            }
        });
    }
    accept.addEventListener('click', async () => {
        if (await analytics.choose('GRANTED')) {
            manuallyOpened = false;
            render(analytics.consent);
        }
    });
    reject.addEventListener('click', async () => {
        const next = analytics.consent === 'GRANTED' ? 'WITHDRAWN' : 'DENIED';
        if (await analytics.choose(next)) {
            manuallyOpened = false;
            render(analytics.consent);
        }
    });
    withdraw.addEventListener('click', async () => {
        if (await analytics.choose('WITHDRAWN')) {
            manuallyOpened = false;
            render(analytics.consent);
        }
    });
}

export function mountMarketingAnalytics(documentObject = document, windowObject = window) {
    const root = documentObject.querySelector('[data-marketing-analytics]');
    if (!root) {
        return null;
    }

    const enabled = root.dataset.enabled === 'true'
        && /^G-[A-Z0-9]{5,20}$/.test(root.dataset.measurementId || '')
        && ['home', 'pricing'].includes(root.dataset.pageFamily)
        && ['en', 'hu'].includes(root.dataset.locale)
        && !/[?#]/.test(root.dataset.canonicalPath || '')
        && !/[?#]/.test(root.dataset.canonicalLocation || '');
    const configuration = {
        enabled,
        measurementId: enabled ? root.dataset.measurementId : '',
        consentVersion: root.dataset.consentVersion || 'technical-v1',
    };
    const context = {
        page_family: root.dataset.pageFamily,
        locale: root.dataset.locale,
        canonical_path: root.dataset.canonicalPath,
    };
    const transport = new GoogleAnalyticsTransport({
        measurementId: configuration.measurementId,
        canonicalLocation: root.dataset.canonicalLocation,
        documentObject,
        windowObject,
    });
    const analytics = new MarketingAnalytics({ configuration, context, storage: getStorage(windowObject), transport });

    mountConsentControls(analytics, documentObject);
    documentObject.addEventListener('click', (event) => {
        const link = event.target.closest?.('[data-marketing-plan]');
        if (!link) {
            return;
        }
        void analytics.track('plan_selected', {
            product_type: link.dataset.productType,
            tier: link.dataset.planTier,
            billing_interval: link.dataset.billingInterval,
        });
    });
    windowObject.addEventListener('caregrid:billing-cycle-selected', (event) => {
        void analytics.track('billing_cycle_selected', event.detail || {});
    });
    void analytics.start();

    return analytics;
}
