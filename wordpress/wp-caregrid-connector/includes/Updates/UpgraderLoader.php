<?php

namespace WPCareGrid\Connector\Updates;

/** Loads WordPress's upgrader classes (admin-only files) in any context. */
final class UpgraderLoader
{
    public static function load(): void
    {
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
        require_once ABSPATH.'wp-admin/includes/update.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
    }

    /** Messages collected by the silent upgrader skin, for the job result. */
    public static function messages(\WP_Upgrader_Skin $skin): array
    {
        $messages = method_exists($skin, 'get_error_messages') ? (string) $skin->get_error_messages() : '';

        return $messages === '' ? [] : ['upgrader' => substr(wp_strip_all_tags($messages), 0, 500)];
    }
}
