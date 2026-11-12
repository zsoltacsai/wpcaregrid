# GO-5.3 helper upgrade

`go53-upgrade-helper.py` replaces only the installed initializer helper and its manifest. Supply the repository's verified four-file package directory: `production-g1-mysql.py`, `production_g1_mysql_contract.py`, `production-g1-mysql-manifest.json`, and `SHA256SUMS`. The checksum file must contain exactly the three pinned entries for helper, contract, and manifest. The installer independently checks the package contract against its pin but never installs it. It requires the exact pinned pre-upgrade or post-upgrade hashes, unchanged installed contract and both AppArmor profile hashes, inactive G1 units, and a stable `mysql.service` MainPID. It fingerprints both instance datadirs and their logs before and after the operation. No MySQL command, service start, initialization, AppArmor reload, or datadir write is part of the transaction.

Recreate that package from the reviewed #349 merge commit in a trusted repository checkout (run this as the unprivileged package preparer, not on the VPS):

```sh
set -eu
commit=4e276b13adbf1dc6b1f2b7b92bed2ea4c34e4ac0
pkg="$PWD/go53-helper-package"
mkdir -m 0700 "$pkg"
git show "$commit:scripts/production-g1-mysql.py" > "$pkg/production-g1-mysql.py"
git show "$commit:scripts/production_g1_mysql_contract.py" > "$pkg/production_g1_mysql_contract.py"
git show "$commit:scripts/production-g1-mysql-manifest.json" > "$pkg/production-g1-mysql-manifest.json"
(cd "$pkg" && sha256sum production-g1-mysql.py production_g1_mysql_contract.py production-g1-mysql-manifest.json > SHA256SUMS)
```

Before use, verify that the resulting three hashes equal the pins embedded in `go53-upgrade-helper.py`; do not edit or regenerate the pins from the package being checked.

The tool takes a bounded exclusive `flock` on a root-owned mode-0600 lock file under `/run`, holding it for the full transaction. It writes a root-owned mode-0600 journal containing the pinned OLD/NEW maps and base64 backups of the two original files, then fsyncs the journal and containing directory before replacement. Each target is replaced by a same-directory temporary file, file fsync, atomic rename, and directory fsync. Datadir and log fingerprints stream data in fixed-size chunks. On ordinary failure, it restores both old files and verifies their hashes. After a kill during replacement, the next invocation validates the journal and target hashes; it removes only a recognized root-owned regular temporary file belonging to a `replacing` transaction, restores the exact OLD pair, then starts a fresh upgrade. Corrupt journals, unknown hashes, unrecognized temporary files, or failed restoration stop for manual investigation. A completed target pair is idempotently verified.

Run only after the package itself has been acquired and verified, and only under a separate Owner GO. This tool does not authorize restore initialization or GO-5.4.
