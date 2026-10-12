<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Resolve the application from this repository, even when Composer's vendor
     * directory is mounted outside the checkout (as in the isolated WSL test
     * runtime). Laravel's default inference uses the Composer loader location.
     */
    public function createApplication()
    {
        $hadBasePath = array_key_exists('APP_BASE_PATH', $_SERVER);
        $previousBasePath = $_SERVER['APP_BASE_PATH'] ?? null;
        $_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

        try {
            return parent::createApplication();
        } finally {
            if ($hadBasePath) {
                $_SERVER['APP_BASE_PATH'] = $previousBasePath;
            } else {
                unset($_SERVER['APP_BASE_PATH']);
            }
        }
    }
}
