<?php

namespace App\Pricing;

/** One resolved plan feature, e.g. key "remote_backups", label "Weekly remote backups". */
final readonly class Feature
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $frequency = null,
    ) {}
}
