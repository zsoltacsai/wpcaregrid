# Release Provenance Specification

## Current evidence

- CI tests source commits and runs Composer/npm audits; seven checks are expected. It does not publish a deployable artifact.
- The staging process accepts an exact full SHA, verifies object type and ancestry in the fetched authoritative branch, checks origin and clean tree, and verifies runtime `HEAD` still matches target.
- Connector package provenance is already stronger than a version-only ZIP: `ConnectorPackageArtifact` embeds a per-file SHA-256 manifest, derives source SHA-256 and optional source revision, writes a private sidecar with package SHA-256, refuses same-version source drift, and rebuilds/verifies on the deployed source. CI currently does not upload its ZIP.
- No durable deployment receipt or CI-run-to-deploy record is established by source readback.

## Minimum release evidence contract

For each staging release preserve, in a private append-only or access-controlled release log/receipt:

1. repository and authoritative branch identity;
2. PR number and reviewed merge method (or explicit emergency exception);
3. PR head SHA and merged primary candidate SHA;
4. exact CI run ID for the merged candidate and all seven successful job results;
5. root/WordPress `composer.lock` hashes, `package-lock.json` hash, tested image/tool policy identifiers, and Gitleaks version/checksum result;
6. deployment actor/time, baseline SHA, requested SHA, origin identity, clean-tree result, and final runtime HEAD;
7. migration status/batch, PHP/Composer/Node versions, Vite manifest digest, Connector version/source SHA/package SHA, health/smoke result and rollback disposition;
8. confirmation M12 gates false, M14.4 retention non-destructive, no production deploy.

Never store `.env`, tokens, SSH paths/private values, database dumps, test customer data, or unredacted logs in the receipt.

## Artifact and signing decisions

- Keep Connector ZIP private and exact-source-built. Preserve sidecar and hash-verification; include source SHA in download/readback metadata where current interface permits without changing Connector auth.
- Add checksum publication/verification for any future downloadable package. Do not introduce GPG/Sigstore until a real consumer verifies signatures.
- No cross-run CI artifact is trusted for deployment. Any future artifact deployment must bind artifact digest to exact source SHA and trusted CI run and verify before extraction/deploy.
- Formal SBOM and full SLSA attestation are deferred: no current consumer requires them. Lock hashes, audit reports, Connector provenance and deployment receipt are the useful minimum. Reassess for M17 or distribution contract.
- Bit-identical ZIP/build reproduction is not a closure requirement; stable source SHA + package checksum and per-file manifest make the actual package explainable.
