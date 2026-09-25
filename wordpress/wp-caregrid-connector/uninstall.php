<?php

use WPCareGrid\Connector\AdminAccess\Accounts;
use WPCareGrid\Connector\Core\Plugin;
use WPCareGrid\Connector\Core\Settings;

/**
 * Removing the plugin: tell WP CareGrid (best effort) and delete every local
 * trace, including the encrypted credential.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__.'/wp-caregrid-connector.php';

Plugin::instance()->connection()->disconnect('uninstalled');
Settings::delete_all();

// The managed accounts: removed when they own nothing, otherwise left without a role.
foreach (array_keys(Accounts::USERS) as $level) {
    if ($user = Accounts::find($level)) {
        Accounts::strip($user);
        if (count_user_posts($user->ID) === 0) {
            require_once ABSPATH.'wp-admin/includes/user.php';
            wp_delete_user($user->ID);
        }
    }
}
remove_role(Accounts::ROLE);
