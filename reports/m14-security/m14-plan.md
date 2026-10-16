# M14 Security, Hosting and Launch Hardening — Planning Baseline

**Status:** planning/audit only. No runtime behavior was changed and no deployment was performed.

## Verified baseline (2026-10-16)

| Item | Evidence / result |
|---|---|
| Authoritative branch | `claude/ecstatic-wozniak-oemyuf` |
| Primary SHA | `62fc2a74f2b0720e61b73d70c449639363a0eead` |
| M13 merge lineage | PRs #76–#82; PR #82 merged at primary SHA above |
| Planning checkout before branch creation | clean `codex/m13-final-closure-reports`; switched to the exact primary SHA before creating this branch |
| Staging executable SHA | `a335162936afa2933e348fe7b04956e98e45c5a3`; clean staging checkout |
| Staging identity | strict SSH host-key validation succeeded for `stg1.wpcaregrid.com`; pinned ED25519 fingerprint matched the approved fingerprint |
| Staging runtime | production mode, debug off, PHP 8.4.26, Laravel 13.33.0, MySQL connected, zero pending migrations, queue and scheduler OK, Connector OK, Stripe TEST configured, backup storage OK, mail OK, zero failed jobs |
| Current primary CI | GitHub Actions run `38883945688` passed on exact primary SHA `62fc2a74f2b0720e61b73d70c449639363a0eead` |
| Security posture | `security_scanner` WARNING: “No completed security scan yet.” Vulnerability data is not configured; no completed scan timestamp |
| Safety | security remediation, Operator full-admin and live restore are off. Privacy recovery readiness OK; M12 destructive-retention gate off |

The six M12 configuration gates were read from staging configuration and were false: global destructive retention, profile anonymization, customer backup deletion, provider deletion, bulk retention and synthetic profile anonymization. A separate M8 synthetic fixture controlled-execution flag reads true, but the implementation additionally binds to staging deployment identity, an active synthetic fixture and one configured site UUID; M8 restore flag is false. Treat that as a restricted M8 test capability, not as M12 anonymization authorization. Revalidate before any later execution.

## Audit scope and confidence

This is a source/configuration/report review plus read-only staging health and gate readback. It is not a penetration test, a completed security scan, a production configuration audit, a provider-account inspection, or a full manual route-by-route exploit exercise. `routes/web.php`, `routes/owner.php`, `routes/operator.php`, `routes/api.php`, middleware, policies, Connector, backup/restore, security, CI and deploy source were inspected. Existing tests and docs are mapped in the supporting audit reports. No secrets were read or recorded.

## Current evidence-led disposition

- **M14 is not closed.** The required completed staging scan is absent, and the four mandated M14 audits (AppSec, tenant isolation, CI/CD supply chain and hosting compatibility) have not yet been executed to closure.
- **No Critical or High exploitable defect was confirmed in this planning inspection.** This is a bounded code-review result, not proof of absence. The high-impact threat paths remain mandatory P0 validation targets.
- **Known medium/open evidence gap:** scanner has never completed; vulnerability feed is not configured. Keep the existing warning visible until scan evidence and source-feed disposition are complete.
- **Product policy decisions are needed before some later M14 waves:** support boundaries for Multisite/Bedrock/custom hosts, GFS override semantics and whether/where AI provider configuration will be exposed. Safe P0 security implementation can begin independently. See [Owner questionnaire](../m14/owner-questionnaire.md).
- External deployment/provider credentials, public DNS, production controls and vendor account settings are not inferred from source. No secrets were read or recorded.

## Proposed implementation waves

1. **M14.1 AppSec and tenant boundaries (P0):** full route/object authorization matrix, IDOR and job scoping tests, auth/session/re-auth review, CSRF/XSS review, scoped throttles, security events, switch inventory.
2. **M14.2 Connector, webhook and file safety (P0):** Connector token lifecycle/replay controls, payload caps, webhook signature/idempotency review, archive/path/symlink/ZIP-bomb adversarial validation, restore conflict and failure handling.
3. **M14.3 hosting compatibility and onboarding (P0):** define supported baseline, capability probes, diagnostics, clear unsupported/unknown states, WordPress/hosting fixtures; implement only after scope decisions.
4. **M14.4 commercial backup policy domain (P1):** versioned canonical package entitlements, GFS scheduling/retention model and safe Owner policy workflow; never conflate schedule, retained points, safety backups, legal holds and deletion. Must follow decision on overrides and pass recovery-point protection tests.
5. **M14.5 AI provider configuration boundary (P1):** encrypted secret lifecycle and separate configured/enabled/healthy/default/authorized states. Provider setup alone grants no customer-data access and activates no AI behavior.
6. **M14.6 CI/CD and deployment supply chain (P0):** pinned/reviewed actions, artifact/secret scope, exact-SHA lineage, staging rollback and SSH identity audit.
7. **M14.7 scan and adversarial staging validation (P0):** complete a scoped scan after P0 fixes; triage findings; manual attack-path checks; synthetic-only integrated staging evidence.
8. **M14.8 audit closure and M15 gate package:** dispositions for four required audits, accepted medium risks, explicit pilot blockers and M15/M17 carry-forward.

## Closure boundary

M14 can close only when required audits have explicit dispositions, no unresolved Critical/High or cross-tenant issue remains, the staging scan is completed and triaged, P0 compatibility/security controls are verified, exact-SHA CI and guarded staging validation pass, and production destructive actions remain off. M14 is not launch approval, independent penetration testing, M15 pilot authorization, or M17 readiness. See [closure criteria](../m14/m14-closure-criteria.md).
