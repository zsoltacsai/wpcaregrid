<?php

namespace App\Invoicing;

/** Mechanical provider-code selection for a synthetic TEST document only. */
final readonly class SyntheticTestTaxSelection
{
    /** @param array{net:int,tax:int,gross:int,tax_code:string} $line */
    public function __construct(
        public string $reference,
        public int $version,
        public array $line,
        public string $language = 'hu',
    ) {
        if ($reference === '' || $version !== 1 || ! SzamlazzTaxCodeRegistry::supports($line['tax_code'] ?? null)) {
            throw new \InvalidArgumentException('A supported synthetic-only provider tax code is required.');
        }
    }
}
