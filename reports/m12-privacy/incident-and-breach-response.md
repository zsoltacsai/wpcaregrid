# Privacy incident and breach response plan

## Current foundation

Security incident tables represent website security findings, malware/site incidents, scan and remediation history. M10 monitoring covers platform/website health and mail alerts. Neither is a demonstrated personal-data breach case-management process. No dedicated privacy incident record, breach scope assessment, legal decision trail, or external notification workflow was found.

## Proposed incident workflow

`detected → triaged → suspected_personal_data_impact → contained → scope_assessed → legal_review → notification_decision → remediation → monitoring → closed`. A case can be re-opened; transitions are append-only and actor-attributed. Preserve original artifacts read-only, record custody/hash and access, and redact secrets from ordinary timeline.

Minimum case fields: opaque incident ID; detection/source/time UTC; affected systems/tenants/provider; categories and approximate data-subject/record scope (counts, not copied data); confidentiality/integrity/availability impact; access/exfiltration status; containment and evidence references; notification assessment/decision, accountable Owner and legal adviser; customer/provider communications and timestamps; root cause/remediation; residual risk and approval. No legal notification deadline or reportability state is auto-inferred by code.

## Runbook

1. Preserve logs/backups and do not run retention deletion or restore that overwrites forensic state.
2. Contain credentials/jobs/egress with narrowly scoped approved action; maintain service safety and tenant isolation.
3. Identify whether personal data is involved, affected processing roles, categories, subjects, data locations and provider copies; distinguish security incident from personal-data breach determination.
4. Engage Owner, incident lead, hosting/provider contacts and qualified privacy counsel; determine notification/communication under applicable law and contract.
5. Coordinate processor/customer notice and evidence exchange according to approved DPA; record decision even if no notification.
6. Remediate, rotate/revoke impacted secrets through approved procedure, verify integrity, preserve closure evidence and lessons.

**LEGAL REVIEW REQUIRED:** roles/responsibilities, notification threshold/deadlines, supervisory authority, customer communication, DPO requirement (do not assume one), evidence retention and insurer/contract reporting. External communication requires Owner/legal approval. Tests use synthetic incidents only.
