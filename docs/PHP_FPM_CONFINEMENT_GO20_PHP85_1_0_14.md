# Production-only confinement acceptance compatibility 1.0.14

This immutable acceptance-tool release changes only how the calibration child exits after the six existing descendant probes.

## Change and evidence boundary

In 1.0.13 the probe attempted to terminate its own child with `SIGKILL`. On the production host, the confined probe raised `PermissionError` at that cleanup call while running under the exact stacked `wpcaregrid-nginx-probe` label. The supplied kernel excerpt had no signal-denial record, so it did not establish AppArmor as the cause. The 1.0.14 probe does not signal the child: it keeps a private pipe writer open during the existing probes, closes the writer in cleanup, and the child exits on EOF. The parent then reaps the child with `waitpid` and checks a successful exit. Cleanup also runs when a probe raises; failed cleanup prevents evidence production.

The six existing calibration probes and their IDs, targets, ordering, and verifier inputs are unchanged. The pipe exists only between the probe and its child. This change does not grant production profile permissions or modify service/runtime files.

## Runtime and helper compatibility

All nine installed runtime files are byte-for-byte identical to confinement 1.0.12 and 1.0.13. Do not reinstall or replace them for this acceptance-tool update. Helper 2.1.11 remains bound to its existing 1.0.12 runtime package; neither the helper nor its pin is changed by 1.0.14.

The package carries the same AppArmor profiles and systemd artifacts as 1.0.13. CI compiles the package profiles and runs the lifecycle regression tests, but this is not live AppArmor acceptance.

## Gate 2 resumption

The earlier baseline result (82 probe records) is not itself a Gate 2 pass. The failed attempt produced no valid confined result, so its verifier result remains HOLD. Preserve the failed run's evidence as diagnostic material. Before resuming, inspect fixture state with the pinned package tooling and confirm it is still exactly the matrix fixture set; do not clean or recreate fixtures by hand. Run the package's documented Gate 2 procedure from the last valid checkpoint only after 1.0.14 is merged and its exact merge-SHA CI and independent reviews pass. The verifier must accept the complete correlated baseline/confined evidence, inventories and audit records. A new probe exception, missing/partial evidence, inventory change, or fixture mismatch remains HOLD.

Gate 2 remains route-closed and fixture-only. It does not authorize secret placement, database/application checks, route opening, or production activation. Those remain separate later gates under the 1.0.12/2.1.11 runbooks and explicit Owner authorization.

## Pins

The authoritative package identity is:

- SHA-256 of `SHA256SUMS`: `58cb88770b084b927b74274a96eba960d6c61b4e2defc44f3e1ae57923970eaf`
- SHA-256 of `binding.json`: `d5a80a91023c9bee128cf4f092f0ae10def6bf3c791fbd957071e682212e29f9`
- Git tree: `3a80b54343021b45bb0ae3e011ff8ee62ddcca4c`

The 1.0.13 package remains immutable. Do not copy acceptance tools from an unmerged branch to the host.
