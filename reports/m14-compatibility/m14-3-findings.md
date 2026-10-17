# M14.3 Findings Register — Planning Audit

| ID | Classification | Evidence / finding | Risk / disposition |
|---|---|---|---|
| M143-F01 | CONFIRMED CONFIGURATION DRIFT | `config/connector.php` defaults `connector.latest_version` to `0.4.1`; packaged Connector/release metadata is `0.4.6`. Staging system check reports latest release 0.4.6, indicating environment override. `Connector::isOutdated()` compares installed version to this value. | Reconcile source default and test overrides in 14.3-A before any version-based status. Not by itself proof of customer impact; staging override masked it. |
| M143-F02 | SUPPORT GAP | Current heartbeat/inventory sends versions, capability whitelist, health, WooCommerce and polling facts; no canonical layout/Multisite/filesystem/disk/DB readiness fact vector. | M14-08 P0; add factual optional payload and server evaluator. |
| M143-F03 | SUPPORT GAP | No persisted versioned per-site compatibility snapshot/evaluator identified; health summary and job capabilities are not restore readiness. | M14-08/09 P0; one canonical evaluator and unknown-safe persistence. |
| M143-F04 | VALIDATION GAP | Connector source declares WP >=6.4 and PHP >=8.1; CI package tests pin WP 7.1.x/PHP ^8.2; local E2E tests WP 7.1.2/PHP 8.3.33. This does not establish full tested customer support ranges or DB version boundaries. | Establish explicit fixture coverage before publishing support claims. |
| M143-F05 | VALIDATION GAP | Connector 0.4.5 staging fixture remains connected; no unsafe re-pair/upgrade was performed. | Preserve as supported-old per-capability evidence; isolate test fixture for any operation needing 0.4.6 guarantees. |
| M143-F06 | EXPECTED UNSUPPORTED CONDITION | Multisite, Bedrock/Composer-managed, arbitrary custom roots and unresolved root symlinks are approved unsupported initial-launch boundaries (OQ-02/OQ-03). | Detect and fail closed for affected mutation/restore; not a vulnerability finding. |
| M143-F07 | VALIDATED SAFETY PRIMITIVE / INTEGRATION GAP | M14.2 has archive limits, path containment, symlink/race guards, staged DB restore, verified backups and bounded recovery semantics. | Reuse tests/primitives; M14.3 integrates current evidence into per-operation readiness rather than re-auditing path security. |
| M143-F08 | EXPECTED M14.7 CARRY-FORWARD | Staging says “No completed security scan yet.” | Keep visible. M14.3 may show scan readiness only; no claim of scan completion or clean status. |
| M143-F09 | VALIDATION GAP | Current preflight docs describe broad fixtures/statuses but lack evidence-linked operation vectors, exact freshness, DB/Connector version policy and persisted evidence design. | Supersede with M14.3 matrices; do not mark runtime controls PASS during planning. |

No confirmed hosting-induced Critical/High defect was found in this planning review. This is not a full execution or independent security scan. No runtime changes were made.

## Implementation source reconciliation — M14.3 execution

The F01 statement above is superseded by direct inspection of the authoritative implementation baseline and the planning-merge source. At both `94b9fbafe2c2ab89d81b677af82d0d3d956c0175` and planning merge `80642e369e86e022f2a2f9d32b6b54d4f2eb28a9`, `config/connector.php` derives `latest_version` from `wordpress/wp-caregrid-connector/release.json`; the source default is not `0.4.1`, and no `CONNECTOR_LATEST_VERSION` override is supported. The earlier planning finding was a stale-source observation and is not a confirmed defect. M14.3 adds a regression assertion that the configured release matches the package metadata. Do not create a runtime configuration change for F01.

## Final M14.3 finding dispositions — 2026-10-17

The implementation and exact-SHA validation supersede the planning dispositions above:

| Finding | Final disposition | Evidence / carry-forward |
|---|---|---|
| M143-F01 | `CLOSED — NOT A RUNTIME DEFECT` | Canonical `release.json` relationship regression-tested; deployed package is 0.4.7. |
| M143-F02 | `CLOSED FOR IMPLEMENTED CONTRACT` | Connector 0.4.7 inventory carries bounded facts; API remains v1. Missing/invalid facts remain UNKNOWN. |
| M143-F03 | `CLOSED` | Policy-versioned snapshot/evaluator and transition persistence are implemented; migration does not backfill PASS. |
| M143-F04 | `RESIDUAL — UNKNOWN / NOT TESTED` | PHP 8.1 and WP 6.4 metadata minima were not fixture-tested; exact exercised branches are documented in the closure report and untested branches fail closed. |
| M143-F05 | `CLOSED AS SAFE VERSION-DRIFT CASE` | Staging 0.4.5 remains unchanged; a transaction-scoped probe returns per-capability WARN/BLOCK/UNKNOWN and leaves zero persisted snapshots/transitions. |
| M143-F06 | `CLOSED FOR FAIL-CLOSED POLICY; EDGE FIXTURE RESIDUAL` | Multisite/custom-layout blocks are covered; standalone Bedrock/root-symlink fixture breadth remains unverified and is UNKNOWN/BLOCK for mutation/restore. |
| M143-F07 | `CLOSED` | M14.2 primitives remain in use; no reproduced regression or redesign. |
| M143-F08 | `M14.7_CARRY_FORWARD` | Staging warning remains visible; scan readiness is not represented as a completed scan or clean result. |
| M143-F09 | `CLOSED FOR CORE CONTROL MODEL; EDGE FIXTURE RESIDUAL` | Domain, reason/status vector, freshness, operation gating, diagnostics, core fixtures, and exact CI are implemented. Unrun host variants are explicitly untested. |

No compatibility-induced Critical/High defect was found. The bounded residual is untested lower declared version edges and specialized host fixtures; these are not promoted to support and cannot pass affected high-impact readiness.
