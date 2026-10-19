<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class M147StagingAuditRunnerTest extends TestCase
{
    private string $root;

    private string $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/m147-audit-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        mkdir($this->root.'/.git/refs/heads', 0700, true);
        mkdir($this->root.'/app', 0700, true);
        file_put_contents($this->root.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root.'/.git/refs/heads/main', str_repeat('a', 40)."\n");
        $this->runner = dirname(__DIR__, 2).'/tools/security/m147-staging-audit/audit.php';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree($this->root.'-common');
    }

    public function test_reports_allowlisted_read_only_evidence_and_unknown_m12_gates(): void
    {
        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name']);

        self::assertSame(0, $result['exit']);
        $json = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('a', 40), $json['application']['git_head']);
        self::assertSame(PHP_VERSION, $json['runtime']['php_version']);
        self::assertGreaterThan(0, $json['filesystem']['available_bytes']);
        self::assertSame(false, $json['side_effects']['application_bootstrap']);
        self::assertSame(false, $json['side_effects']['subprocesses']);
        self::assertSame(6, count($json['m12_gates']));
        foreach ($json['m12_gates'] as $gate) {
            self::assertNull($gate['effective_value']);
            self::assertSame('UNKNOWN', $gate['verification']);
        }
        self::assertStringNotContainsString('DB_PASSWORD', $result['stdout']);
    }

    public function test_refuses_host_or_account_mismatch_without_evidence_output(): void
    {
        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit('unexpected-host.invalid', $identity['name']);
        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('HOST_IDENTITY_MISMATCH', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_refuses_unexpected_options_including_command_injection_shaped_values(): void
    {
        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name'], ['--shell', 'whoami']);
        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('UNSUPPORTED_OPTION', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_refuses_symlinked_git_head(): void
    {
        unlink($this->root.'/.git/HEAD');
        if (! @symlink($this->root.'/.git/refs/heads/main', $this->root.'/.git/HEAD')) {
            self::markTestSkipped('The current Windows filesystem does not permit creating a symlink without additional privileges.');
        }
        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name']);
        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('UNSAFE_OR_MISSING_GIT_METADATA', $result['stderr']);
    }

    public function test_m12_mode_fails_closed_without_reading_environment_overrides(): void
    {
        putenv('M12_DESTRUCTIVE_RETENTION_ENABLED=true');
        try {
            $process = proc_open([PHP_BINARY, $this->runner, '--mode', 'm12'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
        } finally {
            putenv('M12_DESTRUCTIVE_RETENTION_ENABLED');
        }

        $evidence = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(6, count($evidence['gates']));
        self::assertSame(false, $evidence['side_effects']['configuration_reads']);
        self::assertSame('UNKNOWN', $evidence['gates']['M12_DESTRUCTIVE_RETENTION_ENABLED']['effective_boolean']);
        self::assertStringNotContainsString('true', $stdout);
    }

    public function test_resolves_linked_worktree_git_pointer_without_invoking_git(): void
    {
        $gitDirectory = $this->root.'-common/worktrees/fixture';
        mkdir($gitDirectory.'/refs/heads', 0700, true);
        file_put_contents($gitDirectory.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($gitDirectory.'/refs/heads/main', str_repeat('b', 40)."\n");
        $this->removeTree($this->root.'/.git');
        file_put_contents($this->root.'/.git', 'gitdir: '.$gitDirectory."\n");
        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        $evidence = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('b', 40), $evidence['application']['git_head']);
        $this->removeTree($gitDirectory);
    }

    public function test_resolves_linked_worktree_branch_from_common_packed_refs(): void
    {
        $common = $this->root.'-common';
        $gitDirectory = $common.'/worktrees/fixture';
        mkdir($gitDirectory, 0700, true);
        file_put_contents($gitDirectory.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($gitDirectory.'/commondir', "../..\n");
        file_put_contents($common.'/packed-refs', "# pack-refs with: peeled fully-peeled\n".str_repeat('c', 40)." refs/heads/main\n");
        $this->removeTree($this->root.'/.git');
        file_put_contents($this->root.'/.git', 'gitdir: '.$gitDirectory."\n");

        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name']);
        self::assertSame(0, $result['exit'], $result['stderr']);
        $evidence = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('c', 40), $evidence['application']['git_head']);
    }

    public function test_rejects_symlinked_git_worktree_pointer_ancestor(): void
    {
        $common = $this->root.'-common';
        $gitDirectory = $common.'/worktrees/fixture';
        $alias = $this->root.'-git-alias';
        mkdir($gitDirectory, 0700, true);
        file_put_contents($gitDirectory.'/HEAD', "ref: refs/heads/main\n");
        mkdir($gitDirectory.'/refs/heads', 0700, true);
        file_put_contents($gitDirectory.'/refs/heads/main', str_repeat('d', 40)."\n");
        if (! @symlink($common, $alias)) {
            self::markTestSkipped('The current filesystem does not permit creating a symlink without additional privileges.');
        }
        $this->removeTree($this->root.'/.git');
        file_put_contents($this->root.'/.git', 'gitdir: '.$alias.'/worktrees/fixture'."\n");

        $identity = $this->currentPosixIdentity();
        $result = $this->runAudit(gethostname(), $identity['name']);
        self::assertSame(2, $result['exit']);
        self::assertSame('BLOCKED: GIT_METADATA_SYMLINK', trim($result['stderr']));
        self::assertSame('', $result['stdout']);
    }

    private function runAudit(string $host, string $account, array $extra = []): array
    {
        $arguments = [PHP_BINARY, $this->runner, '--mode', 'identity', '--checkout', $this->root, '--expected-host', $host, '--expected-account', $account, ...$extra];
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function currentPosixIdentity(): array
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            self::markTestSkipped('The standalone staging runner requires POSIX account metadata; its positive path is exercised in WSL/CI.');
        }
        $identity = posix_getpwuid(posix_geteuid());
        self::assertIsArray($identity);

        return $identity;
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) && ! is_link($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
