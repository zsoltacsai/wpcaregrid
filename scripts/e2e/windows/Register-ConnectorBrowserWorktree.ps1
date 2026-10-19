param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[0-9a-f]{40}$')]
    [string] $ExpectedCommit
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path.TrimEnd('\')
$ExpectedPath = 'D:\-=AI=-\-=Git=-\wpcaregrid-m147-recovery'
$ExpectedBranch = 'codex/m147-closure-recovery'
$ExpectedIdentity = 'm147-e2a-pr129-browser-e2e'
$IntegrationPath = 'D:\-=AI=-\-=Git=-\wpcaregrid-m147-integration-review'
$IntegrationBranch = 'codex/m147-pr-integration-review'
if ($Repo -ieq $IntegrationPath) {
    $ExpectedPath = $IntegrationPath
    $ExpectedBranch = $IntegrationBranch
    $ExpectedIdentity = 'm147-final-integration-browser-e2e'
}
if ($Repo -ine $ExpectedPath) { throw 'Registration is restricted to an explicitly approved dedicated M147 worktree.' }
if ((git -C $Repo branch --show-current).Trim() -cne $ExpectedBranch) { throw 'Registration branch identity does not match the dedicated M147 branch.' }
$Head = (git -C $Repo rev-parse --verify HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $Head -cne $ExpectedCommit) { throw 'The checked-out commit does not exactly match -ExpectedCommit.' }
$Changes = @(git -C $Repo status --porcelain=v1 --untracked-files=all)
if ($LASTEXITCODE -ne 0) { throw 'Could not verify the clean source worktree.' }
$Unexpected = @($Changes | Where-Object { $_ -notmatch '^\?\? storage/e2e/worktree-identity\.json$' })
if ($Unexpected.Count -gt 0) { throw 'Register only after committing all source changes and confirming there are no untracked artifacts.' }
$E2E = Join-Path $Repo 'storage\e2e'
$Services = Join-Path $E2E 'local-services.json'
$State = Join-Path $E2E 'connector-browser-state.json'
if ((Test-Path -LiteralPath $Services) -or (Test-Path -LiteralPath $State)) { throw 'Registration refused while a service or browser fixture state already exists.' }
New-Item -ItemType Directory -Force -Path $E2E | Out-Null
$Record = [ordered]@{
    identity = $ExpectedIdentity
    repository_path = $Repo
    branch = $ExpectedBranch
    expected_commit = $Head
    registered_at_utc = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
}
$Path = Join-Path $E2E 'worktree-identity.json'
if (Test-Path -LiteralPath $Path) {
    $Existing = Get-Content -Raw -LiteralPath $Path | ConvertFrom-Json
    if ($Existing.identity -cne $Record.identity -or $Existing.repository_path -ine $Repo -or $Existing.branch -cne $ExpectedBranch) {
        throw 'An unrelated worktree registration exists; refusing to replace it.'
    }
}
$Record | ConvertTo-Json | Set-Content -LiteralPath $Path -Encoding ascii
Write-Output "Registered $($Record.identity) at exact commit $Head."
