<?php

namespace Tests\Support;

use App\Backups\Storage\SftpStorage;
use Aws\S3\S3Client;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Real protocol servers for the storage provider tests: moto (an
 * S3-compatible API, started on demand) and OpenSSH's sshd for SFTP, either
 * one already running (CAREGRID_TEST_SFTP_* variables, as in CI) or started
 * on demand.
 *
 * Without the binaries the tests are skipped, except when
 * CAREGRID_TEST_REQUIRE_SERVICES=1 (CI): then a missing service fails the
 * run, so a broken service setup can never pass as "skipped".
 */
final class StorageServers
{
    public static function required(): bool
    {
        return getenv('CAREGRID_TEST_REQUIRE_SERVICES') === '1';
    }

    private static ?array $s3 = null;

    private static ?array $sftp = null;

    /** @var list<resource> */
    private static array $processes = [];

    public static function s3(): ?array
    {
        if (self::$s3 !== null) {
            return self::$s3;
        }

        $endpoint = self::configuredS3Endpoint();
        if ($endpoint === null) {
            $binary = (new ExecutableFinder)->find('moto_server');
            if ($binary === null) {
                return null;
            }

            $port = self::freePort();
            self::start([$binary, '-H', '127.0.0.1', '-p', (string) $port]);
            self::waitFor($port);
            $endpoint = "http://127.0.0.1:{$port}";
        }

        $config = [
            'driver' => 's3', 'endpoint' => $endpoint, 'region' => 'us-east-1', 'bucket' => 'caregrid-test-backups',
            'key' => 'test-access-key', 'secret' => 'test-secret-key-do-not-log', 'prefix' => 'backups', 'use_path_style_endpoint' => true,
            'server_side_encryption' => 'AES256', 'multipart_threshold' => 6 * 1024 * 1024, 'part_size' => 5 * 1024 * 1024,
        ];
        (new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $config['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => $config['key'], 'secret' => $config['secret']]]))
            ->createBucket(['Bucket' => $config['bucket']]);

        return self::$s3 = $config;
    }

    /**
     * An optional, explicit loopback endpoint lets isolated test containers
     * provide Moto without teaching the test process to contact external hosts.
     */
    private static function configuredS3Endpoint(): ?string
    {
        $endpoint = getenv('CAREGRID_TEST_S3_ENDPOINT');
        if ($endpoint === false || $endpoint === '') {
            return null;
        }
        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || ! isset($parts['port'])
            || $parts['port'] < 1
            || $parts['port'] > 65535
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['path']) && $parts['path'] !== '') {
            throw new \RuntimeException('CAREGRID_TEST_S3_ENDPOINT must be a plain HTTP loopback endpoint with an explicit port.');
        }

        return $endpoint;
    }

    public static function sftp(): ?array
    {
        if (self::$sftp !== null) {
            return self::$sftp;
        }

        // An sshd set up outside the tests (CI starts one as root with a dedicated user).
        if (getenv('CAREGRID_TEST_SFTP_HOST')) {
            return self::$sftp = [
                'driver' => 'sftp', 'host' => getenv('CAREGRID_TEST_SFTP_HOST'), 'port' => (int) (getenv('CAREGRID_TEST_SFTP_PORT') ?: 22),
                'username' => getenv('CAREGRID_TEST_SFTP_USER'), 'password' => null, 'private_key' => getenv('CAREGRID_TEST_SFTP_KEY'), 'passphrase' => null,
                'root' => rtrim((string) getenv('CAREGRID_TEST_SFTP_ROOT'), '/').'/backups-'.getmypid(), 'timeout' => 10,
                'host_fingerprint' => SftpStorage::fingerprint((string) file_get_contents((string) getenv('CAREGRID_TEST_SFTP_HOST_KEY'))),
            ];
        }

        $sshKeygen = (new ExecutableFinder)->find('ssh-keygen');
        if (! is_executable('/usr/sbin/sshd') || $sshKeygen === null || ! function_exists('posix_getuid') || posix_getuid() !== 0) {
            return null;
        }

        $dir = sys_get_temp_dir().'/caregrid-sftp-'.getmypid();
        @mkdir($dir.'/root', 0700, true);
        @mkdir('/run/sshd', 0755, true);
        foreach (['host', 'client'] as $key) {
            @unlink("{$dir}/{$key}");
            @unlink("{$dir}/{$key}.pub");
            $process = proc_open([$sshKeygen, '-q', '-t', 'ed25519', '-N', '', '-f', "{$dir}/{$key}"], [0 => ['file', self::nullDevice(), 'r'], 1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']], $pipes);
            if (! is_resource($process) || proc_close($process) !== 0 || ! is_file("{$dir}/{$key}.pub")) {
                throw new \RuntimeException('Could not create the isolated SFTP test key pair.');
            }
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
        $null = self::nullDevice();
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the isolated storage test service.');
        }
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

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
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
