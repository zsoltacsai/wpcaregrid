# Privacy Controls Readiness Evidence — M12 Wave 6

**Baseline:** `57fe3ef74b099f5ae6844f1ba6414149cc079d54`
**Purpose:** reusable control evidence for future M14/M15/M17 audits. It does not establish legal compliance or complete the future audits.

## Existing controls inspected

- Wave 2 export is private, application-encrypted, allowlisted and time-limited; it excludes secrets, other tenants, raw webhooks, site backups, and immutable accounting snapshots. See `reports/m12-privacy/wave2-implementation-report.md`.
- Wave 2 rectification uses current profile/versioned billing identity paths and does not rewrite historical invoice snapshots.
- Wave 3 legal documents have immutable versions and acceptance records, but no legal content was published and acceptance enforcement remains off pending qualified review.
- Wave 4 retention policy and impact previews are report-only. `M12_DESTRUCTIVE_RETENTION_ENABLED` defaults false, and all legal/accounting/security review statuses remain distinct.
- Wave 5 account closure and erasure are separate workflows. Closure restriction is reversible and audited. The service does not anonymize identity, delete backups, or call provider APIs. Provider tasks are metadata only.
- Immutable invoice/seller/tax evidence remains outside ordinary profile anonymization. Exact accounting retention remains `WAIT_ACCOUNTING`.

## Wave 6 changes

- Customer backup deletion is represented per fixed object key; provider response and read-after-delete result are separate fields. Unknown or partial state is not complete.
- Recovery-point protection is now non-overridable through the legacy CLI confirmation option. Partial deletion prevents restore use.
- Owner view exposes aggregate provider/deletion states and explicitly identifies unknown provider facts. It does not expose user/site names, URLs, or secrets.
- Provider task status now includes `unknown`; provider capability inventory separates code-level logical-delete support from unverified provider versions, locks, regions, contracts, and legal rights.
- The independent tombstone journal has a typed interface but no configured production adapter. Recovery readiness remains blocked until the journal is available and replay is verified.
- Audit events contain state/reason codes only; provider exception text and credentials are not persisted by deletion reconciliation.

## Controls not yet established

- No independent tombstone journal storage, cryptographic/authenticity contract, sequence/watermark proof, or end-to-end replay applier exists yet.
- No provider fact has been verified by live deletion/read-back in this wave; the tests exercise local temporary storage and provider mocks only.
- No live customer backup deletion, anonymization, provider-side deletion, or production mutation is authorized or performed.
- Legal basis, data-subject scope, statutory/accounting retention, subprocessors/transfers, and privacy notice conclusions remain for qualified reviewers.

## Future audit contributions

This evidence can inform M14 AppSec/tenant-isolation and CI supply-chain review, M15 DR/billing/privacy/operational-safety review, and M17 production readiness. Those audits remain open and must independently verify the deployed system, provider contracts, and operational recovery process.

## Release update — 2026-10-15

The M12 Wave 6 release correction for the platform-backup reconciliation command is merged at primary SHA `1a45951475a292ecc06c9547a4fd4b94ae5eab77`. Its exact PR head passed GitHub Actions run `38768895991` with all five mandatory jobs green. The change makes the command's persisted reconciliation status/timestamp behavior explicit and covers it with a regression assertion. No staging invocation of that state-writing command was made; staging verification used the read-only storage reconciler.

This does not close the independent tombstone-journal/replay gate, verify provider-side physical deletion/versioning, or resolve legal/accounting questions. Destructive retention remains disabled; no deletion, anonymization, or provider API mutation occurred.
