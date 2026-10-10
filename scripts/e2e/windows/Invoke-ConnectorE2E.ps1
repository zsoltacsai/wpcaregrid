Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$AllowedWorktreeBranches = @{
    'D:\-=AI=-\-=Git=-\wpcaregrid-connector' = 'feature/connector-e2e'
    'D:\-=AI=-\-=Git=-\wpcaregrid-combined' = 'integration/connector-seo'
}
$ExpectedBranch = $AllowedWorktreeBranches[$Repo]
if (-not $ExpectedBranch) { throw 'Refusing to run outside an approved Connector E2E worktree.' }
if ((git -C $Repo branch --show-current).Trim() -ne $ExpectedBranch) { throw 'Refusing to run on an unapproved branch.' }
$E2E = Join-Path $Repo 'storage\e2e'
$Php = Join-Path $E2E 'runtime\php-8.4.26\php.exe'
$Ini = Join-Path $E2E 'runtime\php-8.4.26\php.ini'
$WpPhp = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
$WpIni = Join-Path $E2E 'apache\php83\php.ini'
$Fixture = Join-Path $Repo 'scripts\e2e\php\connector-e2e-fixture.php'
$WordPress = Join-Path $Repo 'scripts\e2e\php\connector-e2e-wordpress.php'
$FailureRouter = Join-Path $Repo 'scripts\e2e\php\failure-stub-router.php'
$StatePath = Join-Path $E2E 'connector-e2e-state.json'
$SitePath = Join-Path $E2E 'wordpress-site'
$AppUrl = 'http://127.0.0.1:8317'
$WpUrl = 'http://127.0.0.1:8318'

function Invoke-LocalPhp([string] $Script, [string] $Mode) {
    & $Php -c $Ini $Script $Mode
    if ($LASTEXITCODE -ne 0) { throw "Local PHP E2E helper failed in mode '$Mode'." }
}

function Invoke-WordPressPhp([string] $Mode) {
    & $WpPhp -c $WpIni $WordPress $Mode
    if ($LASTEXITCODE -ne 0) { throw "WordPress E2E helper failed in mode '$Mode'." }
}

function Get-JsonState {
    if (-not (Test-Path -LiteralPath $StatePath)) { throw 'The local fixture state is missing.' }
    return Get-Content -Raw -LiteralPath $StatePath | ConvertFrom-Json
}

function Send-Json([string] $Path, [hashtable] $Body, [hashtable] $Headers = @{}) {
    $json = $Body | ConvertTo-Json -Depth 10 -Compress
    return Invoke-WebRequest -Uri "$AppUrl$Path" -Method Post -ContentType 'application/json' -Headers $Headers -Body $json -UseBasicParsing -SkipHttpErrorCheck -TimeoutSec 15
}

function Assert-Status($Response, [int] $Expected, [string] $Name) {
    if ([int]$Response.StatusCode -ne $Expected) { throw "$Name returned HTTP $($Response.StatusCode), expected $Expected." }
    Write-Output "PASS $Name (HTTP $Expected)"
}

function Confirm-LocalSafety {
    if (-not (Test-Path -LiteralPath $WpPhp) -or -not (Test-Path -LiteralPath $WpIni)) { throw 'The isolated PHP 8.3 WordPress CLI runtime is missing.' }
    if (-not (Test-Path -LiteralPath (Join-Path $Repo 'public\build\manifest.json'))) { throw 'The supported Vite build is missing; run npm run build in this worktree.' }
    foreach ($port in @(8317, 8318)) {
        $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
        if (-not $listeners) { throw "Required local service port $port is not listening. Run Start-Local.ps1 first." }
        if (@($listeners | Where-Object { $_.LocalAddress -ne '127.0.0.1' }).Count) { throw "Port $port is not loopback-only." }
    }
    $services = Get-Content -Raw (Join-Path $E2E 'local-services.json') | ConvertFrom-Json
    if ([IO.Path]::GetFullPath($services.repo) -ne $Repo) { throw 'The local service record points outside this worktree.' }
    $appListener = Get-NetTCPConnection -State Listen -LocalPort 8317 | Select-Object -First 1
    $laravel = Get-Process -Id $appListener.OwningProcess -ErrorAction SilentlyContinue
    if (-not $laravel -or $laravel.Path -ne $Php) { throw 'Port 8317 is not owned by the isolated PHP runtime.' }
    $wpListener = Get-NetTCPConnection -State Listen -LocalPort 8318 | Select-Object -First 1
    $apache = Get-Process -Id $wpListener.OwningProcess -ErrorAction SilentlyContinue
    $apacheConfig = Join-Path $E2E 'apache\httpd.conf'
    $apachePidFile = Join-Path $E2E 'apache\httpd.pid'
    if (-not $apache -or $apache.Path -notmatch '\\laragon\\bin\\apache\\' -or [int](Get-Content -Raw $apachePidFile) -ne $wpListener.OwningProcess -or -not (Test-Path -LiteralPath $apacheConfig)) { throw 'Port 8318 is not owned by the isolated Laragon Apache process.' }

    $envFile = Join-Path $Repo '.env.e2e'
    $envText = Get-Content -Raw -LiteralPath $envFile
    foreach ($pattern in @('(?m)^APP_ENV=e2e$', '(?m)^APP_URL=http://127\.0\.0\.1:8317$', '(?m)^DB_CONNECTION=sqlite$', '(?m)^DB_DATABASE=storage/e2e/laravel\.sqlite$', '(?m)^MAIL_MAILER=array$', '(?m)^QUEUE_CONNECTION=sync$', '(?m)^CACHE_STORE=array$', '(?m)^SESSION_DRIVER=database$', '(?m)^STRIPE_SECRET=$', '(?m)^STRIPE_KEY=$', '(?m)^STRIPE_ALLOW_LIVE_MODE=false$', '(?m)^SZAMLAZZ_ISSUANCE_ENABLED=false$', '(?m)^CONNECTOR_REQUIRE_HTTPS=false$')) {
        if ($envText -notmatch $pattern) { throw "The local environment failed a required safety check ($pattern)." }
    }
    $wpConfig = Get-Content -Raw (Join-Path $SitePath 'wp-config.php')
    foreach ($pattern in @("define\('WP_HOME', 'http://127\.0\.0\.1:8318'\)", "define\('WP_CAREGRID_API_URL', 'http://127\.0\.0\.1:8317/api/v1/connector'\)", 'WP_HTTP_BLOCK_EXTERNAL', "define\('WP_CAREGRID_ALLOW_INSECURE_LOCALHOST', true\)", 'function wp_mail')) {
        if ($wpConfig -notmatch $pattern) { throw 'The isolated WordPress config failed a local-only safety check.' }
    }
    $blocked = Get-Content -Raw (Join-Path $SitePath 'wp-content\mu-plugins\e2e-local-http-only.php')
    if ($blocked -notmatch 'e2e_external_http_blocked' -or $blocked -notmatch '8317' -or $blocked -notmatch '8318') { throw 'The WordPress outbound HTTP guard is missing.' }

    $laravelDb = (Resolve-Path (Join-Path $E2E 'laravel.sqlite')).Path
    $wpDb = (Resolve-Path (Join-Path $SitePath 'database\wp.sqlite')).Path
    if ($laravelDb -eq $wpDb -or -not $laravelDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase) -or -not $wpDb.StartsWith($E2E, [StringComparison]::OrdinalIgnoreCase)) { throw 'Database isolation preflight failed.' }
    if ($env:WP_CAREGRID_TEST_MYSQL) { throw 'WP_CAREGRID_TEST_MYSQL is set; refusing to run the WordPress suite/harness.' }

    $app = Invoke-WebRequest "$AppUrl/up" -UseBasicParsing -TimeoutSec 10 -SkipHttpErrorCheck
    Assert-Status $app 200 'Laravel health endpoint'
    $wp = Invoke-WebRequest "$WpUrl/wp-json/" -UseBasicParsing -TimeoutSec 15 -SkipHttpErrorCheck
    Assert-Status $wp 200 'WordPress REST endpoint'
}

$fixtureCreated = $false
try {
    Confirm-LocalSafety
    if (Test-Path -LiteralPath $StatePath) { throw 'A previous E2E state file exists. Inspect and clean it before continuing.' }

    $env:APP_ENV = 'e2e'
    $env:APP_URL = $AppUrl
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = 'storage/e2e/laravel.sqlite'
    $env:DB_URL = ''
    $env:MAIL_MAILER = 'array'
    $env:QUEUE_CONNECTION = 'sync'
    $env:CACHE_STORE = 'array'
    $env:SESSION_DRIVER = 'database'
    $env:WP_CAREGRID_TEST_MYSQL = ''
    $env:WP_CAREGRID_E2E_SITE = $SitePath
    $env:HTTP_PROXY = 'http://127.0.0.1:9'
    $env:HTTPS_PROXY = 'http://127.0.0.1:9'
    $env:ALL_PROXY = 'http://127.0.0.1:9'
    $env:NO_PROXY = '127.0.0.1,localhost'
    $env:http_proxy = $env:HTTP_PROXY
    $env:https_proxy = $env:HTTPS_PROXY
    $env:all_proxy = $env:ALL_PROXY
    $env:no_proxy = $env:NO_PROXY

    Invoke-LocalPhp $Fixture 'seed'
    $fixtureCreated = $true
    $state = Get-JsonState

    Invoke-WordPressPhp 'pair-heartbeat'
    $inspection = (Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json)
    foreach ($property in @('connector_hash_matches_plugin', 'pairing_code_used', 'website_connected', 'heartbeat_recorded')) {
        if (-not $inspection.$property) { throw "Laravel post-pair assertion failed: $property." }
    }
    if ($inspection.active_connector_count -ne 1) { throw 'Expected exactly one active connector for the synthetic website.' }
    Write-Output 'PASS server-side credential hash equals the plugin credential hash; raw value was not displayed'
    Write-Output 'PASS one-time code consumed; synthetic website connected; heartbeat recorded'

    $auth = (Invoke-WordPressPhp 'auth-probes' | ConvertFrom-Json)
    foreach ($property in @('missing_credential_rejected', 'invalid_credential_rejected', 'wrong_tenant_scope_rejected')) {
        if (-not $auth.$property) { throw "Credential scope assertion failed: $property." }
        Write-Output "PASS $property"
    }
    $urlGuard = (Invoke-WordPressPhp 'url-guard' | ConvertFrom-Json)
    foreach ($property in @('local_http_allowed', 'remote_http_refused', 'https_remote_allowed_without_request')) {
        if (-not $urlGuard.$property) { throw "Connector URL policy assertion failed: $property." }
        Write-Output "PASS $property"
    }

    # Reuse of the consumed code must fail without issuing another connector.
    $pairEndpoint = '/api/v1/connector/pair'
    $validPayload = @{
        pairing_code = $state.pairing_code
        installation_id = [guid]::NewGuid().ToString()
        site_url = $WpUrl
        connector_version = '0.4.2'
        wp_version = '7.1.2'
        php_version = '8.3.33'
        capabilities = @()
    }
    $reuse = Send-Json $pairEndpoint $validPayload
    Assert-Status $reuse 422 'Consumed pairing code reuse'
    if ($reuse.Content -notmatch 'INVALID_PAIRING_CODE') { throw 'Consumed pairing code did not return INVALID_PAIRING_CODE.' }

    $malformed = Send-Json $pairEndpoint @{ pairing_code = $state.pairing_code }
    if ([int]$malformed.StatusCode -ne 422) { throw "Malformed pairing payload returned HTTP $($malformed.StatusCode), expected 422." }
    Write-Output "PASS malformed pairing payload (HTTP $($malformed.StatusCode))"

    Invoke-LocalPhp $Fixture 'issue'
    $state = Get-JsonState
    $mismatch = Send-Json $pairEndpoint @{
        pairing_code = $state.pairing_code
        installation_id = [guid]::NewGuid().ToString()
        site_url = 'https://different.invalid'
        connector_version = '0.4.2'
        capabilities = @()
    }
    if ([int]$mismatch.StatusCode -ne 422 -or $mismatch.Content -notmatch 'SITE_URL_MISMATCH') { throw "Site URL mismatch returned HTTP $($mismatch.StatusCode) without the expected refusal." }
    Write-Output "PASS registered-site mismatch refused (HTTP $($mismatch.StatusCode))"

    Invoke-LocalPhp $Fixture 'issue'
    $state = Get-JsonState
    Invoke-LocalPhp $Fixture 'expire'
    $expired = Send-Json $pairEndpoint @{
        pairing_code = $state.pairing_code
        installation_id = [guid]::NewGuid().ToString()
        site_url = $WpUrl
        connector_version = '0.4.2'
        capabilities = @()
    }
    if ([int]$expired.StatusCode -ne 422 -or $expired.Content -notmatch 'INVALID_PAIRING_CODE') { throw "Expired pairing code returned HTTP $($expired.StatusCode) without the expected refusal." }
    Write-Output "PASS expired one-time code refused (HTTP $($expired.StatusCode))"

    Invoke-WordPressPhp 'create-fixtures'
    Invoke-WordPressPhp 'updates-offer' | Out-Null
    Invoke-LocalPhp $Fixture 'queue-inventory' | Out-Null
    $firstPoll = Invoke-WordPressPhp 'poll' | ConvertFrom-Json
    if ($firstPoll.executed -lt 2 -or $firstPoll.delivered -lt 2) { throw 'The plugin did not execute and deliver the initial inventory and health jobs.' }
    $synced = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if (-not $synced.heartbeat_recorded -or -not $synced.connector_last_heartbeat_at -or -not $synced.website_last_seen_at) { throw 'Laravel did not persist the real heartbeat timestamps.' }
    if ($synced.wordpress_version -ne '7.1.2' -or $synced.php_version -ne '8.3.33' -or $synced.connector_version -ne '0.4.2') { throw 'Heartbeat version facts were not persisted from the isolated WordPress runtime.' }
    if (-not $synced.health_checked_at -or $synced.health_findings_count -lt 1) { throw 'The actual Site Health job report was not persisted.' }
    if ($synced.inventory_row_count -ne 1 -or -not $synced.connector_last_inventory_at -or -not $synced.inventory_collected_at) { throw 'Laravel did not persist exactly one timestamped inventory record.' }
    if ($synced.inventory_core.version -ne '7.1.2' -or $synced.inventory_php_version -ne '8.3.33') { throw 'Persisted inventory core/PHP versions do not match the local WordPress runtime.' }
    $activePlugin = @($synced.inventory_plugins | Where-Object { $_.file -eq 'e2e-active/e2e-active.php' })
    $currentPlugin = @($synced.inventory_plugins | Where-Object { $_.file -eq 'e2e-current/e2e-current.php' })
    $stalePlugin = @($synced.inventory_plugins | Where-Object { $_.file -eq 'e2e-stale/e2e-stale.php' })
    if ($activePlugin.Count -ne 1 -or -not $activePlugin[0].active -or $activePlugin[0].version -ne '1.0.0' -or $activePlugin[0].update_available -ne $true -or $activePlugin[0].new_version -ne '1.1.0' -or $activePlugin[0].update_source -ne 'updates.example.invalid') { throw 'Active plugin inventory/update facts did not match the deterministic local source fixture.' }
    if ($currentPlugin.Count -ne 1 -or $currentPlugin[0].active -ne $false -or $currentPlugin[0].update_available -ne $false) { throw 'The inactive, current plugin inventory state was not preserved.' }
    if ($stalePlugin.Count -ne 1 -or $stalePlugin[0].update_available -ne $null) { throw 'Stale plugin update information was not reported as unknown.' }
    $activeTheme = @($synced.inventory_themes | Where-Object { $_.stylesheet -eq 'e2e-active-theme' })
    if ($activeTheme.Count -ne 1 -or -not $activeTheme[0].active -or $activeTheme[0].version -ne '3.0.0' -or $activeTheme[0].update_available -ne $true -or $activeTheme[0].new_version -ne '3.1.0') { throw 'Active theme inventory/update facts did not match the deterministic local source fixture.' }
    if ($synced.inventory_core.update_available -ne $true -or $synced.inventory_core.latest_version -ne '7.1.3' -or $synced.inventory_updates_pending -ne 3) { throw 'Core/plugin/theme update detection was not persisted consistently.' }
    if (@($synced.jobs | Where-Object { $_.type -eq 'SITE_HEALTH' -and $_.status -eq 'success' }).Count -ne 1) { throw 'The Site Health job did not complete successfully.' }
    Write-Output 'PASS real heartbeat: connector, WordPress, and PHP versions; connected state; last-seen and heartbeat timestamps'
    Write-Output 'PASS initial plugin poll delivered real inventory and Site Health job results to Laravel'
    Write-Output 'PASS inventory source/persisted comparison: plugin active states, theme, core, versions, and offered/stale updates'

    Invoke-LocalPhp $Fixture 'queue-inventory' | Out-Null
    Invoke-WordPressPhp 'poll' | Out-Null
    $duplicateInventory = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if ($duplicateInventory.inventory_row_count -ne 1 -or $duplicateInventory.inventory_id -ne $synced.inventory_id) { throw 'Repeated inventory reports created duplicate server inventory rows.' }
    Invoke-WordPressPhp 'heartbeat' | Out-Null
    Invoke-WordPressPhp 'heartbeat' | Out-Null
    Write-Output 'PASS repeated inventory is idempotent; repeated authenticated heartbeat remains accepted'

    Invoke-WordPressPhp 'updates-current'
    Invoke-LocalPhp $Fixture 'queue-inventory' | Out-Null
    Invoke-WordPressPhp 'poll' | Out-Null
    $currentInventory = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if ($currentInventory.inventory_core.update_available -ne $false -or $currentInventory.inventory_updates_pending -ne 0) { throw 'Completed no-update state was not persisted for core and extensions.' }
    if (@($currentInventory.inventory_plugins | Where-Object { $_.update_available -ne $false }).Count -or @($currentInventory.inventory_themes | Where-Object { $_.update_available -ne $false }).Count) { throw 'A deterministic current plugin/theme was reported with an update.' }

    Invoke-WordPressPhp 'updates-unknown'
    Invoke-LocalPhp $Fixture 'queue-inventory' | Out-Null
    Invoke-WordPressPhp 'poll' | Out-Null
    $unknownInventory = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if ($unknownInventory.inventory_core.update_available -ne $null -or @($unknownInventory.inventory_plugins | Where-Object { $_.update_available -ne $null }).Count -or @($unknownInventory.inventory_themes | Where-Object { $_.update_available -ne $null }).Count) { throw 'Unchecked update metadata was guessed as current or available.' }
    Write-Output 'PASS completed no-update and unchecked/unknown update states remain distinct'

    $healthChecks = Invoke-WordPressPhp 'health-probes' | ConvertFrom-Json
    foreach ($property in @('malformed_health_rejected', 'partial_health_accepted', 'unauthenticated_health_rejected', 'cross_tenant_health_rejected', 'credential_still_present')) {
        if (-not $healthChecks.$property) { throw "Site Health API assertion failed: $property." }
        Write-Output "PASS $property"
    }
    $healthState = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if ($healthState.health_status -ne 'unknown' -or -not $healthState.health_checked_at -or $healthState.health_findings_count -ne 1) { throw 'Partial health data was not safely persisted.' }

    $inventoryValidation = Invoke-WordPressPhp 'inventory-validation' | ConvertFrom-Json
    if (-not $inventoryValidation.unsafe_inventory_rejected -or -not $inventoryValidation.credential_preserved) { throw 'Malformed inventory identifiers were not rejected safely.' }
    Write-Output 'PASS malformed inventory payload rejected without losing credentials'

    $beforeMalformed = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    $malformedUpdate = Invoke-WordPressPhp 'malformed-update' | ConvertFrom-Json
    $afterMalformed = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if (-not $malformedUpdate.malformed_update_metadata_rejected -or $afterMalformed.inventory_id -ne $beforeMalformed.inventory_id) { throw 'Malformed update metadata altered the persisted inventory.' }
    Write-Output 'PASS malformed update version metadata rejected; persisted inventory unchanged'

    Invoke-WordPressPhp 'updates-unknown'
    Invoke-WordPressPhp 'remove-fixture-plugin'
    Invoke-LocalPhp $Fixture 'queue-inventory' | Out-Null
    Invoke-WordPressPhp 'poll' | Out-Null
    $removedPluginInventory = Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json
    if ($removedPluginInventory.inventory_row_count -ne 1 -or @($removedPluginInventory.inventory_plugins | Where-Object { $_.file -eq 'e2e-active/e2e-active.php' }).Count) { throw 'A removed plugin remained in Laravel inventory.' }
    Write-Output 'PASS removed plugin disappears from the next persisted inventory snapshot'

    # Stop only the isolated local stack, exercise the connector against no
    # server and controlled loopback failures, then restore the real services.
    & (Join-Path $PSScriptRoot 'Stop-Local.ps1')
    foreach ($port in @(8317, 8318)) {
        if (Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue) { throw "Port $port remained open before the outage probe." }
    }
    $offline = Invoke-WordPressPhp 'failure-unavailable' | ConvertFrom-Json
    if (-not $offline.expected_heartbeat_result -or -not $offline.credential_preserved -or -not $offline.logs_redacted) { throw 'The local Laravel outage did not preserve credentials and redact logs.' }
    Write-Output 'PASS temporary Laravel unavailability; credential preserved and logs redacted'

    $modeFile = Join-Path $E2E 'failure-stub-mode.txt'
    Set-Content -LiteralPath $modeFile -Value 'invalid' -NoNewline -Encoding ascii
    $stubArgs = "-c `"$Ini`" -S 127.0.0.1:8317 `"$FailureRouter`""
    $stub = Start-Process -FilePath $Php -ArgumentList $stubArgs -WorkingDirectory $Repo -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $E2E 'failure-stub.out.log') -RedirectStandardError (Join-Path $E2E 'failure-stub.err.log')
    try {
        $deadline = (Get-Date).AddSeconds(15)
        do {
            Start-Sleep -Milliseconds 250
            $stubListener = Get-NetTCPConnection -State Listen -LocalPort 8317 -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($stubListener) { break }
            if ((Get-Date) -gt $deadline) { throw 'The loopback-only failure test server did not start.' }
        } while ($true)
        if ($stubListener.LocalAddress -ne '127.0.0.1' -or $stubListener.OwningProcess -ne $stub.Id -or (Get-Process -Id $stub.Id).Path -ne $Php) { throw 'Failure test server did not own the expected loopback-only listener.' }
        $invalidResponse = Invoke-WordPressPhp 'failure-response' | ConvertFrom-Json
        if (-not $invalidResponse.expected_heartbeat_result -or -not $invalidResponse.credential_preserved -or -not $invalidResponse.logs_redacted) { throw 'Malformed local HTTP response handling failed.' }
        Write-Output 'PASS invalid local server response; credential preserved and logs redacted'

        Set-Content -LiteralPath $modeFile -Value 'timeout' -NoNewline -Encoding ascii
        $timeoutResult = Invoke-WordPressPhp 'failure-timeout' | ConvertFrom-Json
        if (-not $timeoutResult.expected_heartbeat_result -or -not $timeoutResult.credential_preserved -or -not $timeoutResult.logs_redacted) { throw 'Local HTTP timeout handling failed.' }
        Write-Output 'PASS local HTTP timeout; credential preserved and logs redacted'
    } finally {
        $owned = Get-Process -Id $stub.Id -ErrorAction SilentlyContinue
        if ($owned -and $owned.Path -eq $Php) { Stop-Process -Id $stub.Id -Force -ErrorAction SilentlyContinue }
        if (Test-Path -LiteralPath $modeFile) { Remove-Item -LiteralPath $modeFile -Force }
    }

    & (Join-Path $PSScriptRoot 'Start-Local.ps1')
    $recovered = Invoke-WordPressPhp 'failure-recovery' | ConvertFrom-Json
    if (-not $recovered.expected_heartbeat_result -or -not $recovered.credential_preserved -or -not $recovered.logs_redacted) { throw 'Authenticated heartbeat did not recover after the Laravel service restarted.' }
    Write-Output 'PASS heartbeat recovered after Laravel service restoration'

    $revocation = (Invoke-WordPressPhp 'revocation' | ConvertFrom-Json)
    foreach ($property in @('disconnect_succeeded', 'revoked_credential_refused', 'plugin_cleared_revoked_credential')) {
        if (-not $revocation.$property) { throw "Revocation assertion failed: $property." }
        Write-Output "PASS $property"
    }

    $remaining = (Invoke-LocalPhp $Fixture 'inspect' | ConvertFrom-Json)
    if ($remaining.active_connector_count -ne 0) { throw 'Revoked credential still has an active server connector.' }
    Write-Output 'PASS disconnect revoked the server connector'
    Write-Output 'Connector Batch 3 real HTTP heartbeat/inventory/health/update E2E passed.'
} finally {
    if ($fixtureCreated -and (Test-Path -LiteralPath $StatePath)) {
        try { Invoke-WordPressPhp 'cleanup-fixtures' } catch { Write-Warning 'Synthetic WordPress fixture cleanup did not complete; the isolated site can be inspected and cleaned by marker.' }
        try { Invoke-WordPressPhp 'reset' } catch { Write-Warning 'WordPress option cleanup did not complete; the isolated site can be reset with the same helper.' }
        try { Invoke-LocalPhp $Fixture 'cleanup' } catch { Write-Warning 'Synthetic Laravel fixture cleanup failed; inspect only the E2E SQLite database.' }
    }
}
