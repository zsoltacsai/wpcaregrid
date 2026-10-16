# M14.1 Tenant Isolation Test Matrix

All rows require two customers/tenants with distinct users and at least one valid object per domain. Use MySQL for lock/process cases; use SQLite for deterministic broad feature coverage. Assert response, database state, audit payload, queued jobs, and external-call fakes.

| Domain | Attack input | Expected result | Existing baseline | New required coverage |
|---|---|---|---|---|
| Customer/case | Tenant A requests Tenant B profile, closure, privacy request or execution plan UUID | 403/404; no disclosure/mutation | policy/route tests | every show, transition, cancel, export and service entry |
| Export | foreign, expired, revoked, guessed signed artifact URL | deny; no file bytes; audit contains no payload | privacy export tests | foreign-owner and stale signed-link cases |
| Website | foreign valid website UUID in account/operator routes | deny/no effect | WebsitePolicy and OperatorSecurity tests | every read and mutation route; nested-swapped IDs |
| Backup/object | own website with foreign backup; foreign website with own backup; object-name substitution | 404/deny; no storage call | nested restore and Connector tests | customer, Owner, Operator, Connector and queued transfer paths |
| Restore | foreign restore source, stale source after enqueue, concurrent restore/revoke | blocked; lock and source ownership rechecked | M8 restore tests | MySQL two-process and relation-change cases |
| Billing | foreign subscription, invoice, offer, payment portal and customer UUID | deny; no Stripe call | billing authorization tests | private invoice association and replayed webhook interactions |
| Security/incident | Connector submits foreign site finding/job result or operator changes unassigned site | reject; no incident/remediation side effect | Connector and Operator tests | foreign UUID, swapped job/site and delayed worker |
| Admin access | foreign request token, website/request pair mismatch, expired/revoked request | deny; no claim/end call | AdminAccess tests | concurrent revoke vs claim; cross-website IDs |
| Privacy journal/replay | tenant mapping points to foreign subject or absent mapping | quarantine remains; reject/manual review | Wave 6/7 replay tests | explicit A→B tamper and audit no PII |
| Owner global actions | non-Owner, Operator, Customer, stale Owner role, revoked ability | deny; no action | OwnerAccess/authorization contract | every sensitive workflow/direct service and session role revocation |
| Jobs/commands | forged/stale ID or parent association changed before handling | no wrong-tenant effect; fail/reconcile | selected job tests | `TransferBackup`, export, Stripe inbox, billing email and invoice jobs; test doubles for effects |
| Metrics/audit | cross-tenant event detail or PII label injection | aggregate/no PII/no foreign event | audit and monitoring tests | structured payload assertions and log capture |

For each row run positive own-tenant control beside negative foreign-object request; otherwise a blanket denial can falsely pass. Verify `DB::after` hashes or precise related-row snapshots and fake external adapters.
