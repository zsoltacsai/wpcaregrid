<?php

namespace App\Security;

use App\Models\SecurityFinding;

/** A finding as a scanner reports it, before it is correlated with what is already known. */
final readonly class ReportedFinding
{
    /** @param  array<string, scalar|list<scalar>|null>  $evidence  Metadata only. */
    public function __construct(
        public FindingType $type,
        public string $code,
        public ?Severity $severity,
        public Confidence $confidence,
        public string $title,
        public ?string $description = null,
        public ?string $component = null,
        public ?string $path = null,
        public array $evidence = [],
    ) {}

    public function fingerprint(): string
    {
        return SecurityFinding::fingerprint($this->type, $this->code, $this->component, $this->path);
    }
}
