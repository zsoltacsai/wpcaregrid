<?php

namespace App\Backups\Storage;

/**
 * Where verified backups live. Implementations only ever receive keys that
 * passed ObjectKey::assert() ({website_uuid}/{backup_uuid}/{fixed file name}),
 * so no caller can read, write or delete anything else.
 *
 * Every method throws StorageUnavailable when the storage cannot be reached
 * or refuses the request. Credentials never appear in exception messages.
 */
interface BackupStorage
{
    public function name(): string;

    /**
     * Store an object from a stream without loading it into memory.
     *
     * @param  resource  $stream
     *
     * @throws ChecksumMismatch when the storage itself rejects the data as not matching $sha256
     */
    public function put(string $key, $stream, int $size, string $sha256): void;

    /** Check the stored object against the expected size and SHA-256. */
    public function verify(string $key, int $size, string $sha256): Verification;

    /** A byte range, for restore downloads; at most $length bytes. */
    public function readRange(string $key, int $offset, int $length): string;

    public function delete(string $key): void;
}
