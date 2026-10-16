<?php

namespace WPCareGrid\Connector\Backups;

/**
 * A file being written, optionally gzip-compressed on the fly, with the
 * SHA-256 and size of the bytes that end up on disk computed as they are
 * written. Memory use is independent of the file size.
 */
final class Sink
{
    /** @var resource */
    private $handle;

    private $deflate = null;

    private $hash;

    private int $size = 0;

    private int $raw = 0;

    public function __construct(private readonly string $path, bool $compress, private readonly ?int $maxOutputBytes = null)
    {
        TempDir::create($path);
        $this->handle = fopen($path, 'wb');
        $this->hash = hash_init('sha256');

        if ($compress && self::gzip_available()) {
            $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        }
    }

    public static function gzip_available(): bool
    {
        return function_exists('deflate_init') && function_exists('inflate_init');
    }

    public function compression(): string
    {
        return $this->deflate ? 'gzip' : 'none';
    }

    public function write(string $data): void
    {
        $this->raw += strlen($data);
        $this->out($this->deflate ? deflate_add($this->deflate, $data, ZLIB_NO_FLUSH) : $data);
    }

    /** @return array{path: string, size: int, sha256: string, compression: string, uncompressed_size: int} */
    public function close(): array
    {
        if ($this->deflate) {
            $this->out(deflate_add($this->deflate, '', ZLIB_FINISH));
        }

        if (! fclose($this->handle)) {
            throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'Writing the backup file failed.');
        }

        return ['path' => $this->path, 'size' => $this->size, 'sha256' => hash_final($this->hash), 'compression' => $this->compression(), 'uncompressed_size' => $this->raw];
    }

    /** Close and remove a partial archive after a bounded-build failure. */
    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        if (is_file($this->path) || is_link($this->path)) {
            @unlink($this->path);
        }
    }

    private function out(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if ($this->maxOutputBytes !== null && strlen($bytes) > $this->maxOutputBytes - $this->size) {
            throw new BackupFailure('BACKUP_ARCHIVE_LIMIT_EXCEEDED', 'The site backup exceeds the supported archive-size limit.');
        }

        $written = fwrite($this->handle, $bytes);
        if ($written !== strlen($bytes)) {
            // Most often a full disk.
            throw new BackupFailure('INSUFFICIENT_DISK_SPACE', 'Writing the backup file failed.');
        }

        hash_update($this->hash, $bytes);
        $this->size += $written;
    }
}
