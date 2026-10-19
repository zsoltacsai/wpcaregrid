<?php

namespace App\Security\Vulnerabilities;

use RuntimeException;

/** Resolves and pins the official API hostname to public addresses for one HTTPS request. */
class WordfenceFeedAddressResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if ($host !== 'www.wordfence.com') {
            throw new RuntimeException('The vulnerability provider hostname is not approved.');
        }

        $records = $this->lookup($host);
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                continue;
            }
            $addresses[$address] = $address;
        }
        if ($addresses === []) {
            throw new RuntimeException('The official vulnerability provider did not resolve to a public address.');
        }

        return array_values($addresses);
    }

    /** @return array<int, array<string, mixed>> */
    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return is_array($records) ? $records : [];
    }
}
