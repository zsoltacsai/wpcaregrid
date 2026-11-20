# staging-next helper 1.4.12 — protected transfer and host transition

**Source implementation only; no host operation has been performed.** This
runbook is for the already authorized staging-next transition on
`stg1.wpcaregrid.com`. It does not authorize a database migration, application
deployment, worker/scheduler start, or production action.

## Fixed paths and identities

* Preserve `/var/www/wpcaregrid-staging-new` in place (existing app root,
  `1000:1000`).
* Activation path: `/var/www/wpcaregrid-staging-next-current`, a root-owned
  sibling symlink under `/var/www`.
* Nginx root after transition:
  `/var/www/wpcaregrid-staging-next-current/public`.
* PHP-FPM route: `/run/php/php8.5-fpm.sock`.
* Helper source directory:
  `/home/deploy/.private/wpcg-staging-next-helper-1.4.12`.
* Root-only operations files:
  `/home/deploy/.private/wpcg-staging-next-ops-1.0`.
* Runtime state root: `/var/lib/wpcaregrid-deploy/staging-next`.

The helper binds `app_root` and `application_link` separately. Its installer
never creates or replaces the activation link. The root transition utility
creates that link to the existing app root and changes only the uniquely
identified staging-next HTTPS vhost. Its receipt-bound rollback restores the
original Nginx bytes and removes only the exact link created by that operation.

The first controller deployment still returns
`bootstrap_baseline_requires_authenticated_release_receipt` while the link
points to the existing app. This change does not create a baseline receipt or
adopt a guessed application SHA. A separate authenticated baseline-adoption
operation and the release-bound backup/restore/migration gates remain required
before a deployment can proceed.

## Exact-SHA transfer from the HP workstation (no Git checkout)

Run in the Owner's existing Windows PowerShell session on the HP laptop. The
procedure uses built-in PowerShell `Invoke-WebRequest`/`Get-FileHash` and the
already working `caregrid-staging` OpenSSH alias; it asks for no new key,
account, token, or permission. It never runs Git on the HP. Do not bypass a
local PowerShell execution policy; if Windows refuses to run the verified
local script, stop and report that constraint.

After this change is merged, copy the **full reviewed protected-primary merge
SHA** from issue #174 into `$Primary`. Keep the SHA literal unchanged during
the operation.

```powershell
$ErrorActionPreference = 'Stop'
$Primary = '<full-reviewed-protected-primary-merge-sha>'
if ($Primary -notmatch '^[0-9a-f]{40}$') { throw 'TRANSFER=STOP;CODE=primary_sha_invalid' }
$Base = "https://raw.githubusercontent.com/zsoltacsai/wpcaregrid/$Primary"
$Temp = Join-Path $env:TEMP ('wpcg-1412-' + [guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $Temp | Out-Null
$Adapter = Join-Path $Temp 'stage-staging-next-helper.ps1'
$Receiver = Join-Path $Temp 'wpcg-staging-next-helper-transfer-receiver.sh'
Invoke-WebRequest -UseBasicParsing -MaximumRedirection 0 `
  -Uri "$Base/scripts/stage-staging-next-helper.ps1" -OutFile $Adapter
Invoke-WebRequest -UseBasicParsing -MaximumRedirection 0 `
  -Uri "$Base/scripts/wpcg-staging-next-helper-transfer-receiver.sh" -OutFile $Receiver
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $Adapter).Hash.ToLowerInvariant() -ne
    '24f5d31e940965503693627f0a675f6edb8c909c19ba3acabab6de5738eada93') { throw 'TRANSFER=STOP;CODE=adapter_hash_mismatch' }
& $Adapter -ExpectedPrimarySHA $Primary -Mode check
if ($LASTEXITCODE -ne 0) { throw 'TRANSFER=STOP;CODE=check_failed' }
& $Adapter -ExpectedPrimarySHA $Primary -Mode stage
if ($LASTEXITCODE -ne 0) { throw 'TRANSFER=STOP;CODE=stage_failed' }
Remove-Item -LiteralPath $Temp -Recurse -Force
```

The adapter has an embedded SHA-256 for the receiver, verifies the 1.4.12
manifest against its embedded digest, recomputes the combined helper digest,
fetches all five helper artifacts and all three root-operation tools (including
the pinned installer) from the exact
`$Primary` raw GitHub paths, and checks each digest before SSH/SCP. It pins
`caregrid-staging`, requires strict host-key checking and batch mode, and
publishes without overwrite. The receiver writes only under the deploy-owned
`.private` directory, never invokes `sudo`, and keeps the two root-operation
tools outside the installer’s exact-five-file helper directory.

Require both outputs:

```text
TRANSFER_CHECK=PASS;VERSION=1.4.12;SOURCE_COMMIT=<same full SHA>
TRANSFER_PREFLIGHT=PASS;IDENTITY=deploy:1000;PRIVATE=...
TRANSFER=PASS;VERSION=1.4.12;SOURCE_COMMIT=<same full SHA>;FILES=8
```

Any different output, host-key prompt/change, missing binary, existing version
destination, hash mismatch, timeout, or SSH/SCP failure is STOP. A failed partial
upload or publication is inert but may leave its nonce workspace and/or one of
the no-overwrite destination directories. Do not delete by pathname or retry
into those names; preserve them for an exact, inode-checked cleanup review. The
root installer later verifies its own exact input bytes and the five helper
files independently.

The adapter and receiver have not been executed on Windows or on the staging
host. CI tests the receiver and statically checks the PowerShell adapter pins;
the Owner's workstation execution remains the first operational transfer.

## One root-console transition block

Run only on `stg1.wpcaregrid.com` from the already authorized root console,
after the source transfer above reports PASS. This block does no database work,
application migration, worker change, or service restart except the scoped
Nginx reload performed by the path transition utility.

It first refuses if any historical Phase B policy or sudoers path for 1.4.5
through 1.4.11 exists. The bytes and hashes of any such historical controls
have not been verified; **do not remove or rename them blindly**. If a path is
reported, stop and obtain a reviewed path-specific deactivation procedure. The
old helper binaries remain untouched.

```sh
( set -eu
  legacy=0
  for v in 1.4.5 1.4.6 1.4.7 1.4.8 1.4.9 1.4.10 1.4.11; do
    for p in \
      "/etc/wpcaregrid-deploy/profiles/staging-next-phase-b-$v.json" \
      "/etc/sudoers.d/wpcaregrid-staging-next-$v"; do
      if [ -e "$p" ] || [ -L "$p" ]; then
        printf 'LEGACY_PHASE_B_CONTROL=PRESENT;PATH=%s;TYPE=%s;OWNER_MODE=%s;SHA256=%s\n' \
          "$p" "$(stat -c '%F' -- "$p" 2>/dev/null || echo unavailable)" \
          "$(stat -c '%U:%G:%a' -- "$p" 2>/dev/null || echo unavailable)" \
          "$(sha256sum -- "$p" 2>/dev/null | cut -d' ' -f1 || echo unavailable)"
        legacy=1
      fi
    done
  done
  if [ "$legacy" -ne 0 ]; then echo 'HOST_TRANSITION=STOP;CODE=legacy_phase_b_control_present'; exit 20; fi

  private=/home/deploy/.private
  helper="$private/wpcg-staging-next-helper-1.4.12"
  ops="$private/wpcg-staging-next-ops-1.0"
  installer="$ops/install_staging_next_helper_1_4_12.py"
  test "$(stat -c '%U:%G:%a' "$helper")" = deploy:deploy:700
  test "$(stat -c '%U:%G:%a' "$ops")" = deploy:deploy:700
  test "$(stat -c '%U:%G:%a' "$installer")" = deploy:deploy:600

  # Hash-verify and execute the exact file bytes from one open descriptor.
  # This avoids a hash-then-open race on files writable by the deploy account.
  run_pinned_py() {
    /usr/bin/python3 -I - "$@" <<'PY'
import hashlib, os, stat, sys
path, expected, *args = sys.argv[1:]
try:
    fd = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_CLOEXEC', 0))
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode), info.st_nlink) != (1000, 1000, 0o600, 1) or info.st_size > 2_000_000:
        raise SystemExit('PINNED_EXEC=STOP;CODE=file_metadata_invalid')
    chunks=[]
    total=0
    while True:
        chunk=os.read(fd, 65536)
        if not chunk: break
        total += len(chunk)
        if total > 2_000_000: raise SystemExit('PINNED_EXEC=STOP;CODE=file_size_invalid')
        chunks.append(chunk)
    data=b''.join(chunks)
    if total != info.st_size or hashlib.sha256(data).hexdigest() != expected:
        raise SystemExit('PINNED_EXEC=STOP;CODE=file_hash_mismatch')
finally:
    try: os.close(fd)
    except Exception: pass
sys.argv=[path, *args]
exec(compile(data, path, 'exec', dont_inherit=True), {'__name__':'__main__','__file__':path})
PY
  }

  layout="$ops/wpcg_staging_next_runtime_layout.py"
  transition="$ops/wpcg-staging-next-path-transition-1_0.py"
  layout_sha='61042c51c88996568a180b66f461695198d2ed48a5c8a118d4deebcebe484daa'
  transition_sha='199f2489aeebbb4dd00285edbd263d9a56db642e9e5e5d966d8f2bacb2cd0c12'
  installer_sha='a6126bd9f43d53f69dc213b466b66a15402e21adbbe3c88ab95590dab890aecc'

  set +e
  run_pinned_py "$layout" "$layout_sha"
  layout_check=$?
  set -e
  if [ "$layout_check" -ne 0 ] && [ "$layout_check" -ne 2 ]; then echo 'HOST_TRANSITION=STOP;CODE=runtime_layout_check_failed'; exit 21; fi
  run_pinned_py "$layout" "$layout_sha" --apply

  run_pinned_py "$transition" "$transition_sha" --mode check --nginx-file auto
  run_pinned_py "$transition" "$transition_sha" --mode apply --nginx-file auto

  run_pinned_py "$installer" "$installer_sha"
  echo 'HOST_INSTALLATION=PASS;HELPER=1.4.12;DATABASE=UNCHANGED;APPLICATION_TREE=PRESERVED'
)
```

Before executing, confirm these two runtime-operation digests and the installer
digest against the merged issue #174 record for that exact primary. The
adapter-transferred installer is pinned above; helper hashes are in the
versioned manifest and rechecked by the installer. The wrapper verifies
each transferred script's regular-file metadata and SHA through the same open
file descriptor, then executes only those authenticated bytes in memory as
root. This prevents replacing a deploy-owned script between `sha256sum` and
execution.

The runtime-layout provisioner creates only the documented directory skeleton
with identity and ownership checks and inode-bound rollback on partial failure.
It prints a structured JSON receipt to stdout but does not persist it; save the
sanitized output with the operation evidence. It creates the controller's
`source` directory as `deploy-staging-next` mode 0700 and the root-owned helper
base path, and validates the existing profile directory. It does not create the
activation link, environment file, vendor tree, release, database backup, or
deployment marker. The path utility requires the provisioned root-only state
directory and refuses an ambiguous or nonmatching Nginx source. Capture its
durable `complete_receipt_path` from PASS. For a rollback in a later shell, start
a fresh root subshell and re-paste the `run_pinned_py` definition and its exact
pins from the block above; shell functions do not persist across the subshell.
Then run the same pinned wrapper with
`--mode rollback --receipt <complete_receipt_path>`. Rollback refuses changed
Nginx bytes, a changed symlink, or a mismatched backup. It restores only the
original staging-next vhost and removes only the recorded sibling symlink.

If path transition succeeds but helper installation fails, roll back the Nginx
path transition using that exact complete receipt before attempting any other
change. Never remove the receipt or configuration backup as part of rollback.
The empty runtime skeleton is retained for forensic review; no recursive cleanup
is authorized by this procedure.

## STOP conditions and later gates

Stop on any non-PASS source-transfer output, unexpected path/owner/mode, legacy
Phase B route, transfer/hash mismatch, ambiguous Nginx vhost, wrong PHP-FPM
socket, `nginx -t` or reload failure, any rollback HOLD, installer negative-test
failure, or a host identity differing from the verified staging-next target.
Do not improvise a removal command, alter grants, install a Phase B policy, run
`preflight`, `stage`, `deploy`, or `php artisan migrate` as part of this
installation procedure.

The migration/bootstrap chain remains HOLD until there is an authenticated
current backup and protected-config recovery reference, isolated MySQL restore
receipt, complete migration ledger reconciliation, target migration rehearsal,
approved durable least-privilege DEFINER identity and receipt-backed adoption
of the existing application tree. Source transfer and helper installation do
not satisfy those requirements or prove staging acceptance.
