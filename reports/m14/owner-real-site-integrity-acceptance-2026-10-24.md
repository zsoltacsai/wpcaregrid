# Owner-approved real-site Integrity acceptance — 2026-10-24

## Decision and scope

On 2026-10-24, the Owner explicitly selected the existing `kiszov-szeged.hu`
registration for one bounded, non-destructive Integrity scan and replaced the
requirement to provision a second disposable WordPress site solely to prove
Connector-side Integrity execution. This decision changes the M14 acceptance
route; it does not convert the Website to a synthetic fixture, change its
Customer, subscription, billing identity, Connector pairing, or entitlements.

The accepted operation was the ordinary authenticated Website/Connector
workflow for an Integrity scan. No Malware or full scan, quarantine,
remediation, update, restore, database/file mutation, or re-pair was part of
the operation. The M14 synthetic fixture and its exact-scope side-effect
isolation remain separate optional test infrastructure; their privileges and
suppression rules were not applied to this Website.

## Release and staging evidence

| Evidence | Verified value |
|---|---|
| Repository | `zsoltacsai/wpcaregrid` |
| Primary branch | `claude/ecstatic-wozniak-oemyuf` |
| Deployed merge SHA | `8262bb60dccf5bd8681e4a97ac1d8cd56128d603` |
| Exact-SHA CI | [Run 40092077613](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40092077613), 30/30 jobs successful, including MySQL process/concurrency |
| Staging deployment | Protected deployment receipt reported at `2026-10-24T12:45:20.857039Z`, runtime SHA matched `8262bb60dccf5bd8681e4a97ac1d8cd56128d603` |
| M12 readback | [Run 40095400109](https://github.com/zsoltacsai/wpcaregrid/actions/runs/40095400109), Owner-approved read-only readback on the deployed release |
| Staging identity | `stg1.wpcaregrid.com`, application `https://staging.wpcaregrid.com` |
| M12 privacy gates | All six reported `FALSE` in the readback |
| Global incident settings | `security.incidents.auto_open=TRUE`; `security.incidents.auto_backup=TRUE`. They were not changed. |

## Website, Connector and scan

| Evidence | Verified value |
|---|---|
| Website | `https://kiszov-szeged.hu` |
| Website UUID | `01a16409-3537-7061-993d-a18fccd5b945` |
| Website classification | Existing Owner-authorized real, actively subscribed staging registration; not synthetic |
| Connector UUID | `01a16533-b4a5-7271-89f7-c032bae5b643` |
| Connector | Active pairing; version `0.4.7` |
| WordPress / customer-hosted PHP | WordPress `7.1.3`; PHP `8.2.35` |
| Scan UUID | `01a16dfa-afed-735f-a4fd-2eafcd0a8a3d` |
| Scan type / status | Integrity / `COMPLETED` |
| Connector job | Job 48; one attempt; terminal success; result `SCAN_COMPLETED` |
| Execution | Delivered `2026-10-24 12:59:14 UTC`; completed `12:59:24 UTC`; reported duration 10,752 ms |
| Coverage | 5,930 verified; 0 modified; 0 extra; 1 missing; 4 unavailable; coverage status `completed` |
| Findings | 0 persisted findings in this scan |
| Audit trail | Request, start, and completion events were observed |
| Remediation | Disabled; no remediation operation occurred |

The scan is evidence of a completed Connector-side Integrity operation on the
listed release and target. It is not a Malware scan, a full scanner campaign,
or proof of complete filesystem coverage. The missing and unavailable items
must remain visible and be triaged before making broader coverage claims. The
zero-finding result does not mean the Website is malware-free or completely
secure.

## Side effects and restoration

The Owner-provided operational evidence reports that no incident or backup
was created during the scan window and that global auto-open/auto-backup values
remained `TRUE`. Remediation stayed disabled. The Integrity result contract on
the deployed release excludes the malware-class `SuspiciousFile` finding type
that triggers the automatic incident path. This source-path review and the
observed zero incident/backup delta apply only to this scan; they do not prove
every asynchronous failure, retry, notification, or scanner-family side-effect
combination safe. No global setting was toggled for the scan.

An authoritative before/after notification-channel inventory was not retained
with the scan record. Therefore the notification-delta subcriterion remains
`PARTIAL` and is not inferred from the absence of an incident or backup.

## Acceptance-path change

The prior [`M14_DEDICATED_SECURITY_FIXTURE.md`](../../docs/M14_DEDICATED_SECURITY_FIXTURE.md)
requires a dedicated synthetic Customer/Website and disposable external
WordPress instance for Path A. The Owner's decision replaces that separate
external installation as a prerequisite for the one operational Integrity
execution criterion through Path B. It does not waive or claim the separate
security properties proven by isolated automated tests: cross-tenant denial,
incorrect Connector/Website binding, replay handling, readiness expiration,
unauthorized job refusal, incident isolation, backup authorization, and
notification routing. Those remain subject to their own test evidence.

No acceptance policy was changed to allow a customer to opt into a test bypass.
Path B requires an explicit Owner decision, an exact existing Website and
Connector identity, ordinary authorization/entitlement checks, a bounded
Integrity scan, current M12 and readiness evidence, and a current safety
preflight. Production Websites and ordinary Customer jobs do not gain any M14
fixture entitlement.

## M14 closure matrix reconciliation

Statuses below describe this evidence update, not an independent audit
certification. `PARTIAL` is retained where the current source artifacts still
identify incomplete evidence or where this update did not revalidate the
criterion.

| Closure criterion | Disposition on this evidence update | Reason / next proof |
|---|---|---|
| 1. Application Security Audit | `PARTIAL` | `appsec-audit-matrix.md` still identifies partial high-impact controls and operational attack paths. Complete its audit dispositions; this clean Integrity result does not close them. |
| 2. Tenant Isolation Audit | `PARTIAL` | `tenant-isolation-audit.md` explicitly remains a planning inventory / partial. Keep cross-tenant automated cases and async effects in scope. |
| 3. CI/CD Supply-Chain Audit | `PARTIAL` | Exact release-to-CI-to-protected-staging lineage is evidenced above. Reconcile the full current action/dependency, branch/environment protection, secret scope, provenance, and rollback audit before closing the audit criterion. |
| 4. Hosting Compatibility Audit | `PARTIAL` | Use the current operation-specific PHP/WordPress/database matrix and its exact CI fixtures. Unknown and untested high-risk combinations must remain blocked; do not infer the full hosting audit from this one PHP 8.2 site. |
| 5. Connector/webhook/archive safety | `PARTIAL` | Existing M14.2 bounded implementation evidence remains useful; reconcile the full audit and the remaining replay, archive, Connector and webhook cases before marking the aggregate criterion closed. |
| 6. Security scan | `PARTIAL` | The operational Integrity execution subcriterion passes. The 1 missing / 4 unavailable elements require triage; this operation does not prove Malware coverage or complete security scan acceptance. |
| 7. Operational safety | `PARTIAL` | Six M12 gates were `FALSE`, staging identity matched, remediation was disabled, and the scan produced no observed incident/backup. Notification deltas and broader queue/retry/side-effect combinations are not fully evidenced here. |
| 8. Backup entitlement | `PARTIAL` | Preserve the approved GFS/entitlement implementation evidence; unresolved legal/accounting/fact gates and the complete M14.4 disposition must remain explicit. No backup was requested by this scan. |
| 9. AI provider boundary | `PASS_WITH_MEDIUM_RISKS` within M14.5 scope | Existing M14.5 implementation and CI evidence support the bounded Owner-only, encrypted, write-only configuration groundwork. No feature/default or customer-data egress is authorized; this is not an AI product approval. |
| 10. Release/staging | `PASS` for this release | Exact SHA CI, MySQL process/concurrency, protected staging receipt, and runtime SHA match are recorded above. This is release evidence, not proof that every other audit is complete. |
| 11. Audit disposition | `BLOCKED_HIGH` for final M14 closure | Mandatory audits 1–5 and 7–8 remain incomplete or unreconciled; the independent-review residual remains open. No Critical/High exploit was identified by this scan, but unresolved high-impact review gaps are not silently accepted. |

`M14 CLOSED — VERIFIED` is **not** supported by this evidence update alone. The
old `NO COMPLETED STAGING SCAN` statement is superseded for the operational
Integrity-execution criterion, but M14.7/M14.8 remain open until the named
audit, coverage-triage, notification, and disposition requirements are closed
with evidence.
