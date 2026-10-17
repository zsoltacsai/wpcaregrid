# M14.4 — Commercial backup entitlement and retention architecture plan

**Status:** planning/domain audit only. No runtime changes, migration, provider call, deletion, deployment, or plan publication occurred in this pass.

## Verified baseline

- Authoritative remote `claude/ecstatic-wozniak-oemyuf`: `db272400513882bae8fa8d2ab15d0996cd34dcc7` (verified before the planning branch was created).
- M14.3 closure PR #94 ended at this primary; executable SHA reported by its closure evidence: `9e330854f0e7d5ef073db846245f7008d7ef6ec5`.
- Connector source release 0.4.7, API v1; compatibility policy v1/evaluator 1.
- A read-only SSH attempt used the approved host pin; the presented fingerprint matched, but available credentials were rejected. The staging deployment SHA above is therefore the latest repository evidence, not a live runtime readback. Current staging retention environment values are unknown.
- Work is isolated on fresh branch `codex/m14-4-commercial-backup-plan` from the verified primary. The existing root checkout has unrelated user changes and was left untouched.

## Source audit and present behavior

`config/pricing.php` is the canonical product/price catalogue. Its public/runtime product identities are `wordpress` and `woocommerce`; tiers are `basic`, `business`, `secure`. It supplies the six cadence values consumed by `PricingCatalog`, checkout price resolution, Stripe price mapping, and `BackupPolicy`. Preserve those identities; do not create parallel plan-key spellings.

Customer backup policy is split today: cadence comes from `config/pricing.php`, while `config/backup.php` defines plan type, nullable `BACKUP_RETENTION_{PRODUCT}_{TIER}_{COUNT,DAYS}`, manual=true, pre-update requirement, and restore switch. `BackupPolicy` maps the current subscription product/tier to those settings. Null count/days means no configured expiry. A configured numeric zero is clamped to 1 by `max(1, ...)`; it does not mean immediate deletion. Preserve this distinction in migration.

`backup:schedule` runs hourly, uses a UTC day/ISO-week/month window, checks connection/service/storage, and relies on unique `(website_id, schedule_window)` to avoid duplicate scheduled rows. It does not implement GFS slot classification. Missed historical windows are not burst-caught-up.

`backup:retention` runs daily. With a non-null configured retention count or days, `Retention::apply()` selects verified backups and invokes `BackupDeletion::remove()` for points outside the configured count/age. Existing protection includes newest verified point, restore dependencies, and pending update dependencies. The command is not intrinsically report-only and the source path has no separate customer-backup deletion kill switch. Whether non-null values are configured on staging cannot be verified without host access. Treat this as a P0 cutover/migration risk; implementation must make legacy retention non-executable/report-only before introducing any new evaluator, and must not infer runtime deletion posture from source defaults.

Backup rows currently store reason/status/manifest/checksums/sizes, schedule window, verified/completed times, expiry/deletion state and provider. They do not persist commercial catalogue/policy snapshot, GFS membership, or explicit protection reasons. Manual is enabled in source config for all public tiers and has a 60-minute per-site cooldown; commercial/manual quota semantics are not defined. Pre-update backups use separate reason/workflow linkage. Restore has separate safety-backup linkage and existing in-use protections.

Platform/Laravel DB backups use `config/platform_backup.php`, platform-specific storage/metadata/retention and are not customer-site backup entitlements. Do not reuse that retention configuration or storage lifecycle for customer packages.

## Domain boundary and v1 decision

Implement one server-owned, immutable, effective-dated entitlement snapshot for the subscription's canonical `product_type` × `plan`, tied to a versioned catalogue (`backup-entitlements-v1`). Keep price amounts and Stripe price identifiers in `PricingCatalog`; augment/adapt the canonical plan record so cadence and backup entitlement resolve from the same canonical product/tier identity, rather than keeping competing `config/backup.php` retention policy. The snapshot contains cadence, daily/weekly/monthly logical counts, pre-update safety policy, manual policy (explicitly decided before activation), restore feature eligibility, source catalogue version, effective interval, and transition source. It contains no provider deletion state.

Snapshot at confirmed subscription activation and at each effective plan transition. Current billing code maps Stripe price IDs through `PricingCatalog`; confirmed upgrades are applied after the paid transition, and scheduled changes carry `pending_plan`/`pending_change_effective_at`. New entitlement must follow that existing effective-time/idempotency boundary; webhook replay must not create duplicate transitions. Snapshot per transition; never rewrite prior rows. Keep each backup's request-time entitlement version/policy and scheduled slot context for explanation, while current retention selection uses the site's effective entitlement plus immutable historical evidence.

No new Owner decision is required for the approved v1 target. Before a future catalogue version changes paid policy, product/legal must determine grandfathering/renewal behavior; source code alone cannot establish contractual treatment. Manual-backup quota behavior is likewise unspecified: preserve current operational availability in a non-public rollout, but require a product decision/evidence before representing manual points as an entitlement.

## GFS and protection contract

Use UTC calendar slots: daily `YYYY-MM-DD`; weekly ISO-8601, Monday-start `YYYY-Www`; monthly `YYYY-MM`. Current slot plus immediately preceding `N-1` slots are the retained window. A selected point must be same-site, complete, verified, usable, object-complete, non-deleted, and timestamped within that slot. Select newest by verified time then stable ID. One physical backup can satisfy daily + weekly + monthly purposes only when its verified timestamp lies in the current selected day/week/month; store one artifact and multiple logical slot/reason references. Missing verified slot produces a recovery gap; never count failed/unverified points or fabricate historical points.

The evaluator returns deterministic required/satisfied/missing slots and `KEEP`, `ELIGIBLE`, `BLOCKED`, or `UNKNOWN` with policy version and reasons. Unknown subscription mapping, policy, hold, legal/accounting requirement, provider semantics, or protection dependency is never eligible. Candidate eligibility is not approval or execution. No provider calls are in M14.4.

The effective floor is purchased entitlement + Owner extensions + system safety protections + applicable holds. Protection reasons are additive and individually releasable only through their owning workflow. Safety backups are outside GFS counts and remain protected while their linked update/remediation/recovery requires them. Last known good is the newest verified usable recovery point and remains protected until a verified replacement exists. Active restore/compensation source, incidents/holds, legal/accounting holds, and unknown provider state block eligibility.

## Subscription lifecycle and post-service boundary

Use existing Stripe status, event ordering/idempotency, pending plan transition, cancellation-at-period-end, and seven-day payment grace semantics. Active/trialing retain paid service; past-due grace permits service only within its existing seven-day window; restore entitlement has its existing emergency exception. Do not invent retention/deletion consequences for past-due, suspended, unpaid, or canceled states. Plan changes adjust entitlement only when current billing semantics say the transition is effective; downgrades never synchronously delete and trigger preview/re-evaluation only. Cancellation/offboarding stops future service according to existing entitlement rules but does not delete backups.

Post-service desired retention options (0/7/14/30/60/90/custom, intended default 30) are preferences only. `0` means earliest possible eligibility, not immediate deletion. Until `WAIT_LEGAL` and `WAIT_ACCOUNTING` are resolved, executable post-service eligibility remains blocked. Custom bounds need explicit validation and policy approval at implementation; do not infer a legal minimum or publish the target as approved.

## Owner controls

Owner-only, audited, CSRF-protected controls can add protection above the purchased floor. Reduction/removal of an Owner extension requires the existing M14.1 recent re-auth path plus an immutable impact preview/source fingerprint. Preview shows points losing only that Owner reason, points still protected for other reasons, gaps and newly eligible candidates. A changed backup set, verification state, restore, hold, subscription/policy version or safety state makes the preview stale. Confirmation updates policy metadata only and recomputes; it never calls `BackupDeletion` or enqueues provider work. No Customer edit or Force/Override button is in scope.

## Rollout, validation and implementation batches

1. **14.4-A safety/domain:** first add a separate explicit non-destructive/report-only legacy retention posture and prove the existing command cannot delete while the cutover is being validated; catalogue/domain/schema and conservative backfill (`UNKNOWN`/review where mapping unclear).
2. **14.4-B schedule/GFS:** entitlement snapshot resolution, idempotent cadence slots, GFS shadow evaluator, protection reasons, gaps, no provider effects.
3. **14.4-C billing lifecycle:** idempotent subscription transition snapshots for creation/upgrade/downgrade/cancel/grace; checkout-to-entitlement contract.
4. **14.4-D Owner preview:** extension-only settings, stale-preview rejection, recent re-auth and audit.
5. **14.4-E shadow/staging/closure:** compare legacy and new decisions on synthetic metadata; exact CI; preview-only staging; closure evidence. Expected 3 executable PRs (A; B+C; D), plus one report-only closure PR; split further only for code ownership/reviewability.

Never dual-run two mutating retention authorities. Keep candidate generation shadow-only. Existing legacy retention must be safely disabled or made non-destructive before new policy becomes authoritative. Provider deletion and M12 destructive gates remain off.

## Readiness and handoffs

Planning is implementation-ready with no new Owner decision. `WAIT_LEGAL`, `WAIT_ACCOUNTING`, `WAIT_FACT` are explicit fail-closed gates, not blockers to non-destructive domain implementation. M14.5 integrations, M14.6 supply chain, M14.7 scanner/attack campaign, M14.8 integrated M14 closure, and M16 public plan presentation remain future work. M15 must independently validate real pilot hosts/DR.

Source references: `config/pricing.php`, `app/Pricing/PricingCatalog.php`, `config/backup.php`, `app/Backups/BackupPolicy.php`, `PolicyDecision.php`, `Retention.php`, `BackupDeletion.php`, `app/Console/Commands/Backups/{ScheduleBackups,ApplyRetention}.php`, `routes/console.php`, `app/Models/{Backup,Subscription}.php`, `database/migrations/2026_09_28_000000_create_backups_tables.php`, `app/Billing/{SubscriptionSync,SubscriptionEntitlements,SubscriptionStatus}.php`, `config/platform_backup.php`, `docs/BACKUP_ENGINE.md`, `reports/m14-compatibility/m14-3-closure-report.md`.
