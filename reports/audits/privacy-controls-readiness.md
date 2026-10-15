# Privacy Controls Readiness Evidence — M12 Wave 6

**Baseline:** `57fe3ef74b099f5ae6844f1ba6414149cc079d54`
**Purpose:** reusable control evidence for future M14/M15/M17 audits. It does not establish legal compliance or complete the future audits.

## Existing controls inspected

- Wave 2 export is private, application-encrypted, allowlisted and time-limited; it excludes secrets, other tenants, raw webhooks, site backups, and immutable accounting snapshots. See `reports/m12-privacy/wave2-implementation-report.md`.
- Wave 2 rectification uses current profile/versioned billing identity paths and does not rewrite historical invoice snapshots.
- Wave 3 legal documents have immutable versions and acceptance records, but no legal content was published and acceptance enforcement remains off pending qualified review.
- Wave 4 retention policy and impact previews are report-only. `M12_DESTRUCTIVE_RETENTION_ENABLED` defaults false, and all legal/accounting/security review statuses remain distinct.
- Wave 5 account closure and erasure are separate workflows. Closure restriction is reversible and audited. The service does not anonymize identity, delete backups, or call provider APIs. Provider tasks are metadata only.
- Immutable invoice/seller/tax evidence remains outside ordinary profile anonymization. Exact accounting retention remains `WAIT_ACCOUNTING`.

## Wave 6 changes

- Customer backup deletion is represented per fixed object key; provider response and read-after-delete result are separate fields. Unknown or partial state is not complete.
- Recovery-point protection is now non-overridable through the legacy CLI confirmation option. Partial deletion prevents restore use.
- Owner view exposes aggregate provider/deletion states and explicitly identifies unknown provider facts. It does not expose user/site names, URLs, or secrets.
- Provider task status now includes `unknown`; provider capability inventory separates code-level logical-delete support from unverified provider versions, locks, regions, contracts, and legal rights.
- The independent tombstone journal has a typed interface but no configured production adapter. Recovery readiness remains blocked until the journal is available and replay is verified.
- Audit events contain state/reason codes only; provider exception text and credentials are not persisted by deletion reconciliation.

## Controls not yet established

- No independent tombstone journal storage, cryptographic/authenticity contract, sequence/watermark proof, or end-to-end replay applier exists yet.
- No provider fact has been verified by live deletion/read-back in this wave; the tests exercise local temporary storage and provider mocks only.
- No live customer backup deletion, anonymization, provider-side deletion, or production mutation is authorized or performed.
- Legal basis, data-subject scope, statutory/accounting retention, subprocessors/transfers, and privacy notice conclusions remain for qualified reviewers.

## Future audit contributions

This evidence can inform M14 AppSec/tenant-isolation and CI supply-chain review, M15 DR/billing/privacy/operational-safety review, and M17 production readiness. Those audits remain open and must independently verify the deployed system, provider contracts, and operational recovery process.

## Release update — 2026-10-15

The M12 Wave 6 release correction for the platform-backup reconciliation command is merged at primary SHA `1a45951475a292ecc06c9547a4fd4b94ae5eab77`. Its exact PR head passed GitHub Actions run `38768895991` with all five mandatory jobs green. The change makes the command's persisted reconciliation status/timestamp behavior explicit and covers it with a regression assertion. No staging invocation of that state-writing command was made; staging verification used the read-only storage reconciler.

This does not close the independent tombstone-journal/replay gate, verify provider-side physical deletion/versioning, or resolve legal/accounting questions. Destructive retention remains disabled; no deletion, anonymization, or provider API mutation occurred.

## Independent journal / replay candidate — 2026-10-15

- Independent tombstone storage: **IMPLEMENTED IN CANDIDATE / staging unverified**. Journal event objects are encrypted and stored outside the application database rollback domain in the M9 platform-backup S3 namespace. Same-provider/account resilience, immutability/versioning and credential scope remain `WAIT_FACT`.
- Replay and restore-after-suppression prevention: **PARTIAL**. The candidate contains ordered, authenticated, idempotent replay for implemented account-access restriction and privacy-export revocation states. No irreversible profile anonymization exists yet, and future erasure must add its own journal action before it can ship. Missing subject/request mapping, active billing and unsupported/corrupt journal state block replay.
- Quarantine enforcement: **IMPLEMENTED IN CANDIDATE / staging unverified**. Recovery readiness binds to an out-of-band recovery ID, source backup UUID and watermark from the authenticated external manifest plus matching completed replay evidence and current journal watermark. HTTP customer/checkout/webhook operations and unsafe queue/Connector work remain gated during recovery; replay itself does not release quarantine.
- Historical restore proof: **LOCAL SYNTHETIC PASS / staging proof pending**. A real old disposable SQLite database file was restored, shown to lack the T1 action, then replayed from the real encrypted object-storage adapter. Repeat replay was idempotent; synthetic invoice snapshot data was unchanged.
- Exact-candidate CI, merge, guarded staging deployment, staging recovery proof and post-deploy health: **PENDING**. Strict SSH authentication failed with `Permission denied (publickey)`; host-key checking was not bypassed. The earlier PR #61 CI remains historical evidence only and does not validate this candidate.

No provider delete, account anonymization, live billing mutation, staging primary-database restore or production mutation occurred. `M12_DESTRUCTIVE_RETENTION_ENABLED=false`; `WAIT_FACT`, `WAIT_LEGAL` and `WAIT_ACCOUNTING` remain explicit.

### Journal completeness follow-up release evidence — 2026-10-15

PR #65 adds encrypted append-only per-event commit checkpoints chained by watermark and bound to the event-object hash. Missing/corrupt event or checkpoint, orphaned event and ambiguous chain now fail closed. It merged at primary SHA `31ee480be741ab818471e5781a27bbaa8afe2ade`; exact PR-head run `38788096639` and post-merge run `38789787609` passed all five mandatory jobs. Local synthetic historical restore remains separate from staging proof. Strict staging SSH authentication remains unavailable, so the release is not marked deployed or staged. Provider durability and total failure-domain separation remain `WAIT_FACT`; legal/accounting status is unchanged.

### Final staging privacy replay evidence — 2026-10-15

This supersedes the staging-unverified status above; the earlier candidate history is retained.

- **Independent journal:** staging resolves the privacy journal to the M9 platform-backup S3 adapter, outside the primary application-database restore domain. The active staging journal remained ready with watermark empty and zero production/staging events. The synthetic event/checkpoint pair was written only under `platform-database/wave6-proof-20261015-f4559904-7d32/privacy-tombstones/staging/`.
- **Historical restore:** a verified full T0 MySQL 8.4.11 platform snapshot was restored into an isolated disposable schema. The post-T0 synthetic Owner account-access restriction was absent in that restored T0 state. Replay applied the event and restored the suppression state; repeat replay was already-applied/idempotent. Quarantine readiness was blocked before replay and ready only after the matching replay session verified the journal watermark.
- **Integrity and fail-closed behavior:** staging injections verified blocked outcomes for unknown subject/request mapping, missing committed event object, corrupt event envelope and unavailable journal. Cross-tenant mismatch and invalid/orphan checkpoint-chain behaviors are covered by CI regression tests. No real provider objects were deleted.
- **Privacy/accounting boundary:** replay changed only the synthetic customer active flag and closure request state. Full content hashes for 16 billing/accounting/payment/Stripe-related tables were unchanged. No Stripe call or invoice side effect occurred. The synthetic fixture was reopened through the normal service after the drill; its closure request is cancelled. No personal payload was included in the journal event.
- **Current staging safety:** deployed SHA `f4559904990f95cf801b76ddeb4a1561cb3392b2`; recovery mode false; destructive retention false; backup restore and synthetic restore switches false. Failed jobs and active mutating WebsiteJobs were zero. Both queue workers were running. `system:check` passed all operational/privacy checks except the visible security scanner warning that no completed scan exists.
- **Residual gates:** S3 versioning was unset/never enabled, Object Lock was not configured and lifecycle rule count was zero in read-only provider inspection. Do not claim physical deletion or immutability; provider failure-domain/deletion facts remain `WAIT_FACT`. Legal and accounting remain `WAIT_LEGAL` / `WAIT_ACCOUNTING`. Wave 7 remains gated, and no irreversible anonymization/deletion action is implemented by this replay path.

Wave 6 staging validation is complete for the implemented non-destructive suppression/replay scope. This evidence does not claim a complete production DR audit or legal/accounting approval.

### Wave 7 safe execution evidence — 2026-10-16

Status: PARTIAL for Wave 7 staging evidence; no production execution authority.

- Execution plan/action models, preview hash/source binding, Owner password re-authentication and typed approval, stale-source invalidation, pre-action cancellation, and durable circuit breaker: IMPLEMENTED and merged in PR #69.
- Synthetic profile suppression, non-deliverable email release, authentication/session/token and Connector/pairing revocation, independent journal-before-mutation, actual local T0 SQLite restore/replay, repeat replay, re-registration isolation and invoice snapshot hash check: LOCAL SYNTHETIC PASS (7 tests, 65 assertions).
- PR #69 exact head b9865b011d2a1360ebba9569043069278801142b passed GitHub Actions run 38815372947 (5/5 mandatory jobs) and merged as 342f50cd94345dddcb5e5449d22040f3fad187d2. Exact primary was guarded-deployed to staging; staging health passed except the existing visible security scanner WARNING.
- Staging global/action destructive gates were verified false. The required isolated staging synthetic T0/T1/replay proof was NOT RUN: production-mode staging has no PHPUnit installation and the synthetic service refuses non-testing runtime. No primary staging/customer data was mutated. Keep this control status partial pending a separately provisioned isolated test runtime.
- Legal W7-L-01…03 remain WAIT_LEGAL; accounting W7-A-01…02 remain WAIT_ACCOUNTING; provider W7-P-01 remains WAIT_FACT. No provider delete API, production anonymization, or destructive retention was enabled.
