# M14.3 Diagnostics Presentation Plan

## Customer dashboard

Show one capability-specific state, not a universal site rating: Ready; Needs attention; Not supported for this operation; Check unavailable. Show localized, actionable next step (update Connector through the established safe path, contact host about available disk/permissions, or explain unsupported layout). Distinguish “backup created”, “backup verified”, “restore readiness”, and completed restore. Do not say guaranteed recovery, fully supported host, no vulnerabilities, or physical deletion. Never show paths, DB username/host, internal IP, stack trace, raw PHP configuration, credentials or another tenant’s details.

## Operator site detail

Reuse existing Operator site detail/status patterns. Display capability vector, reason codes, policy/evaluator version, checked/expiry time, Connector/WP/PHP/DB versions, measurement categories (not full paths), missing extension name where actionable, measured vs required bytes if safe, last failure code, and safe recheck action. Operator may rerun preflight or record review; cannot override BLOCK/UNKNOWN or force restore. Authorization remains M14.1 ability/tenant scoped.

## Owner summary

No new dashboard is required for M14.3. If existing Owner system/site screens support a small summary, report counts of sites by reason/capability and outdated Connector state, with authorized per-site drill-down. Do not expose customer infrastructure facts broadly or add a new analytics surface solely for this milestone.

## Message architecture

Store reason codes; resolve localized HU/EN message keys at presentation. Include operation and safe next step. Distinguish unsupported from temporary check failure. Avoid provider blame unless known from direct evidence. Customer sees no absolute filesystem path, internal network details, database name/identity, credentials or raw exception. Operator detail is redacted and audit records contain codes and bounded measurements only.

## Security and lifecycle

Customer reads own site only; Operator needs existing appropriate ability; Owner uses approved global scope. No Connector-supplied customer-facing sentence is trusted. Preflight endpoint must be rate/cost bounded; deep probes are on-demand. Audited meaningful transitions only (PASS→BLOCK, supported→unsupported, Multisite detected, disk insufficient); suppress identical heartbeat noise. Preserve AS-16 “No completed security scan yet” warning as M14.7 carry-forward.
