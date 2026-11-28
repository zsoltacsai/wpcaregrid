# Gate 2 post orchestrator for package 1.0.16 (I/O v3, probe self-stat)

This is a separate, commit-pinned operations artifact. It is not a new
confinement-package version: it does not change the 1.0.16 matrix, probes,
profiles, units, runtime bytes, fixture semantics, or verifier contract. A new
package such as 1.0.17 would be appropriate only if one of those acceptance
inputs changed. The source commit and this directory's `SHA256SUMS` pin this
orchestrator; `PINNED_INPUTS.json` binds it to immutable package 1.0.16.

This side-by-side runner leaves the prior `1.0.16-post` and `1.0.16-post-io-v2`
artifacts untouched. It
is for one controlled `post` acceptance. It does not install the
production helper, provision secrets, run the later PHP build/DB commands,
open a route, or modify production configuration. The only listener is the
matrix-pinned sibling canary, held around the two CLI baseline/confined probe
pairs. The original 1.0.16 `fixtures.py remove` runs only after all four pinned
verifier calls pass.

## Bundle integrity

The source commit is the outer immutable pin. Verify its exact full commit SHA
from the approved PR merge record, then verify the artifact tree and files:

```sh
git -C /home/deploy/wpcaregrid rev-parse "$ORCH_COMMIT:deploy/php-fpm-confinement/orchestrators/1.0.16-post-io-v3"
cd /usr/local/libexec/wpcg-gate2-orchestrators/1.0.16-post-io-v3
sha256sum -c --strict SHA256SUMS
python3 -B acceptance-post.py --help
```

The exact commit SHA, artifact tree, `SHA256SUMS` digest, and the separate
`one_shot_bridge.py` digest must be recorded in the preflight evidence. Never
run a mutable `/tmp` copy. The runner itself rechecks the bundle manifest,
package 1.0.16 tool digests, exact file set, modes, ownership, link counts,
fixture set, service state, AppArmor parser, sockets, and route attestation
before creating any listener or derived profile.

## Probe output and systemd support

Before any listener is started, this version requires `systemctl --version` to
report systemd 249 or newer. `StandardOutput=append:` and
`StandardError=append:` are documented in the upstream [systemd 249
`systemd.exec(5)`](https://www.freedesktop.org/software/systemd/man/249/systemd.exec.html)
and [systemd 255 `systemd.exec(5)`](https://www.freedesktop.org/software/systemd/man/255/systemd.exec.html)
manuals. The target's Ubuntu 24.04 systemd baseline is 255;
the runtime version check fails closed if the host reports an older or
unparseable version.

The runner also requires `/proc/1/comm` to identify PID 1 as `systemd`, then
reads `/proc/1/attr/current`. It accepts the exact AppArmor label `unconfined` (with
an optional final newline); missing, oversized, malformed, differently
confined, or unreadable values fail closed as HOLD before any probe launch.
Only the boolean `system_manager_unconfined` is added to the bounded
`preflight_summary.pin_checks`; the label text is never copied into evidence.
The fake child tests this decision logic, but does not emulate the kernel's
systemd descriptor opening or AppArmor profile transition. The target host
must pass and record this preflight before first use.

For each baseline/confined probe, the runner creates three distinct empty
root-owned mode-0600 regular files with exclusive, no-follow creation inside
the root-owned mode-0700 evidence directory. The transient service receives
separate `StandardOutput=append:<json-path>` and
`StandardError=append:<stderr-path>` properties, and `--pipe` is omitted. The
system manager opens these paths before invoking the service executable and
passes the resulting descriptors into the unit. This depends on the system
manager being `unconfined`; the check above is mandatory. The `www-data`
process writes only through those descriptors; it does not need pathname access to the
root-only evidence directory. The `systemd-run` parent's own stderr is captured
to the separate `<probe-stderr>.systemd-run` file, so launcher diagnostics
cannot be mixed into the probe's traceback stream. All three paths are
exclusive-created and checked for regular-file type, root ownership, mode,
single link, and unchanged inode before the result can be consumed.

Each transient probe unit has a random name, `TimeoutStartSec=10s`,
`RuntimeMaxSec=75s`, and `TimeoutStopSec=5s`; the parent waits at most 90
seconds. A wait timeout is
HOLD: the runner does not read, remove, or retry the output files. The service
manager's runtime/stop bounds limit the unit lifetime and `--collect` collects
it after it exits. The v3 runner adds one fixed rule to the generated,
run-scoped probe profile only: `owner @{PROC}/@{pid}/stat r,`. The probe reads
`/proc/self/stat` to bind its own process start time into the JSON accepted by
the pinned verifier. AppArmor resolves that self path through the numeric PID
component. The `owner` qualifier restricts the read to same-UID proc entries;
the source profile's `audit deny ptrace` stays unchanged, and no ptrace
permission is granted. The installed Nginx/FPM/CLI production profiles,
production units, package 1.0.16, route, and runtime configuration are
unchanged. This is a fixed remediation for the observed `PermissionError` in
`starttime_ticks`, not an adaptive permission grant. Any further denial
remains HOLD and requires its own reviewed change.

## Inputs

* `--package-dir`: the independently staged, root-owned 1.0.16 package whose
  package tree is `442fa9ab4e82652a70f997238ad5d98e11ade5b9`.
* `--evidence-envelope`: an existing root:root mode-0700 directory. The
  runner creates a unique 0700 evidence subdirectory and writes raw evidence
  there only.
* `--route-closure-evidence`: a root:root, regular, single-link, mode-0600
  JSON file directly under the envelope. The exact schema is documented in
  `docs/PHP_FPM_CONFINEMENT_GO20_PHP85_1_0_16.md`; it records hashes of the
  Owner-reviewed provider export and origin Nginx evidence, not their raw
  contents.
* `--root-card-file`: one-use T3 file-delivery capability. The bridge opens it only after
  observing the real `su -` password prompt, rejects symlinks/non-regular or
  multiply-linked files, writes the bytes only to that PTY, zeroes its buffer,
  and unlinks the one-use file. The password is never a command argument,
  environment variable, report field, or evidence value.

The bundle is installed root:root mode 0755 so the authorized deploy operator
can launch the bridge from its immutable path; the root child runs the sibling
orchestrator from that same bundle. The bridge requires a real outer TTY, verifies the exact root/host identity,
captures only a schema-checked final JSON report, closes the root shell, and
reaps the `su` child. Raw PTY output is never forwarded. A missing marker,
invalid report, timeout, nonzero command report, or failed reap is HOLD.

## Run behavior

The orchestrator consumes the four `post` profiles in matrix order:

1. `wpcaregrid-nginx`
2. `wpcaregrid-php-fpm-production`
3. `wpcaregrid-php-cli-production-build`
4. `wpcaregrid-php-cli-production-deploy`

For each profile it captures and validates a fresh inventory before and after,
uses only the profile's matrix peer roles, records a fresh baseline and
confined probe pair, unloads the derived profile, captures the correlated
kernel audit window, applies the original-profile Python-exec denial where
the matrix requires it, then calls the pinned verifier with that profile's
own evidence. The sibling listener is used only for build/deploy CLI profiles.
Any failure stops the run and preserves evidence; no weaker profile or retry
is attempted. Fixture cleanup is gated on all four verifier PASS results.

Each sibling listener gets a fresh random nonce in its transient systemd unit
name. The runner owns that unit only after its own `systemd-run` returns success,
and cleanup targets only the recorded nonce unit. A failed or timed-out start is
ambiguous: the runner leaves any late/foreign unit untouched and reports HOLD.
The `sibling-start-<nonce>.stdout` and `.stderr` evidence files use the same
nonce, so both CLI listener invocations can safely use the shared run directory
with exclusive file creation.
