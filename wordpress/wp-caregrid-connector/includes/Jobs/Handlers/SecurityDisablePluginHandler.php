<?php

namespace WPCareGrid\Connector\Jobs\Handlers;

use WPCareGrid\Connector\Jobs\InvalidPayload;
use WPCareGrid\Connector\Jobs\JobHandler;
use WPCareGrid\Connector\Jobs\JobResult;
use WPCareGrid\Connector\Security\Logger;
use WPCareGrid\Connector\Security\Remediation\RemediationLog;

/**
 * SECURITY_DISABLE_PLUGIN: deactivate one compromised plugin (only when WP
 * CareGrid's policy allows it). Deactivated silently, so a compromised
 * plugin's own deactivation code does not run. Files stay in place; the
 * plugin can be activated again. Never the connector itself.
 */
final class SecurityDisablePluginHandler implements JobHandler
{
    public function capability(array $payload = []): string
    {
        return 'security_remediate';
    }

    public function validate(array $payload): array
    {
        InvalidPayload::check($payload, ['remediation_uuid', 'plugin'], ['remediation_uuid', 'plugin']);

        if (! preg_match('/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/', (string) $payload['plugin']) || str_contains((string) $payload['plugin'], '..')) {
            throw new InvalidPayload('Invalid plugin.');
        }

        return ['remediation_uuid' => InvalidPayload::uuid((string) $payload['remediation_uuid']), 'plugin' => $payload['plugin']];
    }

    public function handle(array $payload): JobResult
    {
        if (RemediationLog::applied($payload['remediation_uuid'])) {
            return JobResult::failed('REMEDIATION_ALREADY_APPLIED', 'This remediation was already applied on this site.');
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $plugin = $payload['plugin'];
        if (! isset(get_plugins()[$plugin]) || str_starts_with($plugin, 'wp-caregrid-connector/')) {
            return JobResult::failed('PLUGIN_NOT_FOUND', 'This plugin is not installed or cannot be deactivated.');
        }

        if (is_plugin_active($plugin)) {
            deactivate_plugins($plugin, true);
        }

        RemediationLog::record($payload['remediation_uuid']);
        Logger::info('security.plugin_disabled', ['remediation' => $payload['remediation_uuid'], 'plugin' => $plugin]);

        return JobResult::success('PLUGIN_DISABLED', ['remediation' => [
            'remediation_uuid' => $payload['remediation_uuid'],
            'after' => ['path' => 'wp-content/plugins/'.$plugin, 'active' => is_plugin_active($plugin)],
        ]]);
    }
}
