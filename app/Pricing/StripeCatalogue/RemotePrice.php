<?php

namespace App\Pricing\StripeCatalogue;

final readonly class RemotePrice
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $id,
        public string $productId,
        public string $currency,
        public ?int $unitAmount,
        public bool $active,
        public string $type,
        public ?string $interval,
        public int $intervalCount,
        public string $usageType = 'licensed',
        public string $billingScheme = 'per_unit',
        public bool $hasTiersOrTransform = false,
        public array $metadata = [],
    ) {}

    /** A plain licensed recurring price with one fixed amount: the only kind a catalogue price can be. */
    public function isSimpleRecurring(): bool
    {
        return $this->type === 'recurring' && $this->usageType === 'licensed' && $this->billingScheme === 'per_unit'
            && ! $this->hasTiersOrTransform && $this->unitAmount !== null;
    }
}
