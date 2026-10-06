<?php

namespace App\Cloudflare;

/** Initial staging desired state. This class only describes records; it never applies them. */
final class WpCareGridInfrastructureDns
{
    /** @return array<string, array{id: string, label: string, zone: string, record: array<string, mixed>}> */
    public static function definitions(): array
    {
        return [
            'staging_wp_test_a' => [
                'id' => 'staging_wp_test_a',
                'label' => 'Staging WordPress test host',
                'zone' => 'wpcaregrid.com',
                'record' => DnsRecordData::fromInput('wpcaregrid.com', [
                    'type' => 'A', 'name' => 'wp-test.staging.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 'auto', 'proxied' => false,
                ])->payload(),
            ],
            'staging_mail_a' => [
                'id' => 'staging_mail_a',
                'label' => 'Staging mail host',
                'zone' => 'wpcaregrid.com',
                'record' => DnsRecordData::fromInput('wpcaregrid.com', [
                    'type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 'auto', 'proxied' => false,
                ])->payload(),
            ],
        ];
    }

    /** @return list<DnsRecordData> */
    public static function stagingRecords(): array
    {
        return array_map(
            static fn (array $definition): DnsRecordData => DnsRecordData::fromInput($definition['zone'], $definition['record']),
            array_values(self::definitions()),
        );
    }
}
