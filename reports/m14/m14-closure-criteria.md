# M14 Closure Criteria and Future Audit Boundaries

## Final audit reconciliation — 2026-10-24

**Current verdict: `M14 CLOSED — VERIFIED`** for the bounded engineering and security-audit criteria accepted by the Owner. Current evidence is in [M14 final audit](m14-final-audit-2026-10-24.md). PR #171 merged at `b152fcfc53543938c27d820d966ca99bd56e22d6`; exact merge-SHA [CI 40108236754](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40108236754) passed 30/30 jobs, including MySQL process concurrency. The Owner reports protected staging deployment under receipt `release-20261024T165817.155049Z-b152fcfc53543938c27d820d966ca99bd56e22d6.json`. PRs #165/#166 and current HEAD `761e0e5d37558da808ac961ca2d2a68b4ffab0db` also passed exact-SHA CI; their staging deployment is Owner-reported. Historical blocked/partial statements are preserved and superseded only where this current disposition cites newer proof.

The Owner's 2026-10-24 acceptance-policy directive allows bounded engineering evidence to replace duplicative formality, while preserving authentication, tenant, Connector, privacy, destructive-operation, recovery and release controls. For the one completed, non-destructive Integrity scan, the historical notification-channel delta was not archived. It is not reconstructed or claimed to be zero. Source review finds no notification-send path on the validated Integrity result path; scan-result type validation rejects Malware-only `SuspiciousFile` findings before the incident/backup path, and the existing isolated regression test verifies no finding, incident or backup side effect for that rejection. This evidence is accepted for that one Integrity operation only; no Malware/full-scan or general notification-safety claim follows.

The completed scan's 1 missing and 4 unavailable counters remain explicit limitations. The sanitized persisted record lacks paths/reasons, so exact items cannot be retrospectively identified. PR #171 corrected Operator count visibility and the protected staging deployment is Owner-reported; authenticated UI visual verification was not completed after the browser session expired and remains a bounded M15 follow-up. No further live scan is warranted solely to recover information not persisted by the prior run.

| M14 area | Current disposition | Future boundary |
|---|---|---|
| Application security and tenant isolation | `PASS_WITH_MEDIUM_RISKS` for bounded M14.1 scope; no known Critical/High defect in evidence reviewed | Broader independent penetration and exhaustive service/async probes: M17; pilot must retain site-specific consent and response controls. |
| Supply chain / release | `PASS_WITH_MEDIUM_RISKS`; enforced ruleset and exact-SHA CI verified; historical protected staging receipt recorded | Single-maintainer review residual remains explicit. M17 independently rechecks production release lineage. |
| Hosting compatibility | `PASS_WITH_MEDIUM_RISKS` only within fixture matrix; unknown combinations fail closed | PHP 8.1, WP 6.4 and atypical hosting layouts remain unclaimed; evaluate during M15 host admission. |
| Connector / webhook / archive | `PASS_WITH_MEDIUM_RISKS` within M14.2 test and release evidence | Real pilot-host behavior and independent adversarial testing remain M15/M17. |
| Integrity scan | Operational Integrity criterion passed for the Owner-approved target; PR #171 adds aggregate coverage counters and its staging deployment is Owner-reported; 1 missing and 4 unavailable remain incomplete coverage | Individual historical paths were not persisted; authenticated UI visual confirmation remains M15 follow-up. No Malware scan implied. |
| Operational side effects | Bounded Integrity path accepted with source/test evidence; historical notification delta remains unknown | Malware/full scans and other notification-producing operations require separate evidence. |
| Backup policy | Engineering implementation passes; external `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT` remain fail-closed | Do not activate deletion/reduction until the respective policy owners resolve gates. |
| AI boundary | `PASS_WITH_MEDIUM_RISKS` for configuration groundwork only | No customer-data egress or feature use; separate approval and review required. |
| M15 / M17 | Not authorized by M14 closure | M15 pilot-entry and M17 production-launch criteria remain independently enforced. |

## M14 engineering closure requires

1. **Application Security Audit:** explicit route/service/job/API threat coverage, auth/session/re-auth, CSRF/XSS/SSRF, rate limiting, webhook and Connector review; all Critical/High findings resolved and retested.
2. **Tenant Isolation Audit:** every tenant-bound object and async effect tested; no cross-tenant disclosure/mutation; Owner and Operator abilities separated and enforced server-side.
3. **CI/CD Supply-Chain Audit:** required GitHub Actions contexts verified on protected primary, action/dependency/download integrity reviewed, secrets scoped, exact SHA → CI → merge → staging proven, rollback rehearsed.
4. **Hosting Compatibility Audit:** explicit support boundary and per-operation preflight implemented; supported/unknown/blocked outputs accurate; compatibility fixtures cover required WordPress/server/storage/filesystem/network cases.
5. **Connector/webhook/archive safety:** token lifecycle and request scope/replay model documented and tested; no unsafe archive path, symlink or unbounded expansion; Stripe callback authentic/idempotent.
6. **Security scan:** at least one current completed staging scan with scanner/feed/coverage evidence and all findings triaged. For the operational Integrity-execution criterion, either the dedicated synthetic Path A in [`M14_DEDICATED_SECURITY_FIXTURE.md`](../../docs/M14_DEDICATED_SECURITY_FIXTURE.md) or a separately Owner-authorized, bounded, non-destructive Integrity scan on an ordinary staging Website (Path B) is acceptable. Path B does not make that Website synthetic or waive normal customer/tenant/entitlement controls. The Owner-approved Path B scan on `kiszov-szeged.hu` is recorded in [`owner-real-site-integrity-acceptance-2026-10-24.md`](owner-real-site-integrity-acceptance-2026-10-24.md). Its 1 missing and 4 unverifiable items remain explicit coverage limitations requiring triage; zero findings is not a malware-free claim. Critical/High unresolved blocks; never suppress “no completed security scan” without completion evidence.
7. **Operational safety:** all destructive/action gates inventoried, missing config fails closed, job-time checks, circuit breaker and audit logs verified; M12 gates remain false.
8. **Backup entitlement:** canonical versioned M14 GFS policy domain and Owner workflow match approved OQ-04; Owner overrides extend protection only and cannot reduce below purchased entitlement, last-known-good, active-restore, safety, system or future approved legal/accounting floors. Policy reductions show impact preview and create eligibility only; old customer entitlements migrate safely; cadence/retention/protection/deletion remain distinct. Post-service duration and legal/accounting minima remain `WAIT_LEGAL` / `WAIT_ACCOUNTING` and cannot be invented.
9. **AI provider boundary:** implement only the OQ-05-approved Owner-only configuration groundwork for OpenAI, Anthropic and Google Gemini. Secret lifecycle is encrypted/write-only/masked/revocable; `CONFIGURED`, `ENABLED`, `HEALTHY`, `DEFAULT` and `AUTHORIZED_FOR_FEATURE` remain separate. No default, AI feature, autonomous action, AI marketing claim or customer-data egress is enabled. Customer-data transfer requires a separately approved purpose, data classification, security, privacy/legal/vendor and applicable tenant/customer authorization.
10. **Release/staging:** focused and full mandatory CI green on exact SHA; MySQL process/concurrency gate required; guarded staging rollout/health passes; use isolated synthetic fixtures for synthetic/destructive tests, while Path B real-site Integrity evidence remains an explicitly approved, bounded exception for that read-only scan criterion; no production deploy.
11. **Audit disposition:** required audits final status is one of `PASS`, `PASS_WITH_MEDIUM_RISKS`, `PARTIAL_FUTURE_SCOPE`, `BLOCKED_HIGH`, `BLOCKED_CRITICAL`. No Critical/High unresolved; medium risks named and explicitly accepted; M15 blockers explicit.

## M15 pilot-entry gates (separate from M14 closure)

- any unresolved Critical/High security finding, cross-tenant access, secret exposure, uncontrolled destructive path, kill-switch bypass or data-loss risk;
- absent hosting preflight or unknown operation incorrectly reported supported;
- Connector token/file/restore safety not proven;
- untrusted CI/deploy lineage or failed rollback rehearsal;
- staging scan incomplete or material finding not dispositioned;
- pilot scope/consent/customer-authority and operational response not documented.

DR/restore, billing/subscription/invoice, technical privacy-controls and operational safety audits remain required before/during M15 as their planned scope dictates; M14 evidence may contribute but does not automatically close them.

## Owner decision status

OQ-01–OQ-05 were approved on 2026-10-16 and are recorded in [the decision register](owner-questionnaire.md). These approvals remove the corresponding product-policy decisions as blockers to M14.1 and later implementation within the stated bounds. They do not satisfy compatibility/security evidence, resolve `WAIT_LEGAL`, `WAIT_ACCOUNTING` or `WAIT_FACT`, authorize customer-data transfer to AI providers, authorize destructive production behavior, or authorize M15.

On 2026-10-24, the Owner explicitly approved Path B for the operational
Integrity-execution criterion and accepted the completed bounded scan of the
existing subscribed `kiszov-szeged.hu` staging Website. This replaces the
requirement to provision a second external disposable WordPress site solely
for that criterion. It does not waive remaining M14.7/M14.8 audit, coverage,
independent-review, or synthetic negative-test requirements. The decision and
the exact scan evidence are recorded in the linked acceptance report above.

## M15 carry-forward

M15 separately requires review of Connector offline warnings, backup freshness, Integrity coverage, authenticated Operator UI display, actual pilot-host compatibility, site consent/scope, monitoring, and backup/recovery assurance. These are pilot-readiness gates, not retroactive M14 failures.

## M17 carry-forward

Independent penetration test for auth, tenant isolation, Owner/Operator, Connector, admin access and destructive actions; production DNS/TLS/secrets/backup/monitoring checks; full production DR/rollback; exact-release security recheck; production marketing/analytics setup and launch functions. M14 does not grant public launch readiness.

## M16 roadmap status

`M16 — PROPOSED / TO BE FORMALIZED BY MASTER ROADMAP`. This reconciliation does not define its scope or acceptance criteria.

## Verdicts

- `PASS`: all required scope passed and evidence current.
- `PASS_WITH_MEDIUM_RISKS`: only explicitly accepted medium risks remain.
- `PARTIAL_FUTURE_SCOPE`: named lower-priority work moved to M15/M16/M17 with no pilot/launch blocker hidden.
- `BLOCKED_HIGH` / `BLOCKED_CRITICAL`: do not enter M15; remediation and rerun required.
