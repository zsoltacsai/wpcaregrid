<?php

namespace App\Cloudflare;

use InvalidArgumentException;

final readonly class DnsRecordData
{
    private function __construct(
        public string $type,
        public string $name,
        public string $content,
        public int $ttl,
        public bool $proxied,
        public ?int $priority,
        public ?string $comment,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(string $zone, array $input): self
    {
        try {
            $zone = DnsNames::normalizeZone($zone);
            $type = strtoupper(trim((string) ($input['type'] ?? '')));
            if (! in_array($type, ['A', 'AAAA', 'CNAME', 'TXT', 'MX'], true)) {
                throw new InvalidArgumentException('Unsupported record type.');
            }

            $name = DnsNames::normalizeRecordName((string) ($input['name'] ?? ''), $zone);
            $content = (string) ($input['content'] ?? '');
            if ($content === '' || strlen($content) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $content)) {
                throw new InvalidArgumentException('Invalid record content.');
            }

            if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('Invalid IPv4 address.');
            }
            if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('Invalid IPv6 address.');
            }
            if (in_array($type, ['CNAME', 'MX'], true)) {
                $content = DnsNames::normalizeTarget($content);
                if (filter_var($content, FILTER_VALIDATE_IP) !== false) {
                    throw new InvalidArgumentException('DNS targets must be hostnames, not IP addresses.');
                }
            }

            $ttlInput = $input['ttl'] ?? 1;
            $ttl = $ttlInput === 'auto' ? 1 : filter_var($ttlInput, FILTER_VALIDATE_INT);
            if ($ttl === false || ($ttl !== 1 && ($ttl < 60 || $ttl > 86400))) {
                throw new InvalidArgumentException('Invalid TTL.');
            }

            $proxied = filter_var($input['proxied'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($proxied === null) {
                throw new InvalidArgumentException('Invalid proxy setting.');
            }
            if ($proxied && ! in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
                throw new InvalidArgumentException('This record type cannot be proxied.');
            }
            if ($proxied && $ttl !== 1) {
                throw new InvalidArgumentException('Proxied records require automatic TTL.');
            }
            if ($proxied && self::isProxyForbiddenName($name)) {
                throw new InvalidArgumentException('This infrastructure record must be DNS only.');
            }

            $priority = null;
            if ($type === 'MX') {
                $priority = filter_var($input['priority'] ?? null, FILTER_VALIDATE_INT);
                if ($priority === false || $priority < 0 || $priority > 65535) {
                    throw new InvalidArgumentException('MX priority is required.');
                }
            } elseif (array_key_exists('priority', $input) && $input['priority'] !== null && $input['priority'] !== '') {
                throw new InvalidArgumentException('Priority is only valid for MX records.');
            }

            $comment = isset($input['comment']) ? trim((string) $input['comment']) : null;
            if ($comment === '') {
                $comment = null;
            }
            if ($comment !== null && (strlen($comment) > 500 || preg_match('/[\x00-\x1F\x7F]/', $comment))) {
                throw new InvalidArgumentException('Invalid comment.');
            }

            return new self($type, $name, $content, (int) $ttl, $proxied, $priority, $comment);
        } catch (InvalidArgumentException) {
            throw new CloudflareDnsException('invalid_record');
        }
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $payload = [
            'type' => $this->type,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'proxied' => $this->proxied,
        ];

        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }
        if ($this->comment !== null) {
            $payload['comment'] = $this->comment;
        }

        return $payload;
    }

    private static function isProxyForbiddenName(string $name): bool
    {
        return $name === 'wp-test.staging.wpcaregrid.com'
            || (bool) preg_match('/(?:\A|\.)(?:mail|mx|smtp|imap|pop3?|_?submission|autoconfig|autodiscover|webmail|mta-sts)(?:\.|\z)/i', $name);
    }
}
