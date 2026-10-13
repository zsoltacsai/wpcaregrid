<?php

namespace App\Billing;

interface PaymentMethodPortalGateway
{
    public function createSession(string $stripeCustomerId, string $returnUrl): string;
}
