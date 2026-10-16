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

    public function __construct(
        string $path,
        private readonly int $maxEntries = ArchiveLimits::MAX_ENTRIES,
        private readonly int $maxExpandedBytes = ArchiveLimits::MAX_EXPANDED_BYTES,
        private readonly int $maxPathDepth = ArchiveLimits::MAX_PATH_DEPTH,
        private readonly int $maxTotalPathComponents = ArchiveLimits::MAX_TOTAL_PATH_COMPONENTS,
        private readonly int $maxPaxBytes = ArchiveLimits::MAX_PAX_BYTES,
        private readonly int $maxMemberBytes = ArchiveLimits::MAX_MEMBER_BYTES,
        private readonly int $maxArchiveBytes = ArchiveLimits::MAX_ARCHIVE_BYTES,
    )
    {
        $archiveBytes = @filesize($path);
        if ($archiveBytes === false || $archiveBytes > $this->maxArchiveBytes) {
            throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive exceeds the supported size limit.');
        }
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
        $entries = 0;
        $expanded = 0;
        $pathComponents = 0;
        $paxBytes = 0;
        $seen = [];
        $pathCase = [];
        $children = [];

        try {
            while (true) {
                $block = $this->read(512);
                if ($block === '') {
                    break;
                }
                if (strlen($block) !== 512) {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is truncated.');
                }
                if (trim($block, "\0") === '') {
                    break; // End of archive.
                }

                $header = self::parse($block);
                $size = $header['size'];

                if ($header['type'] === 'g') {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains unsupported global metadata.');
                }
                if ($header['type'] === 'x') {
                    if ($pax_path !== null) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive metadata is incomplete.');
                    }
                    $paxBytes += $size;
                    if ($size > $this->maxPaxBytes || $paxBytes > $this->maxPaxBytes) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive metadata exceeds the supported limit.');
                    }
                    $records = $this->read($size);
                    if (strlen($records) !== $size) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is truncated.');
                    }
                    $this->skip_padding($size);
                    if (! preg_match('/^([0-9]+) path=([^\n]+)\n$/D', $records, $m)
                        || (int) $m[1] !== strlen($records)) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive metadata is invalid.');
                    }
                    $pax_path = $m[2];

                    continue;
                }

                $name = PathGuard::relative($pax_path ?? $header['name']);
                $pax_path = null;
                $parts = explode('/', $name);
                $pathComponents += count($parts);
                if (count($parts) > $this->maxPathDepth || $pathComponents > $this->maxTotalPathComponents) {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive path structure exceeds the supported limit.');
                }

                $type = match ($header['type']) {
                    '0', "\0" => 'file',
                    '5' => 'dir',
                    default => throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains an entry type that is not allowed.'),
                };

                if (++$entries > $this->maxEntries || ($type === 'dir' && $size !== 0)
                    || ($type === 'file' && $size > $this->maxMemberBytes)) {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive exceeds the supported entry limits.');
                }
                if ($type === 'file') {
                    if ($size > $this->maxExpandedBytes - $expanded || isset($children[strtolower($name)])) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive exceeds the supported expanded-size or path limits.');
                    }
                    $expanded += $size;
                }

                $key = strtolower($name);
                if (isset($seen[$key])) {
                    throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains duplicate or case-colliding paths.');
                }
                foreach ($parts as $index => $_part) {
                    $originalPrefix = implode('/', array_slice($parts, 0, $index + 1));
                    $prefixKey = strtolower($originalPrefix);
                    if (isset($pathCase[$prefixKey]) && $pathCase[$prefixKey] !== $originalPrefix) {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains duplicate or case-colliding paths.');
                    }
                    $pathCase[$prefixKey] = $originalPrefix;
                    if ($index === 0) {
                        continue;
                    }
                    $parent = strtolower(implode('/', array_slice($parts, 0, $index)));
                    if (($seen[$parent] ?? null) === 'file') {
                        throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains a file and child-path collision.');
                    }
                    $children[$parent] = true;
                }
                $seen[$key] = $type;

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
            if ($pax_path !== null) {
                throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive metadata is incomplete.');
            }
        } finally {
            if (is_resource($this->handle)) {
                gzclose($this->handle);
            }
        }
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

        $rawSize = trim(substr($block, 124, 12), " \0");
        if ($rawSize !== '' && ! preg_match('/^[0-7]+$/', $rawSize)) {
            throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive contains an invalid size.');
        }

        return [
            'name' => $prefix !== '' ? $prefix.'/'.$name : $name,
            'size' => (int) octdec($rawSize === '' ? '0' : $rawSize),
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
            $padding = $this->read(512 - $size % 512);
            if (strlen($padding) !== 512 - $size % 512) {
                throw new BackupFailure('RESTORE_ARCHIVE_INVALID', 'The archive is truncated.');
            }
        }
    }
}
