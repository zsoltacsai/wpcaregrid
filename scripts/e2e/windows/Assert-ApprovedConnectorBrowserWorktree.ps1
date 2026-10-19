param(
    [string] $ExpectedCommit,
    [switch] $RequireExactCommit
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..\..')).Path
$Branch = (git -C $Repo branch --show-current).Trim()
if ($LASTEXITCODE -ne 0) { throw 'Could not read the current branch identity.' }
$Head = (git -C $Repo rev-parse --verify HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $Head -notmatch '^[0-9a-f]{40}$') { throw 'Could not verify the exact worktree commit.' }
$PathKey = $Repo.TrimEnd('\')
$Historical = @{
    'D:\-=AI=-\-=Git=-\wpcaregrid-connector' = 'feature/connector-e2e'
    'D:\-=AI=-\-=Git=-\wpcaregrid-combined' = 'integration/connector-seo'
}
$NewPath = 'D:\-=AI=-\-=Git=-\wpcaregrid-m147-recovery'
$NewBranch = 'codex/m147-closure-recovery'

if ($Historical.ContainsKey($PathKey)) {
    if ($Branch -cne $Historical[$PathKey]) { throw 'Refusing to run on an unapproved historical Connector E2E branch.' }
} elseif ($PathKey -ieq $NewPath) {
    if ($Branch -cne $NewBranch) { throw 'Refusing to run from the recovery worktree on an unexpected branch.' }
    $IdentityPath = Join-Path $Repo 'storage\e2e\worktree-identity.json'
    if (-not (Test-Path -LiteralPath $IdentityPath -PathType Leaf)) { throw 'Fresh worktree has no explicit browser E2E registration.' }
    $Identity = Get-Content -Raw -LiteralPath $IdentityPath | ConvertFrom-Json
    if ($Identity.identity -cne 'm147-e2a-pr129-browser-e2e' -or $Identity.branch -cne $Branch -or $Identity.repository_path -ine $PathKey -or $Identity.expected_commit -cne $Head) {
        throw 'Fresh worktree registration does not match its path, branch, and exact HEAD.'
    }
} else {
    throw 'Refusing to run outside an explicitly approved Connector E2E worktree.'
}

if ($ExpectedCommit) {
    if ($ExpectedCommit -notmatch '^[0-9a-f]{40}$' -or $Head -cne $ExpectedCommit) { throw 'Current HEAD does not match the explicitly requested exact E2E commit.' }
}
if ($RequireExactCommit -and -not $ExpectedCommit) { throw 'An exact expected commit is required for this browser E2E run.' }

[pscustomobject]@{ Repository = $PathKey; Branch = $Branch; Head = $Head; Identity = if ($PathKey -ieq $NewPath) { 'm147-e2a-pr129-browser-e2e' } else { 'historical-connector-e2e' } }
