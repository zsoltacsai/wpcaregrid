<?php

namespace App\Release;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;

/**
 * Which scheduled tasks may run on the staging-next stack.
 *
 * One central, fail-closed allowlist: routes/console.php attaches this policy to EVERY
 * scheduled event. On staging-next a task runs only if it is listed here AND the
 * environment is proven AND the task's isolation group is open. A task that is not listed
 * (for example one added later) does not run until it is classified, and a test fails
 * until somebody does. Everywhere else the policy does nothing, so production and the
 * legacy staging keep exactly the schedule they had.
 *
 * Groups:
 *  - local:     touches this stack's own database/files only.
 *  - stripe:    calls Stripe or processes Stripe data; needs proven test-mode keys.
 *  - mail:      sends mail; needs a sink mailer or a recipient allowlist.
 *  - connector: works on paired customer sites; needs the Owner's connector opt-in.
 *  - external:  contacts a third party or infrastructure; needs the Owner's opt-in.
 */
final class StagingSchedulePolicy
{
    public const LOCAL = 'local';

    public const STRIPE = 'stripe';

    public const MAIL = 'mail';

    public const CONNECTOR = 'connector';

    public const EXTERNAL = 'external';

    public const HEARTBEAT = 'system:scheduler-heartbeat';

    /** Every scheduled task of routes/console.php, classified. */
    public const TASKS = [
        'connector:update-presence' => self::LOCAL,
        'backup:schedule' => self::CONNECTOR,
        'backup:monitor' => self::LOCAL,
        'backup:retention' => self::LOCAL,
        'privacy:exports-expire' => self::LOCAL,
        'platform:database-backup-schedule' => self::EXTERNAL,
        'admin-access:expire' => self::LOCAL,
        'security:monitor' => self::LOCAL,
        'security:schedule' => self::CONNECTOR,
        'security:vulnerabilities:sync' => self::EXTERNAL,
        'monitoring:run' => self::LOCAL,
        'monitoring:deliver-alerts' => self::MAIL,
        self::HEARTBEAT => self::LOCAL,
        'internal-plans:sync' => self::LOCAL,
        'offers:expire' => self::STRIPE,
        'stripe:webhooks:drain' => self::STRIPE,
        'billing:emails:drain' => self::MAIL,
        'stripe:subscriptions:reconcile' => self::STRIPE,
        'billing:subscriptions:expire-grace' => self::LOCAL,
        'platform:updates:check' => self::EXTERNAL,
        'platform:updates:digest' => self::MAIL,
    ];

    /** A killed run must not keep a task locked for the default 24 hours. */
    public const MUTEX_MINUTES = 15;

    /** @var list<string>|null */
    private ?array $unproven = null;

    public function __construct(private readonly StagingActiveProfile $staging) {}

    /**
     * True on the staging-next stack, recognised by its deployment id, by the staging_active
     * profile having been requested (also when it was refused), or by its exact origin. Any
     * one of them is enough, so a stack that claims to be staging-next but is mis-identified
     * is held, never released.
     */
    public static function appliesHere(): bool
    {
        return StagingActiveProfile::isStagingNextEnvironment()
            || strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) === StagingActiveProfile::HOST;
    }

    /** The artisan command (or the task name of a closure) of a scheduled event, or null. */
    public static function taskKey(Event $event): ?string
    {
        if ($event instanceof CallbackEvent) {
            return is_string($event->description) && $event->description !== '' ? $event->description : null;
        }

        // Laravel quotes the entry point with ' on Linux and with " on Windows.
        // Anchored: "<php binary>" "artisan" <command>; anything else is unreadable and therefore not allowlisted.
        return preg_match('/^\s*[\'"][^\'"]+[\'"]\s+[\'"]artisan[\'"]\s+([a-z0-9:_-]+)/i', (string) $event->command, $match) === 1 ? $match[1] : null;
    }

    /** Whether the event runs now. */
    public function permits(Event $event): bool
    {
        return $this->denial(self::taskKey($event)) === null;
    }

    /** A fixed reason code when the task must not run, or null when it may. */
    public function denial(?string $task): ?string
    {
        if (! self::appliesHere()) {
            return null;
        }
        if ($task === null || ! array_key_exists($task, self::TASKS)) {
            return 'task_not_allowlisted';
        }
        // The heartbeat is a timestamp that proves the scheduler runs; it may run even when the rest is held.
        if ($task === self::HEARTBEAT) {
            return null;
        }
        if (($unproven = $this->unprovenEnvironment()) !== []) {
            return $unproven[0];
        }

        $group = self::TASKS[$task];
        if ($group === self::STRIPE && $this->staging->stripeProblems() !== []) {
            return 'stripe_closed';
        }
        if ($group === self::MAIL && ! StagingMailGuard::restricted()) {
            return 'mail_closed';
        }
        if ($group === self::CONNECTOR && config('marketing_release.staging_active_features.connector_api') !== true) {
            return 'connector_closed';
        }
        if ($group === self::EXTERNAL && config('marketing_release.staging_active_features.external_services') !== true) {
            return 'external_closed';
        }
        if ($task === 'backup:monitor' && ! $this->backupStagingInsideStorage()) {
            return 'backup_staging_path_outside_storage';
        }

        return null;
    }

    /**
     * Held tasks with their reason, for the system page (names and codes only).
     *
     * @return array<string, string>
     */
    public function held(): array
    {
        $held = [];
        foreach (array_keys(self::TASKS) as $task) {
            if (($reason = $this->denial($task)) !== null) {
                $held[$task] = $reason;
            }
        }

        return $held;
    }

    /** @return list<string> */
    private function unprovenEnvironment(): array
    {
        if ($this->unproven !== null) {
            return $this->unproven;
        }
        $problems = $this->staging->identityProblems();
        if ($problems === []) {
            $problems = $this->staging->databaseProblems();
        }

        // Evaluated once per process (one schedule:run); a failure is never cached as "proven".
        return $this->unproven = $problems === [] ? [] : ['environment_unproven'];
    }

    private function backupStagingInsideStorage(): bool
    {
        $path = config('backup.upload.staging_path');
        $root = realpath(storage_path());
        if (! is_string($path) || $path === '' || $root === false) {
            return false;
        }
        $resolved = realpath($path);
        $candidate = $resolved !== false ? $resolved : $path;

        return str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $root), '/').'/');
    }
}
