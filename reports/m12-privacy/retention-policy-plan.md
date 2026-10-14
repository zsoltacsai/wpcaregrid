# Central retention policy plan

## Current code facts

- Customer WordPress `Retention` reads plan-level `BACKUP_RETENTION_*_COUNT` and `_DAYS`. Defaults in `config/backup.php` are null. `backup:retention` is scheduled daily at 03:30 UTC environment-dependent scheduler timezone; the service deletes verified backups only when configured, preserves newest verified artifact, and protects restore/scheduled-update dependencies.
- `BackupDeletion` removes remote backup object names, then transitions metadata to `expired`/`deleted` and records website audit. It refuses invalid states, active restore dependency, and last verified backup absent explicit operator confirmation. Customer UI has no delete route; Operator admin command and scheduled retention are present.
- Platform database backup records calculate `expires_at` from daily/weekly/monthly settings (M9 defaults 7/4/12 retention classes). M9 docs/code say platform expiry is metadata only; no platform artifact deletion is implemented.
- No generic retention-policy registry, legal hold model, privacy request/tombstone, deletion reconciliation ledger, or unified purge audit was found.

## Target rule object

Use a versioned policy record per data category/destination: `policy_id/version`, category, exact selector/model, trigger event, retention duration or review interval, justification reference, approval owner, effective date, action (delete, irreversible anonymize, aggregate, retain), provider/replica behavior, hold predicate, restore replay behavior, last review date, dry-run snapshot ID. The database row is not itself legal approval; attach non-secret approval reference and immutable audit.

Do not encode a deadline until Owner and counsel/accounting approve the basis and start event. Store all timestamps UTC; display locale-time separately. Policy change means future eligibility only and cannot retroactively mass-delete without separate reviewed preview/authorization.

## Candidate lifecycle matrix (duration intentionally TBD)

| Category | Trigger | Candidate action | Dependencies/hold | Approval |
|---|---|---|---|---|
| abandoned/failed checkout | terminal failure/cancel/expiry | minimize identifiers; purge or pseudonymize after approved interval | open Stripe session/reconciliation, fraud case, billing intent | Product + legal |
| account/profile | cancellation ends and offboarding is accepted | disable login/revoke sessions; delete or pseudonymize unnecessary profile | unpaid invoices, disputes, active site or support/security case | Product + legal |
| website inventory/monitoring | site disconnect / subscription ends | stop collection; aggregate then expire raw detail | security incident, active job, restore, contract | Product + legal |
| customer backup objects | plan count/day; canceled/offboarding close | object deletion with verified provider result, metadata retained only if justified | active restore, last recovery point, legal/security hold, pending upload/delete, replicas | Owner + legal + product |
| security incident/evidence | incident closed and review period elapsed | aggregate/redact, then purge selected evidence | active investigation, legal hold, disclosure duty | Security + legal |
| audit logs | event age and record type | retain minimal integrity record; pseudonymize actor/IP when defensible | security/accounting/legal evidential requirement | Owner + legal |
| Stripe raw webhook body | event processed and reconciliation/retry window elapsed | erase encrypted body; retain event ID, type, processing outcome as needed | pending retries/dispute/reconciliation | Billing + legal |
| billing email outbox | final delivery/failure terminal | remove rendered payload/recipient when not needed; keep minimal event and delivery result | unresolved mail failure/contract notice proof | Product + legal |
| invoice/accounting records | close of applicable accounting trigger | preserve immutable record; restrict identifiers / partial pseudonymization only if legally acceptable | statutory period, tax audit, dispute | Accounting + legal |
| platform DB backup | verified backup age/class and destination | delete only after per-destination proof and minimum recovery count; key lifecycle separately | incident, drill, legal hold, last good backup, restore reference | Owner + legal + M9 DR owner |
| staging/test data | fixture run end/account closes | clear only synthetic artifacts using dedicated test labels and provider sandbox | evidence retention requirement, active test job | QA Owner + security |
| logs / failures | log age/type | structured redacted records; shorter raw exception retention | incident investigation / audit | SRE + legal |

## Retention engine and activation stages

1. Inventory selector, relationship and deletion side effects; test idempotent classification.
2. Report-only dry run with row counts, object keys masked, exception counts and policy version.
3. Owner review and dual-control approval for first run / material policy change.
4. Bounded batches; pause/kill switch, distributed lock, idempotency key and transactions where possible.
5. External deletion as separate asynchronous tasks; failed/unknown means request remains open.
6. Verify independent storage/API state; write minimal immutable audit result; reconcile next run.
7. Destructive production enablement is a separate milestone with legal/Owner authorization and rollback/recovery plan.

Fail closed if categories overlap, the DB row is immutable financial evidence, provider says unknown, backup is referenced, account ownership is uncertain, or deletion tombstone would be lost from restore. Storage pressure never substitutes for approved retention.
