<?php

namespace Tests\Support;

use App\Backups\Storage\SftpStorage;
use Aws\S3\S3Client;

/**
 * Real protocol servers for the storage provider tests, started on demand:
 * moto (an S3-compatible API) and OpenSSH's sshd (SFTP). Tests are skipped
 * when the binaries are not installed.
 */
final class StorageServers
{
    private static ?array $s3 = null;

    private static ?array $sftp = null;

    /** @var list<resource> */
    private static array $processes = [];

    public static function s3(): ?array
    {
        if (self::$s3 !== null) {
            return self::$s3;
        }

        $binary = trim((string) shell_exec('command -v moto_server'));
        if ($binary === '') {
            return null;
        }

        $port = self::freePort();
        self::start([$binary, '-H', '127.0.0.1', '-p', (string) $port]);
        self::waitFor($port);

        $config = [
            'driver' => 's3', 'endpoint' => "http://127.0.0.1:{$port}", 'region' => 'us-east-1', 'bucket' => 'caregrid-test-backups',
            'key' => 'test-access-key', 'secret' => 'test-secret-key-do-not-log', 'prefix' => 'backups', 'use_path_style_endpoint' => true,
            'server_side_encryption' => 'AES256', 'multipart_threshold' => 6 * 1024 * 1024, 'part_size' => 5 * 1024 * 1024,
        ];
        (new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $config['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => $config['key'], 'secret' => $config['secret']]]))
            ->createBucket(['Bucket' => $config['bucket']]);

        return self::$s3 = $config;
    }

    public static function sftp(): ?array
    {
        if (self::$sftp !== null) {
            return self::$sftp;
        }

        if (! is_executable('/usr/sbin/sshd') || trim((string) shell_exec('command -v ssh-keygen')) === '' || posix_getuid() !== 0) {
            return null;
        }

        $dir = sys_get_temp_dir().'/caregrid-sftp-'.getmypid();
        @mkdir($dir.'/root', 0700, true);
        @mkdir('/run/sshd', 0755, true);
        foreach (['host', 'client'] as $key) {
            @unlink("{$dir}/{$key}");
            @unlink("{$dir}/{$key}.pub");
            shell_exec('ssh-keygen -q -t ed25519 -N "" -f '.escapeshellarg("{$dir}/{$key}"));
        }

        $port = self::freePort();
        file_put_contents("{$dir}/sshd_config", implode("\n", [
            "Port {$port}", 'ListenAddress 127.0.0.1', "HostKey {$dir}/host", "AuthorizedKeysFile {$dir}/client.pub",
            'PasswordAuthentication no', 'PubkeyAuthentication yes', 'StrictModes no', 'Subsystem sftp internal-sftp', "PidFile {$dir}/sshd.pid",
        ])."\n");
        self::start(['/usr/sbin/sshd', '-D', '-f', "{$dir}/sshd_config", '-E', "{$dir}/sshd.log"]);
        self::waitFor($port);

        return self::$sftp = [
            'driver' => 'sftp', 'host' => '127.0.0.1', 'port' => $port, 'username' => 'root', 'password' => null,
            'private_key' => "{$dir}/client", 'passphrase' => null, 'root' => "{$dir}/root/backups", 'timeout' => 10,
            'host_fingerprint' => SftpStorage::fingerprint((string) file_get_contents("{$dir}/host.pub")),
        ];
    }

    private static function start(array $command): void
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::$processes[] = $process;

        if (count(self::$processes) === 1) {
            register_shutdown_function(function () {
                foreach (self::$processes as $process) {
                    proc_terminate($process);
                }
            });
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    private static function waitFor(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($connection) {
                fclose($connection);
                usleep(200000);

                return;
            }
            usleep(100000);
        }

        throw new \RuntimeException("Test server on port {$port} did not start.");
    }
}
