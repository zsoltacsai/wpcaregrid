<?php

namespace WPCareGrid\Connector\Core;

use WPCareGrid\Connector\AdminAccess\AdminAccess;

/**
 * Deactivation pauses the connector: scheduled work stops, but the pairing
 * is kept so reactivating resumes it. Uninstalling removes everything.
 */
final class Deactivator
{
    public static function deactivate(): void
    {
        Scheduler::unschedule();
        delete_option('wp_caregrid_poll_lock');
        AdminAccess::end_all('deactivated');
    }
}
