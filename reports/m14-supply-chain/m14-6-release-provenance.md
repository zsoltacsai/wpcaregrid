# Release Provenance Specification

## Current evidence

- CI tests source commits and runs Composer/npm audits; seven checks are expected. It does not publish a deployable artifact.
- The pre-M14.6 staging controller accepted any full SHA ancestor of the fetched authoritative branch; it did not verify merged-PR CI. Candidate PR #115 changes forward release authorization to require the target to equal the fetched branch tip and checks an unauthenticated, read-only GitHub Actions API run for that exact SHA. It requires `event=push`, the authoritative branch and workflow path, a completed successful latest run, all seven current job names with success conclusions, and the successful MySQL process/concurrency step. The request is made with a clean fixed environment, excluding caller proxy, Python-module and custom CA overrides. API redirects, errors, incomplete pagination, oversized bodies, missing jobs, canceled/running/failed runs and rate limits fail closed. No staging token is added.
- Connector package provenance is already stronger than a version-only ZIP: `ConnectorPackageArtifact` embeds a per-file SHA-256 manifest, derives source SHA-256 and optional source revision, writes a private sidecar with package SHA-256, refuses same-version source drift, and rebuilds/verifies on the deployed source. CI currently does not upload its ZIP.
- Candidate PR #115 adds a mode-0600 JSON receipt in the deploy user's private backup directory after runtime SHA, smoke/health, worker and permission checks. It binds baseline/target/runtime SHA, exact workflow run ID, mode/time, tool versions, lock hashes, migration result, Connector version/source/package hashes, and Vite manifest digest. It reads no `.env` values. The receipt is audit evidence in a deploy-owned directory and is not itself an authorization source.

## Minimum release evidence contract

For each staging release preserve, in a private access-controlled release receipt (which remains mutable to the deploy principal):

1. repository and authoritative branch identity;
2. PR number and reviewed merge method (or explicit emergency exception);
3. PR head SHA and merged primary candidate SHA;
4. exact successful CI run ID for the merged candidate and all seven successful job results, including the MySQL concurrency step;
5. root/WordPress `composer.lock` hashes, `package-lock.json` hash, tested image/tool policy identifiers, and Gitleaks version/checksum result;
6. deployment actor/time, baseline SHA, requested SHA, origin identity, clean-tree result, and final runtime HEAD;
7. migration status, PHP/Composer/Node versions, Vite manifest digest, Connector version/source SHA/package SHA, health/smoke result and forward/rollback mode;
8. confirmation M12 gates false, M14.4 retention non-destructive, no production deploy.

Never store `.env`, tokens, SSH paths/private values, database dumps, test customer data, provider/customer payloads, or unredacted logs in the receipt. A root administrator separately writes the one approved rollback SHA to `/var/lib/wpcaregrid/approved-rollback-sha`, root-owned mode `0600`; the deploy user cannot self-authorize a rollback target.

## Artifact and signing decisions

- Keep Connector ZIP private and exact-source-built. Preserve sidecar and hash-verification; include source SHA in download/readback metadata where current interface permits without changing Connector auth.
- Add checksum publication/verification for any future downloadable package. Do not introduce GPG/Sigstore until a real consumer verifies signatures.
- No cross-run CI artifact is trusted for deployment. Any future artifact deployment must bind artifact digest to exact source SHA and trusted CI run and verify before extraction/deploy.
- Formal SBOM and full SLSA attestation are deferred: no current consumer requires them. Lock hashes, audit reports, Connector provenance and deployment receipt are the useful minimum. Reassess for M17 or distribution contract.
- Bit-identical ZIP/build reproduction is not a closure requirement; stable source SHA + package checksum and per-file manifest make the actual package explainable.
