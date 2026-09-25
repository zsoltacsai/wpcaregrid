<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\Lock;

/**
 * Reads a (gzip-compressed or plain) tar archive entry by entry. Only
 * regular files and directories are accepted: links, devices and anything
 * unexpected make the whole archive invalid. Every name goes through
 * PathGuard::relative().
 */
final class TarReader
{
    private $handle;

    public function __construct(string $path)
    {
        $this->handle = @gzopen($path, 'rb');
        if (! $this->handle) {
            throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The files archive cannot be opened.');
        }
    }

    /**
     * Calls $on_entry(string $name, string $type 'file'|'dir', int $size, callable $read)
     * where $read() returns the next piece of the file's content or '' at the end.
     */
    public function each(callable $on_entry): void
    {
        $pax_path = null;

        while (true) {
            $block = $this->read(512);
            if (strlen($block) < 512 || trim($block, "\0") === '') {
                break; // End of archive.
            }

            $header = self::parse($block);
            $size = $header['size'];

            if ($header['type'] === 'x' || $header['type'] === 'g') {
                $records = $this->read($size);
                $this->skip_padding($size);
                if ($header['type'] === 'x' && preg_match('/(?:^|\n)\d+ path=([^\n]*)\n/', $records, $m)) {
                    $pax_path = $m[1];
                }

                continue;
            }

            $name = PathGuard::relative($pax_path ?? $header['name']);
            $pax_path = null;

            $type = match ($header['type']) {
                '0', "\0" => 'file',
                '5' => 'dir',
                default => throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains an entry type that is not allowed.'),
            };

            $left = $type === 'file' ? $size : 0;
            $on_entry($name, $type, $size, function () use (&$left): string {
                if ($left <= 0) {
                    return '';
                }
                $data = $this->read(min(1048576, $left));
                if ($data === '') {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is truncated.');
                }
                $left -= strlen($data);
                Lock::refresh();

                return $data;
            });

            // Skip whatever the callback did not read, then the padding.
            while ($left > 0) {
                $data = $this->read(min(1048576, $left));
                if ($data === '') {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is truncated.');
                }
                $left -= strlen($data);
            }
            $this->skip_padding($type === 'file' ? $size : 0);
        }

        gzclose($this->handle);
    }

    /** @return array{name: string, size: int, type: string} */
    private static function parse(string $block): array
    {
        $checksum = octdec(trim(substr($block, 148, 8), " \0"));
        $sum = array_sum(array_map('ord', str_split(substr_replace($block, '        ', 148, 8))));
        if ($checksum !== $sum || substr($block, 257, 5) !== 'ustar') {
            throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is damaged.');
        }

        $name = rtrim(substr($block, 0, 100), "\0");
        $prefix = rtrim(substr($block, 345, 155), "\0");

        return [
            'name' => $prefix !== '' ? $prefix.'/'.$name : $name,
            'size' => (int) octdec(trim(substr($block, 124, 12), " \0")),
            'type' => $block[156],
        ];
    }

    private function read(int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $data = gzread($this->handle, $length - strlen($out));
            if ($data === false || $data === '') {
                break;
            }
            $out .= $data;
        }

        return $out;
    }

    private function skip_padding(int $size): void
    {
        if ($size % 512 !== 0) {
            $this->read(512 - $size % 512);
        }
    }
}
