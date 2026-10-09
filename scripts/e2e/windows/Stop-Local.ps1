Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$AllowedWorktreeBranches = @{
    'D:\-=AI=-\-=Git=-\wpcaregrid-connector' = 'feature/connector-e2e'
    'D:\-=AI=-\-=Git=-\wpcaregrid-combined' = 'integration/connector-seo'
}
$ExpectedBranch = $AllowedWorktreeBranches[$Repo]
if (-not $ExpectedBranch) { throw 'Wrong worktree.' }
if ((git -C $Repo branch --show-current).Trim() -ne $ExpectedBranch) { throw 'Wrong branch.' }
$E2E = Join-Path $Repo 'storage\e2e'
$ServiceFile = Join-Path $E2E 'local-services.json'
$Apache = 'C:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18'
$ApacheConfig = Join-Path $E2E 'apache\httpd.conf'
if (Test-Path $ServiceFile) { $Services = Get-Content -Raw -LiteralPath $ServiceFile | ConvertFrom-Json } else { $Services = $null }

if (Test-Path $ApacheConfig) {
    & (Join-Path $Apache 'bin\httpd.exe') -d $Apache -f $ApacheConfig -k shutdown 2>$null
    Start-Sleep -Milliseconds 500
    $ApacheExe = Join-Path $Apache 'bin\httpd.exe'
    Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object {
        $_.ExecutablePath -eq $ApacheExe -and $_.CommandLine -and $_.CommandLine.Contains($ApacheConfig)
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    $ApacheStillRunning = Get-CimInstance Win32_Process -Filter "Name='httpd.exe'" | Where-Object {
        $_.ExecutablePath -eq $ApacheExe -and $_.CommandLine -and $_.CommandLine.Contains($ApacheConfig)
    }
    $ApachePidFile = Join-Path $E2E 'apache\httpd.pid'
    if (-not $ApacheStillRunning -and (Test-Path $ApachePidFile)) { Remove-Item -LiteralPath $ApachePidFile -Force }
}

# Stop only PHP processes whose command line identifies this exact local Laravel port.
Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
    $_.ExecutablePath -and $_.ExecutablePath -eq (Join-Path $E2E 'runtime\php-8.4.26\php.exe') -and $_.CommandLine -match '127\.0\.0\.1:8317'
} | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

if ($Services -and $Services.laravel_pid) {
    $Parent = Get-CimInstance Win32_Process -Filter "ProcessId=$($Services.laravel_pid)" -ErrorAction SilentlyContinue
    if ($Parent -and $Parent.ExecutablePath -eq (Join-Path $E2E 'runtime\php-8.4.26\php.exe')) {
        Stop-Process -Id $Services.laravel_pid -Force -ErrorAction SilentlyContinue
    }
}
if (Test-Path $ServiceFile) { Remove-Item -LiteralPath $ServiceFile -Force }
Write-Output 'Stopped only the recorded loopback E2E services.'
