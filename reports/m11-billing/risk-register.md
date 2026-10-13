# M11 billing risk register

| Risk | Severity if realized | Current evidence / gap | Mitigation | Required evidence |
|---|---|---|---|---|
| Local state trusts out-of-order event | High | Event ID unique and timestamp guard exists; durable pending inbox absent | Inbox + canonical fetch for stale/conflicting event; shared projector | Out-of-order, same-second, MySQL race tests |
| Failed invoice payment immediately removes all service | High | Current `past_due` grants no access; grace absent | Owner-approved explicit grace and reversible restrictions | Boundary/recovery tests, no site deletion |
| Mutable catalog changes future/current semantics | High | Published price overlay mutable; retired IDs kept; no version ledger | Immutable catalog version and checkout pin | Existing customer price remains same after publish test |
| Webhook acknowledged then lost before queue | High | Current effects synchronous in request transaction; not durable async receipt lifecycle | Durable inbox and dispatcher recovery | Queue dispatch failure then drain test |
| Duplicate invoice issuance after ambiguous response | Critical | Existing one attempt/external reference and reconciliation lookup are good foundation | Never blind resubmit; enforce unique external/provider reference and runbook | Timeout after provider accepted test, exactly one invoice |
| Tax/accounting policy unapproved | High | Tax issuance gates and manual review exist; real rules not proven | Keep live issuance disabled until accountant signoff/test | Owner/accountant approval record and gate test |
| Mail failure blocks payment projection | High | Queued mailables after commit and M10 mail health exist; no common outbox is evident | Independent unique notification outbox; transaction isolation | Mail outage leaves paid subscription active |
| Stripe test/live crossover | Critical | Allow-live flag and empty-test-credentials test guard exist | Bind webhook, API key, Price IDs and app environment to provider account/mode; startup health asserts match | wrong-mode event/price rejection and config tests |
| Owner/admin direct status edit bypasses Stripe | High | Internal plan editing is separate; no general customer lifecycle UI | Operation commands only; read-only mirror | Permission and “cannot set active” tests |
| Grace/downgrade offboarding deletes customer assets | Critical | Connector gate controls operations; no M11 product rule yet | Never delete/disconnect websites in response to billing; preserve recovery | Failure scenarios and Connector regression |
| Billing identity mutation rewrites invoice history | High | Immutable identity snapshots and invoice links exist | Pin version on each intent/invoice; restrict snapshot mutation | Historical invoice snapshot test |
| Coupon reservation release/consume race | Medium/high | Reservation model, concurrency tests exist | Reuse transactional ledger; explicit event terminal handling | MySQL last-code race and unknown Checkout outcome |
| Customer email treated as payment identity | High | Stripe IDs are stored, unique Customer Stripe ID; email is contact | Bind by Checkout intent and provider IDs, not email matching | identity collision test |
| Environment config cache/worker stale | High | M10 found long-running worker stale config; worker lifecycle was handled operationally | Deployment reload protocol and tests for config-sensitive workers; no kill of active jobs | guarded deploy runbook and worker check |
| Stripe / Számlázz outage retry storm | Medium/high | invoice attempt bounded; no full billing retry policy | Exponential backoff+jitter, concurrency limits, poison queue, circuit/health signals | bounded retry and outage recovery tests |
| Webhook raw payload retention/privacy | High | Current table minimizes to IDs; async design needs durable payload or retrieval | Minimize/encrypt payload, short approved retention, M12 review | storage/log secret scans and retention review |
| Owner/operator data overexposure | Medium/high | Existing abilities/Owner area | Operators receive operational hold only; scoped Owner abilities | role matrix/IDOR tests |
| Local planning source stale/dirty | Medium | Local `f0194bca…` 79 behind remote `9b9f888…`; dirty/untracked files preserved | Start implementation from fresh clean primary; re-audit source and migration status | implementation kickoff SHA/worktree inventory |

## Open external/product risks

- Accountant/Owner must approve tax treatment, currency/FX, seller profile, invoice issuance and correction procedure before live Számlázz issuing.
- M12 must decide personal-data retention/deletion and invoice/event evidence retention.
- Stripe webhook/event API availability and data export/retention are provider dependencies; M9 APP_KEY recovery is critical because encrypted identity fields depend on it.
- Customer support, cancellation copy, annual discount marketing representation and payment-failure limits are product policy decisions, not engineering assumptions.
