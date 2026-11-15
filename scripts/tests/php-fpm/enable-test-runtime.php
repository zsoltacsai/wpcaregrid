<?php

// This file is copied only into the disposable PHP-FPM CI image. Keep the
// marker tied to the FPM test SAPI and the exact isolated testing environment.
if (PHP_SAPI === 'fpm-fcgi'
    && getenv('APP_ENV') === 'testing'
    && getenv('CAREGRID_DEPLOYMENT_ENV') === 'testing') {
    define('WPCG_TEST_RUNTIME', true);
}
