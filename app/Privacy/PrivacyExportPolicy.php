<?php

namespace App\Privacy;

use App\Models\PrivacyRetentionPolicy;

/** Resolves only the already-approved, activated report-policy values RET-05 and RET-06. */
final class PrivacyExportPolicy
{
    public function linkHours(): ?int
    {
        return $this->duration('RET-05', 'hours');
    }

    public function artifactDays(): ?int
    {
        return $this->duration('RET-06', 'days');
    }

    private function duration(string $key, string $expectedUnit): ?int
    {
        $policy = PrivacyRetentionPolicy::query()
            ->where('policy_key', $key)
            ->where('enabled', true)
            ->where('action', RetentionAction::ReportOnly->value)
            ->where('owner_approval_status', 'owner_approved')
            ->whereNotNull('activated_at')
            ->where('effective_at', '<=', now('UTC'))
            ->orderByDesc('version')
            ->first();

        $value = $policy?->technical_value;
        if (! is_array($value) || ! is_int($value['value'] ?? null) || $value['value'] < 1 || ($value['unit'] ?? null) !== $expectedUnit) {
            return null;
        }

        // Technical upper bounds prevent overflow/accidental near-permanent links or packages.
        $maximum = $expectedUnit === 'hours' ? 24 * 30 : 365;

        return $value['value'] <= $maximum ? $value['value'] : null;
    }
}
