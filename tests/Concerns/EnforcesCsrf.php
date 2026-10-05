<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** Laravel skips CSRF checks in unit tests; this turns them back on, as in production. */
trait EnforcesCsrf
{
    protected function enforceCsrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }
}
