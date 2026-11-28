# Gate 2 post orchestrator for package 1.0.18 (I/O v5, isolated probe startup)

This is a separate, commit-pinned operations artifact bound to the immutable
1.0.18 acceptance package. Package 1.0.18 is a versioned policy and verifier successor to 1.0.17. It
adds explicit audit denials for the AppArmor `exists` signal on each declared
cross-profile peer, including stacked peer labels, while preserving the existing
real-signal denials and all unrelated runtime profile rules. It also versions the
binding, matrix package version, and checksum manifest; services, probes, fixture
tools, and production configuration are unchanged. The runner requires the
installed policy files and common abstraction to hash-match this package before
it derives or loads a probe profile. This orchestrator does not modify those
package inputs. The source commit and this directory's `SHA256SUMS` pin the
orchestrator; `PINNED_INPUTS.json` binds it to the exact package tree.

The policy rationale and host validation boundary are documented in the [1.0.18
Gate 2 fast-track note](../../../../docs/PHP_FPM_CONFINEMENT_GATE2_FAST_TRACK_1_0_18.md).

This side-by-side runner leaves the prior `1.0.16-post`, `1.0.16-post-io-v2`,
`1.0.16-post-io-v3`, `1.0.16-post-io-v4`, and `1.0.17-post-io-v4` artifacts untouched. It
is for one controlled `post` acceptance. It does not install the
production helper, provision secrets, run the later PHP build/DB commands,
open a route, or modify production configuration. The only listener is the
matrix-pinned sibling canary, held around the two CLI baseline/confined probe
pairs. The original 1.0.18 `fixtures.py remove` runs only after all four pinned
verifier calls pass.

## Bundle integrity

The source commit is the outer immutable pin. Verify its exact full commit SHA
from the approved PR merge record, then verify the artifact tree and files:

```sh
git -C /home/deploy/wpcaregrid rev-parse "$ORCH_COMMIT:deploy/php-fpm-confinement/orchestrators/1.0.18-post-io-v5"
cd /usr/local/libexec/wpcg-gate2-orchestrators/1.0.18-post-io-v5
sha256sum -c --strict SHA256SUMS
python3 -B acceptance-post.py --help
```

The exact commit SHA, artifact tree, `SHA256SUMS` digest, and the separate
`one_shot_bridge.py` digest must be recorded in the preflight evidence. Never
run a mutable `/tmp` copy. The runner itself rechecks the bundle manifest,
package 1.0.18 tool digests, exact file set, modes, ownership, link counts,
fixture set, service state, AppArmor parser, sockets, and route attestation
before creating any listener or derived profile.

Before deriving or loading a probe profile, the runner checks that all four
cross-role AppArmor profile source files and the shared FPM abstraction in
`/etc/apparmor.d` are root-owned regular single-link 0644 files and byte-match
the pinned 1.0.18 package. It does not install or reload those production
profiles. A policy update must be staged and reviewed separately; a mismatch
fails closed before probes.

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
production units, package 1.0.18, route, and runtime configuration are
unchanged. This is a fixed remediation for the observed `PermissionError` in
`starttime_ticks`, not an adaptive permission grant. Any further denial
remains HOLD and requires its own reviewed change.

## Inputs

* `--package-dir`: the independently staged, root-owned 1.0.18 package whose
  package tree is `c7af9368f04b2d37b4212207bd98bbcae016a691`.
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
redacted effective-origin summary artifact, SHA-256
`41cc3cc22968d08eabceceb648ae0370e8df35da82cc91b190e8ef00f76d8d44`. Its
reported effective-configuration fingerprint is
`065847ebcbedcfc7d4cc448e92a5db936cfa99a88725a2a2b89e0aba03b1cb83`; this is
not the summary file's SHA-256. The attestation's `origin_config_sha256` must
bind the reviewed origin evidence artifact, not substitute its fingerprint.
The route-review aid's `acceptance_package_version: 1.0.16` records the package
context of that review; it is not the 1.0.18 acceptance-package pin. The route
attestation is independent of the 1.0.18 policy/verifier change because the
route configuration inputs are unchanged. The parser's
`REVIEW_REQUIRED` output is not itself a closure decision; the separate Owner
attestation is required. Do not regenerate or infer this file from the parser
summary.

### Unproven-check acknowledgment

The runner deliberately does not synthesize or accept an Owner acknowledgment.
If a profile verifier returns 3, the runner records `UNPROVEN`, continues the remaining profile checks, then returns HOLD and preserves fixtures. Hard verifier failures (rc=1), probe failures, identity failures, or any other HOLD still stop the sequence immediately. Its preserved `<profile>-verifier.stdout` contains the
per-check records and unproven summary; `<profile>-verifier.stderr` contains
the exact `ACK_REQUIRED` request. The request is
`--ack-unproven=<matrix-sha>:<boot-id>:<phase>:<profile>:<evidence-sha>:<sorted-ids>`
requested by the pinned verifier. An acknowledgment may explain an
unproven-only result for diagnostic review, but it cannot convert that result
into Gate 2 PASS. The acceptance gate requires zero unproven IDs, all four
verifier calls at rc=0, and the pinned fixture cleanup/check to pass. Never
run cleanup after an unproven result.

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
Any hard failure stops the run and preserves evidence; no weaker profile or retry
is attempted. An unproven-only rc=3 is not a PASS and cannot trigger cleanup. The full four-profile result set is collected so the Owner can review every unproven record in one pass. Gate 2 PASS still requires a clean fresh run with no unproven checks and verified fixture cleanup.

Each sibling listener gets a fresh random nonce in its transient systemd unit
name. The runner owns that unit only after its own `systemd-run` returns success,
and cleanup targets only the recorded nonce unit. A failed or timed-out start is
ambiguous: the runner leaves any late/foreign unit untouched and reports HOLD.
The `sibling-start-<nonce>.stdout` and `.stderr` evidence files use the same
nonce, so both CLI listener invocations can safely use the shared run directory
with exclusive file creation.
