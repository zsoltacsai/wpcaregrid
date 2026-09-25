<?php

namespace App\Providers;

use App\Backups\Storage\StorageManager;
use App\Checkout\CheckoutGateway;
use App\Checkout\StripeCheckoutGateway;
use App\Pricing\PricingCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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

        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
