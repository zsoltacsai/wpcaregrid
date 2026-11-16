[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string] $ExpectedPrimarySHA,
    [ValidateSet('check', 'stage')]
    [string] $Mode = 'check'
)

$ErrorActionPreference = 'Stop'

$PinnedHelperVersion = '1.4.12'
$PinnedManifestPath = 'deploy/release-manifests/staging-next-helper-1.4.12.json'
$PinnedManifestSha256 = '602f8427974d7ebe7f30837bd8dc5304c9fe82334d4a8b2ba9c2e63c648caa7e'
$PinnedReceiverSha256 = 'e80b3821fa90b3b9fe96195c5b0464f69900b7ed2a407ea5b06ed68f46d3e65d'
$PinnedOperations = [ordered]@{
    'wpcg_staging_next_runtime_layout.py' = 'a37b3346d354350a239e83b39253f48edf1f4f42fa201c714fdbf56353779957'
    'wpcg-staging-next-path-transition-1_0.py' = '84ae27a8b725d17160716740cc31e2749fe4638e944a4b2ab4d3b1b9cbe2b9f2'
    'install_staging_next_helper_1_4_12.py' = 'a6126bd9f43d53f69dc213b466b66a15402e21adbbe3c88ab95590dab890aecc'
}
$Repository = 'zsoltacsai/wpcaregrid'
$RemoteAlias = 'caregrid-staging'
$ReceiverPath = Join-Path $PSScriptRoot 'wpcg-staging-next-helper-transfer-receiver.sh'
$RequiredManifestKeys = @(
    'schema', 'profile', 'helper_version', 'compatibility_contract',
    'entrypoint_path', 'entrypoint_sha256', 'backend_path', 'backend_sha256',
    'transaction_module_path', 'transaction_module_sha256', 'target_binding_path',
    'target_binding_sha256', 'environment_contract_path', 'environment_contract_sha256',
    'helper_artifact_sha256', 'release_validation'
)

function Stop-Safe([string] $Code) {
    throw "TRANSFER=STOP;CODE=$Code"
}

function Get-Sha256([string] $Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
}

function Get-VersionedNames([string] $Version) {
    $slug = $Version.Replace('.', '_')
    $contractName = if ($Version -eq '1.4.10') {
        'deployment_environment_contract.py'
    } else {
        "deployment_environment_contract_$slug.py"
    }
    return @(
        "wpcaregrid-staging-next-deploy-priv-$slug.py",
        "staging_next_deploy_backend_$slug.py",
        "staging_next_db_transaction_$slug.py",
        "deployment_target_binding_$slug.py",
        $contractName
    )
}

function Quote-WindowsArgument([string] $Value) {
    # CommandLineToArgvW-compatible quoting for ProcessStartInfo.Arguments.
    $builder = [System.Text.StringBuilder]::new()
    [void] $builder.Append('"')
    $slashes = 0
    foreach ($character in $Value.ToCharArray()) {
        if ($character -eq '\') { $slashes++; continue }
        if ($character -eq '"') {
            [void] $builder.Append(('\' * (2 * $slashes + 1)))
            [void] $builder.Append('"')
            $slashes = 0
            continue
        }
        [void] $builder.Append(('\' * $slashes))
        [void] $builder.Append($character)
        $slashes = 0
    }
    [void] $builder.Append(('\' * (2 * $slashes)))
    [void] $builder.Append('"')
    return $builder.ToString()
}

function Invoke-SshReceiver([string] $RemoteArgs, [byte[]] $InputBytes) {
    $ssh = Get-Command 'ssh.exe' -ErrorAction SilentlyContinue
    if (-not $ssh) { Stop-Safe 'windows_openssh_client_unavailable' }
    $command = "sh -s -- $RemoteArgs"
    $psi = [System.Diagnostics.ProcessStartInfo]::new()
    $psi.FileName = $ssh.Source
    $quotedArgs = @('-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
                    $RemoteAlias, $command) | ForEach-Object { Quote-WindowsArgument $_ }
    $psi.Arguments = $quotedArgs -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $psi
    if (-not $process.Start()) { Stop-Safe 'ssh_start_failed' }
    $stdoutTask = $process.StandardOutput.ReadToEndAsync()
    $stderrTask = $process.StandardError.ReadToEndAsync()
    try {
        $process.StandardInput.BaseStream.Write($InputBytes, 0, $InputBytes.Length)
        $process.StandardInput.Close()
    } catch {
        try { $process.Kill() } catch { }
        Stop-Safe 'ssh_input_failed'
    }
    if (-not $process.WaitForExit(60000)) {
        try { $process.Kill() } catch { }
        Stop-Safe 'ssh_timeout'
    }
    $stdout = $stdoutTask.GetAwaiter().GetResult().Trim()
    $stderr = $stderrTask.GetAwaiter().GetResult().Trim()
    if ($process.ExitCode -ne 0) {
        if ($stderr -match 'TRANSFER=STOP;CODE=[a-z0-9_]+') { Stop-Safe ($Matches[0] -replace '^TRANSFER=STOP;CODE=', '') }
        Stop-Safe 'remote_receiver_failed'
    }
    return $stdout
}

function Invoke-SshCommand([string] $RemoteCommand) {
    return Invoke-SshReceiver -RemoteArgs $RemoteCommand -InputBytes ([System.IO.File]::ReadAllBytes($ReceiverPath))
}

if ($PinnedHelperVersion -ne '1.4.12' -or
    $PinnedManifestPath -ne 'deploy/release-manifests/staging-next-helper-1.4.12.json' -or
    $PinnedManifestSha256 -notmatch '^[0-9a-f]{64}$' -or
    $PinnedReceiverSha256 -notmatch '^[0-9a-f]{64}$') {
    Stop-Safe 'release_pin_not_configured'
}
if (-not (Test-Path -LiteralPath $ReceiverPath -PathType Leaf)) { Stop-Safe 'receiver_missing' }
$receiverHash = Get-Sha256 $ReceiverPath
if ($receiverHash -ne $PinnedReceiverSha256) { Stop-Safe 'receiver_hash_mismatch' }
if ($ExpectedPrimarySHA -notmatch '^[0-9a-f]{40}$') { Stop-Safe 'source_commit_invalid' }

$version = $PinnedHelperVersion
$commit = $ExpectedPrimarySHA
$nonce = [guid]::NewGuid().ToString()
$manifestUrl = "https://raw.githubusercontent.com/$Repository/$commit/$PinnedManifestPath"
$remotePath = "/home/deploy/.private/wpcg-staging-next-helper-$version"
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ("wpcg-helper-" + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $temp | Out-Null

try {
    $manifestFile = Join-Path $temp 'manifest.json'
    Invoke-WebRequest -UseBasicParsing -Uri $manifestUrl -OutFile $manifestFile -MaximumRedirection 0
    if ((Get-Sha256 $manifestFile) -ne $PinnedManifestSha256) { Stop-Safe 'manifest_hash_mismatch' }
    try { $manifest = Get-Content -Raw -LiteralPath $manifestFile | ConvertFrom-Json } catch { Stop-Safe 'manifest_invalid' }
    $observedKeys = @($manifest.PSObject.Properties.Name | Sort-Object)
    $expectedKeys = @($RequiredManifestKeys | Sort-Object)
    if (($observedKeys -join "`n") -ne ($expectedKeys -join "`n") -or
        $manifest.schema -ne 1 -or $manifest.profile -ne 'staging-next' -or
        $manifest.helper_version -ne $PinnedHelperVersion) { Stop-Safe 'manifest_identity_mismatch' }

    $names = Get-VersionedNames $version
    $paths = @($manifest.entrypoint_path, $manifest.backend_path, $manifest.transaction_module_path,
               $manifest.target_binding_path, $manifest.environment_contract_path)
    $hashes = @($manifest.entrypoint_sha256, $manifest.backend_sha256, $manifest.transaction_module_sha256,
                $manifest.target_binding_sha256, $manifest.environment_contract_sha256)
    $artifactPayload = [ordered]@{
        backend_sha256 = [string] $manifest.backend_sha256
        compatibility_contract = [string] $manifest.compatibility_contract
        entrypoint_sha256 = [string] $manifest.entrypoint_sha256
        helper_version = [string] $manifest.helper_version
        schema = 1
        transaction_module_sha256 = [string] $manifest.transaction_module_sha256
    }
    $artifactJson = ConvertTo-Json -InputObject $artifactPayload -Compress
    $artifactBytes = [System.Text.Encoding]::UTF8.GetBytes($artifactJson)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $digestBytes = $sha.ComputeHash($artifactBytes)
        $computedArtifactHash = [System.BitConverter]::ToString($digestBytes).Replace('-', '').ToLowerInvariant()
    } finally { $sha.Dispose() }
    if ($computedArtifactHash -ne $manifest.helper_artifact_sha256) { Stop-Safe 'helper_artifact_hash_mismatch' }
    $localFiles = @()
    for ($i = 0; $i -lt 5; $i++) {
        $path = $paths[$i]
        $hash = [string] $hashes[$i]
        if ($path -ne "scripts/$($names[$i])" -or $hash -notmatch '^[0-9a-f]{64}$') {
            Stop-Safe 'manifest_artifact_entry_invalid'
        }
        $destination = Join-Path $temp $names[$i]
        $url = "https://raw.githubusercontent.com/$Repository/$commit/$path"
        Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $destination -MaximumRedirection 0
        if ((Get-Sha256 $destination) -ne $hash) { Stop-Safe 'artifact_hash_mismatch' }
        $localFiles += $destination
    }
    $opsFiles = @()
    foreach ($entry in $PinnedOperations.GetEnumerator()) {
        $name = [string] $entry.Key
        $hash = [string] $entry.Value
        if ($hash -notmatch '^[0-9a-f]{64}$') { Stop-Safe 'operations_pin_invalid' }
        $relativePath = "scripts/$name"
        $destination = Join-Path $temp $name
        $url = "https://raw.githubusercontent.com/$Repository/$commit/$relativePath"
        Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $destination -MaximumRedirection 0
        if ((Get-Sha256 $destination) -ne $hash) { Stop-Safe 'operations_hash_mismatch' }
        $opsFiles += $destination
    }

    if ($Mode -eq 'check') {
        $remote = Invoke-SshCommand 'preflight'
        Write-Output "TRANSFER_CHECK=PASS;VERSION=$version;SOURCE_COMMIT=$commit"
        Write-Output $remote
        exit 0
    }

    $scp = Get-Command 'scp.exe' -ErrorAction SilentlyContinue
    if (-not $scp) { Stop-Safe 'windows_openssh_scp_unavailable' }
    $null = Invoke-SshCommand "begin $version $nonce"
    $remoteStage = "/home/deploy/.private/.wpcg-helper-upload-$nonce/"
    foreach ($file in ($localFiles + $opsFiles)) {
        & $scp.Source -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 `
            -o ServerAliveInterval=5 -o ServerAliveCountMax=2 -p $file "$RemoteAlias`:$remoteStage"
        if ($LASTEXITCODE -ne 0) { Stop-Safe "scp_failed_workspace_$nonce" }
    }
    $pairs = @()
    for ($i = 0; $i -lt 5; $i++) { $pairs += @($names[$i], [string] $hashes[$i]) }
    foreach ($entry in $PinnedOperations.GetEnumerator()) {
        $pairs += @([string] $entry.Key, [string] $entry.Value)
    }
    $publishArgs = "publish-all $version $nonce " + ($pairs -join ' ')
    try { $receipt = Invoke-SshCommand $publishArgs }
    catch { Stop-Safe "remote_publish_failed_workspace_$nonce" }
    Write-Output "TRANSFER=PASS;VERSION=$version;SOURCE_COMMIT=$commit;FILES=8"
    Write-Output $receipt
} finally {
    Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
}
