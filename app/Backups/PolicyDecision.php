<?php

namespace App\Backups;

/** What the backup policy allows for one website right now. */
final readonly class PolicyDecision
{
    public function __construct(
        public bool $eligible,
        public ?string $refusal,
        public ?string $frequency,
        public BackupType $type,
        public ?int $retentionCount,
        public ?int $retentionDays,
        public ?string $storage,
        public bool $manualAllowed,
        public bool $preUpdateRequired,
        public bool $restoreAllowed,
    ) {}

    public function hasRetention(): bool
    {
        return $this->retentionCount !== null || $this->retentionDays !== null;
    }
}
