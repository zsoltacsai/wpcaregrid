<?php

namespace WPCareGrid\Connector\Health\Checks;

use WPCareGrid\Connector\Health\Check;
use WPCareGrid\Connector\Health\Finding;

final class PhpVersionCheck implements Check
{
    public function run(): Finding
    {
        return match (true) {
            version_compare(PHP_VERSION, '8.1', '<') => new Finding('php_version', Finding::WARNING, 'PHP version', PHP_VERSION.' no longer receives security fixes.'),
            default => new Finding('php_version', Finding::PASS, 'PHP version', PHP_VERSION),
        };
    }
}
