<?php

declare(strict_types=1);


declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');

$checks = [];
$failures = [];
$check = static function (string $name, bool $passed) use (&$checks, &$failures): void {
    $checks[$name] = $passed;
    if (!$passed) {
        $failures[] = $name;
    }
};
$port4 = filter_var(getenv('FPM_WITNESS_PORT4'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$port6 = filter_var(getenv('FPM_WITNESS_PORT6'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
$witnessIPv4 = filter_var(getenv('FPM_WITNESS_IPV4'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

$check('real_fpm_sapi', PHP_SAPI === 'fpm-fcgi');
$check('exact_php_patch', PHP_VERSION === '8.5.12');
$check('worker_uid_unprivileged', function_exists('posix_geteuid') && posix_geteuid() === 33);
$requiredExtensions = [
    'bcmath', 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'mysqli', 'openssl',
    'pcntl', 'pdo_mysql', 'pdo_sqlite', 'posix', 'sodium', 'sqlite3', 'zip',
];
foreach ($requiredExtensions as $extension) {
    $check('extension_'.$extension, extension_loaded($extension));
}
$check('opcache_loaded_and_enabled', extension_loaded('Zend OPcache') && ini_get('opcache.enable') === '1');
$check('app_environment_testing', getenv('APP_ENV') === 'testing' && getenv('CAREGRID_DEPLOYMENT_ENV') === 'testing');
$check('marketing_profile_remains_restricted', getenv('MARKETING_RELEASE_PROFILE') === 'marketing_only');
$check('synthetic_app_key_present', is_string(getenv('APP_KEY')) && str_starts_with((string) getenv('APP_KEY'), 'base64:'));

$environment = getenv();
$forbiddenPrefixes = ['ACTIONS_', 'AWS_', 'AZURE_', 'DOCKER_', 'GITHUB_', 'RUNNER_', 'SSH_'];
$forbiddenNames = [
    'ACTIONS_ID_TOKEN_REQUEST_TOKEN', 'ACTIONS_RUNTIME_TOKEN', 'GITHUB_TOKEN',
    'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy',
    'SSH_AUTH_SOCK',
];
$environmentClean = is_array($environment);
if (is_array($environment)) {
    foreach (array_keys($environment) as $name) {
        if (in_array($name, $forbiddenNames, true)) {
            $environmentClean = false;
        }
        foreach ($forbiddenPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $environmentClean = false;
            }
        }
    }
}
$check('no_runner_credentials_or_proxy_environment', $environmentClean);
$check('no_docker_socket', !file_exists('/var/run/docker.sock') && !file_exists('/run/docker.sock'));

$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$check('database_queue_not_inline_sync', config('queue.default') === 'database');
$check('mail_sink_is_array', config('mail.default') === 'array');
$check('filesystem_sink_is_local', config('filesystems.default') === 'local');
$check('backup_storage_disabled', blank(config('backup.storage')));
$check('stripe_live_mode_disabled', config('services.stripe.allow_live_mode') === false);
$check('stripe_credentials_absent', collect(['key', 'secret', 'webhook_secret', 'payment_method_portal_configuration'])
    ->every(static fn (string $key): bool => blank(config('services.stripe.'.$key))));
$check('external_notification_credentials_absent', collect([
    config('services.postmark.key'),
    config('services.resend.key'),
    config('services.ses.key'),
    config('services.ses.secret'),
    config('services.slack.notifications.bot_user_oauth_token'),
    config('services.cloudflare.api_token'),
])->every(static fn ($credential): bool => blank($credential)));
$check('external_backup_destinations_disabled',
    blank(config('backup.storage'))
    && blank(config('backup.providers.s3.endpoint'))
    && blank(config('backup.providers.s3.bucket'))
    && blank(config('backup.providers.s3.key'))
    && blank(config('backup.providers.s3.secret'))
    && blank(config('backup.providers.sftp.host'))
    && blank(config('backup.providers.sftp.username'))
    && blank(config('backup.providers.sftp.password'))
    && blank(config('backup.providers.sftp.private_key')));
$check('cloud_backup_disk_disabled',
    config('filesystems.disks.platform_backups.driver') === 'local'
    && blank(config('filesystems.disks.platform_backups.key'))
    && blank(config('filesystems.disks.platform_backups.secret'))
    && blank(config('filesystems.disks.platform_backups.bucket')
        ?? config('filesystems.disks.platform_backups.s3_bucket')));

try {
    $dbResult = app('db')->connection()->select('SELECT 1 AS compatibility_probe');
    $check('synthetic_sqlite_connectivity', (int) ($dbResult[0]->compatibility_probe ?? 0) === 1);
    $jobsCount = app('db')->table('jobs')->count();
    $check('queue_has_no_work_or_consumers', $jobsCount === 0);
} catch (Throwable) {
    $jobsCount = null;
    $check('synthetic_sqlite_connectivity', false);
    $check('queue_has_no_work_or_consumers', false);
}

$canConnect = static function (string $target, float $timeout = 0.5): bool {
    $errorNumber = 0;
    $errorText = '';
    $socket = @stream_socket_client($target, $errorNumber, $errorText, $timeout, STREAM_CLIENT_CONNECT);
    if (is_resource($socket)) {
        fclose($socket);
        return true;
    }
    return false;
};
$check('sandbox_loopback_http_witness_reachable', $canConnect('tcp://127.0.0.1:8080'));
$interfaces = array_values(array_diff(@scandir('/sys/class/net') ?: [], ['.', '..', 'lo']));
$check('no_non_loopback_network_interface', $interfaces === []);

if ($port4 !== false && $port6 !== false && $witnessIPv4 !== false) {
    $runnerWitness = 'tcp://'.$witnessIPv4.':'.$port4;
    $check('ipv4_runner_host_witness_denied', !$canConnect($runnerWitness));
    $check('ipv6_external_connection_denied', !$canConnect('tcp://[2606:4700:4700::1111]:53'));
    $check('ipv6_external_route_absent', $interfaces === []);
    foreach (['http' => 'http://example.com/', 'https' => 'https://example.com/'] as $scheme => $url) {
        $proxy = curl_init($url);
        curl_setopt_array($proxy, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => 'http://'.$witnessIPv4.':'.$port4,
            CURLOPT_NOPROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => 1000,
        ]);
        $proxyResult = curl_exec($proxy);
        $proxyErrno = curl_errno($proxy);
        $check('explicit_'.$scheme.'_proxy_escape_denied',
            $proxyResult === false && $proxyErrno === CURLE_COULDNT_CONNECT);
        curl_close($proxy);
    }
} else {
    $check('ipv4_runner_host_witness_denied', false);
    $check('ipv6_external_connection_denied', false);
    $check('ipv6_external_route_absent', false);
    $check('explicit_http_proxy_escape_denied', false);
    $check('explicit_https_proxy_escape_denied', false);
}
$resolved = @gethostbyname('example.com');
$check('external_dns_resolution_denied', $resolved === 'example.com');
$check('metadata_endpoint_denied', !$canConnect('tcp://169.254.169.254:80'));
$check('runner_host_service_denied', $port4 !== false && $witnessIPv4 !== false
    && !$canConnect('tcp://host.docker.internal:'.$port4));
$check('staging_endpoint_denied', $port4 !== false && $witnessIPv4 !== false
    && !$canConnect('tcp://staging.wpcaregrid.com:'.$port4));
$check('production_endpoint_denied', $port4 !== false && $witnessIPv4 !== false
    && !$canConnect('tcp://wpcaregrid.com:'.$port4));

http_response_code($failures === [] ? 200 : 500);
echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'worker_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
    'extensions' => array_values(array_intersect(get_loaded_extensions(), $requiredExtensions)),
    'opcache_enabled' => ini_get('opcache.enable') === '1',
    'jobs_rows_after_smoke' => $jobsCount,
    'checks' => $checks,
    'failures' => $failures,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
