# M12 Owner technical/product default summary

> **Wave 4 supplement — 2026-10-14:** For retention settings, use the consolidated RET-01…RET-33 questionnaire in `retention-defaults-questionnaire.md`. The Owner may accept all recommended technical/product defaults or provide compact overrides. This does not approve legal/accounting values; compliance-locked policies remain view-only, and engineering safety guards are not Owner toggles. Wave 4 has no deletion behavior.

## Owner decision — RET-01 through RET-33 accepted — 2026-10-14

Implementation records the accepted defaults in version-1 report-only policy rows with Owner approval separate from every compliance review. The technical decision reference is stored per version; legal/accounting/security references remain unset until qualified review exists. See `wave4-implementation-report.md` for implementation status and source coverage.

The Owner responded **ACCEPT ALL RECOMMENDED TECHNICAL/PRODUCT DEFAULTS**. Every RET default is therefore **OWNER_APPROVED** as an initial product/engineering default, subject to the row-specific review state below. This approval is not legal advice, accounting approval, statutory retention determination, or permission to delete/anonymize/provider-delete data. See `final-decision-register.md` for the complete decision register and `retention-defaults-questionnaire.md` for individual rationale and response detail.

| IDs | Accepted defaults | Remaining state |
|---|---|---|
| RET-01–RET-06 | 14-day account closure delay; profile anonymization; unique non-deliverable email placeholder; 30-day post-service backup eligibility; 24-hour export link; 7-day export artifact | Legal/accounting exceptions and rights-delivery questions remain provisional where tagged; no destructive action |
| RET-07–RET-12 | 30-day raw webhook; 365-day minimized idempotency evidence; 30-day rendered email; 180-day delivery metadata; 30-day identifying logs; provisional 365-day security/audit horizon | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING and security review as applicable |
| RET-13–RET-16 | 30-day synthetic staging; 30-day retry then manual review; provisional 3-year case history; separate 14-day erasure cancellation window | Review-gated periods remain provisional; provider confirmation invariant unchanged |
| RET-17–RET-25 | Tombstone replay; destructive retention OFF; mandatory dry-run and preview approval; future batch 25 (inactive); restore/update/compensation/last-recovery-point guards; provider confirmation | Locked safety behavior is non-configurable; tombstone duration and exceptions remain review-gated |
| RET-26–RET-27 | Separate accounting evidence from erasable profile; do not rewrite historical snapshots | PROVISIONAL / WAIT_ACCOUNTING / WAIT_LEGAL; no accounting period chosen; compliance lock required after approval |
| RET-28–RET-33 | UTC policy effective time; immutable versioning; mandatory impact preview; category-specific rules; no tenant overrides initially; active-plan retention separate from 30-day post-service rule | Owner/product default resolved; legal/accounting category values and contract/notice review remain as tagged |

`M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory. The Wave 4 report-only evaluator may begin without further Owner product-policy questions. Legal/accounting review is still required before compliance activation or any destructive-retention wave.

This is a product/operations review, not a legal or accounting questionnaire. Recommendations below are reversible implementation defaults; approving them does not approve legal content, a retention period, a lawful basis, or destructive deletion.

| Decision | Proposed Owner default | Reason | Customer impact | Operational impact | Reversible? |
|---|---|---|---|---|---|
| Retention engine | Begin report-only: discover, classify, preview, audit; no delete calls | Safest way to learn eligibility and volume before irreversible work | No data disappears during rollout | Produces reviewable dry-run evidence | Yes; no data mutation |
| Erasure workflow | Asynchronous request with identity verification, impact review and explicit states | Supports retries, manual review and audit without an unsafe one-click cascade | Clear status and controlled handling | Requires queue/reconciliation visibility | Yes until execution |
| Cooling-off UX | Provide a cancel-before-execution window; leave exact duration unset until approved policy exists | Separates reversible product safety from legal response deadlines | Customer can correct accidental request before execution | Adds a scheduler/state transition | Yes before execution |
| Export | ZIP with canonical JSON, useful CSVs and a human-readable index | Portable and inspectable without proprietary tooling | Usable copy with clear contents | Requires allowlist, size limits and artifact expiry | Yes; export itself does not alter source data |
| Export delivery | Private temporary artifact, authenticated short-lived download and automatic expiry | Avoids public/permanent links and reduces exposure | Brief secure retrieval window | Requires private storage and expiry cleanup | Yes; regenerate on request |
| Account closure | Restrict access/revoke sessions first; defer physical deletion pending exception analysis | Keeps service control distinct from irreversible data destruction | Account becomes inactive before purge eligibility | Preserves billing/security linkage for reviewed exceptions | Yes before deletion |
| Cancellation/backups | Cancellation alone does not delete backups; move to paid-through/post-service state, then a separately approved policy | Keeps recovery state explicit and avoids surprise loss | Recovery points remain during transition | Requires status and retention previews | Yes until provider deletion |
| Platform backups | Separate schedule/retention from customer-site backups and protect a destination-specific minimum verified set | Platform recovery has a distinct dependency and risk profile | Better platform continuity | More policy and verification records | Yes before deletion |
| Historical backup erasure | Do not promise instantaneous physical removal from old generations; apply minimal tombstones before restored data is exposed | A restore can reintroduce previously erased records | Notice can describe realistic expiry/replay behavior after legal approval | Requires independent journal and restore gate | Design is reversible; replay policy requires approval |
| Tombstones | Keep a minimal suppression journal independent from the database rollback domain; avoid plain email hashes | Supports safe restore reconciliation without retaining excess identity | Prevents erased account from silently returning | Requires key/backup ownership and reconciliation | Can change before use; records append-only once applied |
| Legal holds | Do not build broad holds now; add only a narrow, scoped and reviewable override if counsel requires it | Avoids indefinite ambiguous retention | Reduces risk of unexplained retention | Fewer workflows until a real need is established | Yes before hold use |
| Staging | Synthetic-only by default; no production-to-staging copy | Limits exposure and keeps test fixtures deterministic | No direct customer impact | Requires maintained synthetic fixtures | Yes, but any exception requires separate controlled approval |
| Webhook/email payloads | Keep minimal event/idempotency and delivery facts separately from raw payload/body; do not retain rendered content indefinitely | Preserves retry/deduplication while reducing sensitive copies | No change to service behavior if designed correctly | Requires separate field lifecycles | Yes before purge schedule |
| Request authentication | Require recent re-authentication; stronger confirmation for erasure than profile edit | Reduces account-takeover deletion risk | Adds confirmation friction for sensitive actions | Standard auth/audit integration | Yes |
| Destructive control | One global `M12_DESTRUCTIVE_RETENTION_ENABLED=false` default; no UI bypass | Makes accidental activation fail closed | Prevents unreviewed removal | Requires explicit release and approval ceremony later | Yes before first destructive run |
| Analytics boundary | M12 stores consent-state interface only; GA4/GSC remain M13 | Keeps consent data model reusable without expanding scope | No analytics introduced by M12 | Clean integration point for later | Yes |

## Requested Owner response

Review and approve, revise, or reject the proposed defaults above. No response is needed to start non-destructive Wave 1 planning/engineering. The exact legal/accounting choices remain in the separate handoffs; this summary intentionally does not repeat them.

## Wave 5 Owner decisions recorded — 2026-10-14

- **ER-01: OWNER_APPROVED — Hybrid closure model.** Preserve required relational identity/history; future profile anonymization and physical deletion are separate, policy-gated actions.
- **ER-08: OWNER_APPROVED — A separate erasure request is required.** Account closure alone does not start the erasure cooling-off period. The account-closure case may separately restrict customer-area access after service-granting subscriptions have ended; an Owner can restore access through the audited closure workflow before a separate active erasure request exists.
- These are product decisions, not legal/accounting approvals. They do not authorize billing cancellation, Connector/site changes, identity revocation, anonymization, deletion, backup deletion, or provider deletion.
- Existing legal/accounting handoffs remain authoritative; no further Owner product-policy question blocks the non-destructive Wave 5 foundation.

## Wave 3 Owner decision recorded — 2026-10-15

- **W3-O1: OWNER_APPROVED — Mandatory acceptance OFF by default; only a separately reviewed and explicitly published rule may activate it.**
- This is a product/engineering default, not legal approval. It does not approve document wording, lawful basis, contract formation, controller/processor roles, DPA audience, notice versus affirmative acceptance, reacceptance triggers, or existing-customer transition.
- Remaining W3 questionnaire choices are pending. Until resolved, no public legal text exists by default, missing locale versions do not fall back, and DPA remains unpublished pending audience and role review.
- No customer-facing enforcement or document publication was enabled by this decision.

## Wave 3 release status — 2026-10-15

Wave 3 engineering and staging release are complete. PR #56 merged to primary as `8fb5be08108ec81c2de3a28c1ee11890554771a9`; exact PR-head CI passed all five jobs (run #220 / `38715011808`) and that primary SHA is on staging. No legal copy was published or acceptance requirement enabled. W3-O2/O3/O4 remain pending and fail closed; qualified legal review is still needed before publication or enforcement. Full evidence and residual security-scanner warning are in `wave3-implementation-report.md`.

## Wave 7 Owner decision — 2026-10-15

The Owner accepted recommended product/security defaults W7-OP-01…05 and W7-SEC-01. Per-subject approval and recent re-authentication are required; eligibility may be automatic but irreversible execution may not be; website content is outside ordinary account erasure; provider-pending/unknown work cannot be called complete; cancellation/reopen/retry follows the approved questionnaire; production destructive execution remains separately gated and disabled after Wave7 implementation.

Unresolved: W7-L-01…03 `WAIT_LEGAL`; W7-A-01…02 `WAIT_ACCOUNTING`; W7-P-01 `WAIT_FACT`. This Owner decision is not legal/accounting/provider approval and authorizes no deletion, anonymization, provider API call, or staging/production mutation. `M12_DESTRUCTIVE_RETENTION_ENABLED=false` remains mandatory.
