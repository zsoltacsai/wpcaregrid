<?php

namespace Tests\Fakes;

use App\Checkout\PromotionGateway;
use App\Checkout\PromotionGatewayException;

/** Records coupon calls instead of talking to Stripe. */
class FakePromotionGateway implements PromotionGateway
{
    /** @var list<array{code: string, percent_off: string, duration: string, months: ?int}> */
    public array $created = [];

    /** @var list<array{id: string, active: bool}> */
    public array $switched = [];

    /** @var list<array{coupon: ?string, promotion_code: ?string}> */
    public array $removed = [];

    public ?string $failWith = null;

    private int $n = 0;

    public function create(string $code, string $percentOff, string $duration, ?int $months, string $name): array
    {
        if ($this->failWith) {
            throw new PromotionGatewayException($this->failWith);
        }

        $this->created[] = ['code' => $code, 'percent_off' => $percentOff, 'duration' => $duration, 'months' => $months];
        $this->n++;

        return ['coupon' => "coupon_test_{$this->n}", 'promotion_code' => "promo_test_{$this->n}"];
    }

    public function setActive(string $promotionCodeId, bool $active): void
    {
        if ($this->failWith) {
            throw new PromotionGatewayException($this->failWith);
        }

        $this->switched[] = ['id' => $promotionCodeId, 'active' => $active];
    }

    public function remove(?string $couponId, ?string $promotionCodeId): void
    {
        $this->removed[] = ['coupon' => $couponId, 'promotion_code' => $promotionCodeId];
    }
}
