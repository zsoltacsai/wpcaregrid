<?php

namespace App\Platform\Updates;

use App\Connector\HealthStatus;
use App\Models\PlatformAdvisory;
use App\Models\PlatformComponent;
use App\Models\PlatformUpdateCheck;
use Illuminate\Support\Carbon;

/**
 * What the Owner page shows, computed from stored rows only (no outbound request, no shell).
 * The overall state is never healthier than what was proven: no successful check, or an
 * unreadable source, is UNKNOWN; unverified components are counted and named, never hidden.
 */
final class PlatformUpdateOverview
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $lastSuccess = PlatformUpdateCheck::query()->whereIn('status', ['succeeded', 'partial'])->latest('finished_at')->first();
        $last = PlatformUpdateCheck::query()->whereIn('status', ['succeeded', 'partial', 'failed'])->latest('started_at')->first();
        $open = PlatformAdvisory::query()->where('state', PlatformAdvisory::OPEN);
        $openCount = (clone $open)->count();
        $critical = (clone $open)->where('severity', 'critical')->count();
        $security = (clone $open)->whereNotNull('fixed_version')->count();
        $components = PlatformComponent::query()->where('present', true);

        $state = match (true) {
            $critical > 0 => HealthStatus::Critical,
            $lastSuccess === null || $last?->status === 'failed' => $openCount > 0 ? HealthStatus::Attention : HealthStatus::Unknown,
            $openCount > 0 || $last?->status === 'partial' => HealthStatus::Attention,
            default => HealthStatus::Healthy,
        };

        return [
            'state' => $state,
            'enabled' => config('platform_updates.enabled') === true,
            'open' => $openCount,
            'critical' => $critical,
            'fixes_available' => $security,
            'components' => (clone $components)->count(),
            'unknown' => (clone $components)->where('version_state', 'UNKNOWN')->count(),
            'unverified' => (clone $components)->where('advisory_state', 'UNVERIFIED')->count(),
            'last_check' => $last,
            'last_success' => $lastSuccess,
            'next_check' => config('platform_updates.enabled') === true ? $this->nextScheduled() : null,
        ];
    }

    /** UTC time of the next scheduled run (routes/console.php: daily 05:20 UTC). */
    public function nextScheduled(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy()->utc();
        $next = $now->copy()->setTime(5, 20);

        return $next->greaterThan($now) ? $next : $next->addDay();
    }

    /** The action text for an advisory: only what the source proves, and never an automatic action. */
    public static function action(PlatformAdvisory $advisory, PlatformComponent $component): string
    {
        return $advisory->fixed_version !== null
            ? 'fixed_version'
            : ($advisory->severity === 'unknown' ? 'review_unknown' : 'review_no_fix');
    }
}
