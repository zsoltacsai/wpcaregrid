<?php

namespace WPCareGrid\Connector\Backups;

/** Opaque, generation-bound identity for a host-managed restore isolation gate. */
final class RestoreIsolationLease
{
    public function __construct(
        public readonly string $provider,
        public readonly string $restore_uuid,
        public readonly string $scope,
        public readonly string $generation,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $provider)
            || ! preg_match('/^[0-9a-f-]{36}$/i', $restore_uuid)
            || ! in_array($scope, ['files', 'database', 'full'], true)
            || ! preg_match('/^[a-f0-9]{64}$/', $generation)) {
            throw new \InvalidArgumentException('Invalid restore isolation lease.');
        }
    }

    /** @return array{provider: string, restore_uuid: string, scope: string, generation: string} */
    public function to_array(): array
    {
        return [
            'provider' => $this->provider,
            'restore_uuid' => $this->restore_uuid,
            'scope' => $this->scope,
            'generation' => $this->generation,
        ];
    }

    public static function from_array(mixed $value): ?self
    {
        if (! is_array($value)
            || ! is_string($value['provider'] ?? null)
            || ! is_string($value['restore_uuid'] ?? null)
            || ! is_string($value['scope'] ?? null)
            || ! is_string($value['generation'] ?? null)) {
            return null;
        }

        try {
            return new self($value['provider'], $value['restore_uuid'], $value['scope'], $value['generation']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
