<?php

namespace WPCareGrid\Connector\Core;

use WPCareGrid\Connector\Auth\InstallationId;

final class Activator
{
    public static function activate(): void
    {
        InstallationId::get();

        if (get_option(Settings::OPTION) === false) {
            add_option(Settings::OPTION, Settings::all(), '', false);
        }

        // A previously paired site resumes its schedule after reactivation.
        if (Settings::is_connected()) {
            Scheduler::schedule();
        }
    }
}
