<?php

namespace App\Invoicing;

/** Provider syntax only. Presence here is not a legal determination of applicability. */
final class SzamlazzTaxCodeRegistry
{
    /** @var array<string, string> Provider code => documentation category. */
    private const CODES = [
        'TAHK' => 'outside_scope', 'TAM' => 'exempt_supply', 'AAM' => 'exempt_person',
        'EUT' => 'within_eu', 'EUKT' => 'outside_eu', 'F.AFA' => 'reverse_vat', 'K.AFA' => 'differential_vat',
        'HO' => 'third_country', 'EUE' => 'eu_other_not_reverse', 'EUFADE' => 'eu_reverse_outside_37',
        'EUFAD37' => 'eu_reverse_under_37', 'ATK' => 'outside_scope', 'NAM' => 'international_exempt',
        'EAM' => 'export_goods', 'KBAUK' => 'intra_community_uk', 'KBAET' => 'intra_community_et',
        '0' => 'rate', '1' => 'rate', '2' => 'rate', '2.1' => 'rate', '3' => 'rate', '4' => 'rate',
        '4.8' => 'rate', '5' => 'rate', '5.5' => 'rate', '6' => 'rate', '7' => 'rate', '7.7' => 'rate',
        '8' => 'rate', '8.1' => 'rate', '9' => 'rate', '9.5' => 'rate', '10' => 'rate', '11' => 'rate',
        '12' => 'rate', '13' => 'rate', '13.5' => 'rate', '14' => 'rate', '15' => 'rate', '16' => 'rate',
        '17' => 'rate', '18' => 'rate', '19' => 'rate', '20' => 'rate', '21' => 'rate', '22' => 'rate',
        '23' => 'rate', '24' => 'rate', '25' => 'rate', '25.5' => 'rate', '26' => 'rate', '27' => 'rate',
    ];

    /** @return array<string, string> */
    public static function all(): array
    {
        $labels = [];
        foreach (self::CODES as $code => $category) {
            $labels[$code] = $category === 'rate'
                ? __('owner.settings.billing_tax.tax_codes.rate', ['rate' => $code])
                : __('owner.settings.billing_tax.tax_codes.'.$category);
        }

        return $labels;
    }

    public static function supports(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::CODES);
    }
}
