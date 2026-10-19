param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PhpUnitArguments = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Identity = & (Join-Path $PSScriptRoot 'Assert-ApprovedConnectorBrowserWorktree.ps1')
$Repo = $Identity.Repository

$Php = Join-Path $Repo 'storage\e2e\runtime\php-8.4.26\php.exe'
$Ini = Join-Path $Repo 'storage\e2e\runtime\php-8.4.26\php.ini'
$PhpUnit = Join-Path $Repo 'wordpress\vendor\bin\phpunit'
$Configuration = Join-Path $Repo 'wordpress\phpunit.xml.dist'
foreach ($path in @($Php, $Ini, $PhpUnit, $Configuration)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required local WordPress test file is missing: $path" }
}

$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$startedAt = [DateTime]::UtcNow
$runToken = [Guid]::NewGuid().ToString('N').Substring(0, 16)
$testRoot = $null
$process = $null
$env:WP_CAREGRID_TEST_MYSQL = ''
$env:WP_CAREGRID_KEEP_SITE = '1'
$env:WP_CAREGRID_TEST_RUN_TOKEN = $runToken
$exitCode = 1

try {
    # The pinned local PHP runtime defaults to 128M. The complete WordPress
    # integration process peaks above that after earlier suites have run.
    # Bound this PHPUnit process at 512M without changing the runtime ini.
    $arguments = @('-c', $Ini, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', $PhpUnit, '--configuration', $Configuration) + $PhpUnitArguments
    $process = Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $Repo -NoNewWindow -Wait -PassThru
    $exitCode = $process.ExitCode
    $testRoot = Join-Path $tempRoot "wp-caregrid-tests-$($process.Id)-$runToken"
} finally {
    if ($process -and $testRoot -and (Test-Path -LiteralPath $testRoot -PathType Container)) {
        $marker = Join-Path $testRoot '.wp-caregrid-test-owned'
        $realRoot = (Resolve-Path -LiteralPath $testRoot).Path
        $expectedName = "wp-caregrid-tests-$($process.Id)-$runToken"
        $markerValue = if (Test-Path -LiteralPath $marker -PathType Leaf) { Get-Content -Raw -LiteralPath $marker } else { '' }
        $directChild = [IO.Path]::GetDirectoryName($realRoot).TrimEnd('\')
        $isOwned = ($directChild -ieq $tempRoot) -and
            ([IO.Path]::GetFileName($realRoot) -ceq $expectedName) -and
            ($markerValue -ceq $runToken) -and
            ((Get-Item -LiteralPath $realRoot).CreationTimeUtc -ge $startedAt.AddSeconds(-2))

        if ($isOwned) {
            try {
                Remove-Item -LiteralPath $realRoot -Recurse -Force
                Write-Output 'Removed the exact marked WordPress test directory after PHP exited.'
            } catch {
                Write-Warning "Could not remove the test-owned directory; exact remaining path: $realRoot. $($_.Exception.Message)"
            }
        } else {
            Write-Warning "Skipped cleanup because test directory ownership checks failed: $realRoot"
        }
    }
    Remove-Item Env:\WP_CAREGRID_TEST_RUN_TOKEN -ErrorAction SilentlyContinue
    Remove-Item Env:\WP_CAREGRID_KEEP_SITE -ErrorAction SilentlyContinue
    Remove-Item Env:\WP_CAREGRID_TEST_MYSQL -ErrorAction SilentlyContinue
}

exit $exitCode
