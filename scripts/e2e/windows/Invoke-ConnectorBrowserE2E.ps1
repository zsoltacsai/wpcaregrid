Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$ExpectedRepo = 'D:\-=AI=-\-=Git=-\wpcaregrid-connector'
if ($Repo -ne $ExpectedRepo) { throw 'Refusing to run outside the Connector E2E worktree.' }
if ((git -C $Repo branch --show-current).Trim() -ne 'feature/connector-e2e') { throw 'Refusing to run on another branch.' }
$Head = (git -C $Repo rev-parse HEAD).Trim()
Write-Output "Preflight worktree: $Repo"
Write-Output "Preflight branch: feature/connector-e2e"
Write-Output "Preflight HEAD: $Head"
git -C $Repo status --short --untracked-files=all

$E2E = Join-Path $Repo 'storage\e2e'
$Php = Join-Path $E2E 'runtime\php-8.4.26\php.exe'
$Ini = Join-Path $E2E 'runtime\php-8.4.26\php.ini'
$WpPhp = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$WpIni = Join-Path $E2E 'apache\php83\php.ini'
$Chrome = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
$PlaywrightCli = Join-Path $Repo 'node_modules\.bin\playwright.cmd'
$Fixture = Join-Path $Repo 'scripts\e2e\php\connector-browser-fixture.php'
$WpHelper = Join-Path $Repo 'scripts\e2e\php\connector-browser-wordpress.php'
$StatePath = Join-Path $E2E 'connector-browser-state.json'
$CredentialsPath = Join-Path $E2E 'credentials.json'
$ServiceFile = Join-Path $E2E 'local-services.json'
$ServicesStartedHere = $false
$SeededHere = $false
$PlaywrightExit = 1

foreach ($path in @($Php, $Ini, $WpPhp, $WpIni, $Chrome, $PlaywrightCli, $Fixture, $WpHelper, $CredentialsPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required local E2E file is missing: $path" }
}
foreach ($port in @(8317, 8318)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) { throw "Port $port is already occupied; refusing to attach to another service." }
}
if (Test-Path -LiteralPath $ServiceFile) { throw 'A local service record exists while the required ports are stopped.' }
if (Test-Path -LiteralPath $StatePath) { throw 'A browser E2E fixture exists; inspect or clean it before another run.' }

$LaravelPhpVersion = (& $Php -v | Select-Object -First 1)
$WordPressPhpVersion = (& $WpPhp -c $WpIni -r 'echo PHP_VERSION;')
if ($LaravelPhpVersion -notmatch '8\.4\.26' -or $WordPressPhpVersion -ne '8.3.33') { throw 'The local PHP runtime versions do not match the E2E contract.' }
$laravelDb = (Resolve-Path (Join-Path $E2E 'laravel.sqlite')).Path
$wpDb = (Resolve-Path (Join-Path $E2E 'wordpress-site\database\wp.sqlite')).Path
if ($laravelDb -eq $wpDb -or -not $laravelDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase) -or -not $wpDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The local Laravel and WordPress SQLite databases are not isolated.'
}
if (-not (Get-Content -Raw -LiteralPath (Join-Path $E2E 'wordpress-site\wp-config.php')).Contains("define('WP_HTTP_BLOCK_EXTERNAL', true)")) {
    throw 'The WordPress outbound HTTP guard is missing.'
}
Write-Output 'Preflight runtimes, separate SQLite files, loopback-only ports, and WordPress egress guard verified.'

function Invoke-BrowserFixture([string] $Mode, [string[]] $Arguments = @()) {
    & $Php -c $Ini $Fixture $Mode @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Browser fixture operation '$Mode' failed." }
}

function Invoke-BrowserWordPress([string] $Mode, [string[]] $Arguments = @()) {
    & $WpPhp -c $WpIni $WpHelper $Mode @Arguments
    if ($LASTEXITCODE -ne 0) { throw "WordPress browser fixture operation '$Mode' failed." }
}

try {
    & (Join-Path $PSScriptRoot 'Initialize-Local.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Local E2E initialization failed.' }
    & (Join-Path $PSScriptRoot 'Start-Local.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Local E2E services did not start.' }
    $ServicesStartedHere = $true

    Invoke-BrowserWordPress 'reset'
    Invoke-BrowserFixture 'browser-seed'
    $SeededHere = $true

    $env:CONNECTOR_BROWSER_STATE = $StatePath
    $env:WP_CAREGRID_E2E_CREDENTIALS = $CredentialsPath
    $env:CONNECTOR_E2E_PHP = $Php
    $env:CONNECTOR_E2E_PHP_INI = $Ini
    $env:CONNECTOR_E2E_WP_PHP = $WpPhp
    $env:CONNECTOR_E2E_WP_PHP_INI = $WpIni
    $env:CONNECTOR_E2E_RESTART_SCRIPT = Join-Path $PSScriptRoot 'Restart-LocalService.ps1'

    $PlaywrightConfig = Join-Path $Repo 'playwright.connector-e2e.config.ts'
    & $PlaywrightCli test "--config=$PlaywrightConfig"
    $PlaywrightExit = $LASTEXITCODE
    if ($PlaywrightExit -ne 0) { throw 'One or more local browser pairing/recovery scenarios failed.' }
} finally {
    if ($ServicesStartedHere -and (Test-Path -LiteralPath $StatePath)) {
        try { Invoke-BrowserWordPress 'cleanup' } catch { Write-Warning 'WordPress browser fixture cleanup failed; local service state was preserved for inspection.' }
        try { Invoke-BrowserFixture 'browser-cleanup'; $SeededHere = $false } catch { Write-Warning 'Laravel browser fixture cleanup failed; its ignored state file remains for inspection.' }
    }
    if ($ServicesStartedHere -and (Test-Path -LiteralPath $ServiceFile)) {
        try { & (Join-Path $PSScriptRoot 'Stop-Local.ps1') } catch { Write-Warning 'Could not stop the isolated local services; verify ports 8317 and 8318.' }
    }
    foreach ($name in @('CONNECTOR_BROWSER_STATE', 'WP_CAREGRID_E2E_CREDENTIALS', 'CONNECTOR_E2E_PHP', 'CONNECTOR_E2E_PHP_INI', 'CONNECTOR_E2E_WP_PHP', 'CONNECTOR_E2E_WP_PHP_INI', 'CONNECTOR_E2E_RESTART_SCRIPT')) {
        Remove-Item "Env:\$name" -ErrorAction SilentlyContinue
    }
}

if ($PlaywrightExit -ne 0) { exit $PlaywrightExit }
Write-Output 'Connector browser E2E lifecycle passed; only local synthetic accounts and credentials were used.'
