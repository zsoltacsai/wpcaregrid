<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

final class ActiveThemeCheck implements Check
{
    public function run(): Finding
    {
        $theme = wp_get_theme();

        if (! $theme->exists() || $theme->errors()) {
            return new Finding('active_theme', Finding::FAIL, 'Active theme', 'The active theme is missing or broken.', true);
        }

        return new Finding('active_theme', Finding::PASS, 'Active theme', $theme->get_stylesheet().' '.$theme->get('Version'), true);
    }
}
