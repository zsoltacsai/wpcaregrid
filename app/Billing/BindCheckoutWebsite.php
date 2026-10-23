<?php

namespace App\Billing;

use App\Models\CheckoutIntent;
use App\Models\Subscription;
use App\Models\Website;
use App\Pricing\ProductType;
use App\Rules\PublicWebsiteUrl;
use App\Websites\ConnectorStatus;
use App\Websites\NormalizeWebsiteUrl;
use App\Websites\WebsiteStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class BindCheckoutWebsite
{
    public function __construct(private readonly NormalizeWebsiteUrl $normalize) {}

    /** Must run inside the verified Stripe webhook transaction. */
    public function apply(Subscription $subscription, ?CheckoutIntent $intent): void
    {
        if (! $subscription->grantsAccess() || ! $intent
            || ! is_string($intent->intended_website_url)
            || ! $subscription->customer_id
            || $intent->customer_id !== $subscription->customer_id) {
            return;
        }

        $type = $intent->intended_website_type instanceof ProductType
            ? $intent->intended_website_type
            : (is_string($intent->intended_website_type) ? ProductType::tryFrom($intent->intended_website_type) : null);
        $url = $intent->intended_website_url;
        if (! $type || $type !== $subscription->product_type
            || ($this->normalize)($url) !== $url
            || Validator::make(['url' => $url], ['url' => ['required', 'string', 'max:255', 'url:http,https', new PublicWebsiteUrl]])->fails()) {
            Log::warning('Paid checkout website target could not be safely bound.', ['checkout_intent' => $intent->uuid]);

            return;
        }

        $existing = Website::query()->where('customer_id', $subscription->customer_id)->where('url', $url)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->subscription_id !== $subscription->id || $existing->type !== $type) {
                Log::warning('Paid checkout website target conflicts with an existing customer website.', ['checkout_intent' => $intent->uuid]);
            }

            return;
        }

        try {
            Website::create([
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'url' => $url,
                'type' => $type,
                'status' => WebsiteStatus::Pending,
                'connector_status' => ConnectorStatus::NotInstalled,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent purchase may have claimed the same customer URL or subscription.
            Log::warning('Paid checkout website target lost a concurrent assignment race.', ['checkout_intent' => $intent->uuid]);
        }
    }
}
