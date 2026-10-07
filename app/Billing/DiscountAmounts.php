<?php

namespace App\Billing;

/** Integer minor-unit discount arithmetic used for estimates and confirmed terms. */
final class DiscountAmounts
{
    public static function afterPercent(int $amount, string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '0');
        $hundredths = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        $factor = 10000 - $hundredths;

        // Split before multiplying to avoid overflowing on large integer amounts.
        $wholeAmount = intdiv($amount, 10000);
        $remainder = $amount % 10000;

        return ($wholeAmount * $factor) + intdiv(($remainder * $factor) + 5000, 10000);
    }
}
