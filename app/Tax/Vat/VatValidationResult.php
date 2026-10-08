<?php

namespace App\Tax\Vat;

use Illuminate\Support\Carbon;

final readonly class VatValidationResult
{
    public function __construct(
        public VatValidationStatus $status,
        public ?int $evidenceId = null,
        public ?Carbon $checkedAt = null,
        public ?string $providerReference = null,
        public string $messageCode = 'not_checked',
    ) {}
}
