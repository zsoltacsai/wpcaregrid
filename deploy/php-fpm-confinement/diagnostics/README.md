# One-run post-acceptance diagnostic

1.0.16-post-evidence.py is a diagnostic-only reader for the single
b726ce partial post run recorded in the script constants. It does not run
acceptance, the verifier, a probe, or cleanup. Its output always labels the
acceptance verdict NOT_RUN and fixture cleanup NOT_RUN.

The helper verifies the exact 1.0.16 package file hashes before executing the
pinned verifier module, and only calls the verifier's parse_audit,
expected_checks, and window_hit helpers for diagnostic correlation. It does
not call verify(). It reads the pinned inputs and exact run envelope
with O_RDONLY|O_NOFOLLOW, bounded sizes, and metadata checks. It emits one
allowlisted, redacted JSON object on stdout. It creates no files, directories,
caches, receipts, temporary data, or __pycache__; it uses no network or
subprocesses. Run only as python3 -B after versioned review and CI.

This source must not be run on the host until it has passed versioned review
and CI and the Owner separately authorizes that diagnostic run. Its output
never replaces the pinned orchestrator or verifier result and cannot establish
acceptance.
