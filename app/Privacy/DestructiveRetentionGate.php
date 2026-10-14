<?php

namespace App\Privacy;

final class DestructiveRetentionGate
{
    public function enabled(): bool
    {
        return config('privacy.destructive_retention_enabled', false) === true;
    }

    public function safeStatus(): string
    {
        return $this->enabled() ? 'REQUIRES_REVIEW' : 'SAFE_DISABLED_BY_POLICY';
    }
}
