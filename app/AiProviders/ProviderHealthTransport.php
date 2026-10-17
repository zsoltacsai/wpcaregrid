<?php

namespace App\AiProviders;

interface ProviderHealthTransport
{
    /** Sends one fixed, bodyless metadata request using only the selected provider's credential. */
    public function get(string $providerId, string $credential): ProviderProbeResponse;
}
