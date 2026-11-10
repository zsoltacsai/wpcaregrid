<?php

namespace App\Platform\Updates;

use App\Release\StagingSchedulePolicy;

/**
 * The one decision about whether Platform Update Intelligence may run or mail now. Every entry point asks it:
 * the scheduler's own conditions, the artisan command, the queued job, the Owner's "check now" button, the scanner
 * and the notifier. None of them may reach the outside world by a path the others have closed.
 *
 * The environment part is not a second opinion: it is the staging scheduler policy (App\Release\StagingSchedulePolicy),
 * the same classification the scheduler itself applies. On staging-next that policy recognises the stack by its
 * deployment id, by the staging_active profile having been requested or by its exact origin (any one is enough, so a
 * mis-identified stack is held, never released), and it also requires the stack to be proven. Elsewhere it changes nothing.
 *
 * Fixed reason codes only; never values.
 */
final class PlatformUpdateGate
{
    public const CHECK_TASK = 'platform:updates:check';

    public const MAIL_TASK = 'platform:updates:digest';

    /** The feature switch. Compared strictly: no truthiness of a string ever enables it. */
    public static function enabled(): bool
    {
        return config('platform_updates.enabled') === true;
    }

    /** Why an outbound advisory check must not run now, or null when it may. */
    public static function checkRefusal(): ?string
    {
        if (! self::enabled()) {
            return 'disabled';
        }
        if (! StagingSchedulePolicy::appliesHere()) {
            return null;
        }
        // staging-next without the dedicated opt-in (never implied by the external-services opt-in, which opens much more).
        if (config('marketing_release.staging_active_features.platform_updates_outbound') !== true) {
            return 'staging_platform_updates_closed';
        }

        return self::environment(self::CHECK_TASK);
    }

    /** Why a notification must not be sent now, or null when it may. */
    public static function mailRefusal(): ?string
    {
        if (! self::enabled()) {
            return 'disabled';
        }
        if (! StagingSchedulePolicy::appliesHere()) {
            return null;
        }

        return self::environment(self::MAIL_TASK);
    }

    /** The staging policy's verdict for the task, as a code of this feature (a fresh policy per call: nothing is cached across jobs). */
    private static function environment(string $task): ?string
    {
        $denial = app(StagingSchedulePolicy::class)->denial($task);

        return match ($denial) {
            null => null,
            'platform_updates_outbound_closed' => 'staging_platform_updates_closed',
            'mail_closed' => 'staging_mail_not_confined',
            'environment_unproven' => 'staging_environment_unproven',
            default => 'staging_held',
        };
    }
}
