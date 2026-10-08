<?php

namespace App\Tax\Vat;

interface VatNumberProvider
{
    public function validate(string $countryCode, string $number): VatProviderResult;
}
