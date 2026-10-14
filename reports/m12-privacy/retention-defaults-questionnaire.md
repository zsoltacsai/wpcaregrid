# M12 Wave 4 — Decision Defaults Questionnaire

**Purpose:** Owner-facing defaults for report-only retention planning. Every duration below is an engineering/product recommendation, not a legal or accounting conclusion. Accepting a technical default does not approve a lawful basis, statutory period, notice, or destructive action.

## Response format

Reply with **ACCEPT ALL RECOMMENDED TECHNICAL/PRODUCT DEFAULTS**, or list compact overrides such as `RET-03 = 14 days`. For a legal/accounting item, acceptance only records the provisional technical default; the item remains blocked from compliance approval and destructive activation until the qualified review/reference is recorded. Locked safety rules are not Owner toggles.

`OWNER CONFIGURABLE` means a future Owner Console setting within validated bounds. `BLOCKS WAVE 4 IMPLEMENTATION` asks whether the report-only evaluator itself must wait. No item below authorizes deletion.

## Defaults

### RET-01 — Account closure cooling-off

- **Question:** How long after service/account closure before a profile can become an anonymization candidate?
- **Recommended default:** 14 days.
- **Why:** Allows correction of accidental closure and settlement of billing/provider operations.
- **Alternatives:** Immediate eligibility; 7/30/60/90 days; custom.
- **Customer impact:** Reversible recovery interval before eligibility reporting.
- **Operational impact:** Requires a stable closure trigger and UTC calculation.
- **Reversibility:** Reversible before any later approved action.
- **Reviews:** Legal review not required for a safety delay itself; legal review required for any claim that it changes statutory rights/deadlines. Accounting: no; security: no.
- **Owner configurable:** Yes, operational policy.
- **Blocks Wave 4:** No; may remain provisional/unset in evaluator.

### RET-02 — Customer profile treatment after erasure

- **Question:** Should linked customer rows be anonymized or physically removed?
- **Recommended default:** Anonymize while preserving an opaque stable internal ID where referential history requires it; use `active → closed → privacy_review → anonymized` states.
- **Why:** Preserves referential integrity while separating erasure from financial/security exceptions.
- **Alternatives:** Physical deletion only where all dependent records and approved exceptions permit; retain restricted identity pending review.
- **Customer impact:** Direct identifiers are removed from active identity, but approved historical exceptions may remain.
- **Operational impact:** Requires field-level inventory and reference checks.
- **Reversibility:** Anonymization is not reversible; therefore later destructive gate and review are mandatory.
- **Reviews:** Legal: yes for scope/exceptions. Accounting: yes for billing-linked fields. Security: yes for credential/session revocation.
- **Owner configurable:** Strategy is product-configurable only within approved legal/accounting and referential constraints.
- **Blocks Wave 4:** No; Wave 4 reports candidates only.

### RET-03 — Email after anonymization

- **Question:** How is the original email made unavailable to the anonymized identity and reusable for a new account?
- **Recommended default:** Replace with a unique non-deliverable `deleted+<opaque-id>@privacy.invalid` value; do not reversibly encode the original.
- **Why:** Keeps uniqueness/FK behavior while preventing delivery and freeing the original address.
- **Alternatives:** Nullable email if schema/auth constraints support it; another reserved non-routable domain.
- **Customer impact:** New registration may reuse the original email after completed anonymization.
- **Operational impact:** Must invalidate sessions/tokens and suppress outbox retries; uniqueness collision handling required.
- **Reversibility:** Not reversible; identity history must not link a later registrant to the old row.
- **Reviews:** Legal: yes for retained pseudonymous linkage. Accounting: only where email is in immutable evidence. Security: yes.
- **Owner configurable:** No; engineering invariant once the strategy is approved.
- **Blocks Wave 4:** No.

### RET-04 — WordPress backups after service end

- **Question:** How long after paid service ends should customer-site backups first become eligible?
- **Recommended default:** 30 days post-service, separate from active plan retention.
- **Why:** Provides a bounded recovery/offboarding window without conflating plan frequency and post-service lifecycle.
- **Alternatives:** Immediate eligibility; 7/14/60/90 days; custom. Zero means eligible immediately, never “delete now.”
- **Customer impact:** Recovery points remain for the selected interval after service end.
- **Operational impact:** Requires termination trigger, provider object/manifest mapping, restore exclusions, and separate customer notice decision.
- **Reversibility:** Eligibility policy can be revised for future cases; a completed object deletion cannot be reversed.
- **Reviews:** Legal: yes for notice/retention claims. Accounting: no by default. Security: yes for recovery-point safeguards.
- **Owner configurable:** Yes, operational policy; cannot override locked safety checks.
- **Blocks Wave 4:** No; unresolved policy is reported as `BLOCKED_POLICY_MISSING`.

### RET-05 — Privacy export download link

- **Question:** How long is an authenticated export download link valid?
- **Recommended default:** 24 hours, signed and scoped to one artifact.
- **Why:** Limits exposure while allowing practical retrieval.
- **Alternatives:** 1/6/12/48 hours within a safe configured range.
- **Customer impact:** Export must be downloaded promptly or regenerated.
- **Operational impact:** Requires expiry enforcement and no public/indexable URL.
- **Reversibility:** Reversible for future links; issued links expire as configured.
- **Reviews:** Legal: review delivery/access policy. Accounting: no. Security: yes.
- **Owner configurable:** Yes within hard maximum bounds.
- **Blocks Wave 4:** No.

### RET-06 — Generated export artifact

- **Question:** How long can an export package remain stored?
- **Recommended default:** Maximum 7 days, then eligible for cleanup.
- **Why:** Avoids indefinite retention of a concentrated data copy.
- **Alternatives:** 1/3/14 days, subject to shorter signed-link lifetime.
- **Customer impact:** Regeneration may be needed after expiry.
- **Operational impact:** Requires private temporary storage, artifact state, and verified cleanup.
- **Reversibility:** Future duration is reversible; deleted package must be regenerated.
- **Reviews:** Legal: yes for response workflow. Accounting: no. Security: yes.
- **Owner configurable:** Yes within safe bounds.
- **Blocks Wave 4:** No.

### RET-07 — Raw webhook payload

- **Question:** How long should complete provider webhook payloads be retained?
- **Recommended default:** 30 days, then remove/minimize raw body while retaining only justified event/idempotency fields.
- **Why:** Supports short-term incident/retry analysis without indefinite provider data duplication.
- **Alternatives:** 7/14/60/90 days or unset pending review.
- **Customer impact:** No expected service change if derived state and idempotency evidence are kept separately.
- **Operational impact:** Requires split lifecycles and replay tests.
- **Reversibility:** Future rule reversible; discarded body cannot be reconstructed from metadata.
- **Reviews:** Legal: yes. Accounting: yes if payload contains payment/invoice evidence. Security: yes.
- **Owner configurable:** Yes only within reviewed bounds; provisional pending review.
- **Blocks Wave 4:** No; missing approval becomes blocker status.

### RET-08 — Webhook event ID/idempotency evidence

- **Question:** How long should minimized event identity and idempotency evidence remain?
- **Recommended default:** 365 days.
- **Why:** Operational window for delayed replay/reconciliation; not asserted as legally required.
- **Alternatives:** 90/180/730 days or reviewed custom period.
- **Customer impact:** No direct change; may affect duplicate-event rejection horizon.
- **Operational impact:** Must coordinate with provider replay horizons and stored derived state.
- **Reversibility:** Future rule reversible; removed dedup evidence can permit old replay unless other guards exist.
- **Reviews:** Legal: yes. Accounting: yes where it evidences transactions. Security: yes.
- **Owner configurable:** Yes subject to reviewed minimum and idempotency design.
- **Blocks Wave 4:** No.

### RET-09 — Rendered email content

- **Question:** How long should rendered email bodies/templates be retained in outbox records?
- **Recommended default:** 30 days, then clear body/content where feasible.
- **Why:** Limits stored copies of profile and billing data while preserving a short troubleshooting window.
- **Alternatives:** 7/14/60 days; store no rendered body after successful send.
- **Customer impact:** Old message content may not be available for support replay.
- **Operational impact:** Keep minimized delivery metadata separately; never re-send cleared bodies.
- **Reversibility:** Future setting reversible; cleared body is not recoverable.
- **Reviews:** Legal: yes. Accounting: review invoices/receipts. Security: yes.
- **Owner configurable:** Yes within safe bounds.
- **Blocks Wave 4:** No.

### RET-10 — Email delivery metadata

- **Question:** How long should minimized delivery records remain?
- **Recommended default:** 180 days.
- **Why:** Provides support/incident traceability without permanent recipient/content storage.
- **Alternatives:** 30/90/365 days or reviewed custom period.
- **Customer impact:** Older delivery status may be unavailable.
- **Operational impact:** Separate recipient/provider reference minimization from message body deletion.
- **Reversibility:** Future policy reversible; removed status is not reconstructible.
- **Reviews:** Legal: yes. Accounting: if message is transaction evidence. Security: yes.
- **Owner configurable:** Yes within approved bounds.
- **Blocks Wave 4:** No.

### RET-11 — Application logs with personal identifiers

- **Question:** How long should logs containing personal identifiers be retained?
- **Recommended default:** 30 days, with masking/minimization at ingestion where possible.
- **Why:** Limits secondary copies while preserving short-term operational diagnosis.
- **Alternatives:** 7/14/60/90 days; separate security audit streams.
- **Customer impact:** Older incidents may need non-identifying derived metrics.
- **Operational impact:** Requires log source inventory, masking, and central/local retention consistency.
- **Reversibility:** Future setting reversible; deleted logs are not recoverable.
- **Reviews:** Legal: yes. Accounting: no. Security: yes.
- **Owner configurable:** Yes within hard bounds.
- **Blocks Wave 4:** No.

### RET-12 — Security/audit events

- **Question:** What initial technical reporting horizon should be used for security and accountability events?
- **Recommended default:** 365 days, explicitly provisional.
- **Why:** Supports investigation and accountability; does not set a legal retention obligation.
- **Alternatives:** Separate security and audit periods; 90/180/730 days; unset until review.
- **Customer impact:** Impacts how far back incidents/actions can be inspected.
- **Operational impact:** Differentiate immutable audit evidence from ordinary application logs.
- **Reversibility:** Future policy reversible; deletion of evidence may be irreversible.
- **Reviews:** Legal: yes. Accounting: yes if event supports accounting evidence. Security: yes.
- **Owner configurable:** Viewable; operational duration configurable only after security/legal approval.
- **Blocks Wave 4:** No; missing approval is reported as a blocker.

### RET-13 — Synthetic/staging accounts

- **Question:** How long should synthetic staging accounts remain before becoming cleanup candidates?
- **Recommended default:** 30 days.
- **Why:** Keeps test environments bounded while allowing regression reproduction.
- **Alternatives:** 7/14/60/90 days; fixture-specific exemption.
- **Customer impact:** None if only synthetic data is used.
- **Operational impact:** Must prove environment tag and synthetic ownership; production data remains excluded.
- **Reversibility:** Reversible until cleanup; test evidence may be lost after cleanup.
- **Reviews:** Legal: review if any real/personal data is present. Accounting: no. Security: yes.
- **Owner configurable:** Yes for synthetic-only data; no production-data exceptions in this policy.
- **Blocks Wave 4:** No.

### RET-14 — Provider delete failure retry window

- **Question:** How long should provider-delete tasks retry before manual review?
- **Recommended default:** Retry with bounded backoff for up to 30 days, then `manual_review`; never mark complete without confirmation.
- **Why:** Avoids both unbounded retries and false completion.
- **Alternatives:** 7/14/60 days or provider-specific approved windows.
- **Customer impact:** Request may remain pending while provider state is unresolved.
- **Operational impact:** Requires idempotency, retry caps, alerting and operator queue.
- **Reversibility:** Reversible; no deletion assumption is made from timeout.
- **Reviews:** Legal: yes for response status/timing. Accounting: where records are held. Security: yes.
- **Owner configurable:** Yes operationally, but cannot weaken provider-confirmation invariant.
- **Blocks Wave 4:** No; Wave 4 does not call providers.

### RET-15 — Privacy request case history

- **Question:** How long should minimal case/audit evidence remain?
- **Recommended default:** 3 years, provisional only; store minimal events and references, not erased content.
- **Why:** Provides an initial technical case-history horizon for auditability.
- **Alternatives:** 1/2/5 years or a reviewed category-specific rule.
- **Customer impact:** Minimal case metadata persists after request completion.
- **Operational impact:** Requires strict access, minimization, and separate content deletion.
- **Reversibility:** Future setting reversible; deleted case evidence is not recoverable.
- **Reviews:** Legal: required. Accounting: if case evidence relates to accounting. Security: yes.
- **Owner configurable:** Viewable; duration remains provisional pending legal review.
- **Blocks Wave 4:** No.

### RET-16 — Cancellation window for erasure request

- **Question:** How long may a requester cancel before a later reviewed action can proceed?
- **Recommended default:** 14 days, distinct from account-closure delay RET-01.
- **Why:** Safety window for mistaken or account-takeover requests; not a statutory response deadline.
- **Alternatives:** 7/30 days; no product cooling-off with immediate review; custom.
- **Customer impact:** Adds a pause before irreversible action, not before legal response obligations.
- **Operational impact:** Requires identity verification, cancellation idempotency and clear case states.
- **Reversibility:** Reversible until approved execution; afterward not guaranteed.
- **Reviews:** Legal: review interaction with rights deadlines. Accounting: no. Security: yes.
- **Owner configurable:** Yes as operational policy, within legal constraints.
- **Blocks Wave 4:** No.

### RET-17 — Privacy tombstone

- **Question:** Should minimal erasure suppression evidence be replayed after restoring historical backups?
- **Recommended default:** Enabled in recovery design; store the minimum stable/pseudonymous reference in a failure-independent, protected journal. Retention period unset.
- **Why:** Prevents erased records from silently reappearing after restore.
- **Alternatives:** No tombstone only if an equivalent reviewed reconciliation control exists.
- **Customer impact:** Historical identity cannot silently reactivate after restore.
- **Operational impact:** Requires independent availability, key recovery, replay ordering and no raw email/hash.
- **Reversibility:** Once used, append-only evidence is difficult to remove; period/scope must be approved.
- **Reviews:** Legal: required for purpose and period. Accounting: review financial exceptions. Security: required.
- **Owner configurable:** Enablement is not an ordinary toggle; period is locked pending review.
- **Blocks Wave 4:** No; evaluator may report missing tombstone readiness.

### RET-18 — Automatic destructive retention

- **Question:** Should any automatic deletion/anonymization execute in Wave 4?
- **Recommended default:** OFF; `M12_DESTRUCTIVE_RETENTION_ENABLED=false` for the entire wave.
- **Why:** Wave 4's purpose is measurement and report-only eligibility.
- **Alternatives:** None within Wave 4.
- **Customer impact:** No data is removed.
- **Operational impact:** No delete executor, provider delete call, or destructive schedule is in scope.
- **Reversibility:** OFF is reversible only through a separate reviewed future change; never by policy UI alone.
- **Reviews:** Legal/accounting/security: future activation requires all applicable reviews.
- **Owner configurable:** No; locked safety guard.
- **Blocks Wave 4:** No.

### RET-19 — Dry-run requirement

- **Question:** Must every future destructive action have a dry-run/preview first?
- **Recommended default:** Yes; mandatory preview, blocker analysis, audit and separate authorization before any execution.
- **Why:** Exposes scope and blockers before irreversible actions.
- **Alternatives:** None for destructive work.
- **Customer impact:** Delays deletion until scope is understood.
- **Operational impact:** Requires deterministic snapshot and approved version binding.
- **Reversibility:** Safety invariant; no ordinary reversal.
- **Reviews:** Legal/accounting/security: no for the engineering control; review the policy scope.
- **Owner configurable:** No; locked safety guard.
- **Blocks Wave 4:** No.

### RET-20 — First destructive run after policy change

- **Question:** What approval is required after a policy version change?
- **Recommended default:** Owner approval of the new version's impact preview before any later destructive execution; policy changes never trigger deletion.
- **Why:** Prevents sudden eligibility expansion from becoming mass deletion.
- **Alternatives:** Separate dual approval for high-impact categories (recommended future enhancement).
- **Customer impact:** New policy may take effect for reporting before any removal.
- **Operational impact:** Requires draft → preview → activate and audit.
- **Reversibility:** Policy version can be superseded; completed deletion cannot.
- **Reviews:** Legal/accounting/security: category-specific approval still applies.
- **Owner configurable:** Approval workflow is a locked safety control; thresholds may be operationally configured later.
- **Blocks Wave 4:** No.

### RET-21 — Initial destructive batch size

- **Question:** What initial bounded batch size should a future executor use?
- **Recommended default:** 25 subjects/accounts per run; not used in Wave 4.
- **Why:** Limits blast radius and supports interruption/reconciliation.
- **Alternatives:** 1/10/50 with category-specific throttles.
- **Customer impact:** Future cleanup occurs gradually.
- **Operational impact:** Requires idempotent checkpoints and stop control.
- **Reversibility:** Adjustable before a run; completed deletions are not reversible.
- **Reviews:** Legal: no; accounting: review special categories; security: yes.
- **Owner configurable:** Later, within engineering caps; not during Wave 4.
- **Blocks Wave 4:** No.

### RET-22 — Active restore blocks backup deletion

- **Question:** Can an active restore be ignored when evaluating backup deletion?
- **Recommended default:** No. Active restore always blocks deletion of any dependent recovery object.
- **Why:** Prevents destroying an in-use source or recovery point.
- **Alternatives:** None.
- **Customer impact:** Cleanup may wait for restore completion.
- **Operational impact:** Requires lock/operation dependency checks fail closed.
- **Reversibility:** Locked safety invariant.
- **Reviews:** Legal/accounting/security: security review of implementation; policy cannot override.
- **Owner configurable:** No.
- **Blocks Wave 4:** No; evaluator reports `BLOCKED_ACTIVE_RESTORE`.

### RET-23 — Active update/compensation blocks deletion

- **Question:** Can an active update or compensation operation be ignored?
- **Recommended default:** No. Active update/compensation always blocks deletion of dependencies.
- **Why:** Prevents cleanup from racing with mutation/recovery.
- **Alternatives:** None.
- **Customer impact:** Candidate may remain blocked until operation settles.
- **Operational impact:** Requires operation-state/lock visibility and stale-lock handling.
- **Reversibility:** Locked safety invariant.
- **Reviews:** Security review required for implementation; no legal/accounting choice.
- **Owner configurable:** No.
- **Blocks Wave 4:** No; evaluator returns a blocker.

### RET-24 — Last verified recovery point protection

- **Question:** May a cleanup remove the last usable verified recovery point?
- **Recommended default:** Never in Wave 4 or later routine retention; preserve at least one usable point per required recovery scope. Any exception needs a separately designed and approved policy.
- **Why:** Avoids making recovery impossible to satisfy retention.
- **Alternatives:** None in this wave.
- **Customer impact:** Some old recovery points remain longer.
- **Operational impact:** Requires verified-state, destination and scope checks.
- **Reversibility:** Locked safety invariant.
- **Reviews:** Security/SRE review required; Owner may separately approve a new design, not toggle it.
- **Owner configurable:** No.
- **Blocks Wave 4:** No; report as `BLOCKED_RECOVERY_SAFETY`.

### RET-25 — Provider confirmation

- **Question:** When is provider deletion considered complete?
- **Recommended default:** Only after provider confirmation/reconciliation verifies expected absence or documented provider completion; request accepted is not completion.
- **Why:** Prevents false deletion status after timeout, async processing, or partial deletion.
- **Alternatives:** None for reporting completion; provider-specific evidence may vary.
- **Customer impact:** Completion may remain pending during provider uncertainty.
- **Operational impact:** Requires readback and reconciliation states.
- **Reversibility:** Locked safety invariant.
- **Reviews:** Legal/accounting: provider-specific exceptions reviewed; security review required.
- **Owner configurable:** No.
- **Blocks Wave 4:** No; provider state is only reported.

### RET-26 — Accounting records

- **Question:** What record types, periods and start events are protected by accounting retention?
- **Recommended default:** Keep accounting evidence separate from erasable profile data; no period is selected here. Owner Console displays only approved rule and approval reference; never permit weakening the approved minimum.
- **Why:** Preserves immutable invoice/payment/tax evidence without retaining all profile data by default.
- **Alternatives:** Category-specific schedules after accountant/legal determination.
- **Customer impact:** Some immutable financial evidence may remain after account closure.
- **Operational impact:** Requires field-level mapping and rule provenance.
- **Reversibility:** Technical separation is reversible before use; issued evidence is not rewritten.
- **Reviews:** **ACCOUNTING REVIEW REQUIRED**, plus legal review for retention basis/scope. Security: access-control review.
- **Owner configurable:** Display-only once approved; cannot lower the approved minimum.
- **Blocks Wave 4:** No; classify as `BLOCKED_ACCOUNTING`/manual review.

### RET-27 — Profile anonymization vs accounting snapshot

- **Question:** Should profile erasure rewrite historical accounting snapshots?
- **Recommended default:** No. Profile may be anonymizable; immutable accounting snapshots remain separate and follow an approved accounting schedule.
- **Why:** Prevents erasure from corrupting issued invoice/accounting history.
- **Alternatives:** Accountant-approved redaction/correction workflow, never silent rewrite.
- **Customer impact:** Historical documents may retain legally required facts.
- **Operational impact:** Requires distinct object categories, exports and access rules.
- **Reversibility:** Snapshot mutation is not reversible; default is to leave immutable evidence unchanged.
- **Reviews:** **ACCOUNTING REVIEW REQUIRED** and legal review.
- **Owner configurable:** No ordinary toggle; approved exception requires reviewed procedure.
- **Blocks Wave 4:** No.

### RET-28 — Policy effective date

- **Question:** When should a retention policy version become applicable?
- **Recommended default:** Require explicit effective timestamp in UTC; present local time only as a display conversion.
- **Why:** Makes evaluation reproducible and DST-safe.
- **Alternatives:** Date-only effective boundary at 00:00 UTC.
- **Customer impact:** Clear transition date; no retroactive implied change.
- **Operational impact:** Requires immutable effective time and deterministic clock injection.
- **Reversibility:** Future versions may supersede; prior decisions retain old version.
- **Reviews:** Legal/accounting review where policy affects approved categories; security no.
- **Owner configurable:** Yes when activating a new version; must be future/current and audited.
- **Blocks Wave 4:** No.

### RET-29 — Policy versioning

- **Question:** Must every policy change create a new immutable version?
- **Recommended default:** Yes; evaluations pin exact version, trigger and effective time. No silent edits to historical evaluations.
- **Why:** Preserves reproducibility and auditability.
- **Alternatives:** None for material policy fields; metadata correction must itself be audited.
- **Customer impact:** Old evaluations remain explainable under their original policy.
- **Operational impact:** Requires versioned draft/preview/active/superseded state.
- **Reversibility:** New version can supersede; historical version/evaluation is immutable.
- **Reviews:** Legal/accounting references attached when relevant; security review of audit controls.
- **Owner configurable:** Values are Owner-managed; versioning is a locked invariant.
- **Blocks Wave 4:** No.

### RET-30 — Policy impact preview

- **Question:** Is an impact preview required before policy activation?
- **Recommended default:** Yes; show counts by category and blocker, including newly eligible and newly blocked objects. Preview never deletes.
- **Why:** Identifies sudden scope change and mass-eligibility effects.
- **Alternatives:** None for material duration/trigger changes.
- **Customer impact:** No immediate data change; supports transparent policy rollout.
- **Operational impact:** Requires stable snapshot/time and preview-to-version binding.
- **Reversibility:** Preview is read-only; activation can be superseded.
- **Reviews:** Legal/accounting reviewers receive relevant counts; security review of leakage.
- **Owner configurable:** No; required control.
- **Blocks Wave 4:** No; this is a Wave 4 core feature.

### RET-31 — Category-specific retention

- **Question:** Should retention use one global duration or separate category rules?
- **Recommended default:** Separate rules for profile, customer backup, platform backup, webhook body, webhook idempotency, email body, delivery metadata, application logs, security/audit, exports, privacy case history, staging/test, and accounting evidence.
- **Why:** Different triggers, risks, providers and exceptions cannot be represented by one period.
- **Alternatives:** Group only after evidence shows equivalent lifecycle and approval.
- **Customer impact:** More accurate handling by data type.
- **Operational impact:** More policy rows and review burden; avoids accidental over-retention/early deletion.
- **Reversibility:** Category map can evolve by new version; historical evaluation retains original category.
- **Reviews:** Legal/accounting by affected category; security review required.
- **Owner configurable:** Individual operational policy values yes; category taxonomy is engineering-controlled.
- **Blocks Wave 4:** No.

### RET-32 — Tenant-specific retention overrides

- **Question:** Should each tenant be allowed a custom retention period in the first M12 implementation?
- **Recommended default:** No; global/product policy initially.
- **Why:** Avoids inconsistent legal promises and policy explosion before product need is proven.
- **Alternatives:** Future contract-scoped exceptions with formal approval and versioning.
- **Customer impact:** Same baseline policy applies to equivalent product categories.
- **Operational impact:** Simpler preview, audit, and support.
- **Reversibility:** Can add scoped override later without mutating global history.
- **Reviews:** Legal/accounting required before any contractual override; security review.
- **Owner configurable:** No per-tenant toggle in initial scope.
- **Blocks Wave 4:** No.

### RET-33 — Plan-specific backup retention

- **Question:** How should active subscription backup retention relate to post-service retention?
- **Recommended default:** Preserve existing plan-specific active backup semantics; configure post-service retention separately (RET-04). Do not infer one from the other.
- **Why:** Product frequency/active retention and offboarding lifecycle have different triggers.
- **Alternatives:** Plan-specific active rules only where already supported; no new tier promises without product approval.
- **Customer impact:** Existing plan commitments remain unchanged; post-service period is explicit.
- **Operational impact:** Requires distinct policy keys and trigger codes.
- **Reversibility:** Future active-plan rules can change only by reviewed product policy/version.
- **Reviews:** Legal review of existing commitments and post-service notice; accounting no; security review for recovery safeguards.
- **Owner configurable:** Existing supported active settings may remain product-configurable; post-service rule is a separate Owner setting.
- **Blocks Wave 4:** No.

## Classification summary

### Concise Owner response index

| ID | Recommended default | Flags |
|---|---|---|
| RET-01 | 14 days | [RECOMMENDED] [OWNER CONFIGURABLE] |
| RET-02 | Anonymize linked profile; preserve opaque ID where needed | [RECOMMENDED] [OWNER CONFIGURABLE WITH EXCEPTIONS] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-03 | Unique non-deliverable `deleted+<opaque-id>@privacy.invalid` | [RECOMMENDED] [SECURITY REVIEW] [LEGAL REVIEW] |
| RET-04 | 30 days after service end; zero means eligible only | [RECOMMENDED] [OWNER CONFIGURABLE] [LEGAL REVIEW] |
| RET-05 | 24-hour signed link | [RECOMMENDED] [OWNER CONFIGURABLE WITH BOUNDS] [SECURITY REVIEW] [LEGAL REVIEW] |
| RET-06 | Export artifact maximum 7 days | [RECOMMENDED] [OWNER CONFIGURABLE WITH BOUNDS] [SECURITY REVIEW] [LEGAL REVIEW] |
| RET-07 | Raw webhook body 30 days | [RECOMMENDED] [OWNER CONFIGURABLE PROVISIONAL] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-08 | Minimal idempotency evidence 365 days | [RECOMMENDED] [OWNER CONFIGURABLE PROVISIONAL] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-09 | Rendered email content 30 days | [RECOMMENDED] [OWNER CONFIGURABLE] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-10 | Minimized delivery metadata 180 days | [RECOMMENDED] [OWNER CONFIGURABLE] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-11 | Identifying application logs 30 days, mask first | [RECOMMENDED] [OWNER CONFIGURABLE WITH BOUNDS] [LEGAL REVIEW] [SECURITY REVIEW] |
| RET-12 | Security/audit 365 days, provisional | [RECOMMENDED] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] [COMPLIANCE-LOCK BEFORE ACTIVATION] |
| RET-13 | Synthetic staging account 30 days | [RECOMMENDED] [OWNER CONFIGURABLE FOR SYNTHETIC ONLY] [SECURITY REVIEW] [LEGAL REVIEW IF PERSONAL DATA] |
| RET-14 | Retry up to 30 days, then manual review | [RECOMMENDED] [OWNER CONFIGURABLE WITH GUARDS] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-15 | Minimal request history 3 years, provisional | [RECOMMENDED] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] [COMPLIANCE-LOCK BEFORE ACTIVATION] |
| RET-16 | 14-day request cancellation window | [RECOMMENDED] [OWNER CONFIGURABLE] [LEGAL REVIEW] [SECURITY REVIEW] |
| RET-17 | Tombstone replay enabled; retention unset | [RECOMMENDED] [LOCKED SAFETY] [LEGAL REVIEW] [ACCOUNTING REVIEW] [SECURITY REVIEW] |
| RET-18 | Destructive retention OFF; global gate false | [RECOMMENDED] [LOCKED SAFETY] |
| RET-19 | Mandatory dry-run before any future action | [RECOMMENDED] [LOCKED SAFETY] |
| RET-20 | Owner approval of impact preview after policy change | [RECOMMENDED] [LOCKED SAFETY] [LEGAL REVIEW] [ACCOUNTING REVIEW AS APPLICABLE] |
| RET-21 | Future destructive batch size 25; not used in Wave 4 | [RECOMMENDED] [LOCKED FOR WAVE 4] [SECURITY REVIEW] |
| RET-22 | Active restore always blocks backup deletion | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] |
| RET-23 | Active update/compensation always blocks deletion | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] |
| RET-24 | Preserve last verified recovery point | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] |
| RET-25 | Provider confirmation required for completion | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] [LEGAL/ACCOUNTING REVIEW AS APPLICABLE] |
| RET-26 | Separate accounting evidence; period unset pending approval | [RECOMMENDED] [ACCOUNTING REVIEW] [LEGAL REVIEW] [COMPLIANCE-LOCKED] |
| RET-27 | Do not rewrite accounting snapshots during profile anonymization | [RECOMMENDED] [ACCOUNTING REVIEW] [LEGAL REVIEW] [COMPLIANCE-LOCKED EXCEPTION PROCESS] |
| RET-28 | Explicit UTC effective timestamp per version | [RECOMMENDED] [OWNER CONFIGURABLE] [LEGAL/ACCOUNTING REVIEW WHERE APPLICABLE] |
| RET-29 | Mandatory immutable policy versioning | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] |
| RET-30 | Mandatory preview before policy activation | [RECOMMENDED] [LOCKED SAFETY] [SECURITY REVIEW] |
| RET-31 | Category-specific policy, not one global duration | [RECOMMENDED] [OWNER CONFIGURABLE VALUES] [LEGAL/ACCOUNTING REVIEW BY CATEGORY] |
| RET-32 | No tenant-specific overrides in initial scope | [RECOMMENDED] [OWNER PRODUCT DECISION] [LEGAL/ACCOUNTING REVIEW BEFORE FUTURE OVERRIDE] |
| RET-33 | Preserve active-plan semantics; separate 30-day post-service policy | [RECOMMENDED] [OWNER CONFIGURABLE POST-SERVICE] [LEGAL REVIEW] [SECURITY REVIEW] |

### Owner acceptance record — 2026-10-14

Owner decision received: **ACCEPT ALL RECOMMENDED TECHNICAL/PRODUCT DEFAULTS**. Each row below records the accepted technical/product default separately from any outstanding compliance approval.

| ID | Owner disposition | Compliance / safety disposition |
|---|---|---|
| RET-01 | OWNER_APPROVED — 14-day account closure cooling-off | No statutory-right conclusion; legal review only if interaction with legal deadlines is asserted |
| RET-02 | OWNER_APPROVED — anonymize linked profile, retain opaque ID where needed | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING for scope and exceptions |
| RET-03 | OWNER_APPROVED — unique non-deliverable placeholder; free original email for new registration | PROVISIONAL / WAIT_LEGAL for retained linkage; security review required |
| RET-04 | OWNER_APPROVED — 30-day post-service backup eligibility | PROVISIONAL / WAIT_LEGAL for retention/notice; not deletion authorization |
| RET-05 | OWNER_APPROVED — 24-hour signed export link | PROVISIONAL / WAIT_LEGAL for delivery/rights procedure; SECURITY_REVIEW |
| RET-06 | OWNER_APPROVED — 7-day maximum export artifact | PROVISIONAL / WAIT_LEGAL for request workflow; SECURITY_REVIEW |
| RET-07 | OWNER_APPROVED — 30-day raw webhook payload | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW |
| RET-08 | OWNER_APPROVED — 365-day minimized idempotency evidence | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW |
| RET-09 | OWNER_APPROVED — 30-day rendered email content | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW |
| RET-10 | OWNER_APPROVED — 180-day minimized delivery metadata | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW |
| RET-11 | OWNER_APPROVED — 30-day identifying application logs; minimize first | PROVISIONAL / WAIT_LEGAL / SECURITY_REVIEW |
| RET-12 | OWNER_APPROVED — provisional 365-day engineering horizon | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW; no compliance activation |
| RET-13 | OWNER_APPROVED — 30-day synthetic-only staging account eligibility | SECURITY_REVIEW; legal review if personal data is present |
| RET-14 | OWNER_APPROVED — bounded 30-day retry then manual_review | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW; provider confirmation stays mandatory |
| RET-15 | OWNER_APPROVED — provisional 3-year minimal case history | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW |
| RET-16 | OWNER_APPROVED — separate 14-day erasure-request cancellation window | PROVISIONAL / WAIT_LEGAL for interaction with rights timelines; SECURITY_REVIEW |
| RET-17 | OWNER_APPROVED — tombstone replay enabled; no duration selected | PROVISIONAL / WAIT_LEGAL / WAIT_ACCOUNTING / SECURITY_REVIEW; replay safety remains locked |
| RET-18 | OWNER_APPROVED — destructive retention OFF | LOCKED SAFETY; `M12_DESTRUCTIVE_RETENTION_ENABLED=false` |
| RET-19 | OWNER_APPROVED — mandatory dry-run before future action | LOCKED SAFETY |
| RET-20 | OWNER_APPROVED — Owner approval of impact preview after policy change | LOCKED SAFETY; category-specific legal/accounting review still applies |
| RET-21 | OWNER_APPROVED — future batch default 25; inactive in Wave 4 | LOCKED OUT OF WAVE 4; no execution |
| RET-22 | OWNER_APPROVED — active restore always blocks backup deletion | LOCKED SAFETY |
| RET-23 | OWNER_APPROVED — active update/compensation always blocks deletion | LOCKED SAFETY |
| RET-24 | OWNER_APPROVED — protect last verified recovery point | LOCKED SAFETY |
| RET-25 | OWNER_APPROVED — provider confirmation required for completion | LOCKED SAFETY; provider-specific legal/accounting exceptions remain reviewable |
| RET-26 | OWNER_APPROVED — separate accounting evidence from erasable profile; no period chosen | PROVISIONAL / WAIT_ACCOUNTING / WAIT_LEGAL; compliance-locked after qualified approval |
| RET-27 | OWNER_APPROVED — do not rewrite immutable accounting snapshots during profile anonymization | PROVISIONAL / WAIT_ACCOUNTING / WAIT_LEGAL for scope and exceptions |
| RET-28 | OWNER_APPROVED — explicit UTC effective time for each policy version | Category-specific legal/accounting approval remains required |
| RET-29 | OWNER_APPROVED — mandatory immutable versioning | LOCKED ENGINEERING SAFETY |
| RET-30 | OWNER_APPROVED — mandatory policy impact preview | LOCKED ENGINEERING SAFETY |
| RET-31 | OWNER_APPROVED — category-specific retention rules | Category-specific legal/accounting values remain provisional |
| RET-32 | OWNER_APPROVED — no tenant-specific overrides in initial scope | Future contractual exceptions require separate legal/accounting review |
| RET-33 | OWNER_APPROVED — preserve active-plan semantics; separate 30-day post-service rule | PROVISIONAL / WAIT_LEGAL for existing commitments and notice; SECURITY_REVIEW |

This approval is product/engineering Owner approval only. It does not approve a lawful basis, statutory duration, legal notice, accounting minimum, or destructive action. No value with a pending legal/accounting/security review may be compliance-activated or used for destructive retention until the required approval is recorded.

This index is the concise response surface; the detailed decision blocks above define impact, reversibility and review dependencies. For every `[LEGAL REVIEW]` or `[ACCOUNTING REVIEW]` item, accepting the technical recommendation leaves the policy provisional until the qualified approval and reference are recorded.

- **Owner-configurable operational policy:** RET-01, RET-04–RET-16 where reviews permit, RET-28 effective timestamp, and future operational category values. All bounds, versioning and impact preview remain enforced.
- **Compliance-locked:** RET-26/27 and any approved statutory/accounting minimum. Owner can view current approved value/source but cannot reduce it. No period is selected by this questionnaire.
- **Engineering safety guards:** RET-18–RET-25, plus RET-19/20/29/30 version/preview controls. These are not routine Owner settings. RET-17's replay protection is a recovery invariant; its duration/scope remains legal-review gated.
- **Provisional due to review:** RET-07–RET-12, RET-15–RET-17 and RET-26/27. Accepted recommendations remain provisional until qualified review is recorded.

**Wave 4 implementation gate:** these recommendations can guide report-only configuration and output. Missing/unapproved policy values must be represented as missing/blocked/manual-review, never silently treated as approved. No response or acceptance here enables destructive retention.
