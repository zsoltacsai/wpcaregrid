# Gate 2 post orchestrator for package 1.0.17 (I/O v4, isolated probe startup)

This is a separate, commit-pinned operations artifact bound to the immutable
1.0.17 acceptance package. Package 1.0.17 is a verifier-only successor to
1.0.16: it changes the binding, matrix package version, verifier, and checksum
manifest while preserving the runtime profiles, service units, probes, fixture
tools, and production configuration. This orchestrator does not modify those
package inputs. The source commit and this directory's `SHA256SUMS` pin the
orchestrator; `PINNED_INPUTS.json` binds it to the exact package tree.

This side-by-side runner leaves the prior `1.0.16-post`, `1.0.16-post-io-v2`,
`1.0.16-post-io-v3`, and `1.0.16-post-io-v4` artifacts untouched. It
is for one controlled `post` acceptance. It does not install the
production helper, provision secrets, run the later PHP build/DB commands,
open a route, or modify production configuration. The only listener is the
matrix-pinned sibling canary, held around the two CLI baseline/confined probe
pairs. The original 1.0.17 `fixtures.py remove` runs only after all four pinned
verifier calls pass.

## Bundle integrity

The source commit is the outer immutable pin. Verify its exact full commit SHA
from the approved PR merge record, then verify the artifact tree and files:

```sh
git -C /home/deploy/wpcaregrid rev-parse "$ORCH_COMMIT:deploy/php-fpm-confinement/orchestrators/1.0.17-post-io-v4"
cd /usr/local/libexec/wpcg-gate2-orchestrators/1.0.17-post-io-v4
sha256sum -c --strict SHA256SUMS
python3 -B acceptance-post.py --help
```

The exact commit SHA, artifact tree, `SHA256SUMS` digest, and the separate
`one_shot_bridge.py` digest must be recorded in the preflight evidence. Never
run a mutable `/tmp` copy. The runner itself rechecks the bundle manifest,
package 1.0.17 tool digests, exact file set, modes, ownership, link counts,
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

The probe interpreter uses `-I -S -B`: isolated mode, no automatic `site`/`sitecustomize` initialization, and no bytecode writes. This keeps host-installed site customizations out of the transient probe interpreter and is intended to avoid site-initialization file access before the probe window begins. The observed v3 run had two pre-window `open` denials; their raw targets remain private and unidentified, so this change targets that startup phase without asserting the exact target. The verifier is unchanged and continues to reject any unexplained event. This does not change AppArmor policy or the pinned probe code.

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
it after it exits. The v4 runner retains one fixed rule in the generated,
run-scoped probe profile only: `owner @{PROC}/@{pid}/stat r,`. The probe reads
`/proc/self/stat` to bind its own process start time into the JSON accepted by
the pinned verifier. AppArmor resolves that self path through the numeric PID
component. The `owner` qualifier restricts the read to same-UID proc entries;
the source profile's `audit deny ptrace` stays unchanged, and no ptrace
permission is granted. The installed Nginx/FPM/CLI production profiles,
production units, package 1.0.17, route, and runtime configuration are
unchanged. This is a fixed remediation for the observed `PermissionError` in
`starttime_ticks`, not an adaptive permission grant. Any further denial
remains HOLD and requires its own reviewed change.

## Inputs

* `--package-dir`: the independently staged, root-owned 1.0.17 package whose
  package tree is `1311127fcc379c27f154c61bfcf3b439a5099356`.
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

### Route-closure evidence provenance

For the currently approved Gate 2 route decision, the input is the existing
Owner-attested file
`/root/wpcg-route-attestation-20261127.KbA7hY/route-closure.json`, SHA-256
`bece4c615ca8995ddb71828da8dd189fe166c8899d0ad87f18112a423752629d`. Use its
parent as `--evidence-envelope` and the exact file as
`--route-closure-evidence`. It binds the Owner-accepted provider export
`ef4dd4e7886e1e6957bda86e062556ad4f47c5cae5a00f5a47d44893d1907966` and the
redacted effective-origin summary `41cc3cc22968d08eabceceb648ae0370e8df35da82cc91b190e8ef00f76d8d44`
(effective configuration fingerprint
`065847ebcbedcfc7d4cc448e92a5db936cfa99a88725a2a2b89e0aba03b1cb83`). The
route-review aid's `acceptance_package_version: 1.0.16` records the package
context of that review; it is not the 1.0.17 acceptance-package pin. The route
attestation is independent of the verifier-only 1.0.17 change because the
runtime profiles and route configuration inputs are unchanged. The parser's
`REVIEW_REQUIRED` output is not itself a closure decision; the separate Owner
attestation is required. Do not regenerate or infer this file from the parser
summary.

### Unproven-check acknowledgment

The runner deliberately does not synthesize or accept an Owner acknowledgment.
If a profile verifier returns 3, the run stops with HOLD before later profiles
and fixture cleanup. Its preserved verifier output contains the exact
`--ack-unproven=<matrix-sha>:<boot-id>:<phase>:<profile>:<evidence-sha>:<sorted-ids>`
payload requested by the pinned verifier. The Owner may review that exact
evidence set and, if warranted, run the pinned verifier against those same
files with that payload; this is a verifier-only review of preserved evidence,
not a probe or acceptance rerun. That standalone result does not change the
orchestrator's HOLD report and does not authorize cleanup. Completing Gate 2
after any unproven result requires a separately versioned, reviewed
finalization procedure that verifies all four profiles and performs cleanup
only after all four verifier passes. Until that exists, an unproven result
remains HOLD; never treat an acknowledgment request or a standalone verifier
pass as Gate 2 PASS.

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
