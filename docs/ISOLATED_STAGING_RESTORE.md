# Isolated staging backup restore

This procedure is a separately reviewed, one-time recovery aid for the pinned
staging backup. Preparing or merging the tool does not authorize its execution.
The application checkout, recovery controller, and backup identities are pinned
in `scripts/isolated-staging-restore.py`; do not substitute them.

## Preconditions

An authorized operator must verify the staging MySQL instance identity,
dedicated staging scope, replication state, original global settings, and the
closed release gate. The isolated restore uses a newly named database on the
existing staging MySQL instance; it is not an off-host disaster-recovery test.
Use only the approved backup path and its pinned SHA-256. Keep the source
archive unchanged. The tool refuses unexpected SQL, selectors, schema
references, global/replication operations, unsafe object definitions, archive
hash mismatch, or a target schema that already exists. It does not drop a
schema after success or failure.

The release recovery prerequisites and any DBA migration window remain
separate. This restore tool does not run Laravel migrations, alter MySQL global
settings, or authorize opening the maintenance gate.

## Operator procedure (not executed by this change)

After the tool commit is merged and its exact SHA and CI are verified, install
that exact file as a root-owned mode-`0600` copy under `/root`, then verify its
SHA-256 against the file hash recorded for the exact merged source commit. The
install source must already be present
through the approved operator delivery path; this document does not authorize
transferring the file. The application checkout must remain clean
at `ee3d29818ff1647d82087446dfaf017ca688cb77`, and the recovery-tool source
commit must be available in its Git object store. If that exact Git object is
not already available, stop and use only the approved repository-fetch process;
do not alter the application checkout.

Obtain the exact local MySQL Unix socket path from the separately verified DBA
host evidence; do not infer or substitute a path. Set it in `MYSQL_SOCKET`.
The tool opens the specified non-symlink Unix socket itself, checks Linux peer
credentials against the `mysql.service` systemd `MainPID`, executable and
cgroup, and only then relays MySQL client traffic through a root-only temporary
Unix socket. A path race or a different local listener fails before the dump
is sent. The MySQL client uses `--no-defaults` first and `--no-login-paths`, so
client option and login-path files cannot add connection-time commands.
Each client session pins `sql_mode` to the audited `NO_AUTO_VALUE_ON_ZERO`
setting. The dump preflight rejects any other `sql_mode` assignment, including
lexical modes that would change quote or escape parsing.
For compatibility with the standard `mysqldump` framing, it removes only the
single exact executable-comment footer that restores `@OLD_SQL_MODE`, and only
when the matching standard save-and-pin line occurs exactly once before it.
The session ends after import, so the pinned mode needs no in-session restore;
reordered, duplicated, or otherwise changed save/restore statements fail
closed. The original archive is never rewritten.

Choose a fresh schema name matching
`wpcg_recovery_restore_YYYYMMDD_<8-lowercase-hex>` and verify that it is absent.
Set `RESTORE_TOOL_SHA` to the full merged source commit SHA. Run the read-only
dump inspection first:

```sh
sudo /usr/bin/python3 /root/isolated-staging-restore.py inspect --schema wpcg_recovery_restore_20261104_a1b2c3d4 --restore-tool-sha "$RESTORE_TOOL_SHA"
```

Only after an independent operator review of the inspection result and a
separate execution authorization, run the restore once:

```sh
sudo /usr/bin/python3 /root/isolated-staging-restore.py restore --schema wpcg_recovery_restore_20261104_a1b2c3d4 --restore-tool-sha "$RESTORE_TOOL_SHA" --socket "$MYSQL_SOCKET"
```

Replace the example schema with one newly generated name. Do not retry on failure. The tool
leaves its disposable schema in place for review; cleanup requires a separate
explicitly approved operation. Never run these commands against production.
