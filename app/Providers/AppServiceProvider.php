<?php

namespace App\Providers;

use App\Backups\Storage\StorageManager;
use App\Checkout\CheckoutGateway;
use App\Checkout\PromotionGateway;
use App\Checkout\StripeCheckoutGateway;
use App\Checkout\StripePromotionGateway;
use App\Content\ContentRepository;
use App\Invoicing\Providers\InvoiceProvider;
use App\Invoicing\Providers\InvoiceSubmissionProvider;
use App\Invoicing\Providers\SyntheticInvoiceTestProvider;
use App\Invoicing\Providers\SzamlazzAgentProvider;
use App\Invoicing\Providers\SzamlazzDryRunProvider;
use App\Invoicing\Providers\SzamlazzResponseParser;
use App\Models\User;
use App\Offers\OfferPaymentGateway;
use App\Offers\StripeOfferPaymentGateway;
use App\Operator\Permission;
use App\Owner\IntegrationCredentials;
use App\Owner\OwnerAbility;
use App\Owner\OwnerSettings;
use App\Pricing\PricingCatalog;
use App\Pricing\PricingOverrides;
use App\Security\EngineScanner;
use App\Security\SecurityScanner;
use App\Security\Vulnerabilities\NoVulnerabilitySource;
use App\Security\Vulnerabilities\VulnerabilitySource;
use App\Security\Vulnerabilities\WordfenceFeed;
use App\Tax\Vat\VatNumberProvider;
use App\Tax\Vat\ViesSoapProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VatNumberProvider::class, ViesSoapProvider::class);
        $this->app->bind(InvoiceProvider::class, fn ($app) => new SzamlazzDryRunProvider($app->make(SzamlazzResponseParser::class)));
        $this->app->bind(InvoiceSubmissionProvider::class, SzamlazzAgentProvider::class);
        $this->app->bind(SyntheticInvoiceTestProvider::class, SzamlazzAgentProvider::class);

        // Published owner content, read once per request (cache cleared on publish).
        $this->app->scoped(ContentRepository::class);
        $this->app->scoped(OwnerSettings::class);

        // config/pricing.php with prices and plan wording published in the owner area.
        $this->app->scoped(
            PricingCatalog::class,
            fn ($app) => PricingCatalog::fromConfig(
                $app->make(PricingOverrides::class)->apply(config('pricing')),
                marketing: $app->make(ContentRepository::class)->planMarketing(),
            ),
        );

        // One set of storage connections per request or queue job.
        $this->app->scoped(StorageManager::class);

        // The operator console reads findings only through this interface.
        $this->app->singleton(SecurityScanner::class, EngineScanner::class);

        // Vulnerability data only from a configured, trusted source (docs/SECURITY_ENGINE.md).
        $this->app->bind(VulnerabilitySource::class, fn () => match (config('security.vulnerabilities.source')) {
            'wordfence' => WordfenceFeed::fromConfig(),
            default => new NoVulnerabilitySource,
        });

        $this->app->bind(CheckoutGateway::class, fn () => new StripeCheckoutGateway(
            secret: config('services.stripe.secret'),
            allowLiveMode: config('services.stripe.allow_live_mode'),
        ));

        // Owner-managed coupons and recovery offer payments: same key rules as checkout.
        $this->app->bind(PromotionGateway::class, fn () => new StripePromotionGateway(
            secret: config('services.stripe.secret'),
            allowLiveMode: config('services.stripe.allow_live_mode'),
        ));
        $this->app->bind(OfferPaymentGateway::class, fn () => new StripeOfferPaymentGateway(
            secret: config('services.stripe.secret'),
            allowLiveMode: config('services.stripe.allow_live_mode'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::defaults(['locale' => config('localization.default', 'en')]);

        // Database-backed service credentials override their fixed config keys at runtime.
        // Never bake these values into bootstrap/cache/config.php.
        $arguments = $_SERVER['argv'] ?? [];
        $buildingConfigCache = $this->app->runningInConsole()
            && (in_array('config:cache', $arguments, true) || in_array('optimize', $arguments, true));
        if (! $buildingConfigCache) {
            $hasCredentialTable = false;
            try {
                $hasCredentialTable = Schema::hasTable('integration_credentials');
            } catch (\Throwable) {
                // Fresh installs and config-only commands can run before DB provisioning.
            }
            if ($hasCredentialTable) {
                $this->app->make(IntegrationCredentials::class)->apply();
            }
            $hasSettingsTable = false;
            try {
                $hasSettingsTable = Schema::hasTable('owner_settings');
            } catch (\Throwable) {
                // A fresh install can boot before migrations are provisioned.
            }
            if ($hasSettingsTable) {
                $this->app->make(OwnerSettings::class)->applyRuntimeConfiguration();
            }
        }

        // Behind a load balancer: trust its X-Forwarded-* headers only when configured (config/http.php).
        if (filled(config('http.trusted_proxies'))) {
            TrustProxies::at(config('http.trusted_proxies') === '*' ? '*' : array_map('trim', explode(',', (string) config('http.trusted_proxies'))));
        }

        // Generated links (emails, redirects, signed URLs) use https:// when the application runs on HTTPS.
        if (config('http.force_https')) {
            URL::forceScheme('https');
        }

        // Starting a checkout creates a Stripe session; keep it from being scripted.
        // Login and password forms: per email and per IP.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        RateLimiter::for('password-email', fn (Request $request) => [
            Limit::perMinute(3)->by(mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(10)->by($request->ip()),
        ]);

        // Connector pairing: per IP, since the caller has no identity yet.
        RateLimiter::for('connector-pair', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(20)->by($request->ip()),
        ]);

        // Authenticated connector traffic: per connector (runs after connector.auth).
        RateLimiter::for('connector-api', fn (Request $request) => Limit::perMinute(30)->by(
            'connector:'.($request->attributes->get('connector')?->id ?? $request->ip()),
        ));

        // Backup chunks and restore downloads: many requests per backup, still per connector.
        RateLimiter::for('connector-transfer', fn (Request $request) => Limit::perMinute(600)->by(
            'connector-transfer:'.($request->attributes->get('connector')?->id ?? $request->ip()),
        ));

        // Admin access claims: a person clicking, so few; per connector.
        RateLimiter::for('connector-admin-access', fn (Request $request) => Limit::perMinute(20)->by(
            'connector-admin-access:'.($request->attributes->get('connector')?->id ?? $request->ip()),
        ));

        // Customers opening WordPress admin: per user and per website.
        RateLimiter::for('admin-access', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-access:user:'.$request->user()?->id),
            // Throttling runs before route-model binding: the parameter may still be the UUID.
            Limit::perHour(20)->by('admin-access:website:'.(is_object($request->route('website')) ? $request->route('website')->uuid : (string) $request->route('website'))),
        ]);
        RateLimiter::for('admin-access-full', fn (Request $request) => Limit::perHour(5)->by('admin-access-full:user:'.$request->user()?->id));

        // Operator console: each operator is limited on its own; sensitive actions
        // also per website. The services keep their own limits on top.
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->gate(), fn (User $user) => $user->hasOperatorPermission($permission));
        }

        // Owner area: every route names one of these; only owners pass.
        foreach (OwnerAbility::cases() as $ability) {
            Gate::define($ability->gate(), fn (User $user) => $ability->allows($user));
        }

        // Sensitive owner changes (prices, operators, customers, content): per owner.
        RateLimiter::for('owner-sensitive', fn (Request $request) => [
            Limit::perMinute(20)->by('owner-sensitive:'.$request->user()?->id),
            Limit::perHour(200)->by('owner-sensitive:'.$request->user()?->id),
        ]);
        // Calls that reach Stripe or a website: fewer.
        RateLimiter::for('owner-external', fn (Request $request) => Limit::perMinute(5)->by('owner-external:'.$request->user()?->id));
        RateLimiter::for('owner-settings-mail', fn (Request $request) => [
            Limit::perMinute(3)->by('owner-settings-mail:'.$request->user('owner')?->id),
            Limit::perHour(10)->by('owner-settings-mail:'.$request->user('owner')?->id),
        ]);
        // Coupons reach Stripe too, but a typo or two while typing a percentage must not lock the owner out.
        RateLimiter::for('owner-coupons', fn (Request $request) => [
            Limit::perMinute(10)->by('owner-coupons:'.$request->user()?->id),
            Limit::perHour(60)->by('owner-coupons:'.$request->user()?->id),
        ]);
        RateLimiter::for('staff-invitation', fn (Request $request) => [
            Limit::perMinute(5)->by('staff-invitation:'.$request->user()?->id),
            Limit::perHour(30)->by('staff-invitation:'.$request->user()?->id),
        ]);

        $operatorWebsite = fn (Request $request) => is_object($request->route('website')) ? $request->route('website')->uuid : (string) $request->route('website');
        RateLimiter::for('operator-action', fn (Request $request) => Limit::perMinute(30)->by('operator-action:'.$request->user()?->id));
        RateLimiter::for('operator-backup', fn (Request $request) => [
            Limit::perMinute(6)->by('operator-backup:'.$request->user()?->id),
            Limit::perHour(10)->by('operator-backup:website:'.$operatorWebsite($request)),
        ]);
        RateLimiter::for('operator-update', fn (Request $request) => [
            Limit::perMinute(10)->by('operator-update:'.$request->user()?->id),
            Limit::perHour(30)->by('operator-update:website:'.$operatorWebsite($request)),
        ]);
        RateLimiter::for('operator-restore', fn (Request $request) => [
            Limit::perMinute(3)->by('operator-restore:'.$request->user()?->id),
            Limit::perHour(5)->by('operator-restore:website:'.$operatorWebsite($request)),
        ]);
        RateLimiter::for('operator-security', fn (Request $request) => [
            Limit::perMinute(10)->by('operator-security:'.$request->user()?->id),
            Limit::perHour(30)->by('operator-security:website:'.$operatorWebsite($request)),
        ]);
        RateLimiter::for('operator-remediate', fn (Request $request) => [
            Limit::perMinute(3)->by('operator-remediate:'.$request->user()?->id),
            Limit::perHour(10)->by('operator-remediate:website:'.$operatorWebsite($request)),
        ]);
        RateLimiter::for('operator-connector', fn (Request $request) => [
            Limit::perMinute(5)->by('operator-connector:'.$request->user()?->id),
            Limit::perHour(10)->by('operator-connector:website:'.$operatorWebsite($request)),
        ]);

        // Customer account changes (profile, adding or disconnecting a website).
        RateLimiter::for('account', fn (Request $request) => [
            Limit::perMinute(20)->by('account:'.$request->user()?->id),
            Limit::perHour(200)->by('account:'.$request->user()?->id),
        ]);

        RateLimiter::for('vies-verification', fn (Request $request) => [
            Limit::perMinute(5)->by('vies-user:'.$request->user()?->id),
            Limit::perHour(15)->by('vies-ip:'.$request->ip()),
        ]);
        RateLimiter::for('owner-vies', fn (Request $request) => Limit::perMinute(5)->by('owner-vies:'.$request->user('owner')?->id));

        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);

        RateLimiter::for('checkout-preview', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perHour(120)->by($request->ip()),
        ]);
    }
}
