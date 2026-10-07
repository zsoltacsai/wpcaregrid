<?php

namespace App\Offers;

use App\Checkout\CheckoutUnavailable;
use App\Mail\RecoveryOfferPaid;
use App\Mail\RecoveryOfferSent;
use App\Models\Customer;
use App\Models\RecoveryOffer;
use App\Models\User;
use App\Models\Website;
use App\Owner\AdminAudit;
use App\Pricing\PriceEditor;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Recovery offers (docs/RECOVERY_OFFERS.md). The owner creates, sends,
 * changes, cancels and completes; the customer accepts or declines and pays;
 * only the signed Stripe webhook marks an offer paid.
 *
 * Rules that hold everywhere here:
 * - The customer and the website come from the owner's selection and are
 *   checked against each other on the server (the website must belong to the
 *   customer). Customers act only on their own offers (OfferController).
 * - The amount is the stored one; currencies are the ones the catalogue sells
 *   in (no conversion, nothing invented).
 * - An accepted offer is frozen. An open offer expires at its validity.
 * - Every state change locks the row, checks the state again and is audited.
 */
final class RecoveryOffers
{
    /** How far ahead an offer may be valid. */
    public const MAX_VALIDITY_DAYS = 180;

    public function __construct(
        private readonly OfferPaymentGateway $gateway,
        private readonly PricingCatalog $catalog,
        private readonly PriceEditor $prices,
    ) {}

    /**
     * @param  array{title: string, scope: string, internal_notes?: ?string, amount: string, currency: string, valid_until: Carbon, website: string}  $data
     */
    public function create(Customer $customer, array $data, User $actor): RecoveryOffer
    {
        $this->ensureHungarianRecoveryMarket($customer);
        $website = $this->websiteOf($customer, $data['website']);
        [$amount, $currency] = $this->price($data['amount'], $data['currency']);
        $this->checkValidity($data['valid_until']);

        return DB::transaction(function () use ($customer, $website, $data, $amount, $currency, $actor) {
            $offer = RecoveryOffer::create([
                'customer_id' => $customer->id,
                'website_id' => $website->id,
                'title' => $data['title'],
                'scope' => $data['scope'],
                'internal_notes' => filled($data['internal_notes'] ?? null) ? $data['internal_notes'] : null,
                'amount' => $amount,
                'currency' => $currency,
                'valid_until' => $data['valid_until'],
                'status' => OfferStatus::Draft,
                'created_by_user_id' => $actor->id,
            ]);

            $this->audit($actor, 'offer.created', $offer, ['customer' => $customer->uuid, 'website' => $website->uuid, 'amount' => $amount, 'currency' => $currency]);

            return $offer;
        });
    }

    /** Change a draft or an offered (not yet accepted) offer. */
    public function update(RecoveryOffer $offer, array $data, User $actor): RecoveryOffer
    {
        [$amount, $currency] = $this->price($data['amount'], $data['currency']);
        $this->ensureHungarianRecoveryMarket($offer->customer);
        $this->checkValidity($data['valid_until']);

        return DB::transaction(function () use ($offer, $data, $amount, $currency, $actor) {
            $offer = $this->locked($offer);

            if (! $offer->status->isEditable()) {
                throw ValidationException::withMessages(['offer' => __('owner.offers.errors.frozen')]);
            }

            $values = [
                'title' => $data['title'],
                'scope' => $data['scope'],
                'internal_notes' => filled($data['internal_notes'] ?? null) ? $data['internal_notes'] : null,
                'amount' => $amount,
                'currency' => $currency,
                'valid_until' => $data['valid_until'],
            ];

            // The website can be corrected while it is a draft; once sent, the customer has seen it.
            if ($offer->status === OfferStatus::Draft && isset($data['website'])) {
                $values['website_id'] = $this->websiteOf($offer->customer, $data['website'])->id;
            }

            $offer->fill($values);
            $changed = array_keys($offer->getDirty());

            if ($changed !== []) {
                $offer->save();
                $this->audit($actor, 'offer.updated', $offer, ['fields' => $changed]);
            }

            return $offer;
        });
    }

    /** Make the offer visible to the customer and tell them. */
    public function send(RecoveryOffer $offer, User $actor): RecoveryOffer
    {
        return DB::transaction(function () use ($offer, $actor) {
            $offer = $this->locked($offer);

            if ($offer->status !== OfferStatus::Draft) {
                throw ValidationException::withMessages(['offer' => __('owner.offers.errors.not_draft')]);
            }
            $this->ensureHungarianRecoveryMarket($offer->customer);
            if ($offer->validityPassed()) {
                throw ValidationException::withMessages(['valid_until' => __('owner.offers.errors.validity_passed')]);
            }

            $offer->forceFill(['status' => OfferStatus::Offered, 'offered_at' => now()])->save();
            $this->audit($actor, 'offer.sent', $offer);
            $this->notifyOffered($offer);

            return $offer;
        });
    }

    public function cancel(RecoveryOffer $offer, User $actor): RecoveryOffer
    {
        $session = null;

        $offer = DB::transaction(function () use ($offer, $actor, &$session) {
            $offer = $this->locked($offer);

            if (! in_array($offer->status, [OfferStatus::Draft, OfferStatus::Offered, OfferStatus::Accepted], true)) {
                throw ValidationException::withMessages(['offer' => __('owner.offers.errors.cannot_cancel')]);
            }

            $session = $offer->stripe_checkout_session_id;
            $offer->forceFill(['status' => OfferStatus::Cancelled, 'cancelled_at' => now(), 'stripe_checkout_url' => null])->save();
            $this->audit($actor, 'offer.cancelled', $offer);

            return $offer;
        });

        // Stop an unpaid Checkout Session from being paid. A payment already in flight is still
        // recorded when Stripe confirms it (markPaid), so money is never lost from view.
        if ($session) {
            $this->gateway->expireSession($session);
        }

        return $offer;
    }

    /** The work has been done. */
    public function complete(RecoveryOffer $offer, User $actor): RecoveryOffer
    {
        return DB::transaction(function () use ($offer, $actor) {
            $offer = $this->locked($offer);

            if ($offer->status !== OfferStatus::Paid) {
                throw ValidationException::withMessages(['offer' => __('owner.offers.errors.not_paid')]);
            }

            $offer->forceFill(['status' => OfferStatus::Completed, 'completed_at' => now()])->save();
            $this->audit($actor, 'offer.completed', $offer);

            return $offer;
        });
    }

    /**
     * The customer accepts (once) and gets a Checkout Session for the stored
     * price. Safe to repeat: a second call reuses the open session.
     *
     * @throws ValidationException when the offer cannot be accepted
     * @throws CheckoutUnavailable when Stripe cannot start the payment (the offer stays accepted)
     */
    public function accept(RecoveryOffer $offer): RecoveryOffer
    {
        DB::transaction(function () use ($offer) {
            $locked = $this->locked($offer);

            if ($locked->status === OfferStatus::Accepted && ! $locked->validityPassed()) {
                $this->ensureHungarianRecoveryMarket($locked->customer, 'offers.errors.not_eligible');

                return; // Already accepted: continue to payment.
            }
            $this->ensureHungarianRecoveryMarket($locked->customer, 'offers.errors.not_eligible');
            if (! $locked->canBeAccepted()) {
                throw ValidationException::withMessages(['offer' => __('offers.errors.not_acceptable')]);
            }

            $locked->forceFill(['status' => OfferStatus::Accepted, 'accepted_at' => now()])->save();
            $this->audit($locked->customer->user, 'offer.accepted', $locked, ['customer' => $locked->customer->uuid], customer: true);
        });

        return $this->startPayment($offer->refresh());
    }

    /**
     * An accepted, unpaid offer: the open Checkout Session, or a new one.
     *
     * @throws ValidationException
     * @throws CheckoutUnavailable
     */
    public function startPayment(RecoveryOffer $offer): RecoveryOffer
    {
        $offer = $offer->refresh();

        $this->ensureHungarianRecoveryMarket($offer->customer, 'offers.errors.not_eligible');

        if (! $offer->canBePaid()) {
            throw ValidationException::withMessages(['offer' => __('offers.errors.not_payable')]);
        }

        if ($offer->hasLiveCheckout()) {
            return $offer;
        }

        $session = $this->gateway->createSession($offer, (string) $offer->stripe_checkout_session_id);

        // Only the stored price was sent; the session is saved against the offer so the webhook can match it.
        return DB::transaction(function () use ($offer, $session) {
            $offer = $this->locked($offer);

            if ($offer->status === OfferStatus::Accepted) {
                $offer->forceFill([
                    'stripe_checkout_session_id' => $session->id,
                    'stripe_checkout_url' => $session->url,
                    'stripe_checkout_expires_at' => $session->expiresAt,
                ])->save();
            }

            return $offer;
        });
    }

    public function decline(RecoveryOffer $offer): RecoveryOffer
    {
        return DB::transaction(function () use ($offer) {
            $offer = $this->locked($offer);

            if ($offer->status !== OfferStatus::Offered) {
                throw ValidationException::withMessages(['offer' => __('offers.errors.not_declinable')]);
            }

            $offer->forceFill(['status' => OfferStatus::Declined, 'declined_at' => now()])->save();
            $this->audit($offer->customer->user, 'offer.declined', $offer, ['customer' => $offer->customer->uuid], customer: true);

            return $offer;
        });
    }

    /**
     * checkout.session.completed / async_payment_succeeded for an offer,
     * from the verified webhook only. The session must be the one saved on
     * the offer, and Stripe's amount and currency must match the stored
     * price; anything else is logged and changes nothing. Repeating it
     * changes nothing and sends nothing twice.
     */
    public function markPaid(object $session): ?RecoveryOffer
    {
        $uuid = $session->metadata->recovery_offer ?? $session->client_reference_id ?? null;
        $offer = is_string($uuid) ? RecoveryOffer::where('uuid', $uuid)->first() : null;

        if (! $offer || ! isset($session->id) || $offer->stripe_checkout_session_id !== $session->id) {
            Log::warning('Stripe payment for an unknown recovery offer session ignored.', ['stripe_session' => $session->id ?? null]);

            return null;
        }

        if (($session->payment_status ?? null) !== 'paid') {
            return $offer; // Delayed method still pending: async_payment_succeeded follows.
        }

        if ((int) ($session->amount_total ?? -1) !== $offer->amount || strtoupper((string) ($session->currency ?? '')) !== $offer->currency) {
            Log::error('Stripe payment does not match the recovery offer price; not marked paid.', ['recovery_offer' => $offer->uuid, 'stripe_session' => $session->id]);

            return $offer;
        }

        return DB::transaction(function () use ($offer, $session) {
            $offer = $this->locked($offer);

            if (in_array($offer->status, [OfferStatus::Paid, OfferStatus::Completed], true)) {
                return $offer; // Replayed event.
            }

            $before = $offer->status->value;
            $offer->forceFill([
                'status' => OfferStatus::Paid,
                'paid_at' => now(),
                'paid_amount' => (int) $session->amount_total,
                'stripe_payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : $offer->stripe_payment_intent_id,
                'stripe_checkout_url' => null,
            ])->save();

            $this->audit(null, 'offer.paid', $offer, ['customer' => $offer->customer->uuid, 'status_before' => $before]);

            if (! $offer->payment_notified_at) {
                $offer->forceFill(['payment_notified_at' => now()])->save();
                Mail::to($offer->customer->email)->queue(new RecoveryOfferPaid($offer));
            }

            return $offer;
        });
    }

    /** Offers whose validity has passed expire; an unpaid Checkout Session is stopped. Run hourly. */
    public function expireDue(): int
    {
        $expired = 0;

        RecoveryOffer::whereIn('status', [OfferStatus::Offered->value, OfferStatus::Accepted->value])
            ->where('valid_until', '<=', now())
            ->each(function (RecoveryOffer $due) use (&$expired) {
                $session = null;

                $changed = DB::transaction(function () use ($due, &$session) {
                    $offer = $this->locked($due);

                    if (! $offer->status->isOpen() || ! $offer->validityPassed()) {
                        return false;
                    }

                    $session = $offer->stripe_checkout_session_id;
                    $offer->forceFill(['status' => OfferStatus::Expired, 'expired_at' => now(), 'stripe_checkout_url' => null])->save();
                    $this->audit(null, 'offer.expired', $offer);

                    return true;
                });

                if ($changed) {
                    $expired++;
                    if ($session) {
                        $this->gateway->expireSession($session);
                    }
                }
            });

        return $expired;
    }

    private function notifyOffered(RecoveryOffer $offer): void
    {
        if (! $offer->offer_notified_at) {
            $offer->forceFill(['offer_notified_at' => now()])->save();
            Mail::to($offer->customer->email)->queue(new RecoveryOfferSent($offer));
        }
    }

    private function locked(RecoveryOffer $offer): RecoveryOffer
    {
        return RecoveryOffer::whereKey($offer->id)->lockForUpdate()->with(['customer.user', 'website'])->firstOrFail();
    }

    /** The website must be one of this customer's; any other value is refused. */
    private function websiteOf(Customer $customer, string $websiteUuid): Website
    {
        return $customer->websites()->where('uuid', $websiteUuid)->first()
            ?? throw ValidationException::withMessages(['website' => __('owner.offers.errors.website')]);
    }

    /** @return array{0: int, 1: string} minor units and currency */
    private function price(string $amount, string $currency): array
    {
        $currency = strtoupper(trim($currency));

        if (! in_array($currency, $this->catalog->recoveryOfferCurrencies(), true)) {
            throw ValidationException::withMessages(['currency' => __('owner.offers.errors.currency')]);
        }

        $decimals = (int) config("pricing.currencies.{$currency}.display_decimals", 0);
        $pattern = $decimals > 0 ? '/^\d{1,9}(\.\d{1,'.$decimals.'})?$/' : '/^\d{1,9}$/';
        $text = str_replace(',', '.', trim($amount));

        if (! preg_match($pattern, $text)) {
            throw ValidationException::withMessages(['amount' => __('owner.offers.errors.amount_format', ['decimals' => $decimals])]);
        }

        $minor = $this->prices->minor($text, $currency);
        if ($minor < 1 || $minor > PriceEditor::MAX_MINOR_AMOUNT) {
            throw ValidationException::withMessages(['amount' => __('owner.offers.errors.amount_range')]);
        }

        return [$minor, $currency];
    }

    /** Recovery work is currently sold only in the Hungarian market, in HUF. */
    private function ensureHungarianRecoveryMarket(Customer $customer, string $errorKey = 'owner.offers.errors.customer_market'): void
    {
        if (strtoupper($customer->country_code) !== 'HU') {
            throw ValidationException::withMessages(['customer' => __($errorKey)]);
        }
    }

    private function checkValidity(Carbon $validUntil): void
    {
        if ($validUntil->lte(now())) {
            throw ValidationException::withMessages(['valid_until' => __('owner.offers.errors.validity_passed')]);
        }
        if ($validUntil->gt(now()->addDays(self::MAX_VALIDITY_DAYS))) {
            throw ValidationException::withMessages(['valid_until' => __('owner.offers.errors.validity_too_long', ['days' => self::MAX_VALIDITY_DAYS])]);
        }
    }

    /** @param  array<string, scalar|list<scalar>|null>  $data */
    private function audit(?User $actor, string $event, RecoveryOffer $offer, array $data = [], bool $customer = false): void
    {
        // A customer's own action is recorded with their id but is not an owner action.
        AdminAudit::record($customer ? null : $actor, $event, 'offer', $offer->uuid, $data + ($customer && $actor ? ['by_customer_user' => $actor->id] : []));
    }
}
