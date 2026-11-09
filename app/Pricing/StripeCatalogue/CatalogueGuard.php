<?php

namespace App\Pricing\StripeCatalogue;

use App\Owner\IntegrationCredentials;

/**
 * Decides which Stripe environment this application may read or change, before any request is built.
 *
 * - The secret is the Owner-managed one (encrypted in the database; the environment only as the
 *   existing fallback). It is never copied anywhere and never returned to a view.
 * - The mode comes from the key prefix and must equal the configured STRIPE_MODE.
 * - A staging stack may only ever use a TEST key. A LIVE key there is refused, even for reading.
 * - Elsewhere LIVE needs STRIPE_ALLOW_LIVE_MODE, and changing LIVE additionally needs the separate
 *   STRIPE_CATALOGUE_LIVE_APPLY_APPROVED switch.
 * - Nothing is ever changed unless STRIPE_CATALOGUE_APPLY_ENABLED is on (the first run is a dry run).
 */
class CatalogueGuard
{
    public function __construct(private readonly IntegrationCredentials $credentials) {}

    /** @throws CatalogueRefused */
    public function mode(): StripeMode
    {
        return $this->resolve()[1];
    }

    /**
     * A gateway for the environment, or a refusal.
     *
     * @throws CatalogueRefused
     */
    public function gateway(): StripeCatalogueGateway
    {
        [$secret, $mode] = $this->resolve();

        return $this->makeGateway($secret, $mode);
    }

    /** The one place a real Stripe client is built (tests replace it with an in-memory Stripe). */
    protected function makeGateway(string $secret, StripeMode $mode): StripeCatalogueGateway
    {
        return new StripeApiCatalogueGateway($secret, $mode);
    }

    /** @throws CatalogueRefused unless Stripe objects may be created in this mode */
    public function assertCanWrite(StripeMode $mode): void
    {
        [, $current] = $this->resolve();
        if ($current !== $mode) {
            throw new CatalogueRefused('mode_changed');
        }
        if (! (bool) config('services.stripe.catalogue.apply_enabled')) {
            throw new CatalogueRefused('apply_disabled');
        }
        if ($mode === StripeMode::Live && ! (bool) config('services.stripe.catalogue.live_apply_approved')) {
            throw new CatalogueRefused('live_apply_not_approved');
        }
    }

    public function isStaging(): bool
    {
        return config('marketing_release.staging_active_enabled') === true
            || config('marketing_release.requested_profile') === 'staging_active'
            || config('app.env') === 'staging'
            || str_starts_with((string) config('app.deployment_environment'), 'staging')
            // A host that calls itself staging is never treated as anything else, whatever else is configured.
            || str_contains(strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), 'staging');
    }

    /**
     * @return array{0: string, 1: StripeMode}
     *
     * @throws CatalogueRefused
     */
    private function resolve(): array
    {
        $secret = $this->credentials->resolve('stripe_secret');
        if (! is_string($secret) || $secret === '') {
            throw new CatalogueRefused('stripe_not_configured');
        }

        $mode = StripeMode::fromKey($secret) ?? throw new CatalogueRefused('key_mode_unknown');
        if ($mode->value !== (string) config('services.stripe.mode')) {
            throw new CatalogueRefused('key_mode_mismatch');
        }
        if ($mode === StripeMode::Live) {
            if ($this->isStaging()) {
                throw new CatalogueRefused('live_forbidden_on_staging');
            }
            if (! (bool) config('services.stripe.allow_live_mode')) {
                throw new CatalogueRefused('live_mode_not_enabled');
            }
        }

        return [$secret, $mode];
    }
}
