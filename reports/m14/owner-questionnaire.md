# M14 Owner Decision Register

**Record status:** all five decisions approved on 2026-10-16.
**Evidence:** Owner response to the M14 Owner Decisions record in this task, following review of planning PR #83.
**Scope:** planning and implementation scope only. These decisions do not authorize production destructive execution, customer-data transfer to AI providers, or unsupported hosting claims.

## OQ-01 — Initial WordPress hosting support — APPROVED

Use an evidence-based, capability-specific support model. A capability is supported only when versioned compatibility/preflight fixtures and runtime checks prove it. Keep distinct states such as `MONITORING_READY`, `UPDATE_READY`, `BACKUP_READY`, `RESTORE_READY`, `SECURITY_SCAN_READY` and `ADMIN_ACCESS_READY`. Unknown restore/write compatibility fails closed. Warnings may remain non-blocking only where the affected capability can operate safely.

**Implementation consequence:** define and test each capability independently; do not treat an ordinary-looking WordPress installation as supported without evidence.

## OQ-02 — WordPress Multisite — APPROVED, UNSUPPORTED FOR INITIAL LAUNCH

Detect Multisite reliably, present an explicit compatibility state, and make no Multisite support claim. Block restore, destructive, filesystem-wide and network-wide operations whenever scope safety is unproven. Do not treat a subsite as an independent ordinary installation when shared network state is involved. Read-only diagnostics may remain only where demonstrated safe and clearly limited.

Reconsider support only in a future milestone after network-level backup, restore, scope/tenant, plugin/theme, users/uploads and recovery fixtures pass.

## OQ-03 — Bedrock/custom document roots — APPROVED, UNSUPPORTED FOR INITIAL LAUNCH

Bedrock, arbitrary custom document roots, Composer-managed WordPress layouts and unresolved symlinked/custom-root installations are outside the initial supported baseline. Detect non-standard layouts where possible, explain the limitation, block mutation/restore if canonical root boundaries cannot be proven, never guess filesystem roots, and prevent symlink/root escape. Standard supported WordPress layouts remain the baseline.

Future support requires canonical-root discovery and fixture-based backup/restore/filesystem evidence.

## OQ-04 — Owner backup-policy overrides — APPROVED

An Owner may extend recovery protection above purchased entitlement, including additional retained recovery points, longer approved retention or additional safety protection. An Owner may not reduce below the purchased entitlement, protected last-known-good point, active restore requirement, safety-backup requirement, system safety floor or any future legal/accounting minimum.

Every policy reduction must show an impact preview, must never synchronously delete backups, and may create eligibility only. Restore, safety, legal, accounting, privacy and provider gates continue to block affected actions. Purchased entitlement is the minimum contractual/product floor; Owner preference cannot override system safety. Commercial/pricing consequences of extensions remain separate product/accounting decisions.

Post-service retention duration remains unresolved; this decision sets no legal or accounting period.

## OQ-05 — AI provider configuration — APPROVED, CONFIGURATION GROUNDWORK ONLY

M14 may implement Owner-only configuration groundwork for **OpenAI, Anthropic and Google Gemini**: provider registry; encrypted secret storage; write-only secret entry; masked configured state; replace/revoke; safe health/test connection; provider availability/configuration status; and audit events that exclude secrets.

Configuration must not choose a default provider, enable an AI feature, send customer/site data, security findings, backups, code/content or credentials, enable autonomous remediation or update/restore/security actions, or support an “AI-powered” marketing claim. Keep `CONFIGURED`, `ENABLED`, `HEALTHY`, `DEFAULT` and `AUTHORIZED_FOR_FEATURE` separate. Configuration does not imply enablement, and enablement does not authorize customer-data transfer.

No customer data may leave WP CareGrid until a future feature has a separately approved purpose, data classification, Owner/product authorization, security review, privacy/legal/vendor review, and tenant/customer authorization where required.

## Decision and safety boundary

OQ-01 through OQ-05 are `OWNER_APPROVED`. They resolve the Owner/product choices recorded here; they do not resolve qualified external facts or approvals. Post-service retention duration remains `WAIT_LEGAL` / `WAIT_ACCOUNTING`; provider physical-deletion semantics remain `WAIT_FACT`.

### M14.5 engineering interpretation — 2026-10-17

The approved boundary is implemented as exactly three source-owned provider entries (`openai`, `anthropic`, `google_gemini`) with fixed official metadata endpoints and no custom URL. The proposed explicit probes are bodyless model metadata GETs; no static marker is required. Provider/account cost is not claimed to be zero based on endpoint documentation. The Owner current-password-confirmation requirement for AI key create/replace/remove, health-generation concurrency handling, no environment fallback, and runtime-resolved-only storage are engineering controls within OQ-05; no new Owner decision is requested. Customer-data processing by AI remains `NOT AUTHORIZED / FUTURE REVIEW REQUIRED`.

The following remain false: `M12_DESTRUCTIVE_RETENTION_ENABLED`, profile anonymization execution, customer backup deletion, provider deletion, bulk destructive execution and normal-staging synthetic execution. No decision in this register authorizes production destructive execution, customer-data egress to AI providers, or work in M15.

## OQ-06 — M14 operational Integrity-scan acceptance route — APPROVED 2026-10-24

The Owner selected the already registered, Owner-controlled, actively
subscribed `kiszov-szeged.hu` staging Website for a bounded, non-destructive
Integrity scan and accepted the completed Connector-side scan as operational
M14 Integrity-execution evidence. This decision replaces the need to provision
a separate external disposable WordPress installation solely for that one
acceptance criterion.

The target remains an ordinary real Customer Website with its existing
subscription, billing identity, Connector association, entitlements and
normal authorization. It must not be relabelled as synthetic, be assigned
fixture-only permissions or suppression, or be used for destructive tests.
Malware/full scanning, quarantine, remediation, update, restore, or other
destructive work is not authorized by OQ-06. Each future live operation needs
its own bounded scope and current safety preflight.

The exact completed scan, deployment, CI, coverage limitations and remaining
M14 audit gaps are recorded in
[`owner-real-site-integrity-acceptance-2026-10-24.md`](owner-real-site-integrity-acceptance-2026-10-24.md).
OQ-06 changes the scanner acceptance route only; it does not close M14.7 or
M14.8 audits, waive synthetic negative tests, or imply M14 closure.

## OQ-07 — bounded notification-delta evidence for accepted Integrity run — APPROVED 2026-10-24

For the single OQ-06 Integrity scan, the Owner accepts the following evidence
instead of requiring a new live scan solely to reconstruct a notification
inventory that was not persisted: (1) inspect the complete scan-result,
incident, backup, queue-lifecycle and scheduled-scan code path; (2) verify that
the Integrity result validator rejects Malware-only `SuspiciousFile` findings
before findings/incident/backup side effects; (3) run the existing isolated
negative test proving that this rejection leaves no finding, incident or
backup; and (4) retain the scan record's reported zero findings and no
incident/backup report without asserting a reconstructed notification delta.

The historical notification before/after delta remains `UNKNOWN`; this
decision does not change it to zero. The decision applies only to the completed
bounded Integrity operation on the OQ-06 target. It does not authorize a
Malware/full scan, suppress customer alerts, prove notification safety for
other paths, or replace site/tenant/consent controls. Future M15 notification
testing must use fake transports/queues or a separately approved isolated
target.
