<?php

namespace App\Pricing;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $symbol,
        public string $symbolPosition = 'after',
        public int $minorUnits = 2,
        public int $displayDecimals = 0,
        public string $decimalSeparator = '.',
        public string $thousandsSeparator = ',',
    ) {}

    /**
     * @param  array<string, mixed>  $config  One entry of config('pricing.currencies').
     * @param  string  $locale  Site locale; picks the matching number format, falling back to English.
     */
    public static function fromConfig(string $code, array $config, string $locale = 'en'): self
    {
        $formats = $config['formats'] ?? [];
        $format = $formats[$locale] ?? $formats['en'] ?? reset($formats) ?: [];

        return new self(
            code: $code,
            symbol: $config['symbol'] ?? $code,
            symbolPosition: $config['symbol_position'] ?? 'after',
            minorUnits: $config['minor_units'] ?? 2,
            displayDecimals: $config['display_decimals'] ?? 0,
            decimalSeparator: $format['decimal_separator'] ?? '.',
            thousandsSeparator: $format['thousands_separator'] ?? ',',
        );
    }

    /** Format an amount in minor units without the symbol, e.g. 899000 → "8,990". */
    public function formatAmount(int $minorAmount): string
    {
        return number_format(
            $minorAmount / (10 ** $this->minorUnits),
            $this->displayDecimals,
            $this->decimalSeparator,
            $this->thousandsSeparator,
        );
    }

    /** Format an amount in minor units with the symbol, e.g. "8,990 Ft" or "€89". */
    public function format(int $minorAmount): string
    {
        $number = $this->formatAmount($minorAmount);

        return $this->symbolPosition === 'before'
            ? $this->symbol.$number
            : $number."\u{00A0}".$this->symbol;
    }
}
