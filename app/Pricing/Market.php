<?php

namespace App\Pricing;

/** A set of billing countries sharing one currency, price list and tax treatment. */
final readonly class Market
{
    /** @param  list<string>  $countries  ISO 3166-1 alpha-2 codes; '*' matches any country. */
    public function __construct(
        public string $key,
        public array $countries,
        public string $currencyCode,
        public TaxTreatment $taxTreatment,
    ) {}

    public function covers(string $country): bool
    {
        return in_array('*', $this->countries, true)
            || in_array(strtoupper($country), $this->countries, true);
    }
}
