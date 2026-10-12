<?php

namespace Tests\Unit\Monitoring;

use App\Monitoring\PublicOrigin;
use App\Monitoring\SafeRedirect;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicOriginTest extends TestCase
{
    #[DataProvider('privateAddresses')]
    public function test_private_and_special_addresses_are_rejected(string $ip): void
    {
        $this->assertFalse(PublicOrigin::isPublicAddress($ip));
    }

    public static function privateAddresses(): array
    {
        return [
            ['127.0.0.1'], ['10.1.2.3'], ['172.20.0.1'], ['192.168.1.1'],
            ['169.254.169.254'], ['100.64.0.1'], ['::1'], ['fc00::1'],
            ['fe80::1'], ['ff02::1'], ['::ffff:127.0.0.1'], ['2001:db8::1'],
        ];
    }

    public function test_public_ip_is_accepted(): void
    {
        $this->assertTrue(PublicOrigin::isPublicAddress('1.1.1.1'));
        $this->assertTrue(PublicOrigin::isPublicAddress('2606:4700:4700::1111'));
    }

    public function test_redirect_to_a_different_host_is_rejected_before_resolution(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('monitoring.redirect_refused');
        SafeRedirect::resolve(new Uri('https://example.com/'), 'https://127.0.0.1/admin', 'example.com');
    }

    public function test_redirect_to_local_ipv6_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        SafeRedirect::resolve(new Uri('https://example.com/'), 'https://[::1]/', 'example.com');
    }
}
