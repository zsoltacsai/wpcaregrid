<?php

namespace App\Models;

use App\Billing\SubscriptionStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The commercial customer: who pays and who the service is for. Login is a
 * separate User. No card data is ever stored here.
 */
class Customer extends Model
{
    use HasFactory, HasUuids;

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

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
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

    public function checkoutIntents(): HasMany
    {
        return $this->hasMany(CheckoutIntent::class);
    }

    /** Subscriptions that currently grant service access. */
    public function activeSubscriptions(): HasMany
    {
        $granting = array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $status) => $status->grantsAccess());

        return $this->subscriptions()->whereIn('status', array_map(fn ($status) => $status->value, $granting));
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
