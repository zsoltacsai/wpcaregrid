<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;
use WPCareGrid\Connector\Backups\BackupIndexLookup;
use WPCareGrid\Connector\Backups\WordPressBackupIndexLookup;

/** In-memory index for copied test variants; it never writes WordPress options. */
final class RestoreVariantIndex implements BackupIndexLookup
{
    private array $variants = [];

    private array $createdDirectories = [];

    private readonly string $root;

    private readonly string $testRoot;

    public function __construct()
    {
        $this->testRoot = RestoreHarnessGuard::activeRoot();
        $this->root = $this->testRoot.'/restore-fault-harness';
        if (is_link($this->root)) {
            throw new RuntimeException('The isolated restore harness directory cannot be a symlink.');
        }
        if (! is_dir($this->root) && ! mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Could not create the isolated restore harness directory.');
        }
        $realRoot = realpath($this->root);
        if (! is_string($realRoot) || dirname($realRoot) !== $this->testRoot) {
            throw new RuntimeException('The isolated restore harness directory escaped its test root.');
        }
        chmod($this->root, 0700);
    }

    /**
     * Copy a verified canonical backup into a unique test-only variant.
     *
     * @param  array<string, string> $sourcePaths  Downloaded artifact copies from this synthetic test site.
     * @return array{backup_uuid: string, parent_uuid: string, objects: array<string, array{size: int, sha256: string}>, paths: array<string, ?string>}
     */
    public function createVariant(string $sourceUuid, array $sourcePaths, string $mutation = 'copy'): array
    {
        RestoreHarnessGuard::activeRoot();
        if (! in_array($mutation, ['copy', 'corrupt_database', 'corrupt_files', 'missing_database', 'missing_files', 'database_checksum_mismatch', 'files_checksum_mismatch'], true)) {
            throw new RuntimeException('Unsupported test-variant mutation.');
        }

        $source = (new WordPressBackupIndexLookup)->find($sourceUuid);
        if (! $source || $sourcePaths === []) {
            throw new RuntimeException('A canonical source backup is required.');
        }
        if (preg_match('/^(?:corrupt|missing)_(database|files)$/', $mutation, $match)
            || preg_match('/^(database|files)_checksum_mismatch$/', $mutation, $match)) {
            $target = $match[1];
            if (! array_key_exists($target, $sourcePaths)) {
                throw new RuntimeException('The requested mutation does not match the source backup scope.');
            }
        }

        $uuid = strtolower(wp_generate_uuid4());
        $dir = $this->root.'/'.$uuid;
        if (! mkdir($dir, 0700)) {
            throw new RuntimeException('Could not create the isolated test-variant directory.');
        }
        $this->createdDirectories[] = $dir;
        $objects = [];
        $paths = [];

        foreach ($sourcePaths as $name => $sourcePath) {
            $meta = $source['objects'][$name] ?? null;
            $realSource = is_string($sourcePath) ? realpath($sourcePath) : false;
            $realTemp = realpath(sys_get_temp_dir());
            if (! in_array($name, ['database', 'files'], true)
                || ! is_array($meta)
                || ! is_string($realSource)
                || ! is_string($realTemp)
                || ! str_starts_with($realSource, rtrim($realTemp, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                || basename($realSource) !== 'caregrid-download-'.$sourceUuid.'-'.$name
                || ! is_file($realSource)
                || is_link($sourcePath)
                || (int) filesize($realSource) !== (int) $meta['size']
                || ! hash_equals((string) $meta['sha256'], (string) hash_file('sha256', $realSource))) {
                throw new RuntimeException('A source artifact did not match its canonical test-site metadata.');
            }

            $copy = $dir.'/'.$name.'.artifact';
            if (! copy($realSource, $copy)) {
                throw new RuntimeException('Could not copy the verified source artifact.');
            }
            chmod($copy, 0600);
            $size = (int) filesize($copy);
            $sha256 = (string) hash_file('sha256', $copy);

            if ($mutation === 'corrupt_'.$name) {
                $handle = fopen($copy, 'r+b');
                if (! $handle || ! flock($handle, LOCK_EX)) {
                    throw new RuntimeException('Could not safely mutate the copied test artifact.');
                }
                $first = fread($handle, 1);
                rewind($handle);
                fwrite($handle, ($first === "\0" ? "\1" : "\0").substr((string) file_get_contents($copy), 1));
                fflush($handle);
                flock($handle, LOCK_UN);
                fclose($handle);
                $size = (int) filesize($copy);
                $sha256 = (string) hash_file('sha256', $copy);
            }

            $paths[$name] = $copy;
            $objects[$name] = ['size' => $size, 'sha256' => $sha256];
            if ($mutation === 'missing_'.$name) {
                unlink($copy);
                $paths[$name] = null;
            }
            if ($mutation === $name.'_checksum_mismatch') {
                $objects[$name]['sha256'] = $sha256 === str_repeat('0', 64) ? str_repeat('1', 64) : str_repeat('0', 64);
            }
        }

        $this->variants[$uuid] = [
            'type' => $source['type'] ?? 'test_variant',
            'created_at' => gmdate('c'),
            'parent_uuid' => $sourceUuid,
            'objects' => $objects,
        ];

        return ['backup_uuid' => $uuid, 'parent_uuid' => $sourceUuid, 'objects' => $objects, 'paths' => $paths];
    }

    public function find(string $uuid): ?array
    {
        return $this->variants[$uuid] ?? null;
    }

    public function cleanup(): void
    {
        foreach ($this->createdDirectories as $dir) {
            $real = realpath($dir);
            if (! is_string($real) || ! str_starts_with($real, rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || dirname($real) !== realpath($this->root)) {
                continue;
            }
            self::removeTree($real);
        }
        $this->createdDirectories = [];
        $this->variants = [];
        if (is_dir($this->root) && count(scandir($this->root) ?: []) === 2) {
            rmdir($this->root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::removeTree($path.'/'.$item);
            }
        }
        rmdir($path);
    }
}
