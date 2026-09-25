<?php

namespace App\Billing;

use App\Mail\AccountActivation;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Resolves the Customer (and login) for a checkout whose payment Stripe has
 * confirmed. Only called from verified webhook events.
 *
 * Identity rules:
 * - An intent started while logged in already names its customer.
 * - Otherwise match the Stripe customer ID, then the normalised email.
 * - An existing customer found by email is not modified: the checkout form
 *   must not be able to overwrite someone's profile. The subscription is
 *   attached to that customer, so access stays with the email's owner.
 * - A login is created only when none exists for the email, with an
 *   unusable password and an emailed activation link. Passwords are never
 *   emailed.
 */
final class ProvisionCustomer
{
    public function forIntent(CheckoutIntent $intent, ?string $stripeCustomerId): Customer
    {
        $customer = $intent->customer
            ?? ($stripeCustomerId ? Customer::where('stripe_customer_id', $stripeCustomerId)->first() : null)
            ?? Customer::findByEmail($intent->email)
            ?? Customer::create([
                'email' => $intent->email,
                'first_name' => $intent->first_name,
                'last_name' => $intent->last_name,
                'company_name' => $intent->company_name,
                'country_code' => $intent->country,
                'customer_type' => $intent->customer_type,
                'tax_id' => $intent->tax_id,
                'billing_address' => $intent->billing_address,
            ]);

        if ($stripeCustomerId && ! $customer->stripe_customer_id
            && ! Customer::where('stripe_customer_id', $stripeCustomerId)->exists()) {
            $customer->stripe_customer_id = $stripeCustomerId;
            $customer->save();
        }

        if ($intent->customer_id !== $customer->id) {
            $intent->customer()->associate($customer)->save();
        }

        $this->ensureUser($customer);

        return $customer;
    }

    private function ensureUser(Customer $customer): void
    {
        if ($customer->user_id) {
            return;
        }

        $user = User::where('email', $customer->email)->first();

        if ($user?->customer()->exists()) {
            // The email's login already belongs to another customer; never merge silently.
            Log::warning('Customer email matches a login linked to another customer.', ['customer' => $customer->uuid]);

            return;
        }

        if (! $user) {
            $user = User::create([
                'name' => $customer->fullName(),
                'email' => $customer->email,
                // Unusable until the customer sets a password via the activation link.
                'password' => Str::random(64),
            ]);

            $token = Password::broker('activations')->createToken($user);
            Mail::to($customer->email)->queue(new AccountActivation($customer, $token));
        }

        $customer->user()->associate($user)->save();
    }
}
