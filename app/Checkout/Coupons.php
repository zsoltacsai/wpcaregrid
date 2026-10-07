<?php

namespace App\Checkout;

use App\Models\Promotion;
use App\Models\PromotionReservation;
use App\Models\User;
use App\Owner\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner-managed coupons. There is one coupon engine: the `promotions` table
 * and Promotion::ineligibilityReason(), which StartCheckout already uses.
 * This class only creates and maintains those rows (and the matching Stripe
 * coupon and promotion code); checkout, redemption counting and the webhook
 * are unchanged and stay authoritative.
 *
 * Fixed after creation (a Stripe coupon is immutable): code, percentage,
 * duration. Editable: validity window, usage limits, restrictions, note,
 * active. Every change is audited by field name; the Stripe secret never
 * reaches this class (it sits inside the gateway).
 */
final class Coupons
{
    public const EDITABLE = ['starts_at', 'expires_at', 'max_redemptions', 'max_redemptions_per_customer', 'product_types', 'plans', 'intervals', 'markets', 'note'];

    public function __construct(private readonly PromotionGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $data  Validated: code, percent_off ("12.50"), duration, duration_months, and the editable fields.
     *
     * @throws PromotionGatewayException
     */
    public function create(array $data, User $actor): Promotion
    {
        if (! in_array($data['duration'] ?? null, ['once', 'forever'], true)) {
            throw ValidationException::withMessages(['duration' => __('owner.coupons.errors.repeating_deprecated')]);
        }

        $code = strtoupper(trim($data['code']));

        if (Promotion::where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('owner.coupons.errors.code_taken')]);
        }

        // Stripe first; if the row cannot be saved afterwards, the Stripe objects are removed again.
        $stripe = $this->gateway->create($code, $data['percent_off'], $data['duration'], $data['duration_months'] ?? null, "Coupon {$code}");

        try {
            return DB::transaction(function () use ($data, $code, $stripe, $actor) {
                $promotion = Promotion::create([
                    'code' => $code,
                    'stripe_coupon_id' => $stripe['coupon'],
                    'stripe_promotion_code_id' => $stripe['promotion_code'],
                    'active' => true,
                    'discount_type' => 'percent',
                    'percent_off' => $data['percent_off'],
                    'duration' => $data['duration'],
                    'duration_months' => $data['duration'] === 'repeating' ? $data['duration_months'] : null,
                    'created_by_user_id' => $actor->id,
                ] + $this->editable($data));

                AdminAudit::record($actor, 'coupon.created', 'coupon', $promotion->code, [
                    'percent_off' => $promotion->percentLabel(),
                    'duration' => $promotion->duration,
                    'max_redemptions' => $promotion->max_redemptions,
                ]);

                return $promotion;
            });
        } catch (\Throwable $e) {
            $this->gateway->remove($stripe['coupon'], $stripe['promotion_code']);

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data  Validated editable fields. */
    public function update(Promotion $promotion, array $data, User $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $data, $actor) {
            $locked = Promotion::query()->whereKey($promotion->id)->lockForUpdate()->firstOrFail();
            $limit = $data['max_redemptions'] ?? null;
            $reserved = PromotionReservation::query()
                ->where('promotion_id', $locked->id)
                ->where('status', 'reserved')
                ->count();
            if ($limit !== null && $limit < $locked->times_redeemed + $reserved) {
                throw ValidationException::withMessages(['max_redemptions' => __('owner.coupons.errors.limit_below_used', ['used' => $locked->times_redeemed + $reserved])]);
            }

            $locked->fill($this->editable($data));
            $changed = array_values(array_intersect(array_keys($locked->getDirty()), self::EDITABLE));
            if ($changed !== []) {
                $locked->save();
                AdminAudit::record($actor, 'coupon.updated', 'coupon', $locked->code, ['fields' => $changed]);
            }

            return $locked;
        });
    }

    /**
     * @throws PromotionGatewayException when Stripe cannot switch the code (nothing changes locally)
     */
    public function setActive(Promotion $promotion, bool $active, User $actor): Promotion
    {
        if ($promotion->active === $active) {
            return $promotion;
        }

        if ($promotion->stripe_promotion_code_id) {
            $this->gateway->setActive($promotion->stripe_promotion_code_id, $active);
        }

        DB::transaction(function () use ($promotion, $active, $actor) {
            $promotion->update(['active' => $active]);
            AdminAudit::record($actor, $active ? 'coupon.activated' : 'coupon.deactivated', 'coupon', $promotion->code);
        });

        return $promotion;
    }

    /** A coupon nobody has used. One with history is deactivated instead. */
    public function delete(Promotion $promotion, User $actor): void
    {
        if ($promotion->hasHistory()) {
            throw ValidationException::withMessages(['coupon' => __('owner.coupons.errors.has_history')]);
        }

        $this->gateway->remove($promotion->stripe_coupon_id, $promotion->stripe_promotion_code_id);

        DB::transaction(function () use ($promotion, $actor) {
            AdminAudit::record($actor, 'coupon.deleted', 'coupon', $promotion->code);
            $promotion->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function editable(array $data): array
    {
        $list = fn (string $key) => ($data[$key] ?? []) === [] ? null : array_values($data[$key]);

        return [
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'max_redemptions_per_customer' => $data['max_redemptions_per_customer'] ?? null,
            'product_types' => $list('product_types'),
            'plans' => $list('plans'),
            'intervals' => $list('intervals'),
            'markets' => $list('markets'),
            'note' => filled($data['note'] ?? null) ? $data['note'] : null,
        ];
    }
}
