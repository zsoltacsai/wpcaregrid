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
an exception-summary line matching a known or conventionally named Python exception class before a
later exact header yields `PREFIX_EXCEPTION_SUMMARY_BLOCKED_HEADER`; generic launcher prefixes such
as `ERROR:` remain ordinary discarded prefix text. Malformed suffixes, prefix text, and all raw lines
are never emitted. Detection includes class-only exception lines and does not skip malformed earlier
header candidates.
It creates no files, directories,
caches, receipts, temporary data, or __pycache__; it uses no network or
subprocesses. Run only as python3 -B after versioned review and CI.

This source must not be run on the host until it has passed versioned review
and CI and the Owner separately authorizes that diagnostic run. Its output
never replaces the pinned orchestrator or verifier result and cannot establish
acceptance.
