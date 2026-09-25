<?php

namespace App\Pricing;

use InvalidArgumentException;

/** An amount in the currency's smallest unit (fillér, cent, penny). */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    public function subtract(self $other): self
    {
        if ($other->currency->code !== $this->currency->code) {
            throw new InvalidArgumentException('Cannot subtract amounts in different currencies.');
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function formattedAmount(): string
    {
        return $this->currency->formatAmount($this->amount);
    }

    public function format(): string
    {
        return $this->currency->format($this->amount);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
