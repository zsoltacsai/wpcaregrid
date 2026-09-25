<?php

namespace App\Backups\Storage;

/** Result of checking a stored object. Only ok() counts as verified. */
final readonly class Verification
{
    public const NATIVE = 'native';

    public const READBACK = 'readback';

    private function __construct(
        public bool $ok,
        public ?string $method,
        public ?int $remoteSize,
        public ?string $failure,
    ) {}

    public static function passed(string $method, int $remoteSize): self
    {
        return new self(true, $method, $remoteSize, null);
    }

    /** @param  string  $failure  MISSING | SIZE_MISMATCH | CHECKSUM_MISMATCH | UNVERIFIABLE */
    public static function failed(string $failure, ?int $remoteSize = null, ?string $method = null): self
    {
        return new self(false, $method, $remoteSize, $failure);
    }

    public function ok(): bool
    {
        return $this->ok;
    }
}
