<?php

namespace App\Monitoring;

interface PublicAddressLookup
{
    /** @return list<string> */
    public function addresses(string $host): array;
}
