<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Updates\UpdateGuard;
use WPCareGrid\Connector\Updates\UpgraderLoader;

/**
 * Updates one installed theme through Theme_Upgrader, by stylesheet name
 * only. Success requires a newer version on disk afterwards.
 */
final class UpdateThemeHandler implements JobHandler
{
    public function __construct(private readonly UpdateGuard $guard) {}

    public function capability(): string
    {
        return 'updates';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['stylesheet', 'target_version'], ['stylesheet']);

        $stylesheet = (string) $payload['stylesheet'];
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $stylesheet) || str_contains($stylesheet, '..')) {
            throw new InvalidPayload('Invalid theme identifier.');
        }

        return ['stylesheet' => $stylesheet, 'target_version' => InvalidPayload::version($payload['target_version'] ?? null)];
    }

    public function handle(array $payload): JobResult
    {
        UpgraderLoader::load();

        $stylesheet = $payload['stylesheet'];
        $target = $payload['target_version'];
        $theme = wp_get_theme($stylesheet);

        if (! $theme->exists()) {
            return JobResult::failed('THEME_NOT_INSTALLED', 'The theme is not installed.', ['stylesheet' => $stylesheet]);
        }

        $previous = (string) $theme->get('Version');
        $details = ['stylesheet' => $stylesheet, 'previous_version' => $previous, 'target_version' => $target];

        $offer = get_site_transient('update_themes')->response[$stylesheet] ?? null;
        if (! $offer) {
            wp_update_themes();
            $offer = get_site_transient('update_themes')->response[$stylesheet] ?? null;
        }

        if (! $offer) {
            return JobResult::skipped('NO_UPDATE_AVAILABLE', 'WordPress offers no update for this theme.', $details);
        }

        if ($target && version_compare((string) $offer['new_version'], $target, '!=')) {
            return JobResult::skipped('TARGET_VERSION_UNAVAILABLE', 'The offered version differs from the requested version.', $details + ['offered_version' => $offer['new_version']]);
        }

        $backup = $this->guard->backup_state();
        $skin = new \WP_Ajax_Upgrader_Skin;
        $result = (new \Theme_Upgrader($skin))->upgrade($stylesheet);

        wp_clean_themes_cache();
        $new_version = (string) wp_get_theme($stylesheet)->get('Version');
        $details += ['new_version' => $new_version] + UpgraderLoader::messages($skin);

        if ($result !== true || ! version_compare($new_version, $previous, '>')) {
            return JobResult::failed('THEME_UPDATE_FAILED', 'Theme update failed.', $details, ['backup' => $backup]);
        }

        $data = ['stylesheet' => $stylesheet, 'previous_version' => $previous, 'new_version' => $new_version, 'backup' => $backup] + $this->guard->after();

        return $data['health']['status'] === 'critical'
            ? JobResult::partial('UPDATED_HEALTH_CRITICAL', 'The theme was updated, but the site health check reports a critical problem.', $data, $details)
            : JobResult::success('THEME_UPDATED', $data);
    }
}
