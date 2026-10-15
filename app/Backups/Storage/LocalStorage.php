<?php

namespace App\Backups\Storage;

/**
 * A directory on the WP CareGrid server. For development and tests only:
 * it is not remote storage and must not be used in production.
 */
final class LocalStorage implements IncompleteUploadListable, InspectableBackupStorage, ListableBackupStorage
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

    public function presence(string $key): ObjectPresence
    {
        $path = $this->path($key);
        clearstatcache(true, $path);
        $root = rtrim($this->root, DIRECTORY_SEPARATOR);
        if (! is_dir($root) || ! is_readable($root)) {
            return ObjectPresence::Unknown;
        }

        $relativeParent = ltrim(substr(dirname($path), strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $relativeParent), fn (string $part) => $part !== '') as $part) {
            if (! is_readable($cursor)) {
                return ObjectPresence::Unknown;
            }
            $next = $cursor.DIRECTORY_SEPARATOR.$part;
            if (is_link($next)) {
                return ObjectPresence::Unknown;
            }
            if (! file_exists($next)) {
                return ObjectPresence::Absent;
            }
            if (! is_dir($next) || ! is_readable($next)) {
                return ObjectPresence::Unknown;
            }
            $cursor = $next;
        }

        if (is_link($path)) {
            return ObjectPresence::Unknown;
        }

        if (is_file($path)) {
            return ObjectPresence::Present;
        }

        if (file_exists($path)) {
            return ObjectPresence::Unknown;
        }

        return ObjectPresence::Absent;
    }

    public function listKeys(): iterable
    {
        if (! is_dir($this->root)) {
            return [];
        }
        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($this->root, DIRECTORY_SEPARATOR)) + 1));
            try {
                $keys[] = ObjectKey::assert($key);
            } catch (\InvalidArgumentException) {
                // Unknown files are deliberately not surfaced as backup objects.
            }
        }

        return $keys;
    }

    public function listIncompleteUploads(): iterable
    {
        if (! is_dir($this->root)) {
            return [];
        }
        $uploads = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink() && str_ends_with($file->getFilename(), '.part')) {
                $uploads[] = [
                    'object_key' => str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($this->root, DIRECTORY_SEPARATOR)) + 1)),
                    'initiated_at' => date(DATE_ATOM, $file->getMTime()),
                ];
            }
        }

        return $uploads;
    }

    private function path(string $key): string
    {
        return rtrim($this->root, '/').'/'.ObjectKey::assert($key);
    }
}
