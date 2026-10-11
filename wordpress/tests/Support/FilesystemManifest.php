<?php

namespace WPCareGrid\Connector\Tests\Support;

use RuntimeException;

/** Deterministic content manifest for a disposable restore fixture subtree. */
final class FilesystemManifest
{
    public static function capture(string $root): array
    {
        $realRoot = realpath($root);
        if (! is_string($realRoot) || ! is_dir($realRoot) || is_link($root)) {
            throw new RuntimeException('A real disposable fixture directory is required.');
        }

        $manifest = [];
        self::walk($realRoot, $realRoot, $manifest);
        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    private static function walk(string $root, string $directory, array &$manifest): void
    {
        $items = scandir($directory);
        if ($items === false) {
            throw new RuntimeException('The disposable filesystem fixture could not be read.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;
            if (is_link($path)) {
                throw new RuntimeException('Symlinks are not part of the deterministic fixture manifest.');
            }

            $relative = ltrim(substr($path, strlen($root)), '/');
            $mode = fileperms($path);
            if ($mode === false) {
                throw new RuntimeException('A fixture filesystem mode could not be read.');
            }

            if (is_dir($path)) {
                $manifest[$relative] = ['type' => 'directory', 'mode' => $mode & 0777];
                self::walk($root, $path, $manifest);
            } elseif (is_file($path)) {
                $size = filesize($path);
                $sha256 = hash_file('sha256', $path);
                if ($size === false || $sha256 === false) {
                    throw new RuntimeException('A fixture file could not be fingerprinted.');
                }
                $manifest[$relative] = ['type' => 'file', 'size' => $size, 'sha256' => $sha256, 'mode' => $mode & 0777];
            }
        }
    }
}
