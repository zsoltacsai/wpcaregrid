<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Updates\UpdateGuard;
use WPCareGrid\Connector\Updates\UpgraderLoader;

/**
 * Updates WordPress core with Core_Upgrader, following the policy in the
 * payload: "minor" only allows updates within the current x.y branch
 * (e.g. 7.1.2 → 7.1.3); "major" also allows 7.1 → 7.2. The installed
 * version is re-read from wp-includes/version.php afterwards.
 */
final class UpdateCoreHandler implements JobHandler
{
    /** @var callable(object): mixed Runs the upgrade for an offer; replaceable in tests. */
    private $runner;

    /** @var callable(): string Reads the installed version from disk. */
    private $installed_version;

    public function __construct(private readonly UpdateGuard $guard, ?callable $runner = null, ?callable $installed_version = null)
    {
        $this->runner = $runner ?? [self::class, 'run_core_upgrader'];
        $this->installed_version = $installed_version ?? [self::class, 'version_on_disk'];
    }

    public function capability(): string
    {
        return 'updates';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['policy', 'target_version'], ['policy']);

        if (! in_array($payload['policy'], ['minor', 'major'], true)) {
            throw new InvalidPayload('Invalid update policy.');
        }

        return ['policy' => $payload['policy'], 'target_version' => InvalidPayload::version($payload['target_version'] ?? null)];
    }

    public function handle(array $payload): JobResult
    {
        UpgraderLoader::load();

        $previous = ($this->installed_version)();
        $offer = $this->offer();
        $details = ['previous_version' => $previous, 'policy' => $payload['policy'], 'target_version' => $payload['target_version']];

        if (! $offer) {
            return JobResult::skipped('NO_UPDATE_AVAILABLE', 'WordPress offers no core update.', $details);
        }

        $offered = (string) $offer->current;
        $details['offered'] = $offered;

        if ($payload['policy'] === 'minor' && self::branch($offered) !== self::branch($previous)) {
            return JobResult::skipped('MAJOR_UPDATE_NOT_ALLOWED', 'The available update is a major release and the policy allows minor updates only.', $details);
        }

        if ($payload['target_version'] && version_compare($offered, $payload['target_version'], '!=')) {
            return JobResult::skipped('TARGET_VERSION_UNAVAILABLE', 'The offered version differs from the requested version.', $details);
        }

        $backup = $this->guard->backup_state();

        try {
            $result = ($this->runner)($offer);
        } catch (\Throwable $e) {
            $result = new \WP_Error('core_upgrade_exception', 'The core upgrade threw an exception.');
        }

        $new_version = ($this->installed_version)();
        $details['new_version'] = $new_version;

        if (is_wp_error($result) || ! $result || version_compare($new_version, $offered, '!=')) {
            $details['error'] = is_wp_error($result) ? $result->get_error_code() : 'version_not_changed';

            return JobResult::failed('CORE_UPDATE_FAILED', 'WordPress core update failed.', $details, ['backup' => $backup]);
        }

        $data = ['previous_version' => $previous, 'new_version' => $new_version, 'backup' => $backup] + $this->guard->after();
        $data['inventory']['core']['version'] = $new_version;

        return $data['health']['status'] === 'critical'
            ? JobResult::partial('UPDATED_HEALTH_CRITICAL', 'WordPress was updated, but the site health check reports a critical problem.', $data, $details)
            : JobResult::success('CORE_UPDATED', $data);
    }

    private function offer(): ?object
    {
        wp_version_check();

        foreach ((array) get_core_updates(['dismissed' => false]) as $update) {
            if ('upgrade' === ($update->response ?? null)) {
                return $update;
            }
        }

        return null;
    }

    /** "7.1.2" → "7.1" */
    public static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    public static function run_core_upgrader(object $offer)
    {
        return (new \Core_Upgrader(new \WP_Ajax_Upgrader_Skin))->upgrade($offer);
    }

    /** The running request still has the old $wp_version; read the file. */
    public static function version_on_disk(): string
    {
        $wp_version = null;
        include ABSPATH.WPINC.'/version.php';

        return (string) $wp_version;
    }
}
