# One-run 1.0.18 post-HOLD diagnostic

This immutable diagnostic bundle is pinned to the existing run
`acceptance-1.0.18-post-20261128T204310Z-f4b8d2` in the existing root-only
envelope `/root/wpcg-route-attestation-20261127.KbA7hY`. It reads only the
allowlisted Nginx evidence files for that run. It does not execute acceptance,
the verifier, a probe, fixture cleanup, or any host mutation. Every report fixes
`acceptance_verdict=NOT_RUN` and `fixture_cleanup=NOT_RUN`.

The helper verifies the exact 1.0.18 package hashes and modes, then loads only
the pinned verifier's pure `parse_audit`, `expected_checks`, and `window_hit`
helpers. It never calls `verify()` or `main()`. Evidence reads use descriptor
anchoring from `/`, `O_RDONLY|O_NOFOLLOW|O_NONBLOCK`, bounded sizes, and
root-owner/mode/link-count and pre/post identity checks. Output contains only
allowlisted IDs, enums, booleans, counts, and digests. It does not emit paths,
raw audit rows, exception messages, verifier detail text, or ACK payloads.

The audit section reports independent dimension matches and the conjunctive
all-dimensions candidate count for each expected Nginx check. It also shows
whether the pinned verifier's historical window matcher found a record for the
probe parent or child. These are diagnostic correlations; they do not alter or
replace the verifier's HOLD result.

Install the three files side-by-side at
`/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.18-post-watchdog-1` only
after exact-head CI and independent review pass. The bundle directory must be
root-owned mode 0555; each regular file root-owned mode 0444, single-link. The
watchdog validates the parent chain, manifest and file hashes, then executes
the verified helper bytes from a sealed memfd. Run only the installed watchdog
entrypoint with `python3 -B`; do not run a checkout copy. The watchdog applies a
90-second deadline and bounded stdout/stderr/progress channels, reaps its child,
and emits only redacted JSON.

This source must not be staged or run on stg1 until the PR is merged, all
required checks pass on the exact merged head, and the Owner separately
authorizes this one read-only diagnostic. Its output cannot establish Gate 2
PASS. No acceptance/verifier/cleanup retry is permitted from this diagnostic.
