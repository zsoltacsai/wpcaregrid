<?php

namespace App\Security\Vulnerabilities;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Applies the minimal read/traverse ACL required by the PHP-FPM account. */
class WordfenceIndexAccess
{
    private const SETFACL = '/usr/bin/setfacl';

    private const PHP_FPM_USER = 'www-data';

    private const PROCESS_TIMEOUT_SECONDS = 60;

    public function allowPhpReadOnManifest(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }
        $this->assertNonRootPublisher();
        $this->assertOwnedRegularFile($path);
        $this->runSetfacl(['-b', '--', $path]);
        $this->runSetfacl(['-m', 'u:'.self::PHP_FPM_USER.':r--,g::---,m::r--,o::---', '--', $path]);
    }

    public function allowPhpReadOnGeneration(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }
        $this->assertNonRootPublisher();
        $this->assertOwnedDirectoryTree($path);
        $this->runSetfacl(['-Rb', '--', $path]);
        $this->runSetfacl(['-Rk', '--', $path]);
        $this->runSetfacl(['-RP', '-m', 'u:'.self::PHP_FPM_USER.':r-X,g::---,m::r-X,o::---', '--', $path]);
    }

    private function assertNonRootPublisher(): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() === 0 || ! function_exists('posix_getpwnam') || posix_getpwnam(self::PHP_FPM_USER) === false) {
            throw new RuntimeException('The vulnerability index publisher identity is not supported.');
        }
    }

    private function assertOwnedRegularFile(string $path): void
    {
        $metadata = @lstat($path);
        if ($metadata === false || ($metadata['mode'] & 0170000) !== 0100000 || ! $this->ownedByCurrentUser($metadata)) {
            throw new RuntimeException('The vulnerability index file is not a safe regular file.');
        }
    }

    private function assertOwnedDirectoryTree(string $path): void
    {
        $metadata = @lstat($path);
        if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000 || ! $this->ownedByCurrentUser($metadata)) {
            throw new RuntimeException('The vulnerability index generation is not a safe directory.');
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $entry) {
                $item = @lstat($entry->getPathname());
                $type = $item === false ? 0 : $item['mode'] & 0170000;
                if ($item === false || ! in_array($type, [0040000, 0100000], true) || ! $this->ownedByCurrentUser($item)) {
                    throw new RuntimeException('The vulnerability index generation contains an unsafe filesystem entry.');
                }
            }
        } catch (Throwable) {
            throw new RuntimeException('The vulnerability index generation could not be validated safely.');
        }
    }

    private function ownedByCurrentUser(array $metadata): bool
    {
        return function_exists('posix_geteuid') && $metadata['uid'] === posix_geteuid();
    }

    /** @param list<string> $arguments */
    private function runSetfacl(array $arguments): void
    {
        if (! is_executable(self::SETFACL)) {
            throw new RuntimeException('The vulnerability index ACL utility is unavailable.');
        }
        $process = new Process([self::SETFACL, ...$arguments]);
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->disableOutput();
        try {
            $process->run();
        } catch (Throwable) {
            throw new RuntimeException('The vulnerability index ACL could not be applied safely.');
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The vulnerability index ACL could not be applied safely.');
        }
    }
}