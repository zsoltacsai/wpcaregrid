# Gate 2 fast evidence path — package 1.0.18

## Purpose

Package 1.0.18 is a side-by-side successor to 1.0.17 for the known AppArmor
`kill(pid, 0)` failure. The verifier remains strict: a confined cross-profile
signal-existence check must still be denied and correlated; `errno=0` remains a
hard failure. The package adds exact stacked-peer denials and explicit
`exists`-signal denials while retaining the existing bare-peer and real-signal
denials. It does not use a wildcard peer or grant a new permission.

The Ubuntu AppArmor `abstractions/base` permits `exists` signals without a
peer restriction. The deny rules must therefore cover the actual compound
`unconfined//&<profile>` label as well as its bare profile label. The relevant
AppArmor syntax documents `exists` as the signal used for PID-existence checks
and describes peer-mediated signal permissions in the [Ubuntu
`apparmor.d(5)` manual](https://manpages.ubuntu.com/manpages/noble/man5/apparmor.d.5.html).

## Runner behavior

The 1.0.18 I/O v5 runner collects all four profile results in one bounded run
when a verifier returns only unproven status (rc=3). This avoids paying for a
second full probe run just to learn the later roles' states. It does not turn
unproven evidence into acceptance: any such result makes the overall run HOLD,
leaves fixtures in place, and skips cleanup. Any hard failure or other HOLD
still stops the sequence immediately.

Gate 2 PASS requires all four pinned verifier invocations to return rc=0 with
zero unproven IDs, followed by successful fixture removal and empty-state
verification. An acknowledgment payload is not a substitute for those
conditions.

The runner checks that the installed profile source files and shared
abstraction are root-owned regular single-link files and match the exact
1.0.18 package bytes. It does not install or reload production profiles. A
policy rollout must be separately reviewed and staged before the acceptance
runner can pass this gate.

## Host validation boundary

CI compiles the versioned profiles without loading them and runs the synthetic
verifier, package-pin, runner-lifecycle, and bridge-schema tests. This proves
the source grammar and fail-closed software behavior; it does not prove the
effective kernel policy on stg1.

Before a full acceptance run, the Owner must separately authorize and verify a
read-only live regression for both a bare and a stacked peer: `kill(pid, 0)`
must return `EACCES`/`EPERM` and produce a matching `signal=exists` denial; a
harmless real signal must also remain denied, while the intended self-control
still behaves as specified. Confirm the exact loaded labels, the active
`kernel.apparmor_restrict_unprivileged_unconfined` value, and the installed
source hashes. If that regression or the mandatory child calibration is not
PASS, stop; do not retry acceptance or acknowledge the signal check.

The final full run must be fresh and use the exact staged package and runner
pins. Preserve its evidence. Any verifier rc=3, any unexplained audit event,
any profile HOLD, or any cleanup failure leaves Gate 2 HOLD.
