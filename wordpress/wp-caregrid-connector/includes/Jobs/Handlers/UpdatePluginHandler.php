<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Updates\UpdateGuard;
use WPCareGrid\Connector\Updates\UpgraderLoader;

/**
 * Updates one installed plugin through WordPress's Plugin_Upgrader, using
 * the update WordPress itself offers. The payload names the plugin by its
 * basename only; paths, URLs and packages are never accepted.
 *
 * Success is only reported when the plugin file on disk shows a newer
 * version and a previously active plugin is active again.
 */
final class UpdatePluginHandler implements JobHandler
{
    public function __construct(private readonly UpdateGuard $guard) {}

    public function capability(): string
    {
        return 'updates';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['plugin', 'target_version'], ['plugin']);

        $plugin = (string) $payload['plugin'];
        if (! preg_match('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/', $plugin) || str_contains($plugin, '..')) {
            throw new InvalidPayload('Invalid plugin identifier.');
        }

        return ['plugin' => $plugin, 'target_version' => InvalidPayload::version($payload['target_version'] ?? null)];
    }

    public function handle(array $payload): JobResult
    {
        UpgraderLoader::load();

        $plugin = $payload['plugin'];
        $target = $payload['target_version'];
        $plugins = get_plugins();

        if (! isset($plugins[$plugin])) {
            return JobResult::failed('PLUGIN_NOT_INSTALLED', 'The plugin is not installed.', ['plugin' => $plugin]);
        }

        $previous = (string) $plugins[$plugin]['Version'];
        $was_active = is_plugin_active($plugin);
        $details = ['plugin' => $plugin, 'previous_version' => $previous, 'target_version' => $target];

        $offer = get_site_transient('update_plugins')->response[$plugin] ?? null;
        if (! $offer) {
            wp_update_plugins();
            $offer = get_site_transient('update_plugins')->response[$plugin] ?? null;
        }

        if (! $offer) {
            return JobResult::skipped('NO_UPDATE_AVAILABLE', 'WordPress offers no update for this plugin.', $details);
        }

        if ($target && version_compare((string) $offer->new_version, $target, '!=')) {
            return JobResult::skipped('TARGET_VERSION_UNAVAILABLE', 'The offered version differs from the requested version.', $details + ['offered_version' => $offer->new_version]);
        }

        $backup = $this->guard->backup_state();
        $skin = new \WP_Ajax_Upgrader_Skin;
        $result = UpgraderLoader::quietly(fn () => (new \Plugin_Upgrader($skin))->upgrade($plugin));

        wp_clean_plugins_cache();
        $after = get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin, false, false);
        $new_version = (string) ($after['Version'] ?? '');

        // Outside cron, WordPress deactivates the plugin during the update.
        if ($was_active && ! is_plugin_active($plugin) && $new_version !== '') {
            $activation = activate_plugin($plugin, '', false, true);
        }

        $now_active = is_plugin_active($plugin);
        $details += ['new_version' => $new_version, 'was_active' => $was_active, 'active' => $now_active] + UpgraderLoader::messages($skin);

        if ($result !== true || ! version_compare($new_version, $previous, '>')) {
            return JobResult::failed('PLUGIN_UPDATE_FAILED', 'Plugin update failed.', $details, ['backup' => $backup]);
        }

        if ($was_active && ! $now_active) {
            return JobResult::failed('PLUGIN_REACTIVATION_FAILED', 'The plugin was updated but could not be reactivated.', $details + ['activation_error' => is_wp_error($activation ?? null) ? $activation->get_error_code() : null], ['backup' => $backup] + $this->guard->after());
        }

        $data = ['plugin' => $plugin, 'previous_version' => $previous, 'new_version' => $new_version, 'active' => $now_active, 'backup' => $backup] + $this->guard->after();

        return $data['health']['status'] === 'critical'
            ? JobResult::partial('UPDATED_HEALTH_CRITICAL', 'The plugin was updated, but the site health check reports a critical problem.', $data, $details)
            : JobResult::success('PLUGIN_UPDATED', $data);
    }
}
