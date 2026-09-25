<?php

namespace WPCareGrid\Connector\Jobs;

final class InvalidPayload extends \InvalidArgumentException
{
    /** @param array<string, mixed> $payload */
    public static function check(array $payload, array $allowed, array $required = []): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        $missing = array_diff($required, array_keys($payload));

        if ($unknown || $missing) {
            throw new self('Unexpected or missing payload fields.');
        }

        foreach ($payload as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new self('Payload values must be scalar.');
            }
        }
    }

    public static function version(?string $version): ?string
    {
        if ($version !== null && ! preg_match('/^\d+(\.\d+){0,3}([.-][0-9A-Za-z]+)?$/', $version)) {
            throw new self('Invalid version.');
        }

        return $version;
    }
}
