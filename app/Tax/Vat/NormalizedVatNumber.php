<?php

namespace App\Tax\Vat;

final readonly class NormalizedVatNumber
{
    public function __construct(
        public string $countryCode,
        public string $number,
        public string $fingerprint,
    ) {}
}
