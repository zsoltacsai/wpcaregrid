<?php

namespace WPCareGrid\Connector\Security\Scan;

use WPCareGrid\Connector\Security\Redactor;

/**
 * One finding as sent to WP CareGrid: codes, a relative path and metadata.
 * Never file contents, option values, e-mail addresses or passwords.
 */
final class ReportedFinding
{
    /** @param array<string, scalar|list<scalar>> $evidence */
    public function __construct(
        public readonly string $type,
        public readonly string $code,
        public readonly ?string $severity,
        public readonly string $confidence,
        public readonly string $title,
        public readonly ?string $component = null,
        public readonly ?string $path = null,
        public readonly array $evidence = [],
        public readonly ?string $description = null,
    ) {}

    public function to_array(): array
    {
        return array_filter([
            'type' => $this->type,
            'code' => $this->code,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'title' => substr($this->title, 0, 191),
            'description' => $this->description !== null ? substr($this->description, 0, 1000) : null,
            'component' => $this->component !== null ? substr($this->component, 0, 191) : null,
            'path' => $this->path,
            // Belt and braces: the redactor removes anything that looks like a secret.
            'evidence' => $this->evidence ? Redactor::redact($this->evidence) : null,
        ], fn ($value) => $value !== null);
    }
}
