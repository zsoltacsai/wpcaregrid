<?php

namespace WPCareGrid\Connector\Api;

/** Outcome of one API call: an HTTP response, or a network failure (status 0). */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly array $data = [],
        public readonly ?string $error_code = null,
        public readonly ?string $error_message = null,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {}

    /** Machine-readable details of an error response (e.g. expected_offset). */
    public function error_details(): array
    {
        $details = $this->data['error']['details'] ?? [];

        return is_array($details) ? $details : [];
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function network_error(string $message): self
    {
        return new self(0, [], 'NETWORK_ERROR', $message);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Worth trying again later: no response, rate limited, or a server error. */
    public function is_temporary(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    public function is_revoked(): bool
    {
        return $this->status === 401 && $this->error_code === 'CONNECTOR_REVOKED';
    }
}
