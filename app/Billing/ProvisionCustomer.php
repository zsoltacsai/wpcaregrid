<?php

namespace App\Billing;

use App\Localization\Locales;
use App\Mail\AccountActivation;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\LegalDocumentAcceptance;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Tax\BillingIdentityCompleteness;
use App\Tax\CustomerBillingIdentityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use UnexpectedValueException;

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
    public function __construct(
        private readonly CustomerBillingIdentityService $billingIdentities,
        private readonly BillingIdentityCompleteness $billingCompleteness,
    ) {}

    public function forIntent(CheckoutIntent $intent, ?string $stripeCustomerId): Customer
    {
        $intentCustomer = $intent->customer;
        $stripeCustomer = $stripeCustomerId
            ? Customer::where('stripe_customer_id', $stripeCustomerId)->first()
            : null;

        if ($intentCustomer && $stripeCustomer && $intentCustomer->id !== $stripeCustomer->id) {
            Log::warning('Stripe customer does not match the checkout customer.', [
                'checkout_intent' => $intent->uuid,
                'stripe_customer' => $stripeCustomerId,
            ]);

            throw new UnexpectedValueException('Stripe customer identity conflict.');
        }

        if ($stripeCustomer && Customer::normalizeEmail($stripeCustomer->email) !== Customer::normalizeEmail($intent->email)) {
            Log::warning('Stripe customer email does not match the checkout intent.', [
                'checkout_intent' => $intent->uuid,
                'stripe_customer' => $stripeCustomerId,
            ]);

            throw new UnexpectedValueException('Stripe customer identity conflict.');
        }

        $customer = $intentCustomer
            ?? $stripeCustomer
            ?? Customer::findByEmail($intent->email)
            ?? $this->customerByEmailOrCreate($intent);

        if ($stripeCustomerId && ! $customer->stripe_customer_id
            && ! Customer::where('stripe_customer_id', $stripeCustomerId)->exists()) {
            $customer->stripe_customer_id = $stripeCustomerId;
            $customer->save();
        }

        if ($intent->customer_id !== $customer->id) {
            $intent->customer()->associate($customer)->save();
            $intent->refresh();
        }

        // Bind the invoice identity to the exact details accepted before this
        // Checkout, rather than whichever profile happens to be current later.
        if ($this->billingCompleteness->missingForIntent($intent) === []) {
            $this->billingIdentities->snapshotFromCheckoutIntent($customer, $intent);
            $intent->refresh();
        }

        $this->ensureUser($customer, $intent);
        $this->recordCheckoutLegalAcceptance($intent, $customer);

        return $customer;
    }

    private function customerByEmailOrCreate(CheckoutIntent $intent): Customer
    {
        $email = Customer::normalizeEmail($intent->email);

        try {
            return Customer::query()->firstOrCreate(['email' => $email], [
                'first_name' => $intent->first_name,
                'last_name' => $intent->last_name,
                'company_name' => $intent->company_name,
                'country_code' => $intent->country,
                'customer_type' => $intent->customer_type,
                'tax_identifier_encrypted' => $intent->taxIdentifier(),
                'billing_address' => $intent->billing_address,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Under MySQL REPEATABLE READ, the transaction's earlier email lookup
            // can establish a snapshot that cannot see a concurrently committed
            // row. A locking read is a current read and resolves the winning insert.
            return Customer::query()->where('email', $email)->lockForUpdate()->first() ?? throw $exception;
        }
    }

    private function ensureUser(Customer $customer, CheckoutIntent $intent): void
    {
        if ($customer->user_id) {
            return;
        }

        $email = Customer::normalizeEmail($customer->email);

        try {
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $customer->fullName(),
                // Unusable until the customer sets a password via the activation link.
                'password' => Str::random(64),
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Match the customer current-read recovery for a concurrent account insert.
            $user = User::query()->where('email', $email)->lockForUpdate()->first() ?? throw $exception;
        }

        if ($user->customer()->exists()) {
            // The email's login already belongs to another customer; never merge silently.
            Log::warning('Customer email matches a login linked to another customer.', ['customer' => $customer->uuid]);

            return;
        }

        $customer->user()->associate($user)->save();
        $customer->setRelation('user', $user);

        if ($user->wasRecentlyCreated) {
            $locale = Locales::isSupported($intent->locale) ? $intent->locale : null;
            if ($locale !== null) {
                $user->forceFill(['locale' => $locale])->save();
            }

            $token = Password::broker('activations')->createToken($user);
            Mail::to($customer->email)->queue((new AccountActivation($customer, $token))->afterCommit());
        }
    }

    private function recordCheckoutLegalAcceptance(CheckoutIntent $intent, Customer $customer): void
    {
        if (! $intent->legal_document_version_id || ! $intent->legal_document_sha256 || ! $intent->legal_accepted_at) {
            return;
        }

        $user = $customer->user;
        $document = LegalDocumentVersion::query()->find($intent->legal_document_version_id);
        if (! $user || ! $document || $document->document_type !== 'terms_of_service'
            || $document->acceptance_mode !== 'acceptance_required_before_checkout'
            || ! hash_equals((string) $document->content_sha256, (string) $intent->legal_document_sha256)) {
            throw new UnexpectedValueException('Checkout legal acceptance evidence does not match its immutable document version.');
        }

        LegalDocumentAcceptance::query()->firstOrCreate(
            ['user_id' => $user->id, 'legal_document_version_id' => $document->id],
            [
                'customer_id' => $customer->id,
                'document_type' => $document->document_type,
                'locale' => $document->locale,
                'content_sha256' => $intent->legal_document_sha256,
                'source' => 'checkout',
                'accepted_at' => $intent->legal_accepted_at,
            ],
        );
    }
}
