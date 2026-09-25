<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\Lock;

/**
 * Builds the files archive for a backup following FilePolicy: a streamed
 * tar.gz on local disk, with its SHA-256 computed while it is written.
 */
final class FilesArchiver
{
    private TarWriter $tar;

    private array $stats;

    /** Rough bytes needed for the archive (before compression), for the disk-space check. */
    public static function estimate(): int
    {
        $total = 0;
        self::walk(WP_CONTENT_DIR, '', function (string $relative, string $path, bool $is_dir, int $size) use (&$total) {
            $total += $size;
        }, fn () => null);

        return $total;
    }

    /** @return array{path: string, size: int, sha256: string, compression: string, format: string, file_count: int, uncompressed_size: int, policy: string, wp_config: string, skipped: array} */
    public function build(string $uuid): array
    {
        $sink = new Sink(TempDir::file($uuid, Sink::gzip_available() ? 'files.tar.gz' : 'files.tar'), true);
        $this->tar = new TarWriter($sink);
        $this->stats = ['file_count' => 0, 'skipped' => ['symlinks' => 0, 'too_large' => 0, 'unreadable' => 0, 'excluded' => 0]];
        $max = FilePolicy::max_file_size();

        $this->tar->add_dir('wp-content', (int) @filemtime(WP_CONTENT_DIR));

        self::walk(WP_CONTENT_DIR, '', function (string $relative, string $path, bool $is_dir, int $size) use ($max) {
            if ($is_dir) {
                $this->tar->add_dir('wp-content/'.$relative, (int) @filemtime($path));

                return;
            }

            if ($size > $max) {
                $this->stats['skipped']['too_large']++;
            } elseif (! is_readable($path) || ! $this->tar->add_file('wp-content/'.$relative, $path, $size, (int) @filemtime($path))) {
                $this->stats['skipped']['unreadable']++;
            } else {
                $this->stats['file_count']++;
            }
        }, function (string $reason) {
            $this->stats['skipped'][$reason]++;
        });

        foreach (FilePolicy::ROOT_FILES as $name) {
            $path = ABSPATH.$name;
            if (is_file($path) && ! is_link($path) && is_readable($path) && $this->tar->add_file('root/'.$name, $path, (int) filesize($path), (int) filemtime($path))) {
                $this->stats['file_count']++;
            }
        }

        $config = WpConfigRedactor::read();
        if ($config['content'] !== null) {
            $this->tar->add_string('root/wp-config.php', $config['content'], time());
            $this->stats['file_count']++;
        }

        $this->tar->finish();
        $file = $sink->close();

        return $file + [
            'format' => 'ustar',
            'file_count' => $this->stats['file_count'],
            'policy' => FilePolicy::NAME,
            'wp_config' => $config['status'],
            'skipped' => $this->stats['skipped'],
        ];
    }

    /**
     * Depth-first, sorted, never following symlinks.
     *
     * @param  callable(string $relative, string $path, bool $is_dir, int $size): void  $visit
     * @param  callable(string $reason): void  $skip
     */
    private static function walk(string $root, string $relative, callable $visit, callable $skip): void
    {
        $dir = $relative === '' ? $root : $root.'/'.$relative;
        $items = @scandir($dir);
        if ($items === false) {
            $skip('unreadable');

            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $relative === '' ? $item : $relative.'/'.$item;
            $path = $dir.'/'.$item;
            Lock::refresh();

            if (is_link($path)) {
                $skip('symlinks');
            } elseif (is_dir($path)) {
                if (FilePolicy::excludes_dir($child)) {
                    $skip('excluded');

                    continue;
                }
                $visit($child, $path, true, 0);
                self::walk($root, $child, $visit, $skip);
            } elseif (is_file($path)) {
                if (FilePolicy::excludes_file($child)) {
                    $skip('excluded');

                    continue;
                }
                $visit($child, $path, false, (int) @filesize($path));
            }
            // Sockets, FIFOs and devices are ignored.
        }
    }
}
