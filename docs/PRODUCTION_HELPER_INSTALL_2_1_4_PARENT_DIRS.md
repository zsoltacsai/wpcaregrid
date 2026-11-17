# Production helper 2.1.4 — parent-directory provisioner (source only)

Status: **source only. Nothing here is installed or has run on a host.** This document
describes one narrow root-console tool:

`scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py`

It creates **only** the four parent directories the confirmed 2.1.4 installer
(`scripts/install_production_helper_2_1_4.py`) refuses to run without:

| Path | Owner | Mode |
| --- | --- | --- |
| `/var/lib/wpcaregrid-deploy` | `root:root` | `0755` |
| `/usr/local/libexec/wpcaregrid-deploy` | `root:root` | `0755` |
| `/etc/wpcaregrid-deploy` | `root:root` | `0755` |
| `/etc/wpcaregrid-deploy/profiles` | `root:root` | `0755` |

The list is asserted in tests against `PRODUCTION.required_dirs` in the installer, so the
two files cannot drift silently.

## What this tool does not do

* It does not install, copy, execute or modify the production helper, its modules, services,
  Nginx, PHP-FPM, the database, sudoers, users or groups.
* It does not create `staging-next`, staging apps or staging helpers. An existing
  `/var/lib/wpcaregrid-deploy` is accepted only if it is already an exact `root:root 0755`
  directory; its contents are opaque and are never listed (apart from the emptiness check on
  directories this run created), read, changed, chmodded or chowned.
* It does not repair wrong modes, owners, symlinks or partial state. It refuses them.
* It does not certify security or quality. Independent AppSec and QA review remain required;
  the tests below prove source behaviour only.

## Safety model in one paragraph

The tool must run from a real/effective-root console, never through `sudo`/`doas`/`pkexec`
(`SUDO_*`, `PKEXEC_UID`, `DOAS_USER` are refused, as are `PYTHONPATH`, `PYTHONHOME`,
`LD_PRELOAD` and similar). The running file must be a `root:root 0700` regular file with a
single link inside a `root:root 0700` directory, and its SHA-256 must equal
`--expected-sha256`. Every path component is opened relative to a directory descriptor with
`O_DIRECTORY|O_NOFOLLOW|O_CLOEXEC`; device, inode, owner, group and mode are checked on the
descriptor and against the directory entry before any write. A hash-chained, fsynced journal
is written before the first `mkdir`. Only paths proven absent at preflight are created.
Receipts are published by atomic hard link and can never overwrite an existing file. Rollback
and recovery remove only empty directories whose current inode equals the inode recorded by
this run; anything else becomes `HOLD`. `SIGINT`/`SIGTERM`/`SIGHUP` roll back the run's own
inodes. `SIGKILL` cannot be caught, so the unfinished journal forces the next invocation to
refuse with `RECOVERY_REQUIRED` until `--recover` proves and removes those exact inodes.

## Exact source hashes

| File | SHA-256 |
| --- | --- |
| `scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py` | `694ecad268108655e2d82582ca838a43be5f01f6987b6ac08b68a969eea7c57d` |
| `scripts/tests/root-console-parent-dirs-2-1-4-test.py` | `48e4c133bf3999f2bed4bed2c9ceca32b618f3fa07910a18925dbcd2933e2a6a` |

Recompute both after any merge that changes either file; the operator must use the hash of the
reviewed, merged commit, not this document's snapshot. The tool hashes itself, so a stale
`--expected-sha256` fails closed with `SCRIPT_HASH_MISMATCH`.

## Operator sequence

Every command is run **directly in the Owner-controlled root console**. Do not prefix with
`sudo`; do not run from a deploy shell.

### 1. Stage the verified copy

```sh
install -d -o root -g root -m 0700 /root/wpcaregrid-deploy-parents-2.1.4
install -o root -g root -m 0700 \
  scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py \
  /root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py
sha256sum /root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py
```

The printed hash must equal the reviewed hash. The same directory is the default
`--state-dir`; it must already exist `root:root 0700` and is never created by the tool. It
must not be inside any target path. If a different state directory is used, create it the
same way and pass `--state-dir`.

### 2. Smoke test and read-only plan

```sh
/root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py --version
/root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py \
  --plan --expected-sha256 <reviewed-sha256>
```

`--version` prints `2.1.4`. A successful plan prints:

```text
PROVISION=PLAN code=preflight_ok receipt=plan-<run-id>.json
```

Read `plan-<run-id>.json` in the state directory. `targets` must list exactly the four paths,
with `state` `absent` for those to be created and `existing` for any already-correct
directory. A plan performs no `mkdir`, `chmod` or `chown`.

If the plan ends in `PROVISION=HOLD` or `PROVISION=HOLD code=...`, stop. Do not work around a
refusal by editing paths by hand.

### 3. Apply

```sh
/root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py \
  --apply --expected-sha256 <reviewed-sha256>
```

Expected success:

```text
PROVISION=PASS code=provisioned journal=journal-<run-id>.jsonl receipt=receipt-<run-id>.json
CREATED=/var/lib/wpcaregrid-deploy,/usr/local/libexec/wpcaregrid-deploy,/etc/wpcaregrid-deploy,/etc/wpcaregrid-deploy/profiles
```

Only paths printed in `CREATED=` were created. `CREATED=` is empty when nothing was needed.

Verify the result without changing it:

```sh
stat -c '%U:%G %a %n' \
  /var/lib/wpcaregrid-deploy \
  /usr/local/libexec/wpcaregrid-deploy \
  /etc/wpcaregrid-deploy \
  /etc/wpcaregrid-deploy/profiles
```

Each line must read `root:root 755`.

### 4. Confirm idempotence

Run the same `--apply` command again. Expected:

```text
PROVISION=NO_OP code=already_complete receipt=receipt-<run-id>.json
```

### 5. Recovery after interruption or `SIGKILL`

Any interruption that leaves an unfinished journal makes the next `--plan` or `--apply`
refuse with:

```text
PROVISION=HOLD code=RECOVERY_REQUIRED
```

Run recovery with the same verified copy and hash:

```sh
/root/wpcaregrid-deploy-parents-2.1.4/provision-2.1.4.py \
  --recover --expected-sha256 <reviewed-sha256>
```

Expected:

```text
PROVISION=ROLLBACK code=recovery_complete journal=journal-<run-id>.jsonl receipt=recovery-<run-id>.json
REMOVED=<paths this run proved it created>
```

Recovery is identity-bound: it removes only empty directories whose inode matches the
journal's `created` record. If interruption occurs after `mkdir` but before that identity is
durably journaled, the next recovery refuses with `UNEXPLAINED_STATE` and preserves the
directory. Creation time is not proof of ownership or provenance. That narrow window requires
Owner-led identity adjudication; do not delete or adopt the directory automatically. Catchable
SIGINT/SIGTERM/SIGHUP is handled by the provisioner and rolls back journaled identities; an
uncatchable SIGKILL or power loss may leave the narrow unrecorded window on HOLD. Anything
unrecognized fails closed:

```text
PROVISION=HOLD code=UNEXPLAINED_STATE
```

On `UNEXPLAINED_STATE` (or any other `HOLD`), stop and escalate with the receipt and journal.
Do not delete, rename or chmod anything manually; the tool cannot distinguish operator edits
from concurrent changes.

## Exit codes

| Exit | Meaning |
| --- | --- |
| `0` | `PLAN`, `PASS`, `NO_OP`, or a successful `--recover` (`ROLLBACK`) |
| `1` | Every `HOLD`, every explicit refusal, or any failed rollback |

## Common refusal codes

| Code | Meaning |
| --- | --- |
| `ROOT_REQUIRED` | Not real/effective root (or non-root group IDs) |
| `DELEGATED_INVOCATION_REFUSED` | `sudo`/`doas`/`pkexec` variables present |
| `UNSAFE_ENVIRONMENT_REFUSED` | Interpreter/loader injection variables present |
| `SCRIPT_HASH_REQUIRED` / `SCRIPT_HASH_MISMATCH` | Missing or wrong `--expected-sha256` |
| `SCRIPT_LOCATION_UNTRUSTED` | Running copy is not `root:root 0700` single-link in a `root:root 0700` directory |
| `STATE_DIRECTORY_UNAVAILABLE` / `UNSAFE` / `OVERLAPS_TARGET` | State directory missing, symlinked/wrong mode, or inside a target |
| `PARENT_MISSING` | A required base parent (for example `/usr/local/libexec`) is absent |
| `SYMLINK_REFUSED` | A target or any path component is a symlink |
| `DIRECTORY_OWNER_INVALID` / `DIRECTORY_MODE_INVALID` | Existing ancestor or target is not `root:root` / `0755` |
| `DIRECTORY_UNSAFE` / `DIRECTORY_TYPE_INVALID` | Existing entry is not a safe directory |
| `TARGET_EXISTS_UNEXPECTED` / `TARGET_IDENTITY_CHANGED` | A target appeared or changed between proof and write |
| `PARENT_IDENTITY_CHANGED` / `INODE_IDENTITY_CHANGED` | An ancestor's inode/owner/mode changed during the run |
| `RECOVERY_REQUIRED` | An unfinished journal exists; run `--recover` |
| `UNEXPLAINED_STATE` | Recovery cannot prove an inode belongs to this run |
| `PROVISION_BUSY` | Another invocation holds the state-directory lock |
| `RECEIPT_EXISTS` / `JOURNAL_EXISTS` | The requested run ID already has a receipt/journal |

## Evidence retained

* `plan-<run-id>.json`, `receipt-<run-id>.json`, `hold-<run-id>.json`,
  `recovery-<run-id>.json` — atomic, no-overwrite receipts with outcome, code, script hash,
  journal name/digest and per-target identity.
* `journal-<run-id>.jsonl` — append-only, hash-chained `prepared`, `created`, `removed`,
  `hold`, `complete`, `rolled_back` records, fsynced per line.
* `lock` — the operator state directory's single-writer lock.

Keep the state directory until independent review closes the installation. Never edit these
files; a chain or identity change is itself a refusal.

## Test evidence

Run from the repository root as a normal user; the suite uses synthetic temporary trees only:

```sh
python3 -B scripts/tests/root-console-parent-dirs-2-1-4-test.py
python3 -m py_compile \
  scripts/provision_wpcaregrid_deploy_parent_dirs_2_1_4.py \
  scripts/tests/root-console-parent-dirs-2-1-4-test.py
```

The suite covers: exact installer contract match; parental ordering; plan immutability;
positive apply; idempotent `NO_OP`; protected existing content; exact modes under a permissive
umask; wrong mode/owner/symlink/non-directory/missing-parent refusals; state-directory
pre-existence, mode, symlink and overlap refusals; atomic no-overwrite receipt and journal
publication; tampered journal chains; parent and target inode races; unexpected mid-run
target; `SIGINT`/`SIGTERM`-style rollback; simulated `SIGKILL` requiring recovery; recovery
identity mismatch; lock contention; script self-hash/mode/location checks; and static scope
checks (no privileged mutation or network/process primitives).

This is not an AppSec or QA certification.
