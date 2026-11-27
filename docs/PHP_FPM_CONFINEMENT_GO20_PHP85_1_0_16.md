# Production-only confinement acceptance compatibility 1.0.16

Package 1.0.16 is an immutable acceptance-tool successor to 1.0.15. It fixes the confined symlink probe's dependency on a writable system temporary directory. The nine installed runtime files, AppArmor profiles, PHP-FPM files, and systemd units remain byte-for-byte identical to package 1.0.12. Helper 2.1.11 remains bound to runtime package 1.0.12; this acceptance-only update does not alter or install the helper.

## Symlink escape probe

The 1.0.15 probe called `tempfile.mkdtemp()` while confined. The `wpcaregrid-nginx` profile correctly grants no general write access to `/tmp`, `/var/tmp`, or another scratch directory, so a host without a usable temp location fails before producing a confined result. Do not widen the production profile to make this test pass.

Version 1.0.16 declares the exact probe link as a matrix fixture:

`/var/www/wpcaregrid-production/releases/wpcg-fixture/public/wpcg-probe-escape` → `/etc/wpcaregrid-deploy/wpcg-fixture-canary`

The pinned fixture tool creates, verifies, and removes that link with the other canaries. Before probing, `probe.py` verifies the exact path and target against the matrix and the live symlink, then attempts the read through the link. The baseline must reach the canary; each confined profile must deny access to the resolved target with matching audit evidence. A missing or mismatched link is a probe refusal and cannot produce a partial result. The probe creates no scratch file or symlink, and no AppArmor or systemd permission is added.

The matrix digest changed. Evidence collected with 1.0.15 is diagnostic only and cannot be combined with 1.0.16 evidence. Keep the failed 1.0.15 run intact; perform a fresh 1.0.16 package-integrity check, fixture inventory, complete `post` acceptance, and pinned verifier run. A failed role, incomplete evidence, missing correlated denial, or inventory change remains HOLD. Run pinned fixture cleanup only after verifier PASS and then confirm the matrix fixture set is absent.

The existing `/run/mysqld/wpcaregrid-acceptance/canary.sock` procedure from 1.0.15 is unchanged. Keep `/run/mysqld` ownership and mode unchanged; use only the dedicated parent and pinned sibling listener required by the CLI profile checks.

Do not continue to helper installation, secret placement, DB/application checks, or route opening from a Gate 2 result. Those remain separate gates.

## Validation

The 1.0.16 regression test checks package checksums and binding, the exact matrix symlink fixture across all acceptance roles, fixture create/status/remove behavior, refusal on absent or mismatched symlinks, the unchanged verifier's target-denial contract, unchanged installed runtime bytes, and byte-for-byte immutability of package 1.0.15. Ubuntu 26.04 CI compiles the unchanged runtime profiles and runs the 1.0.16 regression test.

## Gate 2 `post` live acceptance: pinned orchestration artifact

The complete four-profile live procedure is implemented by the separate
commit-pinned artifact at
`deploy/php-fpm-confinement/orchestrators/1.0.16-post/`. This is not package
1.0.17: the 1.0.16 acceptance inputs, runtime files, profiles, units, route,
and verifier contract do not change. The Git commit containing this artifact,
its artifact-tree object, and the artifact `SHA256SUMS` are the outer
immutable pins. The artifact's `PINNED_INPUTS.json` binds it to the exact
1.0.16 package tree, manifest, binding, matrix, collector, derive tool, probe,
sender, sibling listener, verifier, and fixture tool. Do not run an unpinned
`/tmp` copy or a runner from a mutable worktree.

### Preconditions and external route attestation

Run only after the protected primary has merged the artifact and exact-head CI
and independent review have passed. Owner approval for this Gate 2 run must be
recorded. The host must already have the exact 1.0.16 package staged, the
versioned 1.0.16 `fd-sender.py` published and pinned, and the dedicated
`/run/mysqld/wpcaregrid-acceptance` parent present as root:root 0755. The
orchestrator verifies package bytes/modes/ownership/links, all 21 fixtures,
services, parser, sockets, probe-label absence, and a root-only route attestation
before creating any probe profile or listener. It never creates or repairs a
fixture/canary parent and never changes `/run/mysqld` ownership or mode.

The route attestation is a separate root:root 0600 regular file with one link,
directly under the root:root 0700 evidence envelope. Its exact JSON schema is:

```json
{
  "schema": 1,
  "host": "stg1.wpcaregrid.com",
  "provider": "cloudflare",
  "route_closed": true,
  "apex_dns_only": true,
  "www_dns_only": true,
  "no_aaaa": true,
  "no_load_balancer": true,
  "no_tunnel": true,
  "origin_explicit_404": true,
  "origin_app_upstreams": 0,
  "provider_export_sha256": "<64 lowercase hex chars>",
  "origin_config_sha256": "<64 lowercase hex chars>"
}
```

An authorized operator creates this record only after independently reviewing
the current provider export/control-plane state and the origin's effective
Nginx evidence. The two hashes identify those reviewed source artifacts; the
record is not itself proof of provider state. DNS-only records alone do not
exclude a load balancer or tunnel. If the current provider/origin evidence does
not support every field, do not create an all-true attestation; the run must
remain HOLD.

### Install and verify the immutable bundle

Set `ORCH_COMMIT` to the exact full merge commit recorded for the reviewed PR;
do not use a moving branch name. The tracked bundle must be absent at the target
path before extraction. Run as root from the trusted existing checkout, with
`set -euo pipefail`:

```sh
set -euo pipefail
ORCH_COMMIT='<exact reviewed merge commit>'
ORCH_REL='deploy/php-fpm-confinement/orchestrators/1.0.16-post'
ORCH_TREE='ac7a5b71bcb400efa92606cb71006672b705865f'
test "$(git -C /home/deploy/wpcaregrid rev-parse "$ORCH_COMMIT:$ORCH_REL")" = "$ORCH_TREE"
DEST='/usr/local/libexec/wpcg-gate2-orchestrators/1.0.16-post'
BASE='/usr/local/libexec/wpcg-gate2-orchestrators'
test -d /usr/local/libexec && test ! -L /usr/local/libexec
test "$(stat -c '%u:%g:%a' /usr/local/libexec)" = '0:0:755'
if [ ! -e "$BASE" ] && [ ! -L "$BASE" ]; then install -d -o root -g root -m 0755 "$BASE"; fi
test -d "$BASE" && test ! -L "$BASE"
test "$(stat -c '%u:%g:%a' "$BASE")" = '0:0:755'
test ! -e "$DEST" && test ! -L "$DEST"
install -d -o root -g root -m 0755 "$DEST"
git -C /home/deploy/wpcaregrid archive --format=tar "$ORCH_COMMIT" "$ORCH_REL" \
  | tar --extract --file=- --directory="$DEST" --strip-components=4 --no-same-owner
diff -u \
  <(printf '%s\n' '100644 PINNED_INPUTS.json' '100644 README.md' '100644 SHA256SUMS' '100755 acceptance-post.py' '100755 one_shot_bridge.py' '100644 pty_io.py' | LC_ALL=C sort) \
  <(git -C /home/deploy/wpcaregrid ls-tree -r --format='%(mode) %(path)' "$ORCH_COMMIT" -- "$ORCH_REL" | sed "s#^\\([0-9]*\\) $ORCH_REL/#\\1 #" | LC_ALL=C sort)
diff -u \
  <(git -C /home/deploy/wpcaregrid ls-tree -r --name-only "$ORCH_COMMIT" -- "$ORCH_REL" | sed "s#^$ORCH_REL/##" | LC_ALL=C sort) \
  <(cd "$DEST" && find . -maxdepth 1 -type f -printf '%P\n' | LC_ALL=C sort)
test "$(find "$DEST" -mindepth 1 -maxdepth 1 -type f | wc -l)" -eq 6
test -z "$(find "$DEST" -mindepth 1 ! -type f ! -type d -print -quit)"
test -z "$(find "$DEST" -mindepth 1 -type d -print -quit)"
test -z "$(find "$DEST" -type f -links +1 -print -quit)"
chown root:root "$DEST"/*
chmod 0644 "$DEST"/*
chmod 0755 "$DEST/acceptance-post.py" "$DEST/one_shot_bridge.py"
chown root:root "$DEST"
chmod 0755 "$DEST"
test "$(stat -c '%u:%g:%a' "$DEST")" = '0:0:755'
for file in "$DEST"/*; do
  case "${file##*/}" in acceptance-post.py|one_shot_bridge.py) mode=755 ;; *) mode=644 ;; esac
  test -f "$file" && test ! -L "$file"
  test "$(stat -c '%u:%g:%a:%h' "$file")" = "0:0:$mode:1"
done
cd "$DEST"
sha256sum -c --strict SHA256SUMS
test "$(sha256sum SHA256SUMS | cut -d' ' -f1)" = 'af5831f07753e47b5aa38ac8d8e3837133c889852d3f015b2b75b408192a9549'
test "$(sha256sum one_shot_bridge.py | cut -d' ' -f1)" = '6fe069525fa5e0f9b4b01f96e590323be4eec2699101693426052a1d569ac634'
test "$(sha256sum acceptance-post.py | cut -d' ' -f1)" = '6901b25fd5062c01133ba225a3dbf36b3e7df2d2d5b99704c8c150af6f85edd1'
```

Before proceeding, compare the staged file names to the exact artifact tree,
confirm all six files are regular files with nlink=1, owner root:root, and the
Git-mode mapping (the two scripts above 0755; other files 0644). Confirm the
directory is root:root 0755, then record the full merge SHA, artifact tree,
manifest hash and individual `one_shot_bridge.py` hash in the fresh evidence
envelope. Any pre-existing destination or mismatch is HOLD; never overwrite.
The bridge itself is executed from this root-owned 0755 directory by the
interactive deploy operator, and it runs the sibling `acceptance-post.py`
under the same root-owned immutable bundle after `su -` identity validation.

### One controlled run

From a real interactive TTY on `stg1.wpcaregrid.com`, create a fresh
root:root 0700 evidence envelope. The route attestation described above must
already be present directly inside it. Invoke the staged bridge once, passing
the one-use card file capability only as `--root-card-file`; the bridge waits
for the real `su -` password prompt before opening it, sends it only to that
PTY, unlinks it immediately, and never puts its contents in argv, environment,
logs, or evidence. Example invocation (replace the file capability and the three paths with the
already approved, exact host paths):

```sh
python3 -B /usr/local/libexec/wpcg-gate2-orchestrators/1.0.16-post/one_shot_bridge.py \
  --root-card-file '<one-use private-card file capability>' \
  --package-dir '/root/<existing staged package-1.0.16 path>' \
  --evidence-envelope '/root/<new 0700 evidence envelope>' \
  --route-closure-evidence '/root/<new 0700 evidence envelope>/route-closure.json'
```

The bridge accepts only the exact root UID/host identity and one validated
final report. A missing marker, invalid report, timeout, nonzero command,
unreaped child, or nonzero `su` exit is HOLD. The orchestrator then:

1. Rechecks the exact bundle/package pins, Git modes, root ownership, file
   types/link counts, package checksums, fixture set, service/profile/socket
   state, parser, canary parent and route attestation before any active step.
2. Processes the matrix `post` profiles in this exact order:
   `wpcaregrid-nginx`, `wpcaregrid-php-fpm-production`,
   `wpcaregrid-php-cli-production-build`,
   `wpcaregrid-php-cli-production-deploy`. It uses one fresh complete pinned
   inventory before and after each profile, the matrix `peer_roles`, and the
   pinned verifier's `check_inventory()` and final verdict.
3. For each profile, starts a fresh pinned sender, captures a baseline, derives
   and loads only that profile's probe profile in enforce mode, captures the
   confined probe, unloads and verifies absence, captures the boot/call-window
   correlated kernel audit, and runs the original-profile Python-exec denial
   only where required by the matrix. Raw JSON, stderr and audit evidence stay
   in that run's root-only evidence directory.
4. Starts the matrix-pinned sibling listener only around the baseline/confined
   pair for the two CLI profiles. Each invocation uses a fresh 128-bit random
   suffix in its transient systemd unit name. The runner records ownership only
   after that exact `systemd-run` call returns success; cleanup can stop only
   the recorded nonce unit, never the legacy/shared `wpcg-sibling-canary.service`.
   If `systemd-run` fails or times out, a late/active unit is ambiguous and is
   left untouched for operator review (HOLD). After a successful start it
   verifies the exact banner, stops its own unit, and verifies that unit is
   inactive/unloaded and the socket is absent before moving on. It does not run
   PHP cache-building or database identity commands.
5. Runs the pinned verifier separately for each profile with only that
   profile's baseline, confined, before/after inventory and correlated audit
   inputs. Any failure stops the run and preserves evidence. No cleanup occurs
   unless all four verifier calls pass.
6. After all four verifier PASS results, runs only the pinned fixture removal
   tool, then verifies zero matrix fixtures, no probe profile, and no sibling
   listener/socket. Any cleanup or zero-state mismatch is HOLD.

This artifact does not install the production helper, provision secrets, run
database/application health checks, or open/activate a production route. Those
remain separate gates requiring their own authorization.
