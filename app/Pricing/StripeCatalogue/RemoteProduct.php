<?php

namespace App\Pricing\StripeCatalogue;

final readonly class RemoteProduct
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $id,
        public string $name,
        public bool $active,
        public array $metadata = [],
    ) {}
}
