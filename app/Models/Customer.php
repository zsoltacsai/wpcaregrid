<?php

namespace App\Models;

use App\Billing\SubscriptionStatus;
use App\Localization\Locales;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The commercial customer: who pays and who the service is for. Login is a
 * separate User. No card data is ever stored here.
 */
class Customer extends Model
{
    use HasFactory, HasUuids;

    protected $hidden = ['tax_id', 'tax_identifier_encrypted'];

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'tax_identifier_encrypted' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function findByEmail(string $email): ?self
    {
        return static::where('email', static::normalizeEmail($email))->first();
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => static::normalizeEmail($value));
    }

    protected function countryCode(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtoupper($value));
    }

    /**
     * The language for emails that can use the customer's choice: the one saved
     * on their login (users.locale), else the site default. Older emails are
     * still English only (docs/LOCALIZATION.md).
     */
    public function mailLocale(): string
    {
        $saved = $this->user?->locale;

        return Locales::isSupported($saved) ? $saved : Locales::default();
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /** New writes use encrypted storage; legacy rows remain readable without backfill. */
    public function taxIdentifier(): ?string
    {
        return filled($this->tax_identifier_encrypted) ? $this->tax_identifier_encrypted : ($this->tax_id ?: null);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function privacyRequests(): HasMany
    {
        return $this->hasMany(PrivacyRequest::class);
    }

    public function syntheticFixture(): HasOne
    {
        return $this->hasOne(SyntheticSiteFixture::class);
    }

    public function recoveryOffers(): HasMany
    {
        return $this->hasMany(RecoveryOffer::class);
    }

    public function checkoutIntents(): HasMany
    {
        return $this->hasMany(CheckoutIntent::class);
    }

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class)->latest('stripe_created_at');
    }

    public function billingEmailOutbox(): HasMany
    {
        return $this->hasMany(BillingEmailOutbox::class)->latest('id');
    }

    public function billingIdentities(): HasMany
    {
        return $this->hasMany(CustomerBillingIdentity::class)->orderByDesc('version');
    }

    public function latestBillingIdentity(): ?CustomerBillingIdentity
    {
        return $this->billingIdentities()->first();
    }

    /** Subscriptions that currently grant service access. */
    public function activeSubscriptions(): HasMany
    {
        $granting = array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status) => $status->grantsAccess());

        return $this->subscriptions()->where(function ($query) use ($granting) {
            $query->whereIn('status', array_map(fn ($status) => $status->value, $granting))
                ->orWhere(fn ($grace) => $grace->where('status', SubscriptionStatus::PastDue->value)
                    ->whereNotNull('grace_ends_at')->where('grace_ends_at', '>', now()));
        })
            // An internal plan counts only inside its optional start and end dates.
            ->where(fn ($q) => $q->whereNull('internal_plan')->orWhere(fn ($i) => $i
                ->where(fn ($s) => $s->whereNull('internal_starts_at')->orWhere('internal_starts_at', '<=', now()))
                ->where(fn ($e) => $e->whereNull('internal_ends_at')->orWhere('internal_ends_at', '>', now()))));
    }

    public function hasActiveSubscription(): bool
    {
        return $this->active && $this->activeSubscriptions()->exists();
    }

    /** An active subscription without a website yet (each plan covers one site). */
    public function subscriptionAvailableForWebsite(): ?Subscription
    {
        return $this->activeSubscriptions()->whereDoesntHave('website')->oldest('id')->first();
    }

    /** The subscription to feature on the dashboard: an active one, else the latest. */
    public function primarySubscription(): ?Subscription
    {
        return $this->activeSubscriptions()->latest('id')->first()
            ?? $this->subscriptions()->latest('id')->first();
    }
}
