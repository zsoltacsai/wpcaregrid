<?php

namespace App\Providers;

use App\Backups\Storage\StorageManager;
use App\Checkout\CheckoutGateway;
use App\Checkout\StripeCheckoutGateway;
use App\Models\User;
use App\Operator\Permission;
use App\Pricing\PricingCatalog;
use App\Security\EngineScanner;
use App\Security\SecurityScanner;
use App\Security\Vulnerabilities\NoVulnerabilitySource;
use App\Security\Vulnerabilities\VulnerabilitySource;
use App\Security\Vulnerabilities\WordfenceFeed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(
            PricingCatalog::class,
            fn () => PricingCatalog::fromConfig(config('pricing')),
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
