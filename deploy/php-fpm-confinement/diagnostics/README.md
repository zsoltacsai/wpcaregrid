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
encoding, size/frame bounds, and invalid traceback suffixes without echoing input. The
`CHAIN_OR_TRAILING_MISMATCH` case is split into four parser-state substages:
`EXPECTED_SEPARATOR_AFTER_EXCEPTION`, `EXPECTED_CHAIN_MARKER`,
`EXPECTED_SEPARATOR_AFTER_CHAIN_MARKER`, and `EXPECTED_NEXT_TRACEBACK_HEADER`. These identify
only the exact grammar check that failed; they do not identify the underlying runtime cause.
They never include line text, exception messages, paths, partial frame counts, or offsets. It searches the
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
`MALFORMED_EXCEPTION_SUMMARY`, `EXPECTED_SEPARATOR_AFTER_EXCEPTION`, `EXPECTED_CHAIN_MARKER`,
`EXPECTED_SEPARATOR_AFTER_CHAIN_MARKER`, `EXPECTED_NEXT_TRACEBACK_HEADER`, `SOURCE_CONTEXT_OVER_BOUND`,
`OVER_FRAME_BOUND`, and `UNSUPPORTED_EXCEPTION_FORMAT`.
It creates no files, directories,
caches, receipts, temporary data, or __pycache__; it uses no network or
subprocesses. Run only as python3 -B after versioned review and CI.

Install the versioned three-file bundle (`1.0.16-post-evidence-watchdog.py`,
`1.0.16-post-evidence.py`, and `bundle-manifest.json`) at
`/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post-watchdog-2`. Keep
the prior `1.0.16-post` bundle unchanged; each watchdog bundle version uses a
distinct fixed directory and never replaces an earlier immutable install. The bundle
directory must be root-owned mode 0555; each regular file must be root-owned,
mode 0444, and have one hard link. Every parent directory is checked for root
ownership, no group/world write permission, and absence of symlinks. The
manifest pins both source SHA-256 values. Run only the installed entrypoint with
`python3 -B /usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post-watchdog-2/1.0.16-post-evidence-watchdog.py`.
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

## One-run v2 I/O diagnostic

`1.0.16-post-v2/` contains a separate diagnostic-only bundle for the existing
run `acceptance-1.0.16-post-20261128T065928Z-c90b29` in envelope
`/root/wpcg-route-attestation-20261128.t1cs092z`. It reads the pinned package
and only the allowlisted Nginx evidence files for that run. It reports probe
JSON identity and matrix-result fields through fixed allowlists, keeps the
probe's `*.stderr` and `*.stderr.systemd-run` summaries separate, and emits
only aggregate audit profile/PID/time-window correlations. Audit data that
cannot be correlated to a confined probe with matching schema, mode, profile,
label, matrix, boot identity, PID, and valid time window is explicitly UNKNOWN;
per-check audit rows are omitted. Diagnostic verifier-window correlation
checks both the probe PID and its reported child PID, and labels that scope
explicitly without changing verifier behavior. The
report always fixes acceptance, verifier, and fixture cleanup to NOT_RUN.

The immutable three-file bundle is installed side by side at
`/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post-watchdog-3`, using
`watchdog.py`, `helper.py`, and `bundle-manifest.json`. Do not replace the
existing watchdog-2 installation. Verify the manifest, ownership, modes,
single-link regular files, and directory tree before any installation or use.
The watchdog binds and executes the helper bytes it verified, enforces its
hard deadline and output bounds, and rejects any report whose run id differs
from the exact run above.

This bundle is for one bounded read of already-existing evidence only. It must
not be run before exact-head CI and independent review pass. Do not use it to
retry acceptance, a probe, verifier, or cleanup, and do not alter or create
evidence. The result is diagnostic evidence only and cannot establish Gate 2
acceptance.
