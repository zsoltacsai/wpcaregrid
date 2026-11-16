<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/e2e/fixtures/marketing-test-router-policy.php';

final class MarketingTestRouterPolicyTest extends TestCase
{
    public function test_only_ipv4_and_ipv6_loopback_peers_are_accepted(): void
    {
        foreach (['127.0.0.1', '127.45.67.89', '::1'] as $address) {
            self::assertTrue(wpcg_test_router_client_is_loopback($address), $address);
        }

        foreach ([null, '', 'invalid', '0.0.0.0', '10.0.0.1', '192.168.1.5', '::', '2001:db8::1', '::ffff:127.0.0.1'] as $address) {
            self::assertFalse(wpcg_test_router_client_is_loopback($address), var_export($address, true));
        }
    }
}
