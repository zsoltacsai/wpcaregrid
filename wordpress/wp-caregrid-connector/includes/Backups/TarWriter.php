<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\Lock;

/**
 * Streaming POSIX tar (ustar, with pax headers for long names) into a Sink.
 * Files are copied in 1 MiB pieces; nothing is held in memory whole. Only
 * regular files and directories are written, never links.
 */
final class TarWriter
{
    private const PIECE = 1048576;

    /** Files that changed size while being read (padded or cut to the size first seen). */
    public int $changed = 0;

    public function __construct(private readonly Sink $sink) {}

    public function add_dir(string $name, int $mtime): void
    {
        $this->header(rtrim($name, '/').'/', 0, 0755, $mtime, '5');
    }

    public function add_file(string $name, string $source, int $size, int $mtime): bool
    {
        $handle = @fopen($source, 'rb');
        if (! $handle) {
            return false;
        }

        $this->header($name, $size, 0644, $mtime, '0');
        $left = $size;

        while ($left > 0) {
            $data = fread($handle, min(self::PIECE, $left));
            if ($data === false || $data === '') {
                // Shrunk while reading: pad so the archive stays valid.
                $this->changed++;
                $this->sink->write(str_repeat("\0", $left));
                break;
            }
            $this->sink->write($data);
            $left -= strlen($data);
            Lock::refresh();
        }

        fclose($handle);
        $this->pad($size);

        return true;
    }

    public function add_string(string $name, string $content, int $mtime): void
    {
        $this->header($name, strlen($content), 0600, $mtime, '0');
        $this->sink->write($content);
        $this->pad(strlen($content));
    }

    public function finish(): void
    {
        $this->sink->write(str_repeat("\0", 1024));
    }

    private function header(string $name, int $size, int $mode, int $mtime, string $type): void
    {
        if (strlen($name) > 100 || preg_match('/[^\x20-\x7e]/', $name)) {
            $record = self::pax_record('path', $name);
            $this->sink->write(self::block('PaxHeader/'.substr(md5($name), 0, 16), strlen($record), 0644, $mtime, 'x'));
            $this->sink->write($record);
            $this->pad(strlen($record));
            $name = substr(preg_replace('/[^\x20-\x7e]/', '_', $name), -100);
        }

        $this->sink->write(self::block($name, $size, $mode, $mtime, $type));
    }

    private function pad(int $size): void
    {
        if ($size % 512 !== 0) {
            $this->sink->write(str_repeat("\0", 512 - $size % 512));
        }
    }

    private static function block(string $name, int $size, int $mode, int $mtime, string $type): string
    {
        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', $mode)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', $size)."\0"
            .sprintf('%011o', max(0, $mtime))."\0"
            .'        '
            .$type
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

        $sum = array_sum(array_map('ord', str_split($header)));

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    /** "<length> key=value\n", where length counts the whole record. */
    private static function pax_record(string $key, string $value): string
    {
        $body = ' '.$key.'='.$value."\n";
        $n = strlen($body);
        $length = $n + strlen((string) ($n + strlen((string) $n)));

        return $length.$body;
    }
}
