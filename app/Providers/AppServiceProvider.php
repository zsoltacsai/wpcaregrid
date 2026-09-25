<?php

namespace App\Providers;

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
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
