<?php

namespace Tests\Unit\Cloudflare;

use App\Cloudflare\CloudflareDnsException;
use App\Cloudflare\DnsNames;
use App\Cloudflare\DnsRecordData;
use App\Cloudflare\WpCareGridInfrastructureDns;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DnsRecordDataTest extends TestCase
{
    public function test_supported_record_types_are_normalized_and_validated(): void
    {
        $a = DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'a', 'name' => 'wp-test.staging.wpcaregrid.com', 'content' => '85.155.243.164']);
        $aaaa = DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'AAAA', 'name' => 'v6.wpcaregrid.com', 'content' => '2001:db8::1']);
        $cname = DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'CNAME', 'name' => 'www.wpcaregrid.com', 'content' => 'wpcaregrid.com.']);
        $txt = DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'TXT', 'name' => '_dmarc.wpcaregrid.com', 'content' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.test']);
        $mx = DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'MX', 'name' => 'wpcaregrid.com', 'content' => 'mail.example.test', 'priority' => 10]);

        $this->assertSame('A', $a->type);
        $this->assertFalse($a->proxied);
        $this->assertSame(1, $a->ttl);
        $this->assertSame('2001:db8::1', $aaaa->content);
        $this->assertSame('wpcaregrid.com', $cname->content);
        $this->assertStringContainsString('v=DMARC1', $txt->content);
        $this->assertSame(10, $mx->priority);
    }

    public function test_txt_payload_is_preserved_but_control_characters_are_rejected(): void
    {
        $txt = DnsRecordData::fromInput('wpcaregrid.com', [
            'type' => 'TXT', 'name' => '_dmarc.wpcaregrid.com', 'content' => 'v=DMARC1; p=none; note="<script>alert(1)</script>"',
        ]);
        $this->assertSame('v=DMARC1; p=none; note="<script>alert(1)</script>"', $txt->content);

        $this->expectException(CloudflareDnsException::class);
        DnsRecordData::fromInput('wpcaregrid.com', [
            'type' => 'TXT', 'name' => '_dmarc.wpcaregrid.com', 'content' => "v=DMARC1\r\np=none",
        ]);
    }

    public function test_names_accept_one_absolute_trailing_dot_but_reject_duplicate_dots_and_zone_escape(): void
    {
        $this->assertSame('wpcaregrid.com', DnsNames::normalizeZone('WPCareGrid.COM.'));
        $this->assertSame('mail.wpcaregrid.com', DnsNames::normalizeRecordName('MAIL.WPCAREGRID.COM.', 'wpcaregrid.com'));

        foreach ([
            fn () => DnsNames::normalizeZone('wpcaregrid.com..'),
            fn () => DnsNames::normalizeRecordName('mail.wpcaregrid.com..', 'wpcaregrid.com'),
            fn () => DnsNames::normalizeRecordName('wpcaregrid.com.attacker.tld', 'wpcaregrid.com'),
            fn () => DnsNames::normalizeRecordName('evilwpcaregrid.com', 'wpcaregrid.com'),
        ] as $invalidName) {
            try {
                $invalidName();
                $this->fail('Malformed or out-of-zone names must be rejected.');
            } catch (CloudflareDnsException $exception) {
                $this->assertSame('invalid_record', $exception->category);
            }
        }
    }

    public function test_initial_staging_desired_state_is_dns_only_and_does_not_execute_operations(): void
    {
        $records = WpCareGridInfrastructureDns::stagingRecords();

        $this->assertSame([
            ['type' => 'A', 'name' => 'wp-test.staging.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 1, 'proxied' => false],
            ['type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 1, 'proxied' => false],
        ], array_map(fn (DnsRecordData $record) => $record->payload(), $records));
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_or_dangerous_records_are_rejected(array $input): void
    {
        $this->expectException(CloudflareDnsException::class);
        DnsRecordData::fromInput('wpcaregrid.com', $input);
    }

    public static function invalidRecords(): array
    {
        return [
            'bad ipv4' => [['type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '999.2.3.4']],
            'bad ipv6' => [['type' => 'AAAA', 'name' => 'a.wpcaregrid.com', 'content' => 'nope']],
            'malformed hostname' => [['type' => 'CNAME', 'name' => 'bad..wpcaregrid.com', 'content' => 'target.example.test']],
            'cname ip target' => [['type' => 'CNAME', 'name' => 'alias.wpcaregrid.com', 'content' => '192.0.2.4']],
            'out of zone name' => [['type' => 'TXT', 'name' => 'elsewhere.example.test', 'content' => 'value']],
            'wildcard zone' => [['type' => 'TXT', 'name' => '*.wpcaregrid.com', 'content' => 'value']],
            'mx missing priority' => [['type' => 'MX', 'name' => 'wpcaregrid.com', 'content' => 'mail.example.test']],
            'mx bad priority' => [['type' => 'MX', 'name' => 'wpcaregrid.com', 'content' => 'mail.example.test', 'priority' => 70000]],
            'txt proxied' => [['type' => 'TXT', 'name' => 'wpcaregrid.com', 'content' => 'v=spf1 -all', 'proxied' => true]],
            'mail host proxied' => [['type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '192.0.2.1', 'proxied' => true]],
            'staging test host proxied' => [['type' => 'A', 'name' => 'wp-test.staging.wpcaregrid.com', 'content' => '192.0.2.1', 'proxied' => true]],
            'proxied with nonautomatic ttl' => [['type' => 'A', 'name' => 'web.wpcaregrid.com', 'content' => '192.0.2.1', 'proxied' => true, 'ttl' => 300]],
            'unsupported type' => [['type' => 'SRV', 'name' => '_sip._tcp.wpcaregrid.com', 'content' => 'target.example.test']],
            'invalid ttl' => [['type' => 'A', 'name' => 'a.wpcaregrid.com', 'content' => '192.0.2.1', 'ttl' => 30]],
        ];
    }
}
