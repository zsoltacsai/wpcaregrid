# M147 staging read-only audit runner

Version 1.0.0 is a manually invoked, standalone PHP CLI tool. It is not wired
to deployment, cron, a queue, a web route, or an application bootstrap. It
does not make network requests, start subprocesses, load Laravel or WordPress,
read environment files, or write local state. Stdout is one JSON document;
stderr contains stable error codes only.

## Scope

The initial identity mode reports host name, effective non-root POSIX account,
UTC time, the checked-out Git commit, PHP version, and available filesystem
bytes. The expected hostname, account, and absolute checkout path are required
on every invocation. Any mismatch exits with status 2 and no evidence JSON.

The six M12 gates deliberately remain `UNKNOWN`. The runner has no safe,
verified source for Laravel's effective configuration: repository defaults,
process environment, and a cached Laravel configuration may differ. This tool
does not bootstrap Laravel or read `.env`/configuration cache. A future
effective-gate reader requires a separate design and approval.

The explicit `--mode m12` mode is a fail-closed evidence operation. It emits
only the six normalized gate names, `UNKNOWN`, unavailable-source provenance,
status, and a timestamp. It does not inspect process environment or any
configuration file. It is not a live gate verification.

## Future operator invocation

Only after separate operation-specific authorization and independent staging
identity checks, an operator may run the reviewed script as the approved
non-root account from the staging checkout:

```sh
php /var/www/wpcaregrid/tools/security/m147-staging-audit/audit.php \
  --mode identity \
  --checkout /var/www/wpcaregrid \
  --expected-host '<Owner-approved exact hostname>' \
  --expected-account '<Owner-approved non-root account>'
```

The M12 representation can be rendered with
`php /var/www/wpcaregrid/tools/security/m147-staging-audit/audit.php --mode m12 --format json`;
it cannot turn a missing source into a verified gate value.

Replace both placeholders only from an active authorization record. Do not
invoke through Laravel, `sudo`, a web endpoint, or an unapproved automation.
The tool is not evidence that the target is staging by itself. Owner-approved
host and account values, pinned host identity, and independent environment
identifiers remain required. The emitted classification means only that the
provided hostname and account matched; it does not prove that the checkout,
database, storage, or Connector is staging. This document grants no staging
access.

## Failure behavior

Exit 2 for missing/unknown identity metadata, root execution, symlinked or
ambiguous checkout metadata, mismatched expected host/account/path, invalid Git
HEAD, unsupported options, or insufficient POSIX identity support. Do not
retry by relaxing an identity check. Stop and obtain a separately reviewed
change if a legitimate deployment layout cannot be represented safely.

## Evidence and integrity

Evidence schema: `evidence.schema.json`. Do not add credentials, environment
values, database information, customer data, or raw logs to the JSON. Generate
the allowlisted SHA-256 artifact manifest with `php .../manifest.php`. Hash the
runner and schema before any future authorized execution; record both hashes
and the exact repository SHA in the operator evidence.

Local tests use synthetic Git metadata and the local PHP CLI only. Passing
them validates the parser and fail-closed behavior; it does not validate a
staging host or any M12 effective gate.
