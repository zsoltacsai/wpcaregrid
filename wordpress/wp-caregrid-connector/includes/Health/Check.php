<?php

namespace WPCareGrid\Connector\Health;

/**
 * A single health check. Later functional checks (forms, login, cart,
 * checkout, custom URLs) implement the same interface.
 */
interface Check
{
    public function run(): Finding;
}
