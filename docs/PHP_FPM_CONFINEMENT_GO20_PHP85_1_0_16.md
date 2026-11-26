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
