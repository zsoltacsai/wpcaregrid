# M13 Marketing Claim Register

Status describes copy inspected in the built-in HU/EN source, not a complete live content-database audit. Owner-published content can override defaults, so pre-release claim review must also inspect published runtime content.

| Claim | Page/context | Current copy / theme | Technical evidence | Safe as written? | Required qualifier | Future dependency | Owner/legal review? | Status |
|---|---|---|---|---|---|---|---|---|
| WordPress maintenance | Home | HU/EN hero positions routine WordPress/WooCommerce maintenance | service workflows, update/backup/security modules and plan catalogue | Yes, scope must match plan | State included tasks vary by plan | none | Owner review | supported; confirm published copy |
| Backups stored remotely | Home/pricing | “remote backups” / távoli mentés | backup subsystem/provider config exists; actual destination is deployment-configured | Conditional | say off-site only when configured destination/evidence supports it; clarify plan cadence | plan-specific config | Owner review | verify deployment and wording |
| Verified backups | Product positioning | safer wording approved in roadmap | code has write/readback/verification concepts; must tie “verified” to evidence definition and plan | Conditional | define technical verification, not recovery guarantee | none | Owner + technical review | qualify |
| Restore / recovery | Home/process | backup before update and post-update checking; marketing may describe controlled recovery | restore capability and compatibility gates exist; host/backup state can constrain ability | Conditional | “controlled recovery” / “restore readiness checks”; host compatibility applies | M14 hosting audit | Owner review | qualify |
| Updates | Home | core/plugin/theme updates in plan copy | update job system; not all updates necessarily unattended | Conditional | state actual approval/automation model and plan | none | Owner review | confirm per plan |
| Functional verification | Home | important flows checked after updates | feature exists; use exact coverage and frequency supported by plan/config | Conditional | “selected checks” if not universal | none | Owner review | qualify |
| Vulnerability/malware checks | Home | vulnerability and malware checks | security scanning capability; scanner warning says no completed staging scan in M12 report | Conditional | describe scan coverage and cadence; scan is not complete protection | M14 AppSec/security scan | Owner + security review | needs evidence/cadence |
| Malware cleanup | plan copy | included for Secure; separately quoted elsewhere | plan feature key and operational cleanup path | Conditional | only say for eligible plans and supported cases | current plan entitlement/config | Owner review | verify exact active catalogue |
| Uptime monitoring | pricing | uptime monitoring | monitoring feature, plan-dependent | Conditional | use actual cadence/coverage, avoid “real-time” | config/plan | Owner review | verify |
| Secure admin access | product capability | “secure connection” / admin access | scoped admin access feature with expiry/revoke controls | Conditional | describe authorization and duration; no absolute security claim | none | Owner/security review | qualify |
| Privacy controls | product trust | privacy engineering controls exist | M12 engineering closed, but WAIT_LEGAL/ACCOUNTING/FACT remains and destructive gates off | No if phrased as compliance/complete deletion | call it technical privacy controls; mention request-specific review as appropriate | legal/accounting/provider approvals | Legal review | no compliance claims |
| Physical deletion / permanent erasure | privacy lifecycle | none supported as a universal external fact | provider physical version/delete semantics are WAIT_FACT; real destructive execution off | No | no claim of guaranteed physical erasure | W7-P-01 + approval | Legal + provider review | prohibited pending evidence |
| GDPR compliance | privacy | prohibited by M12 boundary | engineering closure is not a legal certification | No | none; do not publish | qualified legal review and evidence | Legal | prohibited |
| Future backup retention tiers | pricing | roadmap-only future GFS schedule | not implemented in M13; M14/M16 scope | No as live behavior | clearly future if mentioned at all; best omit until launched | M14 then M16 | Owner review | future only |
| Intelligent / AI operations | positioning | requested long-term progression; not evidence of live AI | no marketed autonomous agent evidence found in audited routes | No for “AI automatically fixes” | describe currently available automation precisely; future AI clearly labeled | future product milestone | Owner review | future only |

## Prohibited/high-risk claims

Do not use: “100% secure”, “hack-proof”, “guaranteed recovery”, “zero downtime”, “fully automatic recovery”, “GDPR compliant”, “permanent deletion guaranteed”, “complete malware protection”, “AI automatically fixes everything”, “unlimited backups”, or “real-time monitoring” unless exact, current evidence supports the specific statement and required review is complete.

## Lightweight control

Keep this version-controlled table as the claim register. Each substantive public claim should link to a test, configuration, operating evidence, or approved product rule; record last review and limitation when claims are revised. Owner-published copy should be included in release review because source defaults are not necessarily the live published version.

## M13.1 built-in copy review

Reviewed with the M13.1 source changes on the implementation branch. Status below applies to built-in HU/EN defaults only; current Owner-published staging content still requires the read-only `marketing:claims-audit` review and authenticated visual review before closure.

| Claim family | Built-in HU/EN status | Evidence / visible location | Limitation / next action |
|---|---|---|---|
| WordPress and WooCommerce operations | SUPPORTED | Home and pricing copy; current feature and pricing catalogue | Scope remains plan-specific. |
| Separate backup storage | QUALIFIED | Localized home FAQ describes configured separate storage | Do not infer provider versioning, immutable retention or universal outage protection. |
| Backup verification | QUALIFIED | Existing backup verification and readiness implementation | Verification does not guarantee a successful restore. |
| Recovery after update | QUALIFIED | Localized FAQ describes pre-update backup where supported and environment-dependent recovery options | Hosting compatibility and available recovery point apply. |
| Activity history | QUALIFIED | Home activity section says supported actions are recorded | Does not claim every action is logged. |
| Update and security checks | QUALIFIED | Plan catalogue and home copy | Cadence and coverage depend on plan; scan completion warning remains an M14 residual. |
| GDPR compliance / physical erasure | NOT_PUBLISHED | Prohibited by M12 closure boundary; destructive execution remains off | WAIT_LEGAL / WAIT_ACCOUNTING / WAIT_FACT remain unresolved. |
| Future backup retention schedules | FUTURE_ONLY | No future GFS schedule added as current copy | M14 implementation and M16 pricing presentation remain dependencies. |
| Autonomous AI remediation | NOT_PUBLISHED | No live autonomous AI claim added | Future product direction only. |

The source audit is supported by `app/Marketing/MarketingClaimAuditor.php` and the read-only `marketing:claims-audit` command. Pattern matches are review candidates; they do not automatically block or rewrite content.

### M13.1 staging published-content review — 2026-10-16

On deployed SHA `9431db502105b51102905aefe60faee2efbcc021`, the read-only `php artisan marketing:claims-audit` command inspected built-in and currently published marketing content and reported no review-pattern matches. This is a bounded pattern audit, not legal approval or a guarantee that all claims are supported. The four rendered HU/EN home and pricing pages were separately inspected for titles, descriptions, H1, canonical, robots, language alternates, Open Graph, Twitter and structured data. The existing staging security-scanner warning remains an M14 residual and was not hidden or reclassified.
