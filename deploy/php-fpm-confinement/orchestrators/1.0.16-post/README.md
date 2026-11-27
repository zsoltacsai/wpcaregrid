# Gate 2 post orchestrator for package 1.0.16

This is a separate, commit-pinned operations artifact. It is not a new
confinement-package version: it does not change the 1.0.16 matrix, probes,
profiles, units, runtime bytes, fixture semantics, or verifier contract. A new
package such as 1.0.17 would be appropriate only if one of those acceptance
inputs changed. The source commit and this directory's `SHA256SUMS` pin this
orchestrator; `PINNED_INPUTS.json` binds it to immutable package 1.0.16.

The runner is for one controlled `post` acceptance. It does not install the
production helper, provision secrets, run the later PHP build/DB commands,
open a route, or modify production configuration. The only listener is the
matrix-pinned sibling canary, held around the two CLI baseline/confined probe
pairs. The original 1.0.16 `fixtures.py remove` runs only after all four pinned
verifier calls pass.

## Bundle integrity

The source commit is the outer immutable pin. Verify its exact full commit SHA
from the approved PR merge record, then verify the artifact tree and files:

```sh
git -C /home/deploy/wpcaregrid rev-parse "$ORCH_COMMIT:deploy/php-fpm-confinement/orchestrators/1.0.16-post"
cd /usr/local/libexec/wpcg-gate2-orchestrators/1.0.16-post
sha256sum -c --strict SHA256SUMS
python3 -B acceptance-post.py --help
```

The exact commit SHA, artifact tree, `SHA256SUMS` digest, and the separate
`one_shot_bridge.py` digest must be recorded in the preflight evidence. Never
run a mutable `/tmp` copy. The runner itself rechecks the bundle manifest,
package 1.0.16 tool digests, exact file set, modes, ownership, link counts,
fixture set, service state, AppArmor parser, sockets, and route attestation
before creating any listener or derived profile.

## Inputs

* `--package-dir`: the independently staged, root-owned 1.0.16 package whose
  package tree is `442fa9ab4e82652a70f997238ad5d98e11ade5b9`.
* `--evidence-envelope`: an existing root:root mode-0700 directory. The
  runner creates a unique 0700 evidence subdirectory and writes raw evidence
  there only.
* `--route-closure-evidence`: a root:root, regular, single-link, mode-0600
  JSON file directly under the envelope. The exact schema is documented in
  `docs/PHP_FPM_CONFINEMENT_GO20_PHP85_1_0_16.md`; it records hashes of the
  Owner-reviewed provider export and origin Nginx evidence, not their raw
  contents.
* `--root-card-file`: one-use T3 file-delivery capability. The bridge opens it only after
  observing the real `su -` password prompt, rejects symlinks/non-regular or
  multiply-linked files, writes the bytes only to that PTY, zeroes its buffer,
  and unlinks the one-use file. The password is never a command argument,
  environment variable, report field, or evidence value.

The bundle is installed root:root mode 0755 so the authorized deploy operator
can launch the bridge from its immutable path; the root child runs the sibling
orchestrator from that same bundle. The bridge requires a real outer TTY, verifies the exact root/host identity,
captures only a schema-checked final JSON report, closes the root shell, and
reaps the `su` child. Raw PTY output is never forwarded. A missing marker,
invalid report, timeout, nonzero command report, or failed reap is HOLD.

## Run behavior

The orchestrator consumes the four `post` profiles in matrix order:

1. `wpcaregrid-nginx`
2. `wpcaregrid-php-fpm-production`
3. `wpcaregrid-php-cli-production-build`
4. `wpcaregrid-php-cli-production-deploy`

For each profile it captures and validates a fresh inventory before and after,
uses only the profile's matrix peer roles, records a fresh baseline and
confined probe pair, unloads the derived profile, captures the correlated
kernel audit window, applies the original-profile Python-exec denial where
the matrix requires it, then calls the pinned verifier with that profile's
own evidence. The sibling listener is used only for build/deploy CLI profiles.
Any failure stops the run and preserves evidence; no weaker profile or retry
is attempted. Fixture cleanup is gated on all four verifier PASS results.
