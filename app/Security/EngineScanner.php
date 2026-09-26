<?php

namespace App\Security;

use App\Models\SecurityFinding;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operator console's view of the Security & Malware Engine: states come
 * from websites.security_* (kept by SecurityPosture), findings from
 * security_findings.
 */
final class EngineScanner implements SecurityScanner
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function summaries(iterable $websites): array
    {
        $websites = collect($websites);
        $highest = SecurityFinding::whereIn('website_id', $websites->pluck('id'))->active()->whereNotNull('severity')
            ->get(['website_id', 'severity'])->groupBy('website_id')
            ->map(fn ($findings) => $findings->sortByDesc(fn ($f) => $f->severity->rank())->first()->severity);

        return $websites->mapWithKeys(fn ($website) => [$website->id => new SecuritySummary(
            state: match (true) {
                $website->security_scanned_at === null && $website->security_open_findings === 0 => SecurityState::NotScanned,
                $website->security_open_findings > 0 => SecurityState::Findings,
                default => SecurityState::NoIssues,
            },
            findings: (int) $website->security_open_findings,
            highestSeverity: $highest[$website->id] ?? null,
            lastScanAt: $website->security_scanned_at,
        )])->all();
    }

    public function openFindings(): int
    {
        return SecurityFinding::active()->count();
    }

    public function whereState(Builder $websites, SecurityState $state): void
    {
        match ($state) {
            SecurityState::NotConfigured => $websites->whereRaw('1 = 0'),
            SecurityState::NotScanned => $websites->whereNull('security_scanned_at')->where('security_open_findings', 0),
            SecurityState::Findings => $websites->where('security_open_findings', '>', 0),
            SecurityState::NoIssues => $websites->whereNotNull('security_scanned_at')->where('security_open_findings', 0),
        };
    }
}
