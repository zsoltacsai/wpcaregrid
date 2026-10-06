<?php

namespace App\Cloudflare;

/** Initial staging desired state. This class only describes records; it never applies them. */
final class WpCareGridInfrastructureDns
{
    /** @return list<DnsRecordData> */
    public static function stagingRecords(): array
    {
        return [
            DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'A', 'name' => 'wp-test.staging.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 'auto', 'proxied' => false]),
            DnsRecordData::fromInput('wpcaregrid.com', ['type' => 'A', 'name' => 'mail.wpcaregrid.com', 'content' => '85.155.243.164', 'ttl' => 'auto', 'proxied' => false]),
        ];
    }
}
