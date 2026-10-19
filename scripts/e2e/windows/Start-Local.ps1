param([string] $ExpectedCommit = $env:WP_CAREGRID_E2E_EXPECTED_COMMIT)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Identity = & (Join-Path $PSScriptRoot 'Assert-ApprovedConnectorBrowserWorktree.ps1') -ExpectedCommit $ExpectedCommit
$Repo = $Identity.Repository

& (Join-Path $PSScriptRoot 'Initialize-Local.ps1') -ExpectedCommit $ExpectedCommit
if ($LASTEXITCODE -ne 0) { throw 'Local E2E initialization failed.' }

$E2E = Join-Path $Repo 'storage\e2e'
$Php = Join-Path $E2E 'runtime\php-8.4.26\php.exe'
$Ini = Join-Path $E2E 'runtime\php-8.4.26\php.ini'
$Apache = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18'
$ApacheConfig = Join-Path $E2E 'apache\httpd.conf'
$ApachePid = Join-Path $E2E 'apache\httpd.pid'
$ServiceFile = Join-Path $E2E 'local-services.json'

foreach ($Port in @(8317, 8318)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) {
        throw "Port $Port is already in use; refusing to attach to another service."
    }
}
if (Test-Path $ServiceFile) { throw 'A prior local service record exists. Run Stop-Local.ps1 first.' }
if (Test-Path $ApachePid) { throw 'Apache PID file exists. Verify and stop the prior E2E server first.' }

# Ensure the child process cannot inherit usable provider or shared-service credentials.
$env:APP_ENV = 'e2e'
$env:APP_URL = 'http://127.0.0.1:8317'
$env:APP_KEY = ((Select-String -LiteralPath (Join-Path $Repo '.env.e2e') -Pattern '^APP_KEY=').Line -replace '^APP_KEY=', '')
$env:APP_DEBUG = 'false'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = 'storage/e2e/laravel.sqlite'
$env:DB_URL = ''
$env:STRIPE_KEY = ''
$env:STRIPE_SECRET = ''
$env:STRIPE_WEBHOOK_SECRET = ''
$env:STRIPE_ALLOW_LIVE_MODE = 'false'
$env:CONNECTOR_REQUIRE_HTTPS = 'false'
$env:SZAMLAZZ_PROVIDER_ENABLED = 'false'
$env:SZAMLAZZ_DRY_RUN = 'true'
$env:SZAMLAZZ_ISSUANCE_ENABLED = 'false'
$env:SZAMLAZZ_SYNTHETIC_TEST_ENABLED = 'false'
$env:CLOUDFLARE_API_TOKEN = ''
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'database'
$env:WP_CAREGRID_TEST_MYSQL = ''
$env:HTTP_PROXY = 'http://127.0.0.1:9'
$env:HTTPS_PROXY = 'http://127.0.0.1:9'
$env:ALL_PROXY = 'http://127.0.0.1:9'
$env:NO_PROXY = '127.0.0.1,localhost'
$env:http_proxy = 'http://127.0.0.1:9'
$env:https_proxy = 'http://127.0.0.1:9'
$env:all_proxy = 'http://127.0.0.1:9'
$env:no_proxy = '127.0.0.1,localhost'
if (Get-NetTCPConnection -State Listen -LocalPort 9 -ErrorAction SilentlyContinue) {
    throw 'Local egress sink port 9 is occupied; refusing to start.'
}

$AppArgs = "-c `"$Ini`" `"$(Join-Path $Repo 'artisan')`" serve --host=127.0.0.1 --port=8317 --env=e2e"
$AppProcess = Start-Process -FilePath $Php -ArgumentList $AppArgs -WorkingDirectory $Repo -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $E2E 'laravel-server.out.log') -RedirectStandardError (Join-Path $E2E 'laravel-server.err.log')
@{ laravel_pid = $AppProcess.Id; apache_pid = ''; repo = $Repo } | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
try {
    & (Join-Path $Apache 'bin\httpd.exe') -d $Apache -f $ApacheConfig -t
    if ($LASTEXITCODE -ne 0) { throw 'Isolated Apache configuration validation failed.' }
    $ApacheArgs = "-d `"$Apache`" -f `"$ApacheConfig`""
    $ApacheProcess = Start-Process -FilePath (Join-Path $Apache 'bin\httpd.exe') -ArgumentList $ApacheArgs -WindowStyle Hidden -PassThru
    Start-Sleep -Milliseconds 300
    if (Test-Path $ApachePid) {
        $Services = Get-Content -Raw -LiteralPath $ServiceFile | ConvertFrom-Json
        $Services.apache_pid = (Get-Content -Raw $ApachePid).Trim()
        $Services | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
    }

    $Deadline = (Get-Date).AddSeconds(40)
    do {
        Start-Sleep -Milliseconds 500
        $AppListener = Get-NetTCPConnection -State Listen -LocalPort 8317 -ErrorAction SilentlyContinue
        $WpListener = Get-NetTCPConnection -State Listen -LocalPort 8318 -ErrorAction SilentlyContinue
        if ($AppListener -and $WpListener) { break }
        if ((Get-Date) -gt $Deadline) { throw 'Local HTTP services did not become ready.' }
    } while ($true)

    $AppResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:8317/up' -UseBasicParsing -TimeoutSec 10 -SkipHttpErrorCheck
    if ($AppResponse.StatusCode -ne 200) { throw "Laravel /up returned $($AppResponse.StatusCode)." }
    $ApiResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:8317/api/v1/connector/jobs' -UseBasicParsing -TimeoutSec 10 -SkipHttpErrorCheck
    if ($ApiResponse.StatusCode -ne 401) { throw "Unauthenticated Connector API probe returned $($ApiResponse.StatusCode), expected 401." }
    $WpResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:8318/' -UseBasicParsing -TimeoutSec 15 -SkipHttpErrorCheck
    if ($WpResponse.StatusCode -ne 200) { throw "WordPress homepage returned $($WpResponse.StatusCode)." }
    $WpRestResponse = Invoke-WebRequest -Uri 'http://127.0.0.1:8318/wp-json/' -UseBasicParsing -TimeoutSec 15 -SkipHttpErrorCheck
    if ($WpRestResponse.StatusCode -ne 200) { throw "WordPress REST API returned $($WpRestResponse.StatusCode)." }

    $PluginCheck = Join-Path $E2E 'check-wordpress-plugin.php'
    @'
<?php
require getenv('WP_CAREGRID_E2E_SITE').'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$plugin = 'wp-caregrid-connector/wp-caregrid-connector.php';
if (!is_plugin_active($plugin)) { fwrite(STDERR, "Connector plugin is not active.\n"); exit(1); }
echo 'active '.get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin)['Version'].PHP_EOL;
'@ | Set-Content -LiteralPath $PluginCheck -Encoding ascii
    $env:WP_CAREGRID_E2E_SITE = Join-Path $E2E 'wordpress-site'
    $PluginStatus = (& $Php -c $Ini $PluginCheck).Trim()
    $ConnectorHeader = Join-Path $Repo 'wordpress\wp-caregrid-connector\wp-caregrid-connector.php'
    if (-not (Test-Path -LiteralPath $ConnectorHeader -PathType Leaf)) { throw 'The checked-out Connector plugin header is missing.' }
    $VersionLines = @(Select-String -LiteralPath $ConnectorHeader -Pattern '^\s*\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$')
    if ($VersionLines.Count -ne 1) { throw 'Could not establish one exact Connector version from the checked-out plugin header.' }
    $ExpectedConnectorVersion = $VersionLines[0].Matches[0].Groups[1].Value
    if ($LASTEXITCODE -ne 0 -or $PluginStatus -cne "active $ExpectedConnectorVersion") { throw "Connector plugin check failed: expected active $ExpectedConnectorVersion from source; observed $PluginStatus" }

    $LaravelDb = (Resolve-Path (Join-Path $E2E 'laravel.sqlite')).Path
    $WpDb = (Resolve-Path (Join-Path $E2E 'wordpress-site\database\wp.sqlite')).Path
    if ($LaravelDb -eq $WpDb -or -not $LaravelDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase) -or -not $WpDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Database isolation check failed.'
    }
    Write-Output 'Smoke test passed:'
    Write-Output '  Laravel /up: HTTP 200 on 127.0.0.1:8317'
    Write-Output '  Connector API auth gate: HTTP 401 on 127.0.0.1:8317'
    Write-Output "  WordPress: HTTP 200 on 127.0.0.1:8318; connector $PluginStatus"
    Write-Output '  WordPress REST API: HTTP 200'
    Write-Output '  Databases: distinct Laravel and WordPress SQLite files under storage/e2e'
    Write-Output 'No pairing request was made.'
} catch {
    & (Join-Path $PSScriptRoot 'Stop-Local.ps1')
    throw
}
