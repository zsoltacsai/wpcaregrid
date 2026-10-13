# M11 execution backlog (dependency order)

All tasks are planning-level. Runtime work starts only from a fresh clean primary checkout at the then-current SHA. **M** marks mandatory for M11 closure; **O** optional/deferrable. Each implementation PR should be narrow, reviewed, exact-SHA CI-green, and provider changes remain test-mode until approved staging E2E.

| ID / priority | Dependencies / areas | Acceptance criteria | Tests/evidence | Owner approvals |
|---|---|---|---|---|
| A1 M — source re-audit & invariant inventory | Current primary; subscription, checkout, invoice, M10 mail | Document actual schema/state/jobs/env before coding; no duplicate model | Source paths, schema snapshot, test inventory | None |
| A2 M — policy decisions freeze | Owner choices in `owner-decisions.md`; accountant tax/invoice gate | Written decisions for grace, cancellation, switch/proration, refunds, portal | Approved policy table | Owner; accountant for tax issuance |
| B1 M — immutable catalog versions | PricingCatalog, PriceDefinition, checkout intent | Publish creates immutable version; old Stripe price/subscriber history preserved; checkout pins version | Price-change backcompat, retired ID webhook tests | Owner pricing approval |
| B2 M — lifecycle transition/projector | Subscription, status, billing snapshots | Provider facts separated from internal state; deterministic transition map | Full transition table tests, same-second/order tests | Owner policy approval |
| B3 M — DB schema expand | Checkout, subscriptions, events | Additive indexes/nullable fields for version, lifecycle, operation and receipt/outbox; no data reset | migration up/down on MySQL+SQLite; schema diff | Review only |
| B4 M — entitlement evaluator | Connector JobQueue, websites/backups/security | Central feature-key/limit service with grace and PROBONO parity; delivery recheck | Unit/feature and MySQL cap race | Owner plan mapping |
| C1 M — Stripe customer/Checkout hardening | Customer, StartCheckout, Stripe gateway | Customer identity remains immutable by provider ID; catalog version pinned; unknown result reconciled with same key | duplicate Checkout/concurrency/timeout | Stripe test account approval |
| D1 M — durable webhook inbox | Controller, handler, StripeEvent | Signed receipt durable before 2xx; unique event; retry/poison/lease; replay and mode protection | duplicate, queue dispatch loss, signature, poison tests | None; retention M12 review |
| D2 M — subscription/invoice projection & reconciliation | SubscriptionSync, InvoiceEvents, scheduler | Shared idempotent projector; bounded Stripe reconciliation repairs missed webhook | stale/mismatch/provider outage tests; MySQL races | Stripe test-mode credentials |
| E1 M — operation ledger and lifecycle commands | Customer UI, Stripe adapter | Upgrade/downgrade/cancel/reactivate are provider-confirmed, idempotent and serialized | timeout, concurrent commands, wrong customer tests | Owner policy/portal choice |
| E2 M — dunning/grace | State machine, scheduler, entitlements | Fixed deadlines, duplicate events do not extend; reversible restriction; no site deletion | frozen-clock boundaries and recovery tests | Owner grace/operational exception approval |
| F1 M — invoice snapshot and single issue | Existing invoice intent/Számlázz | Preserve existing issue gate; immutable identity/commercial snapshot; ambiguous lookup-only | issuance concurrency, provider loss, identity snapshot | Accountant/Owner tax approval for live issue |
| F2 M — customer invoice/state UX | Account screens, locales | Accurate pending/current/scheduled/reconciliation states; invoice access scoped | Feature/auth/localization tests | Product copy approval |
| G1 M — notification outbox | Existing queued mailables, M10 provider.mail | Idempotent emails; business transaction independent; bounded retry; alert on permanent failure | duplicate/outage/recovery HU+EN | Owner approved templates/recipients |
| G2 M — Owner/Operator UX and audit | Owner billing, AdminAudit | Safe troubleshooting, narrow action allowlist, no local state override; Operators see operational flag only | permission/IDOR/audit tests | Owner role matrix |
| H1 M — full regression/CI | All above | Laravel suites, Connector compatibility, MySQL process races, quality/security all green | Exact counts, skip reasons, CI SHA | None |
| H2 M — Stripe test mode E2E | H1, isolated test endpoint/mail sink | Checkout, verified webhook, entitlement, plan changes, failure/grace/recovery, cancellation/reactivation work without real money | preserved synthetic identities/event IDs, no live IDs | Owner test-mode staging window |
| H3 M — controlled staging release/closure | H2, guarded deploy | exact SHA deployed, health, invoice test boundary, mail/provider signals, gates and report verified | deploy evidence, closure report pushed | Owner release approval |
| I1 O — Billing Portal | E1 | Payment method update only; unsupported portal operations disabled | Stripe test portal integration | Owner UX choice |
| I2 O — Refund automation | accountant review, stable refund ledger | Only after signed refund/storno policy and provider idempotency design | test-mode partial/full refund matrix | Owner + accountant |
| I3 O — analytics cohorting | H1 | Privacy-safe events without payment details | event schema/retention review | Product/privacy approval |

## Suggested PR slicing

1. policy/catalog snapshots + additive schema;
2. entitlement resolver and transition projector;
3. inbox/outbox durability and reconciliation;
4. command ledger + upgrade/downgrade/cancel;
5. dunning and customer/Owner UI;
6. invoice gate integration and email completion;
7. concurrency/security hardening and staging test-mode E2E;
8. final evidence-only closure PR.

Do not bundle a large migration, live billing policy switch and provider activation into one deployment.
