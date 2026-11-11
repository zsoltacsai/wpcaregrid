<?php

namespace App\Platform\Updates;

/** One component as read from the deployment. A null version means it could not be verified. */
final readonly class DetectedComponent
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public ?string $version,
        public ?string $ecosystem,
        public string $evidenceSource,
        public bool $developmentOnly = false,
    ) {}

    /** Only a verified version in an ecosystem with an advisory source can be checked. */
    public function checkable(): bool
    {
        return $this->version !== null && $this->ecosystem !== null;
    }
}
