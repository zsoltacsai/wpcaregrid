<?php

namespace App\Release;

/**
 * The `staging_active` release profile: the isolated staging-next environment runs the
 * full application instead of the marketing-only surface.
 *
 * It is an environment gate, never an authorisation: every route keeps its own guards,
 * roles and CSRF protection. This class only answers three questions, all fail-closed
 * and all answered with fixed reason codes (never values, never secrets):
 *
 *  1. Is this provably the staging-next stack?                    identityProblems()
 *  2. Is the connected database the staging database?             databaseProblems()
 *  3. May a route that touches something outside this stack run?  groupOpen()
 */
final class StagingActiveProfile
{
    public const APP_ENV = 'staging';

    public const DEPLOYMENT_ENV = 'staging_next';

    public const URL = 'https://staging-next.wpcaregrid.com';

    public const HOST = 'staging-next.wpcaregrid.com';

    public const DATABASE = 'wpcg_staging_next';

    public const GROUP_CONNECTOR = 'connector';

    public const GROUP_STRIPE = 'stripe';

    public const GROUP_EXTERNAL = 'external';

    /** Paths that exist for Stripe, whatever the method. */
    private const STRIPE_PATHS = [
        '~^/checkout(?:/|$)~i',
        '~^/stripe/webhook/?$~i',
        '~^/account/billing(?:/|$)~i',
        '~^/account/subscriptions/~i',
        '~^/account/offers/[^/]+/(?:accept|pay)/?$~i',
        '~^/account/websites/purchase/?$~i',
        '~^/owner/pricing/verify-stripe/?$~i',
    ];

    /** Pages that are plain reads, but whose changes create Stripe objects. */
    private const STRIPE_WRITE_PATHS = [
        '~^/owner/coupons(?:/|$)~i',
        '~^/owner/offers(?:/|$)~i',
        // Saving or publishing a price verifies it against Stripe.
        '~^/owner/pricing/prices/[^/]+(?:/publish)?/?$~i',
    ];

    /** Operations that call a third-party service or change infrastructure. */
    private const EXTERNAL_PATHS = [
        '~^/owner/dns/cloudflare(?:/|$)~i',
        '~^/owner/infrastructure/platform-backups(?:/|$)~i',
        '~^/owner/billing/(?:synthetic-szamlazz-test|m11-lifecycle-validation)(?:/|$)~i',
        '~^/owner/billing/invoice-intents/[^/]+/reconcile/?$~i',
        '~^/owner/integrations/ai/~i',
        '~^/(?:account/profile|owner/customers/[^/]+)/vat-verification(?:/|$)~i',
    ];

    private const CONNECTOR_PATH = '~^/api/v1/connector(?:/|$)~i';

    public function __construct(private readonly StagingDatabaseProbe $probe) {}

    /** True when the environment asked for the profile (also when it was refused). */
    public static function requested(): bool
    {
        return config('marketing_release.requested_profile') === 'staging_active'
            || config('marketing_release.profile') === 'staging_active';
    }

    /** True for anything that identifies itself as the staging-next deployment. */
    public static function isStagingNextEnvironment(): bool
    {
        return config('app.deployment_environment') === self::DEPLOYMENT_ENV || self::requested();
    }

    /**
     * True on staging-next while the Owner has not opened external services: nothing may
     * contact a customer website, a feed or an infrastructure API from this stack (website
     * probes, vulnerability feed, platform backups). The same switch that opens the
     * matching routes opens these scheduled/queued paths.
     */
    public static function externalContactClosed(): bool
    {
        return self::isStagingNextEnvironment()
            && config('marketing_release.staging_active_features.external_services') !== true;
    }

    /**
     * True on staging-next while Stripe is not proven to be in test mode: scheduled
     * Stripe commands (webhook drain, reconciliation, offer expiry) do not run.
     */
    public static function stripeClosed(): bool
    {
        return self::isStagingNextEnvironment() && app(self::class)->stripeProblems() !== [];
    }

    /** @return list<string> */
    public function identityProblems(): array
    {
        $problems = [];
        if (config('marketing_release.staging_active_enabled') !== true) {
            $problems[] = 'profile_not_eligible';
        }
        if (config('marketing_release.enforcement_mode') !== 'enforced') {
            $problems[] = 'enforcement_not_enforced';
        }
        if (config('app.env') !== self::APP_ENV) {
            $problems[] = 'app_env';
        }
        if (config('app.deployment_environment') !== self::DEPLOYMENT_ENV) {
            $problems[] = 'deployment_env';
        }
        if (config('app.url') !== self::URL) {
            $problems[] = 'app_url';
        }
        if (config('app.debug') !== false) {
            $problems[] = 'app_debug';
        }
        if (config('database.default') !== 'mysql') {
            $problems[] = 'database_driver';
        }
        if (config('database.connections.mysql.database') !== self::DATABASE) {
            $problems[] = 'database_name';
        }
        if (! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
            $problems[] = 'database_host';
        }
        if (config('services.stripe.allow_live_mode') !== false) {
            $problems[] = 'stripe_live_mode_allowed';
        }

        return $problems;
    }

    /**
     * The live connection, not the configuration: what the application really talks to.
     *
     * @return list<string>
     */
    public function databaseProblems(): array
    {
        if ($this->probe->currentDatabase() !== self::DATABASE) {
            return ['database_identity_unverified'];
        }

        $expected = config('marketing_release.staging_active_db_uuid_sha256');
        if (is_string($expected) && $expected !== '' && ! hash_equals(strtolower($expected), (string) $this->probe->serverUuidSha256())) {
            return ['database_server_mismatch'];
        }

        return [];
    }

    /**
     * Stripe is usable only with test keys and live mode off.
     *
     * @return list<string>
     */
    public function stripeProblems(bool $needsWebhookSecret = false): array
    {
        $problems = [];
        $secret = config('services.stripe.secret');
        if (! is_string($secret) || $secret === '') {
            $problems[] = 'stripe_secret_missing';
        } elseif (preg_match('/^(?:sk|rk)_test_/', $secret) !== 1) {
            $problems[] = 'stripe_secret_not_test';
        }
        $publishable = config('services.stripe.key');
        if (is_string($publishable) && $publishable !== '' && ! str_starts_with($publishable, 'pk_test_')) {
            $problems[] = 'stripe_publishable_not_test';
        }
        if (config('services.stripe.allow_live_mode') !== false) {
            $problems[] = 'stripe_live_mode_allowed';
        }
        $webhook = config('services.stripe.webhook_secret');
        if ($needsWebhookSecret && (! is_string($webhook) || $webhook === '')) {
            $problems[] = 'stripe_webhook_secret_missing';
        }

        return $problems;
    }

    /** Which isolation group a request belongs to, or null for ordinary application routes. */
    public function groupFor(string $path, string $method): ?string
    {
        if (preg_match(self::CONNECTOR_PATH, $path) === 1) {
            return self::GROUP_CONNECTOR;
        }
        if ($this->matches(self::STRIPE_PATHS, $path)) {
            return self::GROUP_STRIPE;
        }
        if (! in_array(strtoupper($method), ['GET', 'HEAD'], true) && $this->matches(self::STRIPE_WRITE_PATHS, $path)) {
            return self::GROUP_STRIPE;
        }
        if ($this->matches(self::EXTERNAL_PATHS, $path)) {
            return self::GROUP_EXTERNAL;
        }

        return null;
    }

    /** Whether a group's isolation is proven (Stripe) or deliberately opened by the Owner (the rest). */
    public function groupOpen(string $group, string $path): bool
    {
        return match ($group) {
            self::GROUP_CONNECTOR => config('marketing_release.staging_active_features.connector_api') === true,
            self::GROUP_EXTERNAL => config('marketing_release.staging_active_features.external_services') === true,
            self::GROUP_STRIPE => $this->stripeProblems(preg_match('~^/stripe/webhook/?$~i', $path) === 1) === [],
            default => false,
        };
    }

    /** @param  list<string>  $patterns */
    private function matches(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
