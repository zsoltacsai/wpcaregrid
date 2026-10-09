param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Laravel', 'WordPress')]
    [string] $Service,
    [ValidateSet('stop', 'start', 'restart')]
    [string] $Action = 'restart'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$ExpectedRepo = 'D:\-=AI=-\-=Git=-\wpcaregrid-connector'
if ($Repo -ne $ExpectedRepo) { throw 'Refusing to restart a service outside the Connector E2E worktree.' }
if ((git -C $Repo branch --show-current).Trim() -ne 'feature/connector-e2e') { throw 'Refusing to restart services on another branch.' }

$E2E = Join-Path $Repo 'storage\e2e'
$ServiceFile = Join-Path $E2E 'local-services.json'
if (-not (Test-Path -LiteralPath $ServiceFile -PathType Leaf)) { throw 'The isolated local service record is missing.' }
$Services = Get-Content -Raw -LiteralPath $ServiceFile | ConvertFrom-Json
if ([IO.Path]::GetFullPath($Services.repo) -ne $Repo) { throw 'The service record points outside this worktree.' }

$Php = Join-Path $E2E 'runtime\php-8.4.26\php.exe'
$Ini = Join-Path $E2E 'runtime\php-8.4.26\php.ini'
$Apache = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18'
$Httpd = Join-Path $Apache 'bin\httpd.exe'
$ApacheConfig = Join-Path $E2E 'apache\httpd.conf'
$ApachePid = Join-Path $E2E 'apache\httpd.pid'

function Get-LoopbackListener([int] $Port) {
    return Get-NetTCPConnection -State Listen -LocalAddress '127.0.0.1' -LocalPort $Port -ErrorAction SilentlyContinue | Select-Object -First 1
}

function Wait-Port([int] $Port, [bool] $Listening) {
    $deadline = (Get-Date).AddSeconds(20)
    do {
        $listener = Get-LoopbackListener $Port
        if (([bool]$listener) -eq $Listening) { return $listener }
        Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)
    throw "Loopback port $Port did not reach the expected state."
}

function Stop-Laravel {
    $listener = Get-LoopbackListener 8317
    $parent = $null
    if ([int]$Services.laravel_pid -gt 0) {
        $parent = Get-CimInstance Win32_Process -Filter "ProcessId=$([int]$Services.laravel_pid)" -ErrorAction SilentlyContinue
        if ($parent) {
            if ($parent.ExecutablePath -ne $Php -or -not $parent.CommandLine -or $parent.CommandLine -notmatch 'artisan"? serve' -or $parent.CommandLine -notmatch '--port=8317') {
                throw 'Recorded Laravel parent is not the isolated artisan serve process; refusing to stop it.'
            }
        }
    }
    if ($listener) {
        $process = Get-Process -Id $listener.OwningProcess -ErrorAction Stop
        if ($process.Path -ne $Php) { throw 'Port 8317 is not owned by the isolated PHP runtime.' }
        $listenerProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$($process.Id)" -ErrorAction Stop
        $launcher = Get-CimInstance Win32_Process -Filter "ProcessId=$($listenerProcess.ParentProcessId)" -ErrorAction Stop
        if (-not $listenerProcess.CommandLine -or $listenerProcess.CommandLine -notmatch '-S 127\.0\.0\.1:8317' `
            -or [IO.Path]::GetFullPath($listenerProcess.ExecutablePath) -ne $Php `
            -or $launcher.Name -ne 'cmd.exe' -or [int]$launcher.ParentProcessId -ne [int]$Services.laravel_pid `
            -or -not $launcher.CommandLine -or -not $launcher.CommandLine.Contains($Php) `
            -or $launcher.CommandLine -notmatch '-S 127\.0\.0\.1:8317') {
            throw 'Port 8317 listener is not a child of the isolated artisan serve process; refusing to stop it.'
        }
        if ($parent) { Stop-Process -Id $parent.ProcessId -Force }
        foreach ($pidToStop in @($process.Id, [int]$launcher.ProcessId)) {
            if (Get-Process -Id $pidToStop -ErrorAction SilentlyContinue) { Stop-Process -Id $pidToStop -Force }
        }
        Wait-Port 8317 $false | Out-Null
    } elseif ($parent) {
        Stop-Process -Id $parent.ProcessId -Force
    }
    $Services.laravel_pid = 0
    $Services | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
}

function Start-Laravel {
    if (Get-LoopbackListener 8317) { throw 'Port 8317 is already occupied.' }
    if (-not (Test-Path -LiteralPath $Php) -or -not (Test-Path -LiteralPath $Ini)) { throw 'The isolated PHP 8.4 runtime is missing.' }

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
    if (Get-LoopbackListener 9) { throw 'The closed local egress sink port is occupied.' }

    $serverArgs = "-c `"$Ini`" `"$(Join-Path $Repo 'artisan')`" serve --host=127.0.0.1 --port=8317 --env=e2e"
    $process = Start-Process -FilePath $Php -ArgumentList $serverArgs -WorkingDirectory $Repo -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $E2E 'laravel-restart.out.log') -RedirectStandardError (Join-Path $E2E 'laravel-restart.err.log')
    $Services.laravel_pid = $process.Id
    $Services | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
    Wait-Port 8317 $true | Out-Null
    $response = Invoke-WebRequest -Uri 'http://127.0.0.1:8317/up' -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -ne 200) { throw 'The restarted Laravel health endpoint did not return HTTP 200.' }
}

function Stop-WordPress {
    $listener = Get-LoopbackListener 8318
    if ($listener) {
        $process = Get-Process -Id $listener.OwningProcess -ErrorAction Stop
        if ($process.Path -ne $Httpd) { throw 'Port 8318 is not owned by the isolated Apache runtime.' }
        $owned = @(Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object {
            $_.ExecutablePath -eq $Httpd -and $_.CommandLine -and $_.CommandLine.Contains($ApacheConfig)
        })
        if ($owned.ProcessId -notcontains $process.Id) { throw 'Apache listener does not match the isolated config process; refusing to stop it.' }
        foreach ($entry in $owned) { Stop-Process -Id $entry.ProcessId -Force -ErrorAction Stop }
        Wait-Port 8318 $false | Out-Null
        if (Test-Path -LiteralPath $ApachePid) { Remove-Item -LiteralPath $ApachePid -Force }
    }
    $Services.apache_pid = ''
    $Services | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
}

function Start-WordPress {
    if (Get-LoopbackListener 8318) { throw 'Port 8318 is already occupied.' }
    $process = Start-Process -FilePath $Httpd -ArgumentList "-d `"$Apache`" -f `"$ApacheConfig`"" -WindowStyle Hidden -PassThru
    $listener = Wait-Port 8318 $true
    $owner = Get-Process -Id $listener.OwningProcess -ErrorAction Stop
    if ($owner.Path -ne $Httpd) { throw 'Restarted port 8318 is not owned by the isolated Apache runtime.' }
    $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)" -ErrorAction Stop
    if (-not $process.CommandLine -or -not $process.CommandLine.Contains($ApacheConfig)) { throw 'Restarted Apache listener does not use the isolated config.' }
    if (-not (Test-Path -LiteralPath $ApachePid -PathType Leaf) -or (Get-Content -Raw -LiteralPath $ApachePid).Trim() -ne [string]$listener.OwningProcess) {
        throw 'The restarted Apache PID file does not identify the loopback listener.'
    }
    $Services.apache_pid = [string]$listener.OwningProcess
    $Services | ConvertTo-Json | Set-Content -LiteralPath $ServiceFile -Encoding ascii
    $response = Invoke-WebRequest -Uri 'http://127.0.0.1:8318/wp-json/' -UseBasicParsing -TimeoutSec 15
    if ($response.StatusCode -ne 200) { throw 'The restarted WordPress REST endpoint did not return HTTP 200.' }
}

if ($Action -in @('stop', 'restart')) {
    if ($Service -eq 'Laravel') { Stop-Laravel } else { Stop-WordPress }
}
if ($Action -in @('start', 'restart')) {
    if ($Service -eq 'Laravel') { Start-Laravel } else { Start-WordPress }
}

Write-Output "$Service $Action completed on its isolated loopback service."
