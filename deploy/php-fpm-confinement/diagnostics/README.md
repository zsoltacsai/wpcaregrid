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
`parse_failure_stage` enum and a bounded `frame_count`, distinguishing missing headers, headers with
no recognized frame record (including a malformed first frame), invalid encoding, size/frame bounds,
and frame/chain mismatches without echoing input.
It creates no files, directories,
caches, receipts, temporary data, or __pycache__; it uses no network or
subprocesses. Run only as python3 -B after versioned review and CI.

This source must not be run on the host until it has passed versioned review
and CI and the Owner separately authorizes that diagnostic run. Its output
never replaces the pinned orchestrator or verifier result and cannot establish
acceptance.
