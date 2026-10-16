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

The following remain false: `M12_DESTRUCTIVE_RETENTION_ENABLED`, profile anonymization execution, customer backup deletion, provider deletion, bulk destructive execution and normal-staging synthetic execution. No decision in this register authorizes production destructive execution, customer-data egress to AI providers, or work in M15.
