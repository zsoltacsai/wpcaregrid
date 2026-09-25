<?php

namespace WPCareGrid\Connector\Jobs;

/** Outcome of a job, as reported to WP CareGrid. */
final class JobResult
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const PARTIAL = 'partial';

    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly array $data = [],
        public readonly ?array $error = null,
    ) {}

    public static function success(string $code, array $data = []): self
    {
        return new self(self::SUCCESS, $code, $data);
    }

    public static function skipped(string $code, string $message, array $details = []): self
    {
        return new self(self::SKIPPED, $code, [], self::error($code, $message, $details));
    }

    public static function failed(string $code, string $message, array $details = [], array $data = []): self
    {
        return new self(self::FAILED, $code, $data, self::error($code, $message, $details));
    }

    public static function partial(string $code, string $message, array $data, array $details = []): self
    {
        return new self(self::PARTIAL, $code, $data, self::error($code, $message, $details));
    }

    private static function error(string $code, string $message, array $details): array
    {
        return ['code' => $code, 'message' => $message, 'details' => (object) $details];
    }
}
