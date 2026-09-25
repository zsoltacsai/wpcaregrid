<?php

namespace App\Models;

use App\Pricing\Price;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A promotion code the checkout accepts. The discount itself is applied by
 * Stripe (through `stripe_promotion_code_id`); this model holds the rules we
 * check on the server before creating a Checkout Session.
 */
class Promotion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'percent_off' => 'integer',
            'amount_off' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'product_types' => 'array',
            'plans' => 'array',
            'intervals' => 'array',
            'markets' => 'array',
        ];
    }

    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper(trim($value)),
        );
    }

    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtoupper(trim($code)))->first();
    }

    /**
     * Why this code cannot be used for a price, or null when it can.
     * Reasons map to lang/{locale}/checkout.php `promotion_errors`.
     */
    public function ineligibilityReason(Price $price, ?Carbon $now = null): ?string
    {
        $now ??= Carbon::now();

        return match (true) {
            ! $this->active || ! $this->stripe_promotion_code_id => 'inactive',
            $this->starts_at && $now->lt($this->starts_at) => 'not_started',
            $this->expires_at && $now->gte($this->expires_at) => 'expired',
            $this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions => 'used_up',
            ! $this->allows($this->product_types, $price->productType->value) => 'not_applicable',
            ! $this->allows($this->plans, $price->tier->value, "{$price->productType->value}.{$price->tier->value}") => 'not_applicable',
            ! $this->allows($this->intervals, $price->interval->value) => 'not_applicable',
            ! $this->allows($this->markets, $price->market) => 'not_applicable',
            $this->discount_type === 'fixed' && $this->currency !== $price->money->currency->code => 'not_applicable',
            default => null,
        };
    }

    /** A null or empty restriction list allows everything. */
    private function allows(?array $allowed, string ...$candidates): bool
    {
        return empty($allowed) || array_intersect($candidates, $allowed) !== [];
    }
}
