# One-run post-acceptance diagnostic

1.0.16-post-evidence.py is a diagnostic-only reader for the single
b726ce partial post run recorded in the script constants. It does not run
acceptance, the verifier, a probe, or cleanup. Its output always labels the
acceptance verdict NOT_RUN and fixture cleanup NOT_RUN.

The helper verifies the exact 1.0.16 package file hashes before executing the
pinned verifier module, and only calls the verifier's parse_audit,
expected_checks, and window_hit helpers for diagnostic correlation. It does
not call verify(). It reads the pinned inputs and exact run envelope
with dirfds, openat-style relative opens, O_RDONLY|O_NOFOLLOW, bounded sizes,
and owner/mode/link/identity checks on each directory and file component. It emits one
allowlisted, redacted JSON object on stdout. For standard Python tracebacks in the confined
probe stderr, it may add only an allowlisted exception class, frame count, safe basename
classification, function token, and line number; it never emits exception messages, source
lines, or full paths. It accepts standard chained tracebacks with the exact
blank separators emitted by Python and up to two indented source-context lines per frame; malformed intermediate lines and
unsupported multiline ExceptionGroup output remain UNKNOWN. Parse failures carry only an allowlisted
`parse_failure_stage` enum and a bounded `frame_count`, distinguishing missing headers, invalid
encoding, size/frame bounds, and invalid traceback suffixes without echoing input. It searches the
complete bounded stderr for exact traceback-header lines. No exact header anywhere yields
`NO_EXACT_TRACEBACK_HEADER`. A found header whose suffix does not fully match the strict grammar
yields `FIRST_HEADER_SUFFIX_INVALID` with no partial frame count. A complete valid traceback after a
prefix emits only the redacted summary with `parse_failure_stage=PREFIX_BEFORE_TRACEBACK`. An
exception-summary line matching a known or conventionally named Python exception class before a
later exact header yields `PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER`; generic launcher prefixes such
as `ERROR:` remain ordinary discarded prefix text. Malformed suffixes, prefix text, and all raw lines
are never emitted. Detection includes class-only exception lines and does not skip malformed earlier
header candidates. Invalid suffixes may include only these substage enums: `NO_FRAME_AFTER_HEADER`,
`MALFORMED_FRAME_LINE`, `UNEXPECTED_LINE_AFTER_HEADER`, `MISSING_EXCEPTION_SUMMARY`,
`MALFORMED_EXCEPTION_SUMMARY`, `CHAIN_OR_TRAILING_MISMATCH`, `SOURCE_CONTEXT_OVER_BOUND`,
`OVER_FRAME_BOUND`, and `UNSUPPORTED_EXCEPTION_FORMAT`.
It creates no files, directories,
caches, receipts, temporary data, or __pycache__; it uses no network or
subprocesses. Run only as python3 -B after versioned review and CI.

Install the versioned three-file bundle (`1.0.16-post-evidence-watchdog.py`,
`1.0.16-post-evidence.py`, and `bundle-manifest.json`) at
`/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post`. The bundle
directory must be root-owned mode 0555; each regular file must be root-owned,
mode 0444, and have one hard link. Every parent directory is checked for root
ownership, no group/world write permission, and absence of symlinks. The
manifest pins both source SHA-256 values. Run only the installed entrypoint with
`python3 -B /usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post/1.0.16-post-evidence-watchdog.py`.
The watchdog reads the bundle with dirfd/O_NOFOLLOW checks and verifies the
manifest before starting a child. It places the exact verified helper bytes in
a sealed anonymous memfd and executes that descriptor, so replacing the
neighboring helper path after validation cannot change the code that runs.
There is no fallback to a checkout or unverified path.

The watchdog starts exactly one helper child in a new process group, passes an inherited progress pipe, and
enforces a 90-second monotonic deadline. The progress pipe accepts only fixed
stage enums. Stdout, stderr, and progress are separately byte-bounded; stderr
is never forwarded. On timeout or malformed/oversized output, the parent sends
TERM then KILL to the private process group and makes bounded wait/reap
attempts. Its timeout JSON is separate from the helper's unchanged success
schema and contains only a valid last stage, a coarse elapsed bucket, and
whether the direct child was reaped. An unconfirmed reap is fail-closed and
must not be retried automatically.

This source must not be run on the host until it has passed versioned review
and exact-head CI and the Owner separately authorizes that diagnostic run. Its
output never replaces the pinned orchestrator or verifier result and cannot
establish acceptance.
