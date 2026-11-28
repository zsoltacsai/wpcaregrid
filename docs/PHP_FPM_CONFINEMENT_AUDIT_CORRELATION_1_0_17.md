# Gate 2 audit correlation verifier 1.0.17

Package 1.0.17 is a side-by-side verifier-only successor to 1.0.16 for a future, fresh Gate 2 run. It corrects the audit comparison contract for matrix-declared symlink targets and the exact AppArmor stacked labels accepted by the inventory contract. It also prevents unbound deny windows from absorbing unrelated records and makes the verifier report hard failures before requesting an acknowledgment for unproven checks.

The package's runtime profiles, service units, probe, fixture tools, and production configuration are byte-identical to 1.0.16. Only `binding.json`, the matrix package version, the verifier, and the package checksum manifest differ. CI checks this side-by-side preservation and validates the package manifest. The original 1.0.16 tree remains unchanged.

## Compatibility and safety

Evidence created with 1.0.16 cannot be accepted by the 1.0.17 verifier: the matrix digest is part of each probe document and the two package versions have different matrix digests. The existing partial 1.0.16 run remains diagnostic only. A fresh run under 1.0.17 would need its own package pin check, fixture precheck, full four-profile post acceptance, pinned 1.0.17 verifier, and fixture cleanup in the runbook order. No production route, runtime policy, or installed service change is included here.

The side-by-side runner is `deploy/php-fpm-confinement/orchestrators/1.0.17-post-io-v4/`. Its independent `SHA256SUMS`, `PINNED_INPUTS.json`, and acceptance-post constants bind the full 1.0.17 package tree and tool digests. It retains the reviewed I/O-v4 split-output and system-manager-label gates; it does not reuse the 1.0.16 package pin. CI checks both the verifier behavior and the runner/package pin agreement. The runner is not yet a host deployment approval.

The verifier keeps baseline-masked checks inconclusive: `/proc/<pid>/mem` masked by DAC/Yama remains explicitly unproven and requires the existing child calibration; it is not converted to PASS. Missing or ambiguous audit evidence for a check that must be proven remains a hard failure. The signal-0 probe remains a hard requirement and is not relaxed. This version does not claim that an inconclusive signal check proves isolation.

If the verifier reports unproven checks, exit 3 is HOLD and includes an exact
`--ack-unproven=<matrix-sha>:<boot-id>:<phase>:<profile>:<evidence-sha>:<sorted-ids>`
request bound to those preserved inputs. The 1.0.17 post runner does not pass
that acknowledgment and stops before later profiles or fixture cleanup. An
Owner may review the exact files and use the pinned verifier's acknowledgment
mode on that same evidence set, without rerunning probes; this does not change
the runner's HOLD report or authorize cleanup. Gate 2 remains HOLD until a
versioned finalization flow has verified every profile and completed cleanup.

## Verification scope

`scripts/tests/production-only-confinement-1-0-17-verifier-test.py` covers resolved symlink targets, accepted exact stacked labels, negative prefix lookalikes, strict matrix audit contracts, unbound-record handling, baseline-masked memory checks, failure/ack ordering, all-profile synthetic acceptance, and preservation of 1.0.16 runtime bytes. CI compiles the package profiles and runs this suite.

No host rerun is authorized by this package change. Host acceptance remains on HOLD until the versioned package has exact-head CI and independent review, and the Owner authorizes the complete fresh post run.

## Diagnostic-only v5 for the existing partial run

`deploy/php-fpm-confinement/diagnostics/1.0.16-post-v5/` is a separate read-only helper/watchdog bundle for the already-existing `acceptance-1.0.16-post-20261128T130846Z-d424f9` evidence. Bundle version: `1.0.16-post-watchdog-10`, installed only at `/usr/local/lib/wpcaregrid/php-fpm-confinement/1.0.16-post-watchdog-10`. The helper SHA-256 is `3b6035b4471f5c36354141fa6a3f01212d1112bf011bb81aa6fa88cc65cf4179`; watchdog SHA-256 is `8c97904da22084b7badda9d0c933d9f5becff0d05838c0bf5553f577fbf8b869`. It reports bare versus accepted stacked probe-profile labels and separates `name`, `peer`, `fields`, and `mention` audit dimensions. Each `*_match` boolean says whether that dimension matched somewhere in the parsed audit records; it does not claim that all booleans came from one record. Only `denied_record_count_all_dimensions` is conjunctive: it counts a record only when all shared correlation dimensions and one applicable target alternative match on that same record. File-path comparison uses the pinned 1.0.16 matrix's declared symlink map. The historical 1.0.16 verifier used prefix matching for peer labels, while this diagnostic uses the exact accepted bare/stacked label set; that difference can undercount historical matches, but cannot create an acceptance PASS. `verifier_window_hit` describes the historical 1.0.16 verifier behavior. The helper does not execute or replace the acceptance verifier; its parsed verifier-output summary is diagnostic metadata only. It never emits audit target values, profile strings, raw stderr, or ACK payloads. The v4 bundle stays immutable.

The v5 summary is diagnostic only; it cannot repair or replace that run's failed verifier result. Installing/running it requires its own exact pin review and CI, plus the Owner's bounded one-time authorization to inspect the existing root-only evidence. No new probe or acceptance run is required merely to perform this read-only summary.
