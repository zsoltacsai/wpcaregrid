<?php

namespace App\Models;

use App\Checkout\CheckoutStatus;
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

    /** The owner area addresses a coupon by its code (upper-case letters, digits, - and _). */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'percent_off' => 'decimal:2',
            'duration_months' => 'integer',
            'max_redemptions_per_customer' => 'integer',
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
     * A percentage typed by the owner as "10", "12.5" or "12,50", as the
     * canonical string "12.50", or null when it is not a number in (0, 100]
     * with at most two decimals. Works on digits only: no floats, so
     * 100 is exactly 100 and 100.01 is refused. Zero is refused because
     * Stripe cannot create a zero-percent coupon and it would change nothing.
     */
    public static function parsePercent(mixed $input): ?string
    {
        $text = str_replace(',', '.', trim((string) $input));

        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $text, $m)) {
            return null;
        }

        $hundredths = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $hundredths >= 1 && $hundredths <= 10000 ? sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100) : null;
    }

    /** "10", "12.5", "100" for display. */
    public function percentLabel(): string
    {
        return $this->percent_off === null ? '' : rtrim(rtrim(number_format((float) $this->percent_off, 2, '.', ''), '0'), '.');
    }

    public function isFullDiscount(): bool
    {
        return $this->discount_type === 'percent' && $this->percent_off !== null && (float) $this->percent_off >= 100.0;
    }

    /**
     * Every invoice of the subscription is zero: 100% off, for ever. Only then
     * does Checkout skip the payment method; with "once" or "repeating" the
     * subscription is charged later, so a payment method is still needed.
     */
    public function makesEveryInvoiceFree(): bool
    {
        return $this->isFullDiscount() && $this->duration === 'forever';
    }

    /** Redemptions still available, or null when unlimited. */
    public function remainingRedemptions(): ?int
    {
        return $this->max_redemptions === null ? null : max(0, $this->max_redemptions - $this->times_redeemed);
    }

    /** Whether this buyer (a customer or an email address) has used the code as often as allowed. */
    public function usedUpBy(string $email, ?int $customerId = null): bool
    {
        if ($this->max_redemptions_per_customer === null) {
            return false;
        }

        $used = CheckoutIntent::where('promotion_id', $this->id)
            ->where('status', CheckoutStatus::Completed)
            ->where(fn ($q) => $q->where('email', mb_strtolower(trim($email)))->when($customerId, fn ($q) => $q->orWhere('customer_id', $customerId)))
            ->count();

        return $used >= $this->max_redemptions_per_customer;
    }

    /** Whether any checkout ever used this code; a coupon with history is deactivated, not deleted. */
    public function hasHistory(): bool
    {
        return $this->times_redeemed > 0 || CheckoutIntent::where('promotion_id', $this->id)->exists();
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
