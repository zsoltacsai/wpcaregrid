<?php

namespace App\Invoicing;

final readonly class InvoiceValidationResult
{
    /** @param list<string> $errors */
    public function __construct(public bool $valid, public array $errors, public ?string $xml = null) {}
}
