# M14 Owner Decisions — Consolidated Questionnaire

These decisions affect specific M14 product/compatibility waves. Safe P0 AppSec and tenant-isolation work can proceed now. Recommended defaults below are not approvals and do not change support, backup, privacy or AI behavior.

## OQ-01 — Initial WordPress hosting support boundary

- **Recommended default:** support only installation layouts and host capabilities covered by published, versioned fixtures; show per-operation preflight and block unknown restore/write capability.
- **Alternatives:** broad “most WordPress hosts” support with exceptions; or a narrower named-host/managed-host list.
- **Impact:** scope of compatibility matrix, preflight rules, support burden and truthful restore claims.
- **Security consequence:** broad support without path/network/filesystem evidence expands SSRF, path and data-loss exposure.
- **Roadmap consequence:** needed before M14.3 compatibility acceptance; not a prerequisite for M14.1 AppSec fixes.

## OQ-02 — Multisite support

- **Recommended default:** treat Multisite/network-wide operations as unsupported until dedicated network-level fixtures and restore/recovery tests pass; detect and block ambiguous destructive or restore scope.
- **Alternatives:** support selected Multisite cases with explicit constraints; or defer all Multisite support beyond M14.
- **Impact:** affects site identity, backup consistency, shared plugins/users, network tables and restore blast radius. A manifest boolean alone is insufficient evidence.
- **Security consequence:** one subsite action could affect the entire network; scope errors can cross customer/site boundaries.
- **Roadmap consequence:** decision gates M14.3 support claims and relevant Connector work.

## OQ-03 — Bedrock and custom document roots

- **Recommended default:** no support claim until canonical root discovery and symlink/bootstrap fixtures prove backup, restore and Connector access boundaries.
- **Alternatives:** support a named Bedrock layout; support arbitrary custom roots with operator-managed configuration; defer.
- **Impact:** limits eligible hosting layouts and onboarding diagnostics.
- **Security consequence:** root confusion and symlink escapes can expose or overwrite files outside WordPress.
- **Roadmap consequence:** decision required before declaring compatibility for those installations.

## OQ-04 — Owner backup-policy overrides

- **Recommended default:** Owner may extend recovery protection (more retained points/additional safety copy) but cannot reduce customer entitlement, verified recovery floor, or any eventual legal/accounting minimum; policy reductions preview blockers and never delete immediately.
- **Alternatives:** no per-customer override; or allow downward overrides only after explicit customer/contract policy.
- **Impact:** affects support flexibility, storage cost, package contract and subscription migration.
- **Security consequence:** reducing retention or removing last-good points can create data-loss and compliance risk.
- **Roadmap consequence:** needed before M14.4 Owner policy UI/domain is finalized; product plan remains unimplemented until approved.

## OQ-05 — AI provider configuration scope

- **Recommended default:** M14 may add Owner-only, encrypted, write-only provider configuration and health check with no customer data, no feature enablement and no default provider; keep all AI behavior off until separate purpose/legal/privacy authorization.
- **Alternatives:** defer all provider configuration; or choose one provider for a specifically scoped future internal feature after security/privacy review.
- **Impact:** determines M14.5 scope, secret lifecycle and external provider setup.
- **Security consequence:** configuration must not grant data egress or autonomous action authority.
- **Roadmap consequence:** provider choice and any real data flow remain future authorized work; M14 does not imply an AI feature or marketing claim.
