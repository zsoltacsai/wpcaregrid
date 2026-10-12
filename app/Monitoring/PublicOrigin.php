<?php

namespace App\Monitoring;

use GuzzleHttp\Psr7\Uri;

/** A website origin pinned to one public DNS answer for an individual request. */
final readonly class PublicOrigin
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    public static function resolve(string $url, ?PublicAddressLookup $lookup = null): self
    {
        $uri = new Uri($url);
        $scheme = strtolower($uri->getScheme());
        $host = strtolower(rtrim($uri->getHost(), '.'));
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $uri->getUserInfo() !== '' || ! in_array($port, [80, 443], true) || ($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new \DomainException('monitoring.invalid_origin');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            throw new \DomainException('monitoring.invalid_origin');
        }

        $addresses = ($lookup ?? new SystemPublicAddressLookup)->addresses($host);
        if ($addresses === [] || count(array_unique(array_map(self::packed(...), $addresses))) !== count($addresses)) {
            throw new \DomainException('monitoring.dns_unavailable');
        }
        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new \DomainException('monitoring.private_address');
            }
        }

        return new self($scheme, $host, $port, $addresses[0]);
    }

    public function url(string $path = '/'): string
    {
        return $this->scheme.'://'.$this->host.$path;
    }

    /** @return array{0:string,1:string} */
    public function curlResolve(): array
    {
        $address = str_contains($this->address, ':') ? '['.$this->address.']' : $this->address;

        return [CURLOPT_RESOLVE => [$this->host.':'.$this->port.':'.$address]];
    }

    public static function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($address);
            if ($long === false) {
                return false;
            }
            foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $bits]) {
                if (self::inIpv4Range($long, $network, $bits)) {
                    return false;
                }
            }

            return true;
        }

        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        // Reject IPv4-mapped IPv6 explicitly and all local, unique-local,
        // multicast, discard/documentation and transition ranges we can recognize.
        if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return false;
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        $third = ord($packed[2]);

        return ! ($packed === str_repeat("\0", 16)
            || $packed === str_repeat("\0", 15)."\1"
            || ($first & 0xFE) === 0xFC // fc00::/7 unique local
            || ($first === 0xFE && ($second & 0xC0) === 0x80) // fe80::/10 link local
            || $first === 0xFF // multicast
            || ($first === 0x20 && $second === 0x01 && $third === 0x0D && ord($packed[3]) === 0xB8) // 2001:db8::/32 docs
            || ($first === 0x00 && $second === 0x64 && $third === 0xFF && ord($packed[3]) === 0x9B) // NAT64 well-known prefix
            || ($first === 0x20 && $second === 0x02) // 6to4 embeds an IPv4 destination
            || ($first === 0x20 && $second === 0x01 && $third === 0x00 && ord($packed[3]) === 0x00)); // Teredo embeds IPv4
    }

    private static function packed(string $ip): string
    {
        return inet_pton($ip) ?: $ip;
    }

    private static function inIpv4Range(int $address, string $network, int $bits): bool
    {
        $base = ip2long($network);
        $mask = -1 << (32 - $bits);

        return ($address & $mask) === ($base & $mask);
    }
}
