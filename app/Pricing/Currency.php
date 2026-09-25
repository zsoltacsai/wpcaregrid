<?php

namespace App\Pricing;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $symbol,
        public string $symbolPosition = 'after',
        public int $decimals = 0,
        public string $decimalSeparator = '.',
        public string $thousandsSeparator = ',',
    ) {}

    /** @param  array<string, mixed>  $config */
    public static function fromConfig(string $code, array $config): self
    {
        return new self(
            code: $code,
            symbol: $config['symbol'] ?? $code,
            symbolPosition: $config['symbol_position'] ?? 'after',
            decimals: $config['decimals'] ?? 0,
            decimalSeparator: $config['decimal_separator'] ?? '.',
            thousandsSeparator: $config['thousands_separator'] ?? ',',
        );
    }

    /** Format an amount without the currency symbol, e.g. "8 990". */
    public function formatAmount(int|float $amount): string
    {
        return number_format($amount, $this->decimals, $this->decimalSeparator, $this->thousandsSeparator);
    }

    /** Format an amount with the currency symbol, e.g. "8 990 Ft". */
    public function format(int|float $amount): string
    {
        $number = $this->formatAmount($amount);

        return $this->symbolPosition === 'before'
            ? $this->symbol.$number
            : $number."\u{00A0}".$this->symbol;
    }
}
