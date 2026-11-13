# Production G1 — GO-6.2 Option A read-only inventory

Status: source preparation only. This document and the accompanying helper do not
authorize host changes, privileged execution, a MySQL start, or any deployment.
They were not executed against a production host as part of this change; no
credentials were used and no production system was contacted.

## Objective

Provide a standalone, fixed-scope, read-only inventory for the two isolated G1
MySQL instances and the surrounding production deployment state. The helper
answers "what is observable from an authorized root console without database
authentication" and reports every fact as `PASS`, `FAIL`, `UNKNOWN`, or
`UNAVAILABLE`. It never converts missing or privileged evidence into a pass.

Option A keeps this work independent of `wpcaregrid-deploy-priv`. It adds no
sudoers rule, no privileged helper, no database credential, no secret, no host
access path, and no public CI output. It does not extend or modify the existing
GO-0 toolkit pin or the immutable GO-5.4a package pin.

## Fixed scope

The helper is one Python 3 file with no arguments, no shell, no caller-supplied
path, and no arbitrary command. Every observation uses the following fixed paths
and fixed `argv` arrays.

Runtime and services:

| Source | Command or path |
| --- | --- |
| OS identity | `/usr/lib/os-release`, then `/etc/os-release` |
| Kernel | `platform.release()` |
| PHP CLI | `/usr/bin/php -v` |
| PHP-FPM | `/usr/sbin/php-fpm8.4 -v` |
| Nginx | `/usr/sbin/nginx -v` |
| MySQL server | `/usr/sbin/mysqld --version` |
| Units | `/usr/bin/systemctl show <unit> --no-pager ...` |

Deployment and evidence paths:

| Fact | Fixed path |
| --- | --- |
| Active release symlink | `/var/www/wpcaregrid-production/current` |
| Release directory | `/var/www/wpcaregrid-production/releases/<40-hex>` |
| Release manifest | `<release>/release-manifest.json` |
| Deployment state | `/var/lib/wpcaregrid-deploy/production/state` |
| Transaction journals | `state/db-release-<32-hex>.json` |
| Database backups | `/var/lib/wpcaregrid-deploy/production/database-backups` |
| Deploy lock | `state/deploy.lock` |
| Migration lock | `state/database-release.lock` |
| Restore datadir | `/var/lib/mysql-wpcaregrid-production-restore` |
| AppArmor loaded profiles | `/sys/kernel/security/apparmor/profiles` |
| AppArmor denials | `/usr/bin/journalctl -k -n 2000 --no-pager -o cat` |
| Active advisory locks | `/proc/locks` matched to fixed lock-file device/inode metadata |

G1 installed artifacts:

| Name | Path | Expected mode |
| --- | --- | --- |
| helper | `/usr/local/libexec/wpcaregrid-g1-mysql.py` | `0755` |
| contract | `/usr/local/libexec/production_g1_mysql_contract.py` | `0644` |
| manifest | `/usr/local/libexec/production_g1_mysql_manifest.json` | `0644` |
| production config | `/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf` | `0644` |
| restore config | `/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf` | `0644` |
| production drop-in | `/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf` | `0644` |
| restore drop-in | `/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf` | `0644` |
| production profile | `/etc/apparmor.d/wpcaregrid-mysql-production` | `0644` |
| restore profile | `/etc/apparmor.d/wpcaregrid-mysql-production-restore` | `0644` |

MySQL option values (never runtime values) are read with
`/usr/bin/my_print_defaults --defaults-file=/etc/mysql/my.cnf
--defaults-group-suffix=@wpcaregrid-production mysqld server mysqld-8.4`, and
once with the suffix `@wpcaregrid-production-restore`, so the two instances are
classified independently.
No MySQL client is invoked and no connection is opened.
Binary logging is reported from the last applicable `--log-bin`,
`--skip-log-bin`, or `--disable-log-bin` option. A basename such as `OFF` is
still a basename and means binary logging is enabled; the helper never treats
that string as a disable switch. Missing binary-log, binlog-format, or trust
settings are `UNKNOWN`, not `PASS`.

## Output and statuses

The helper prints exactly one JSON document to stdout. The schema is `1`, the tool
is `go62-production-inventory`, and the version is `1.0.4`.

| Status | Meaning | Exit |
| --- | --- | --- |
| `PASS` | Evidence was obtained and matches the expected contract. | `0` |
| `FAIL` | Evidence was obtained and contradicts the expected contract. | `3` |
| `UNKNOWN` | No authorized evidence exists to decide. | `4` |
| `UNAVAILABLE` | The required source could not be read or executed. | `5` |

These are status-to-exit mappings, not a promise that every outcome is reachable
from a complete invocation. The audit section is always `UNKNOWN` because
syslog delivery is unverified, so a complete healthy invocation normally has
`overall=UNKNOWN` and exits `4`; `FAIL` may override it and exit `3`. `PASS`/0
and `UNAVAILABLE`/5 are reserved mappings, not expected smoke-test outcomes.

Aggregation is fail-closed and ordered: `FAIL` dominates `UNKNOWN`, which
dominates `UNAVAILABLE`, which dominates `PASS`.

Because privilege and trigger state require authenticated database access that
this scope deliberately does not use, those facts are always `UNKNOWN`. The
`mysql` section may be `UNKNOWN` when the settings are read but privilege/trigger
facts remain unavailable, or `UNAVAILABLE` when its fixed option reader cannot
run. Missing settings are `UNKNOWN`. The overall report is normally `UNKNOWN`
because audit delivery is never confirmed. The report does not pretend to
certify what it cannot observe.

Sections:

* `runtime` — OS identity, kernel, PHP CLI/FPM, Nginx and MySQL versions, and the
  PHP-FPM, Nginx and vendor MySQL unit snapshots. Version `PASS` means the
  version string was read and parsed; compatibility against a release contract
  is not asserted by this metadata inventory. A unit snapshot `PASS` means the
  snapshot was read and parsed; it does not itself mean the unit is active.
* `g1_systemd` — both G1 unit snapshots plus the vendor `mysql.service`, and
  whether both G1 instances are inactive.
* `g1_artifacts` — owner, group, mode, size and SHA-256 of each fixed G1 artifact.
  The section checks expected ownership/mode and reports hashes; it does not
  compare those hashes to release pins. It covers the existing GO-5 G1 artifact
  set, not the separately installed GO-6.2 inventory package.
* `apparmor` — loaded mode of each G1 profile and the kernel profile
  `/usr/sbin/mysqld`, plus bounded, sanitized denial summaries. Only denials
  attached to those three profiles affect this section's status; unrelated
  profile denials are counted separately.
* `mysql` — option-file binlog/trigger/read-only settings queried separately
  with the production and restore `--defaults-group-suffix` values,
  unsafe-setting classification per instance, and always-`UNKNOWN`
  privilege/trigger state with an explicit reason.
* `release` — active symlink target, release identity, release metadata and
  manifest-to-directory consistency.
* `backups` — backup inventory, owner/mode/size, SHA-256, gzip integrity and
  journal-evidence match.
* `restore` — restore datadir presence, restore-rehearsal evidence presence and
  readiness. A checkpoint is only considered current when its application SHA
  matches the active release and its recorded backup filename and SHA match an
  inspected, integrity-checked backup. Truncated journal inventories remain
  `UNKNOWN`. Live restore validation is never performed.
* `locks` — deploy and database-release locks are observed from `/proc/locks`
  without acquiring a competing lock; the documented fact that no separate
  backup lock exists is reported as `NOT_SEPARATELY_DEFINED`.
* `journals` — state-directory metadata, inspected/total journal counts and
  truncation status.
* `audit` (top level) — invocation identity, root identity, helper hash,
  best-effort syslog submission status and mode. Delivery is not independently
  confirmed, so the status remains `UNKNOWN`.

## Privileges and triggers are never invented

The helper does not read `.env`, a credential store, a client option file, or a
raw database row. It has no MySQL client in its command allowlist. Therefore:

* privilege categories are reported as `UNKNOWN` with
  `reason=privileged_db_auth_not_authorized`;
* trigger definitions are reported as `UNKNOWN` with
  `reason=trigger_definitions_require_privileged_db_auth`;
* `credentials_used=NONE` and query output is `REDACTED`.

A future authorized database-read contract must be a separate, reviewed change.

## Backup and restore evidence rules

A backup is evidence of a backup, not of a restore. The helper reports:

* backup metadata, SHA-256 and gzip integrity for a bounded number of recent
  backups;
* whether a transaction journal records the same backup SHA-256;
* `restore_proof=NOT_PROVEN` and
  `metadata_only_backup_does_not_prove_restore=true` at all times.

A `restore` `PASS` means a structurally valid transaction journal contains the
nine-field `RESTORE_REHEARSAL_VERIFIED` checkpoint, its transaction ID matches
the journal filename, its state is consistent with checkpoint presence, the
checkpoint receipt hash recomputes correctly, and the checkpoint, journal and
on-disk backup hashes and metadata agree for the active release. It does not
mean a restore was performed by this invocation. The receipt digest is unkeyed;
it shows internal consistency, not who produced the receipt.
`live_restore_validation=NOT_PERFORMED` is always reported. An early-phase
journal without a checkpoint is `UNKNOWN`; a journal in
`RESTORE_REHEARSAL_VERIFIED` or a later valid state that omits its required
checkpoint, or any `RECOVERY_REQUIRED` journal, is `FAIL`.
Journal profile/database identity, migration fields, non-empty migration lists,
and the producer's nanosecond timestamps are validated. Timestamp values must
be at least `1e18` and no more than five minutes ahead of the inventory host's
clock; invalid timestamps or duplicate journal references to one backup fail
closed. The active release manifest must use the exact production schema,
contain no duplicate keys, and be root-owned with mode `0644`.

## Security model

* Fixed paths and fixed `argv` tuples only; `shell=False`; an explicit allowlist
  of `/usr/bin/systemctl`, `/usr/bin/journalctl`, `/usr/bin/my_print_defaults`,
  `/usr/bin/php`, `/usr/sbin/php-fpm8.4`, `/usr/sbin/nginx`, and `/usr/sbin/mysqld`.
* No arguments are accepted. Any argument fails closed before collection.
* Non-root invocation fails before collection; it attempts an audit record.
* Fixed files are opened read-only with `O_NOFOLLOW`, `O_NOATIME`,
  `O_NONBLOCK`, `O_CLOEXEC`, an `lstat`
  pre-check, an `fstat` identity check, a regular-file requirement, and size
  limits. Symlinks, non-regular files and changed files are reported as `FAIL`.
  Fixed state, backup and release directory chains are checked for symlinks,
  ownership and unsafe write permissions before traversal; directory entries
  are listed through `O_NOATIME` descriptors. If a file changes during the
  read/verification sequence, some sections report `UNKNOWN` rather than `FAIL`.
* Release symlinks must resolve inside `releases/` to a single 40-hex directory;
  traversal outside `releases/` is rejected.
* Journal and backup filenames must match their exact patterns; anything else is
  redacted.
* Output text is restricted to safe token/path alphabets. AppArmor denial paths
  are mapped to fixed categories, with unknown paths collapsed to `REDACTED`.
* MySQL option paths are never echoed; binary logging is classified as `ON`,
  `OFF`, or `UNKNOWN` separately for each G1 instance. MySQL `loose-`,
  `enable-`, `disable-`, and `skip-` boolean spellings are normalized for the
  explicitly inspected settings. Missing `local-infile` or
  `skip-grant-tables` evidence also remains `UNKNOWN`.
* Every invocation attempts one fixed-field start record and one finish record
  to the local syslog `auth` facility, including rejected arguments and
  non-root calls. Python's `syslog` API does not confirm durable delivery, so
  the report labels this `ATTEMPTED_UNVERIFIED` and audit status `UNKNOWN` even
  when the API call returns. An exception on the start call aborts collection;
  a finish-call exception also leaves audit status `UNKNOWN`. Audit records
  contain no argument, path or credential data. This is best-effort logging,
  not fail-closed audit evidence; operators must verify both records in the
  system journal during the separately authorized smoke test.

The authorized operator must invoke this tool from the approved root-console
session that can submit to the local syslog `auth` facility. The helper only
checks effective UID 0; it cannot distinguish a root login from `sudo`, and its
audit UID fields do not prove the console path. The helper does not install,
configure or escalate that access, and cannot independently confirm that syslog
persisted a submitted record.

## Package and install proposal

The package proposal is `scripts/production-g1-inventory/`:

* `go62-inventory.py` — the helper;
* `go62-inventory-manifest.json` — schema, version, helper install path/mode
  and helper SHA-256. Its `files` list describes the helper; the manifest does
  not hash itself;
* `SHA256SUMS` — exact checksums for the helper and manifest. It does not hash
  itself, so all three artifact digests must be verified out of band.

The exact, source-only install and rollback command procedure is in
`docs/PRODUCTION_G1_INVENTORY_GO62_INSTALL.md`. It is a proposed procedure, not
authorization; the command blocks are not to be run until a separate Owner
host-install GO names the commit, host, approved root-console path, hashes,
smoke-test scope and evidence reviewer. No secrets or production output are part
of the package.

The GO-6.2 install is separate from the GO-5 G1 helper listed in the fixed-scope
table above: the GO-6.2 directory is
`/usr/local/libexec/wpcaregrid-g1-inventory/`, with a non-executable helper and
manifest at mode `0644` in a root-only `0700` directory. The exact bytes of the
installed helper must be checked against the approved out-of-band digest before
every invocation. The manifest is installed alongside it; `SHA256SUMS` remains
in the root-only handoff and is not installed.

The read-only smoke test is the single authorized root-console invocation
specified in the install runbook. It is not part of CI and must not run in a
production workflow automatically. A successful checksum or gzip test reports
backup metadata/integrity only; the restore gate remains closed until a separate
isolated restore proof exists.

No sudoers entry, systemd unit, timer, credential file, or deploy-priv change is
part of this proposal.

## Unchanged pins

This change does not modify `scripts/production-g1-ops/SHA256SUMS`, the GO-0
toolkit manifest or hashes, or the GO-5.4a package pins. The GO-0 pin at the
base commit remains `0951d9e829364296796a1d4acd303feef698cbc7` with checksum-file
digest `70f92e3a2aba84ca13f5d6c81f732be605c50a09531308878991f7b671c48a9e`.
Any future change to those pins must be explicit and versioned.

## Tests

`scripts/tests/test_go62_production_inventory.py` is hermetic and requires no
root, Docker or MySQL. It injects every subprocess result and builds all
filesystem fixtures under a temporary root. Coverage includes:

* the complete observable fixture and section statuses;
* symlinked artifacts, manifests and release traversal;
* redaction of journal names, MySQL paths, SQL-mode values and AppArmor denials;
* command-allowlist and argument-injection rejection;
* unavailable runtime, state and backup directories;
* backup gzip corruption, hash mismatch, wrong mode, symlink and empty directory;
* journals without a restore checkpoint and malformed journals;
* held, free and wrongly-moded locks;
* argument, non-root and audit-failure guards;
* syslog `auth` facility invocation;
* package `SHA256SUMS`/manifest consistency and unchanged GO-0/GO-5.4a pins.

Run locally with:

```sh
python3 -B -m unittest scripts/tests/test_go62_production_inventory.py
```

CI runs the same suite as a normal non-root step.

## Limitations

* The helper is not a gate and does not certify production readiness.
* Privilege and trigger state remain `UNKNOWN` until a separate authorized
  database-read contract exists.
* Restore proof remains `NOT_PROVEN`; only a live rehearsal in an approved
  environment can change that.
* AppArmor denial summaries are bounded to the last 2000 kernel records and are
  not exhaustive.
* `systemctl` and `journalctl` output is trusted only within the fixed property
  and sanitization grammar; unexpected output is reported, never echoed raw.
