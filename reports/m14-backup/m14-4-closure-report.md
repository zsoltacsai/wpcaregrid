# M14.4 implementation and validation closure report

**Status: implementation and required non-destructive validation complete; closure evidence PR pending.** Runtime implementation is merged and deployed to staging; no production deployment occurred.

## Release lineage

| Stage | Evidence |
|---|---|
| Planning PR #95 | reviewed head `05d31405f3fcd417f3c4f0c3b3e628f848c41abe`; planning merge `b4eeaea92ca4efe56fbc32ab266f55fdb5629b44`; exact CI `39003344652`, 7/7 |
| PR #96 — retention containment | candidate `d7c416a7fb3977bbe6f20f54ac3329a720e7fe5c`; merge `28cd690270452b181ef300436ba8af11abf7a18d`; CI `39006057842`, 7/7 |
| PR #97 — entitlement domain | candidate `b1740370513c3c934c2671a184ff1adc400315e2`; merge `470294f881939480f77fd15d387a4898c2f3a160`; CI `39008416514`, 7/7 |
| PR #98 — GFS / scheduling | candidate `1606ea527024800a6176e7a386d8edc0d94eeea3`; merge `cfc584a34f518952f222b752900a7547fbac097c`; CI `39010351986`, 7/7 |
| PR #99 — Owner controls / preview | candidate `69b086eab5446cd8bb787245a776e0ebb56d28b2`; merge and current primary `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209`; CI `39011616615`, 7/7 |

Mandatory jobs on each executable PR: Quality, Server SQLite, Server MySQL, Server MariaDB, Connector WordPress SQLite, Connector WordPress MySQL, Connector WordPress MariaDB. The MySQL process/concurrency gate ran successfully inside Server MySQL for PR #99. On PR #99, Server SQLite and Server MariaDB each reported 1,377 tests (19 skipped); each Connector WordPress matrix suite reported 210 tests. The workstation has no PHP CLI, so local PHPUnit could not be rerun here; exact-head CI is authoritative.

## Implemented behavior

- **M144-F02 containment:** scheduled `backup:retention` is report/comparison-only. It never calls `BackupDeletion::remove()`, provider deletion, storage deletion, or deletion queues. Legacy count/day values are comparison inputs only; `NULL` remains “not configured,” and no legacy configuration can enable deletion.
- **Canonical commercial entitlement:** `PricingCatalog` remains the price and plan identity source. The versioned engineering catalogue is `backup-entitlements-v1`; immutable/effective-dated subscription snapshots preserve product, tier, cadence, daily/weekly/monthly counts and policy context. Unknown mappings do not default to Basic.
- **Six v1 target plans:** WordPress Basic 0D/0W/3M monthly; WordPress Business 0D/8W/3M weekly; WordPress Secure 14D/8W/3M daily; WooCommerce Basic 0D/8W/3M weekly; WooCommerce Business 14D/8W/3M daily; WooCommerce Secure 30D/12W/6M daily. Engineering policy only, not a public offer.
- **GFS shadow evaluator:** UTC day `YYYY-MM-DD`, ISO Monday week `YYYY-Www`, month `YYYY-MM`; current slot plus prior `N-1` slots. Only complete, verified, usable, same-site points satisfy coverage. One point may satisfy multiple natural classes. Missing points produce recovery gaps. No catch-up burst is generated.
- **Protection:** additive GFS, last-known-good, safety backup, active restore/compensation, update/remediation dependency, incident/Owner holds. Unknown/legal/accounting/provider facts are blocked from destructive execution. There is no M14.4 provider deletion executor.
- **Manual backup semantics:** a verified manual point may satisfy a natural GFS slot, does not suppress schedule cadence, and receives no permanent protection merely because it is manual.
- **Owner controls:** extension-only server enforcement above plan floor; bounded post-service desired duration (default 30, 0 means immediate eligibility only after all gates); preview hash/fingerprint, 30-minute expiry, current-password confirmation for change, source-change stale rejection, audit trail. Owner actions only alter metadata and refresh shadow evaluation. No bypass/force/delete control exists.
- **Lifecycle / integration:** entitlement snapshots follow existing billing state and idempotency; subscription changes do not delete, cancel or downgrade does not synchronously remove points, and checkout/catalogue/entitlement/schedule consistency is covered by feature tests. Scheduler requires a known entitlement and M14.3 `BACKUP_READY`; entitlement alone cannot bypass readiness.
- **External waits:** `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT` remain explicit and fail-closed for post-service/provider deletion. Physical erasure is not claimed.

## Staging validation

The exact final executable primary SHA `29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209` was deployed to `stg1.wpcaregrid.com` using the guarded deployment script and pinned SSH host key. Pre-deploy SHA was PR #96 merge `28cd690270452b181ef300436ba8af11abf7a18d`; the deployment retained its database backup and completed migrations, cache rebuild, worker restart, scheduler resume, and HTTP smoke checks. Staging `HEAD` read back as the exact target SHA.

Safe runtime readbacks after deployment:

- all six M12 gates false;
- all twelve per-plan legacy `retention_count`/`retention_days` values read as null (`ALL_NULL`);
- application, database, queue, scheduler, Connector API, Stripe TEST, Cloudflare, backup storage, mail, safety and privacy checks OK;
- failed jobs 0; Connector 0.4.7 reporting; backup storage verified/readable;
- expected M14.7 warning remains: “No completed security scan yet.”
- public marketing smoke routes returned 200; protected Owner route redirected guests to authentication; environment files remained 403.

The interactive staging Owner session had expired and redirected to sign-in; authentication was not automated. The Owner page/preview/confirm/render/access behavior is covered by authenticated HTTP feature tests on exact-head CI. No pixel-level browser inspection is claimed.

Staging inventory found exactly one site with backups and confirmed it is the existing M8 synthetic fixture (8 verified backups). The deployed `backup:retention` report-only command was run against that isolated staging dataset and reported 0 legacy comparison candidates, 0 GFS gaps, and 0 blocked GFS candidates; it stated no backups were deleted. Its persisted evaluation state was `UNKNOWN`, not PASS, because the legacy synthetic fixture has no entitlement snapshot. Zero backup-object deletion records remained.

A known `wordpress.business` entitlement and Owner extension preview (base 8 weekly → proposed 12 weekly, 3 monthly → 6 monthly) was exercised against the same synthetic site inside a database transaction. A temporary synthetic Owner confirmed the extension; a below-floor request was rejected; a reduction preview became stale after backup metadata changed. The preview returned `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT`; the transaction was rolled back. Readback proved the synthetic website had no subscription, and temporary Owner, subscription, entitlement, policy, preview, backup metadata marker and deletion records were absent. This verifies deployed service-level preview/reduction safety without leaving test data. No real Owner policy was changed and no customer backup was touched.

## Safety and gates

No provider delete/storage delete/customer backup destruction occurred in M14.4. The scheduled legacy command is non-destructive. All six M12 gates were read before and after deploy and remained false:

| Gate | Before | After |
|---|---:|---:|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | false | false |
| profile anonymization | false | false |
| customer backup deletion | false | false |
| provider deletion | false | false |
| bulk destructive execution | false | false |
| normal-staging synthetic execution | false | false |

No production destructive functionality was enabled. M14.5, M15 and production deployment were not started.

## Closure disposition

Implementation PRs #96–#99 are merged and green. M144-F02, F03, and F12 are closed for acceptance based on source tests and exact-SHA synthetic staging evidence. `WAIT_LEGAL`, `WAIT_ACCOUNTING`, and `WAIT_FACT` remain the intended fail-closed residuals. This report is being carried by a documentation-only closure PR; no redeploy is needed for that report merge.
