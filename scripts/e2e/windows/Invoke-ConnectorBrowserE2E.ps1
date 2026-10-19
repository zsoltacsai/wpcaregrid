param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string] $ExpectedCommit
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Identity = & (Join-Path $PSScriptRoot 'Assert-ApprovedConnectorBrowserWorktree.ps1') -ExpectedCommit $ExpectedCommit -RequireExactCommit
$Repo = $Identity.Repository
$Head = $Identity.Head
Write-Output "Preflight worktree: $Repo"
Write-Output "Preflight branch: $($Identity.Branch)"
Write-Output "Preflight HEAD: $Head"
Write-Output "Preflight identity: $($Identity.Identity)"
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

foreach ($path in @($WpPhp, $Chrome, $PlaywrightCli, $Fixture, $WpHelper)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required local E2E file is missing: $path" }
}
foreach ($port in @(8317, 8318)) {
    if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) { throw "Port $port is already occupied; refusing to attach to another service." }
}
if (Test-Path -LiteralPath $ServiceFile) { throw 'A local service record exists while the required ports are stopped.' }
if (Test-Path -LiteralPath $StatePath) { throw 'A browser E2E fixture exists; inspect or clean it before another run.' }

function Invoke-BrowserFixture([string] $Mode, [string[]] $Arguments = @()) {
    & $Php -c $Ini $Fixture $Mode @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Browser fixture operation '$Mode' failed." }
}

function Invoke-BrowserWordPress([string] $Mode, [string[]] $Arguments = @()) {
    & $WpPhp -c $WpIni $WpHelper $Mode @Arguments
    if ($LASTEXITCODE -ne 0) { throw "WordPress browser fixture operation '$Mode' failed." }
}

try {
    $env:WP_CAREGRID_E2E_EXPECTED_COMMIT = $ExpectedCommit
    & (Join-Path $PSScriptRoot 'Initialize-Local.ps1') -ExpectedCommit $ExpectedCommit
    if ($LASTEXITCODE -ne 0) { throw 'Local E2E initialization failed.' }

    foreach ($path in @($Php, $Ini, $WpIni, $CredentialsPath, (Join-Path $E2E 'laravel.sqlite'), (Join-Path $E2E 'wordpress-site\database\wp.sqlite'), (Join-Path $E2E 'wordpress-site\wp-config.php'))) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Initialization did not create a required isolated E2E file: $path" }
    }
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

    & (Join-Path $PSScriptRoot 'Start-Local.ps1') -ExpectedCommit $ExpectedCommit
    if ($LASTEXITCODE -ne 0) { throw 'Local E2E services did not start.' }
    $ServicesStartedHere = $true

    Invoke-BrowserWordPress 'reset'
    Invoke-BrowserFixture 'browser-seed'
    $SeededHere = $true

    # The normal localization browser suite uses this disposable account; do
    # not source credentials from the developer's environment or a shared DB.
    $FixtureState = Get-Content -Raw -LiteralPath $StatePath | ConvertFrom-Json
    $env:E2E_BASE_URL = 'http://127.0.0.1:8317'
    $env:E2E_CUSTOMER_EMAIL = [string] $FixtureState.accounts.a.email
    $env:E2E_CUSTOMER_PASSWORD = [string] $FixtureState.accounts.a.password

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
        try { & (Join-Path $PSScriptRoot 'Stop-Local.ps1') -ExpectedCommit $ExpectedCommit } catch { Write-Warning 'Could not stop the isolated local services; verify ports 8317 and 8318.' }
    }
    foreach ($name in @('CONNECTOR_BROWSER_STATE', 'WP_CAREGRID_E2E_CREDENTIALS', 'CONNECTOR_E2E_PHP', 'CONNECTOR_E2E_PHP_INI', 'CONNECTOR_E2E_WP_PHP', 'CONNECTOR_E2E_WP_PHP_INI', 'CONNECTOR_E2E_RESTART_SCRIPT', 'WP_CAREGRID_E2E_EXPECTED_COMMIT', 'E2E_BASE_URL', 'E2E_CUSTOMER_EMAIL', 'E2E_CUSTOMER_PASSWORD')) {
        Remove-Item "Env:\$name" -ErrorAction SilentlyContinue
    }
}

if ($PlaywrightExit -ne 0) { exit $PlaywrightExit }
Write-Output 'Local browser E2E suites passed; Connector pairing, localization/authentication and security-boundary specs used only loopback services and synthetic accounts.'
