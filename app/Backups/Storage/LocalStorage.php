<?php

namespace App\Backups\Storage;

/**
 * A directory on the WP CareGrid server. For development and tests only:
 * it is not remote storage and must not be used in production.
 */
final class LocalStorage implements BackupStorage
{
    use HashesStreams;

    public function __construct(private readonly string $root) {}

    public function name(): string
    {
        return 'local';
    }

    public function put(string $key, $stream, int $size, string $sha256): void
    {
        $path = $this->path($key);

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true)) {
            throw new StorageUnavailable('The storage directory cannot be created.');
        }

        $target = @fopen($path.'.part', 'wb');
        if (! $target || stream_copy_to_stream($stream, $target) === false || ! fclose($target) || ! rename($path.'.part', $path)) {
            throw new StorageUnavailable('Writing to storage failed.');
        }
    }

    public function verify(string $key, int $size, string $sha256): Verification
    {
        $path = $this->path($key);
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return Verification::failed('MISSING');
        }

        $remoteSize = (int) filesize($path);
        if ($remoteSize !== $size) {
            return Verification::failed('SIZE_MISMATCH', $remoteSize);
        }

        return hash_equals($sha256, (string) hash_file('sha256', $path))
            ? Verification::passed(Verification::READBACK, $remoteSize)
            : Verification::failed('CHECKSUM_MISMATCH', $remoteSize, Verification::READBACK);
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        $handle = @fopen($this->path($key), 'rb');
        if (! $handle) {
            throw new StorageUnavailable('The stored object cannot be read.');
        }
        fseek($handle, $offset);
        $data = (string) fread($handle, $length);
        fclose($handle);

        return $data;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
        @rmdir(dirname($path));
        @rmdir(dirname($path, 2));
    }

    private function path(string $key): string
    {
        return rtrim($this->root, '/').'/'.ObjectKey::assert($key);
    }
}
