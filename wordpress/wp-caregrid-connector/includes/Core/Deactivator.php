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
        // A plugin deactivation request is not the poll-lock owner. Leave any
        // active lease intact; it will expire naturally if its process died.
        AdminAccess::end_all('deactivated');
    }
}
