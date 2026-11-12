# Production G1 MySQL — GO-5.4a AppArmor socket-lock hotfix

Status: source preparation only. This document and the accompanying transaction tool do not authorize host changes, AppArmor reloads, or a MySQL start. A separate, exact-scope Owner GO is required after the change is merged and reviewed.

## Cause and scope

The reported production startup denial is for `mknod` on
`/run/mysqld/wpcaregrid-production.sock.lock` under the confined production profile.
The generated profile allowed the instance socket and PID file but omitted the
socket's lock file. The generator now allows only the current instance's exact
`{socket}.lock` path using the same `rwk` permissions as its socket/PID entries.
It explicitly denies the legacy `/run/mysqld/mysqld.sock.lock` and the other G1
instance's lock path. No directory wildcard, systemd notification permission,
sysfs permission, vendor profile, or legacy `mysql.service` rule is added.

The supplied `/run/systemd/notify` and `/sys/devices/system/node/` observations
remain separate. Do not widen access based only on their abbreviated descriptions;
obtain the complete audit records and establish necessity in a disposable test
environment before proposing any additional rule.

## Installed baseline and package pins

The transaction is deliberately bound to the reported installed five-file state:

| Installed artifact | Required OLD SHA-256 | Target NEW SHA-256 |
| --- | --- | --- |
| `/usr/local/libexec/wpcaregrid-g1-mysql.py` | `27c7e1e6ba8536f7f64e9a4bd53ed31fe846678ff05b0a051ca2ca7abe705e1b` | `9ff1b969fa2371d3967c15ff9f6fcf4553ecf5fcda2b039b46e924d885bbf274` |
| `/usr/local/libexec/production_g1_mysql_contract.py` | `270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f` | `0023be227381e617f482c1e905cd0019c1fba604a7aa7050345a95f208c89cf8` |
| `/usr/local/libexec/production_g1_mysql_manifest.json` | `0af365c9515e2eff34d552c185c8d46fff3a5d5f7cade6d1ab3c0de1809ef911` | `eef752c71e15c3a41f1cf5948305b699a5eba6a91b66aeae243787ba9e4e5f9b` |
| `/etc/apparmor.d/wpcaregrid-mysql-production` | `9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d` | `28e02508e195efe285be40932df74b9e6f3c6aa792250ef67c078da40699f7d2` |
| `/etc/apparmor.d/wpcaregrid-mysql-production-restore` | `ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e` | `9567eab67e96d352ab971ca26adafe4e35bc5e12891ef788147ff673bf7cfbab` |

The repository package `scripts/production-g1-ops/SHA256SUMS` pins both GO-5.4a
tools. Its checksum file is pinned by the eventual reviewed merge commit.
Do not use the GO-5.3 or GO-5.2c AppArmor upgrader: their OLD state does not match
this reported installed baseline.

## Transaction behavior

`go54a-upgrade-apparmor.py` is intended to run only from the approved root console
after a separate Owner GO. It requires an exact root-owned `0700` package directory
with precisely three root-owned `0600` payloads and `SHA256SUMS`, then validates
the sum list, every payload hash, regenerated profile hashes, and parser compilation
before replacing anything. Its source targets the five exact OLD hashes above and
refuses mixed or unknown states.

Before changes it requires both G1 systemd units inactive and enforced G1 profiles.
It snapshots the active `mysql.service` MainPID, vendor AppArmor source/loaded
state, both datadirs and both MySQL logs. It atomically replaces only the three
`/usr/local/libexec` files and two named G1 profiles; it reloads only those two
profiles, verifies the new static helper/configuration, and checks the snapshots
again. It never starts or initializes MySQL, edits configuration, writes either
datadir/log, or targets `mysql.service` or the vendor profile. Failures attempt a
hash-checked rollback to the OLD five-file state and report whether rollback was
verified. A recognized interrupted journal is recovered (rollback for `prepared`
or `replacing`; cleanup for `committed`) and the current invocation then stops
for manual review. The operator must inspect the resulting state; any retry is a
separate action. Unknown journal or file state also stops for manual recovery.

The approved operator will first retrieve the fetch/transaction scripts from the
exact reviewed merge SHA through the verified toolkit workflow and verify their
hashes against that commit's `scripts/production-g1-ops/SHA256SUMS`. The fetch
script then retrieves the three payloads and their `scripts/SHA256SUMS` from the
same immutable commit and checks the pinned checksum-file digest and each file
digest before promoting the package directory. The staged directory must be
`root:root 0700`; its three payload files and `SHA256SUMS` must each be
`root:root 0600`. No package preparation or host execution has been performed
as part of this source change.

## Acceptance and stop conditions

Before any future execution GO, require independent exact-HEAD QA and AppSec,
successful required CI including generated-profile parser validation, a fresh
read-only host inventory confirming all five OLD hashes, inactive G1 units,
enforced profile state, stable `mysql.service`, and intact datadirs/logs. If any
identity, hash, owner/mode, service state, parser result, or invariant differs,
stop without retry. After a successful transaction, independently verify the
five NEW hashes, profile enforce state, unchanged vendor profile and legacy MySQL
PID, and unchanged datadir/log fingerprints. A later GO-5.4 start is a separate
decision and must not be chained to this transaction.
