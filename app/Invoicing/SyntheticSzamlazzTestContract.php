<?php

namespace App\Invoicing;

use App\Pricing\Currency;
use LogicException;

/** Fixed commercial terms for the isolated Számlázz.hu provider syntax test. */
final class SyntheticSzamlazzTestContract
{
    public const CURRENCY = 'HUF';

    public const GROSS_MAJOR_UNITS = 1_000;

    public const TAX_CODE = 'AAM';

    public const INVOICE_PREFIX = 'A';

    public const CANCELLATION_REASON_INVALID_AMOUNT = 'invalid_amount_snapshot';

    public static function grossMinorUnits(): int
    {
        $currency = Currency::fromConfig(self::CURRENCY, (array) config('pricing.currencies.'.self::CURRENCY, []));
        if ($currency->minorUnits < 0 || $currency->minorUnits > 4) {
            throw new LogicException('Synthetic invoice currency precision is not supported.');
        }

        return self::GROSS_MAJOR_UNITS * (10 ** $currency->minorUnits);
    }

    /** @return array{net: int, tax: int, gross: int, tax_code: string} */
    public static function line(): array
    {
        $gross = self::grossMinorUnits();

        return ['net' => $gross, 'tax' => 0, 'gross' => $gross, 'tax_code' => self::TAX_CODE];
    }

    /** @return array<string, string> */
    public static function seller(): array
    {
        return [
            'name' => 'WP CareGrid Synthetic TEST Seller', 'country' => 'HU', 'postal_code' => '1111',
            'city' => 'Budapest', 'address' => 'Synthetic Test Street 1',
        ];
    }

    /** @return array<string, string> */
    public static function buyer(): array
    {
        return [
            'name' => 'WP CareGrid Synthetic TEST Buyer', 'country' => 'HU', 'postal_code' => '1111',
            'city' => 'Budapest', 'address' => 'Synthetic Test Street 1',
        ];
    }

    public static function formatMinorUnits(int $amount): string
    {
        $minorUnits = Currency::fromConfig(self::CURRENCY, (array) config('pricing.currencies.'.self::CURRENCY, []))->minorUnits;
        $divisor = 10 ** $minorUnits;

        return $minorUnits === 0
            ? (string) $amount
            : intdiv($amount, $divisor).'.'.str_pad((string) ($amount % $divisor), $minorUnits, '0', STR_PAD_LEFT);
    }
}
