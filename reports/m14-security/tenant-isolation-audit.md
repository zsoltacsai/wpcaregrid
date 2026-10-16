# M14 Tenant Isolation Audit — Planning Inventory

**Disposition:** `PARTIAL`; no cross-tenant defect confirmed in this source review. This is not a completed tenant-isolation audit. Cross-tenant reads or writes remain immediate M14/M15 blockers.

## Observed isolation foundations

- Customer web routes are grouped under `auth`, `customer`, current legal acceptance and recovery quarantine in `routes/web.php`.
- Nested website/backup restore and admin-access routes use scoped bindings; website and subscription/recovery-offer policies are registered in `app/Policies`.
- Owner and Operator use separate guards and role middleware, explicit abilities, and separate route files.
- Connector API credentials resolve a Connector, then require its exact Website UUID header; job and backup APIs should be checked for ownership at every use.
- Export and erasure services bind case/artifact/customer and check actor ownership; backup services check website/object relations and restore source belongs to the locked website.
- Coverage exists in `AuthorizationMatrixTest`, `AccountBackupsTest`, `PrivacyExportTest`, `PrivacyRequestConcurrencyTest`, `AdminAccessTest`, Connector tests and Owner/Operator authorization suites.

## Object/action matrix to close in M14

| Object/domain | Read | Mutation | Export/side effect | Async/API path | Evidence status / key tests |
|---|---|---|---|---|---|
| Customer profile and privacy cases | own customer only; Owner ability | own profile/service; Owner workflow | privacy export/download | `GeneratePrivacyExport`, signed download | PARTIAL; add cross-customer artifact/case, expiry and stale job cases |
| Websites/site metadata | own website relation | scoped pairing/disconnect/customer request | Connector pairing or external site operation | Connector heartbeat/inventory | PARTIAL; test every controller and stale UUID |
| Backups/backup objects | website belongs to customer | request backup/restore | object upload/download/delete | Connector backup API, transfer/retention jobs | PARTIAL; prove website→customer→backup→object scope at each boundary |
| Restore records | same website and verified backup | request/worker transition | writes to customer site | restore queue / controlled M8 runner | PARTIAL; concurrent reparenting, restore-vs-revoke and source-object mismatch |
| Billing/subscriptions/invoices | current customer association | own subscription actions; Owner-only financial changes | Stripe portal, webhook, invoice issuance | inbox/outbox/reconciliation workers | PARTIAL; test foreign UUIDs, event association, stale renewal job |
| Security findings/incidents/remediation | website scope; Operator permission | Operator workflow; remediation guarded | customer-site file operation | scan ingestion, remediation queue | PARTIAL; ensure connector can't submit another site's finding/incident/job result |
| Admin access requests | same website + customer | customer request/revoke, Operator approval path | one-time customer-site login token | Connector claim/end callbacks, expiry worker | PARTIAL; revocation/expiry race and cross-site token IDs |
| Provider deletion/reconciliation tasks | tenant/case scope | explicit Owner workflow | external provider effect disabled/WAIT_FACT | provider task workers | PARTIAL / WAIT_FACT; no production delete adapter authorized |
| Audit events and monitoring | Owner/Operator ability and tenant association | system append workflow | exports/metrics | queued delivery | PARTIAL; test event access and ensure metric labels contain no PII |
| Synthetic fixture/site | special explicit synthetic marker only | server provisioning and UUID-bound runner | synthetic backup/restore only per separate flag | M8 controlled polling | PARTIAL; verify synthetic marker cannot be forged through customer routes |

## Required audit method

1. Export every route and permission, then enumerate model-bound parameters and service method entry points.
2. Search `find`, `findOrFail`, `whereKey`, raw `DB::table`, unscoped relations, and serialized job identifiers. Classify each as global/system, already scoped, or needing an ownership assertion; the search result itself is not a vulnerability.
3. Build a two-tenant/two-user fixture and run read, update, delete, export, restore, billing, security, privacy, admin-access, Connector and provider-task attempts over web, API and worker paths.
4. Repeat with stale models, foreign-but-valid UUIDs, swapped nested IDs, duplicate deliveries and revoked permissions between queue and execution.
5. Assert denial is 404/403 per current policy, with no state or audit payload leakage and no external side effect.

## Launch blockers

Any cross-tenant disclosure, mutation, queued action, object download or external effect is `BLOCKED_CRITICAL`, blocks M14 closure and M15 entry. No passing audit should rely only on UI filtering or tenant IDs supplied by a caller.

## M14.1 source-review addendum — 2026-10-16

The verified primary for this review is `039a9ce555909243cd36ff5bd77352d43821915d`; staging executable last verified by strict SSH is `a335162936afa2933e348fe7b04956e98e45c5a3`. No cross-tenant defect was confirmed by this source review, but no runtime test was executed here. Existing foreign valid backup/admin-access and UUID route tests are useful evidence, not a complete domain matrix.

`TransferBackup` serializes an integer backup ID and globally reloads it with `Backup::find()`. Its processing follows the selected backup’s current website and backup-object relations. Treat this as a required stale/reparented-object job-boundary test, not as a demonstrated IDOR: prove in MySQL/process tests that stale/deleted/reparented source state cannot cause a cross-tenant or wrong-object storage effect. See [`m14-1-tenant-test-matrix.md`](m14-1-tenant-test-matrix.md).

Expanded Laravel route inventory remains a required implementation artifact. Static source declarations total 235 across web/Owner/Operator/API route files; PHP CLI was not available to produce `route:list` during planning.
