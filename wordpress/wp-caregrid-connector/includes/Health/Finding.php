<?php

namespace WPCareGrid\Connector\Health;

/** One observation. The overall status is derived from all findings together. */
final class Finding
{
    public const PASS = 'pass';

    public const WARNING = 'warning';

    public const FAIL = 'fail';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $label,
        public readonly string $message = '',
        public readonly bool $critical = false,
    ) {}

    public function to_array(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'label' => $this->label,
            'message' => substr($this->message, 0, 500),
        ];
    }
}
