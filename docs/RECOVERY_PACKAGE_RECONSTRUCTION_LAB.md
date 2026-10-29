Attempting to perform the InitializeDefaultDrives operation on the 'FileSystem' provider failed.
# Synthetic Manual Recovery Package Reconstruction Lab

## Purpose and boundary

This test laboratory exercises the customer-driven, manual recovery-package path with artifacts produced by the real Connector `BACKUP_FILES`, `BACKUP_DATABASE`, and `BACKUP_FULL` job handlers. The Connector API is replaced by `FakeBackupServer`; source files, WordPress rows, credentials, and URLs are synthetic.

This is not the Connector automated restore flow and is not a production import implementation. It never invokes `RestoreHandler`, a `RESTORE*` Connector job or route, `BackupIndex::find()`/seeding, or `RestoreIsolationGate`. The only operational job executed in the source fixture is a normal, synthetic `BACKUP_*` job to produce authentic package bytes. Its regular handler may record its own local synthetic backup result.

No live backup, external network, customer information, storage credential, or non-loopback database host is used. A target receives a random database name, a random database-only user/password, new WordPress salts, and (for a Files-only target install) a random administrator login, email, and password. The temporary database user receives privileges only on its unique test schema. Target databases and filesystem trees are removed in test teardown.

## Package preflight and reconstruction

Before the target database or WordPress tree is created or modified, the test laboratory:

1. Requires the exact `wp-caregrid-backup/1` manifest field set and a package type of `full`, `files`, or `database`.
2. Requires exactly the object names permitted by that type: Full=`files+database`, Files-only=`files`, Database-only=`database`.
3. Verifies each object byte length and SHA-256 against its manifest entry.
4. Checks the declared archive format, compression, policy, redacted-config state, SQL engine/version/charset and identical site/database table prefixes.
5. Uses the Connector's `TarReader` with lab-specific bounds (2,000 entries, 32 MiB compressed archive, 64 MiB expanded archive, 16 MiB member, bounded path depth/components and PAX metadata) to reject malformed, truncated, duplicate/case-colliding, linked, device, path-traversal, path-collision, over-limit, and unexpected archive members. It requires the archive's actual regular-file count to match the manifest. Archive members are first extracted only to a private temporary staging tree. `root/wp-config.php` is reference material and is never copied over target-generated configuration.
6. Rejects database objects over 16 MiB compressed, bounds decompressed SQL to 128 MiB, and accepts only the simple one-statement-per-line form emitted by the synthetic `DatabaseDumper`: the matching `SET NAMES`, expected foreign-key/SQL-mode statements, and prefixed `DROP TABLE`, `CREATE TABLE`, and `INSERT` statements. It rejects incomplete, multi-statement, out-of-prefix, comment-injection, or unsupported statements before any target database write.

Only after all package components and metadata pass preflight does the lab provision an isolated target schema. SQL is executed statement-by-statement into that disposable schema. Files are copied from the already validated staging tree into the new WordPress content tree. The source fixture includes synthetic `wp-config.php` credentials and salts; the test verifies the generated package redacts them and never replaces the target's newly generated `wp-config.php`. Imported `active_plugins` is cleared for the lab and the Connector is not activated. A synthetic target URL is set, and a child PHP process boots the target WordPress, verifies installation and sentinels, and performs a synthetic post create/read check. The process is capped at 30 seconds and outbound HTTP is blocked by target configuration.

Malformed-package tests alter synthetic bytes and, for archive-structure cases, update the synthetic manifest size/digest so that the structure parser is exercised after integrity verification. They check a pre-existing target guard file remains unchanged; package validation and staging happen before any target write.

## Outcome classification

| Package | Package integrity | SQL/files reconstruction | WordPress application recovery |
|---|---|---|---|
| Full | PASS only after manifest, object, archive and SQL checks | PASS only after both package components are applied to the disposable target | PASS only if WordPress boots, both synthetic file/database sentinels match, generated target config remains intact, the Connector is inactive, and a synthetic post can be created/read |
| Files-only | PASS under the same checks for its declared files object | Files reconstruction PASS against a separately installed fresh lab WordPress; no source database import | **NOT CLAIMED**. Fresh-install functionality does not prove recovery of the backed-up site's database or application state |
| Database-only | PASS under the same checks for its declared database object | SQL reconstruction PASS into a fresh disposable schema; no backup files are applied | **NOT CLAIMED**. A functioning WordPress core and imported database do not restore omitted site files |

A self-consistent SHA-256 manifest establishes byte integrity relative to that manifest, not package provenance, authenticity, legal entitlement, or restoration safety for real customer data. A passing lab result does not prove that any customer backup is complete or recoverable.

## Test cases

`wordpress/tests/Integration/RecoveryPackageReconstructionLabTest.php` covers:

- Real Connector-generated Full, Files-only and Database-only synthetic artifacts.
- Exact top-level manifest and object inventories, object sizes, SHA-256 values, SQL table-prefix scope, and actual archive regular-file count.
- Retained normal database/file sentinels and exclusion of Connector state/jobs and transient sentinel values from the actual SQL object.
- Isolated target credentials, non-overwrite of target-generated `wp-config.php`, inactive Connector, WordPress installation check, and synthetic post functionality.
- Missing object, corrupted object, unexpected component, incompatible package type/prefix, and SQL/archive metadata rejection before target writes.
- Rehashed synthetic archive fixtures with traversal, duplicate path, and link entries to exercise structural validation rather than only the checksum check.
- Loopback-only DB enforcement, separate random database/user per test, and cleanup.

All three reconstruction tests require the configured disposable MySQL/MariaDB Connector integration environment because every package type is reconstructed into an isolated WordPress target with its own schema/user. They skip if the suite uses SQLite, gzip support is unavailable, the database host is not loopback, or the constrained test-service grants cannot create/drop a uniquely named schema and user; they never fall back to the shared integration database for target import.

## Explicit limitations

- This laboratory is not a supported end-user restore utility, a security-reviewed production importer, or a new download/import endpoint.
- It does not use S3/SFTP, remote object generations, real WordPress installations, customer accounts, or production credentials.
- It tests a single local Connector/WordPress/DB compatibility combination at a time. Cross-version/provider coverage remains a separate acceptance item.
- Files-only and Database-only packages do not receive a full application recovery verdict.
- Database version compatibility is deliberately limited to the same loopback test-service major/minor version as the source dump.
- Manifest hashes detect byte changes only when compared with a trusted manifest; the laboratory does not establish external signatures or authenticity.
- Package integrity, SQL/files reconstruction, and WordPress application recovery are distinct evidence levels. No result here proves recoverability of a live backup.
