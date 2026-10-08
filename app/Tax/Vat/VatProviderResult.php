<?php

namespace App\Tax\Vat;

final readonly class VatProviderResult
{
    public function __construct(
        public bool $valid,
        public ?string $requestDate = null,
        public ?string $referenceId = null,
        public ?string $name = null,
        public ?string $address = null,
    ) {}
}
