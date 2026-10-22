<?php

declare(strict_types=1);

/**
 * Read-only parser for Laravel's generated static configuration cache.
 * This script deliberately does not include, require, evaluate, or bootstrap
 * the cached PHP file.
 */
final class StaticLaravelConfigCacheParser
{
    private array $tokens = [];

    private int $position = 0;

    private int $depth = 0;

    public function parse(string $source): array
    {
        $this->tokens = [];
        $this->position = 0;
        $this->depth = 0;

        if (strlen($source) > 1_048_576) {
            throw new RuntimeException('CACHE_TOO_LARGE');
        }

        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (Throwable) {
            throw new RuntimeException('CACHE_FORMAT_INVALID');
        }

        if (count($rawTokens) > 100_000) {
            throw new RuntimeException('CACHE_TOO_COMPLEX');
        }

        foreach ($rawTokens as $token) {
            if (is_array($token)) {
                [$id, $value] = $token;

                if ($id === T_WHITESPACE) {
                    continue;
                }

                if (in_array($id, [
                    T_OPEN_TAG,
                    T_RETURN,
                    T_ARRAY,
                    T_CONSTANT_ENCAPSED_STRING,
                    T_LNUMBER,
                    T_DNUMBER,
                    T_DOUBLE_ARROW,
                    T_STRING,
                ], true)) {
                    $this->tokens[] = [(string) $id, $value];

                    continue;
                }

                throw new RuntimeException('CACHE_FORMAT_INVALID');
            }

            if (in_array($token, ['(', ')', ',', ';', '-'], true)) {
                $this->tokens[] = ['CHAR', $token];

                continue;
            }

            throw new RuntimeException('CACHE_FORMAT_INVALID');
        }

        $this->expect((string) T_OPEN_TAG);
        $this->expect((string) T_RETURN);
        $value = $this->parseValue();
        $this->expect('CHAR', ';');

        if ($this->position !== count($this->tokens) || ! is_array($value)) {
            throw new RuntimeException('CACHE_FORMAT_INVALID');
        }

        return $value;
    }

    private function parseValue(): mixed
    {
        if (++$this->depth > 64) {
            throw new RuntimeException('CACHE_TOO_DEEP');
        }

        try {
            $token = $this->current();

            if ($token === null) {
                throw new RuntimeException('CACHE_FORMAT_INVALID');
            }

            if ($token[0] === (string) T_ARRAY) {
                $this->position++;
                $this->expect('CHAR', '(');
                $array = [];

                if (($this->current()[1] ?? null) === ')') {
                    $this->position++;

                    return $array;
                }

                while (true) {
                    if (count($array) >= 100_000) {
                        throw new RuntimeException('CACHE_TOO_COMPLEX');
                    }

                    $key = $this->parseValue();

                    if (! is_string($key) && ! is_int($key)) {
                        throw new RuntimeException('CACHE_FORMAT_INVALID');
                    }

                    $this->expect((string) T_DOUBLE_ARROW);
                    $value = $this->parseValue();

                    if (array_key_exists($key, $array)) {
                        throw new RuntimeException('CACHE_FORMAT_INVALID');
                    }

                    $array[$key] = $value;
                    $next = $this->current();

                    if ($next === ['CHAR', ',']) {
                        $this->position++;

                        if (($this->current()[1] ?? null) === ')') {
                            $this->position++;

                            break;
                        }

                        continue;
                    }

                    $this->expect('CHAR', ')');

                    break;
                }

                return $array;
            }

            if ($token[0] === (string) T_CONSTANT_ENCAPSED_STRING) {
                $this->position++;

                return $this->parseSingleQuotedString($token[1]);
            }

            if ($token[0] === (string) T_STRING) {
                $this->position++;

                return match (strtolower($token[1])) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => throw new RuntimeException('CACHE_FORMAT_INVALID'),
                };
            }

            $negative = false;

            if ($token === ['CHAR', '-']) {
                $this->position++;
                $negative = true;
                $token = $this->current();
            }

            if ($token !== null && $token[0] === (string) T_LNUMBER) {
                if (! preg_match('/^(0|[1-9][0-9]*)$/D', $token[1])) {
                    throw new RuntimeException('CACHE_FORMAT_INVALID');
                }

                $this->position++;
                $number = (int) $token[1];

                if ((string) $number !== $token[1]) {
                    throw new RuntimeException('CACHE_FORMAT_INVALID');
                }

                return $negative ? -$number : $number;
            }

            if ($token !== null && $token[0] === (string) T_DNUMBER && is_numeric($token[1])) {
                $this->position++;
                $number = (float) $token[1];

                if (! is_finite($number)) {
                    throw new RuntimeException('CACHE_FORMAT_INVALID');
                }

                return $negative ? -$number : $number;
            }

            throw new RuntimeException('CACHE_FORMAT_INVALID');
        } finally {
            $this->depth--;
        }
    }

    private function parseSingleQuotedString(string $token): string
    {
        if (strlen($token) < 2 || $token[0] !== "'" || substr($token, -1) !== "'") {
            throw new RuntimeException('CACHE_FORMAT_INVALID');
        }

        $value = '';

        for ($index = 1, $end = strlen($token) - 1; $index < $end; $index++) {
            if ($token[$index] === '\\') {
                $index++;

                if ($index >= $end || ! in_array($token[$index], ['\\', "'"], true)) {
                    throw new RuntimeException('CACHE_FORMAT_INVALID');
                }
            }

            $value .= $token[$index];
        }

        return $value;
    }

    private function current(): ?array
    {
        return $this->tokens[$this->position] ?? null;
    }

    private function expect(string $id, ?string $value = null): string
    {
        $token = $this->current();

        if ($token === null || $token[0] !== $id || ($value !== null && $token[1] !== $value)) {
            throw new RuntimeException('CACHE_FORMAT_INVALID');
        }

        $this->position++;

        return $token[1];
    }
}

function m12CacheAclMatches(int $mode, string $output): bool
{
    $entries = array_values(array_filter(preg_split('/\r?\n/', rtrim($output, "\r\n")) ?: []));
    $ownerOnly = ['user::rw-', 'group::---', 'other::---'];
    $phpFpmRead = [
        'user::rw-',
        'user:www-data:r--',
        'group::---',
        'mask::r--',
        'other::---',
    ];
    $permissions = $mode & 0777;

    return ($permissions === 0600 && $entries === $ownerOnly)
        || ($permissions === 0640 && $entries === $phpFpmRead);
}

function m12ReadAclForOpenFile($handle): ?string
{
    if (! function_exists('proc_open')) {
        return null;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
        3 => $handle,
    ];
    $process = @proc_open(
        ['/usr/bin/getfacl', '-cp', '--', '/proc/self/fd/3'],
        $descriptors,
        $pipes,
        '/',
        ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin'],
        ['bypass_shell' => true],
    );

    if (! is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 513);
    $error = stream_get_contents($pipes[2], 129);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if (! is_string($output) || strlen($output) > 512 || $error !== '' || $status !== 0) {
        return null;
    }

    return $output;
}

function m12ReadCache(): never
{
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ini_set('memory_limit', '96M');
    set_time_limit(20);

    $cachePath = '/var/www/wpcaregrid/bootstrap/cache/config.php';
    $expectedDirectories = [
        '/var',
        '/var/www',
        '/var/www/wpcaregrid',
        '/var/www/wpcaregrid/bootstrap',
        '/var/www/wpcaregrid/bootstrap/cache',
    ];

    if (! function_exists('posix_getpwnam') || ! function_exists('posix_geteuid')) {
        m12Fail('OWNER_CHECK_UNAVAILABLE');
    }

    $deploy = posix_getpwnam('deploy');

    if (! is_array($deploy) || posix_geteuid() !== 0) {
        m12Fail('HELPER_IDENTITY_INVALID');
    }

    foreach ($expectedDirectories as $directory) {
        $directoryStat = @lstat($directory);

        if (! is_array($directoryStat) || (($directoryStat['mode'] & 0170000) !== 0040000)) {
            m12Fail('CACHE_PATH_UNSAFE');
        }
    }

    if (realpath($cachePath) !== $cachePath) {
        m12Fail('CACHE_PATH_UNSAFE');
    }

    $pathStat = @lstat($cachePath);

    if (! is_array($pathStat)
        || (($pathStat['mode'] & 0170000) !== 0100000)
        || $pathStat['uid'] !== $deploy['uid']
        || (($pathStat['gid'] ?? null) !== $deploy['gid'])
        || (($pathStat['mode'] & 0007) !== 0)
        || ! in_array($pathStat['mode'] & 0777, [0600, 0640], true)
        || (($pathStat['mode'] & 0400) === 0)
        || $pathStat['size'] > 1_048_576) {
        m12Fail('CACHE_METADATA_INVALID');
    }

    $handle = @fopen($cachePath, 'rb');

    if (! is_resource($handle)) {
        m12Fail('CACHE_READ_FAILED');
    }

    $openedStat = fstat($handle);
    $pathStatAfterOpen = @lstat($cachePath);

    if (! is_array($openedStat)
        || ! is_array($pathStatAfterOpen)
        || (($pathStatAfterOpen['mode'] & 0170000) !== 0100000)
        || $pathStatAfterOpen['dev'] !== $openedStat['dev']
        || $pathStatAfterOpen['ino'] !== $openedStat['ino']
        || $openedStat['dev'] !== $pathStat['dev']
        || $openedStat['ino'] !== $pathStat['ino']
        || (($openedStat['mode'] & 0170000) !== 0100000)
        || $openedStat['uid'] !== $deploy['uid']
        || $openedStat['gid'] !== $deploy['gid']) {
        fclose($handle);
        m12Fail('CACHE_PATH_RACE');
    }

    $acl = m12ReadAclForOpenFile($handle);

    if (! is_string($acl) || ! m12CacheAclMatches($openedStat['mode'], $acl)) {
        fclose($handle);
        m12Fail('CACHE_METADATA_INVALID');
    }

    $source = stream_get_contents($handle, 1_048_577);
    fclose($handle);

    if (! is_string($source) || strlen($source) > 1_048_576) {
        m12Fail('CACHE_TOO_LARGE');
    }

    try {
        $configuration = (new StaticLaravelConfigCacheParser)->parse($source);
    } catch (Throwable) {
        m12Fail('CACHE_FORMAT_INVALID');
    }

    foreach (m12FormatValues($configuration) as $line) {
        echo $line."\n";
    }

    exit(0);
}

function m12FormatValues(array $configuration): array
{
    $privacy = is_array($configuration['privacy'] ?? null) ? $configuration['privacy'] : [];
    $app = is_array($configuration['app'] ?? null) ? $configuration['app'] : [];
    $security = is_array($configuration['security'] ?? null) ? $configuration['security'] : [];
    $incidents = is_array($security['incidents'] ?? null) ? $security['incidents'] : [];
    $lines = [];
    $privacyKeys = [
        'destructive_retention_enabled',
        'profile_anonymization_enabled',
        'backup_deletion_enabled',
        'provider_deletion_enabled',
        'bulk_retention_execution_enabled',
        'synthetic_profile_anonymization_enabled',
    ];

    foreach ($privacyKeys as $key) {
        $value = $privacy[$key] ?? null;
        $status = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : 'UNKNOWN';
        $lines[] = "M12|{$key}|{$status}";
    }

    foreach (['auto_open', 'auto_backup'] as $key) {
        $value = $incidents[$key] ?? null;
        $status = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : 'UNKNOWN';
        $lines[] = "SECURITY|{$key}|{$status}";
    }

    foreach ([
        'deployment_environment' => 'staging',
        'deployment_hostname' => 'stg1.wpcaregrid.com',
        'url' => 'https://staging.wpcaregrid.com',
    ] as $key => $expected) {
        $value = $app[$key] ?? null;
        $status = ! is_string($value) ? 'UNKNOWN' : ($value === $expected ? $expected : 'MISMATCH');
        $lines[] = "IDENTITY|{$key}|{$status}";
    }

    return $lines;
}

function m12Fail(string $code): never
{
    $allowedCodes = [
        'OWNER_CHECK_UNAVAILABLE',
        'HELPER_IDENTITY_INVALID',
        'CACHE_PATH_UNSAFE',
        'CACHE_METADATA_INVALID',
        'CACHE_READ_FAILED',
        'CACHE_PATH_RACE',
        'CACHE_TOO_LARGE',
        'CACHE_FORMAT_INVALID',
    ];
    $safeCode = in_array($code, $allowedCodes, true) ? $code : 'READBACK_FAILED';
    echo "DIAGNOSTIC_STATUS|UNKNOWN|{$safeCode}\n";
    exit(1);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    m12ReadCache();
}
