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

    /**
     * Run an upgrader without letting its HTML reach the response (cron or
     * an admin redirect). Buffers the upgrader leaves open are closed too.
     */
    public static function quietly(callable $upgrade)
    {
        $level = ob_get_level();
        ob_start();

        try {
            return $upgrade();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** Messages collected by the silent upgrader skin, for the job result. */
    public static function messages(\WP_Upgrader_Skin $skin): array
    {
        $messages = method_exists($skin, 'get_error_messages') ? (string) $skin->get_error_messages() : '';

        return $messages === '' ? [] : ['upgrader' => substr(wp_strip_all_tags($messages), 0, 500)];
    }
}
