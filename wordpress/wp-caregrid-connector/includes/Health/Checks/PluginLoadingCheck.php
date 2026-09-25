<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

/**
 * Plugins and themes WordPress has paused after a fatal error (recovery
 * mode), and active plugins whose files are missing.
 */
final class PluginLoadingCheck implements Check
{
    public function run(): Finding
    {
        require_once ABSPATH.'wp-admin/includes/plugin.php';

        $paused = array_keys(function_exists('wp_paused_plugins') ? (array) wp_paused_plugins()->get_all() : []);
        $paused = array_merge($paused, array_keys(function_exists('wp_paused_themes') ? (array) wp_paused_themes()->get_all() : []));
        $invalid = array_keys(validate_active_plugins());

        if ($paused || $invalid) {
            $names = implode(', ', array_map('sanitize_text_field', array_merge($paused, $invalid)));

            return new Finding('plugin_loading', Finding::FAIL, 'Plugins loading', "Paused after an error or missing: {$names}", true);
        }

        return new Finding('plugin_loading', Finding::PASS, 'Plugins loading', 'All active plugins load.', true);
    }
}
