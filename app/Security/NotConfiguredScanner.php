<?php

namespace App\Security;

use Illuminate\Database\Eloquent\Builder;

/** No scanner yet: every website is "not configured", and there are no findings. */
final class NotConfiguredScanner implements SecurityScanner
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function summaries(iterable $websites): array
    {
        $summaries = [];
        foreach ($websites as $website) {
            $summaries[$website->id] = SecuritySummary::notConfigured();
        }

        return $summaries;
    }

    public function openFindings(): int
    {
        return 0;
    }

    public function whereState(Builder $websites, SecurityState $state): void
    {
        if ($state !== SecurityState::NotConfigured) {
            $websites->whereRaw('1 = 0');
        }
    }
}
