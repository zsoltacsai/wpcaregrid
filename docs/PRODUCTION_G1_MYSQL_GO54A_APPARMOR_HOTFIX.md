# Production G1 MySQL — GO-5.4a AppArmor socket-lock and notify hotfix

Status: source preparation only. This document and the accompanying transaction tool do not authorize host changes, AppArmor reloads, or a MySQL start. A separate, exact-scope Owner GO is required after the change is merged and reviewed.

## Cause and scope

Two confirmed, independently reviewed defects in the generated profiles are
remediated; nothing else in the transaction changes.

1. **Socket lock** (original GO-5.4a scope). The reported production startup
   denial is for `mknod` on
   `/run/mysqld/wpcaregrid-production.sock.lock` under the confined production
   profile. The generated profile allowed the instance socket and PID file
   but omitted the socket's lock file. The generator now allows only the
   current instance's exact `{socket}.lock` path using the same `rwk`
   permissions as its socket/PID entries. It explicitly denies the legacy
   `/run/mysqld/mysqld.sock.lock` and the other G1 instance's lock path.

2. **Systemd notification** (GO-20.29 root cause; GO-20.30/GO-20.31
   remediation). The vendor MySQL 8.4 template runs `Type=notify`
   (the project's in-repo record of the vendor unit is fixture-based, not a
   host observation: `scripts/tests/production-g1-mysql-contract-test.py`
   pins the vendor unit template shape with `[Service] Type=notify`, and
   `scripts/tests/g1-notify-evidence-test.py` asserts the collector's
   `UNITPROP mysql.service Type=notify` classification; the live unit's
   `Type` and `NotifyAccess` are re-observed host-side by
   `g1-notify-evidence.py` during the separately approved preflight).
   `mysqld` therefore calls
   `sd_notify(3)`, which opens an `AF_UNIX` `SOCK_DGRAM` socket and sends one
   datagram to `/run/systemd/notify`. The previous profile granted neither the
   path write nor the datagram socket family, so a confined G1 start would
   stay in `activating` until systemd's start timeout. The complete minimal
   permission set and its boundary denies, added to both generated profiles:

   - `  /run/systemd/notify w,` — path mediation for the notify socket.
     Write only: `sd_notify` is one-way, so no read or lock permission is
     granted and no `/run/systemd/` directory or wildcard rule is added.
   - `  network unix dgram,` — the socket(2) family/type mediation for the
     datagram socket. AppArmor network rules are default-deny per family and
     type, so the profile's `network unix stream,` does NOT authorize a
     `SOCK_DGRAM` socket; the project's own policy test asserts exactly this
     (`scripts/tests/php-fpm-confinement-test.py`). Without this rule the
     `socket()` call is denied before the path rule is ever reached.
   - `  audit deny unix (connect, send, receive) peer=(addr=@**),` and
     `  audit deny unix (bind, listen, accept) addr=@**,` — abstract-namespace
     unix sockets bypass every path rule, so the stream/dgram family grants
     are bounded by denying abstract endpoints outright: the exact rule pair
     the php-fpm precedent ships in its shared abstraction
     (`deploy/php-fpm-confinement/1.0.6/apparmor/abstractions/wpcaregrid-php-fpm-common`,
     enforced by its `AbstractSocketDenial` tests). mysqld's own socket and
     systemd notify are path sockets; the generated profiles carry NO
     `unix` allow rule — their only `unix` rules are these two denies,
     while unix-family socket creation is granted only by the generic
     `network unix stream,` / `network unix dgram,` rules above, whose
     endpoint reach is bounded by the profile's path rules plus these
     denies.

   Production precedent: the repository's production environment contract
   binds the production php-fpm confinement to package **1.0.5** —
   `scripts/deployment_environment_contract_2_1_4.py` pins it
   (`package_version="1.0.5"`, its immutable Git tree object and
   `SHA256SUMS` digest), and GO-20 keeps helper 2.1.4 bound to 1.0.5 while
   1.0.6 remains source-preparation-only and unbound
   (`docs/PHP_FPM_CONFINEMENT_GO20_PHP85.md`). The bound 1.0.5 production
   profile is a confined `Type=notify` service
   (`deploy/php-fpm-confinement/1.0.5/systemd/wpcaregrid-production-php8.4-fpm.service:12`)
   that grants exactly this pair and nothing more for notification:
   `/run/systemd/notify w,` at line 40 and `network unix dgram,` at line
   55, with its shared abstraction carrying the same abstract-namespace
   denies at lines 32-33 — each rule byte-identical to the corresponding
   1.0.6 rule (lines 45, 60 and 32-33 of the source-only successor). The
   G1 permission set is therefore the production-bound precedent's rule
   set in full: pair plus boundary denies, with path rules the only unix
   socket reach. The deployment's host-stage execution is evidenced by the
   GO-13 records and the #174 release ledger; this document cites the
   in-repo contract binding and the byte-identical rule block.

   The earlier gate in this document — no notify widening before the complete
   audit records and a demonstrated necessity — is closed by the GO-20.29
   analysis and this evidence chain, enforced by a dedicated contract-test
   regressions
   (`test_notify_permission_is_exact_write_only_with_dgram_family_and_nothing_broader`,
   `test_profiles_deny_abstract_namespace_unix_sockets_like_the_php_fpm_precedent`)
   and subject to the mandatory exact-HEAD CI (including generated-profile
   parser compilation) and the three independent GO-20.31 reviews. The
   read-only collector
   `scripts/production-g1-diagnostics/g1-notify-evidence.py` remains part of
   the Owner's preflight for host-side confirmation of the OLD-state denial
   baseline; its output is advisory and does not gate the transaction.

   Deliberately NOT added: `/run/systemd/notify rw`, any `/run/systemd/**`
   wildcard, `network inet/inet6 dgram` (no UDP use), `network netlink` (no
   netlink use), and any `/sys/devices/system/node/` or other sysfs
   permission — that observation remains out of scope, unexplained and
   ungranted. No directory wildcard, vendor profile, or legacy
   `mysql.service` rule is added.

## Installed baseline and package pins

The transaction is deliberately bound to the reported installed five-file state:

| Installed artifact | Required OLD SHA-256 | Target NEW SHA-256 |
| --- | --- | --- |
| `/usr/local/libexec/wpcaregrid-g1-mysql.py` | `27c7e1e6ba8536f7f64e9a4bd53ed31fe846678ff05b0a051ca2ca7abe705e1b` | `9ff1b969fa2371d3967c15ff9f6fcf4553ecf5fcda2b039b46e924d885bbf274` |
| `/usr/local/libexec/production_g1_mysql_contract.py` | `270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f` | `c81af789c99876cd9cf60ed26681eb8501c6bfe7cacfd46b120f748e43d8b4d8` |
| `/usr/local/libexec/production_g1_mysql_manifest.json` | `0af365c9515e2eff34d552c185c8d46fff3a5d5f7cade6d1ab3c0de1809ef911` | `25d8446724740a0f8390b13d6e380b5d75425221a87b6438e25f588ecdf71b35` |
| `/etc/apparmor.d/wpcaregrid-mysql-production` | `9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d` | `64f1bd60ba0b8626978d79951179bb9c2484df1ce79981e567a7a41e057ea73a` |
| `/etc/apparmor.d/wpcaregrid-mysql-production-restore` | `ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e` | `b612b2d913d4d8a178707516d8581724f2c003c5e8076f12e7b6930930c4fc32` |

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
enforced profile state, vendor `mysql.service` with matching initial/closing
observations (ActiveState and MainPID; matching observations, never continuous
stability), and intact datadirs/logs. If any
identity, hash, owner/mode, service state, parser result, or invariant differs,
stop without retry. After a successful transaction, independently verify the
five NEW hashes, profile enforce state, unchanged vendor profile, the same
vendor MySQL MainPID as the pre-transaction observation record, and unchanged
datadir/log fingerprints. A later GO-5.4 start is a separate
decision and must not be chained to this transaction.
