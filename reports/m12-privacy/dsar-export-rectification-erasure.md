# Access/export, rectification and erasure design

## Existing behavior

Authenticated customer routes include profile edit, website/subscription/billing/backup operations. `CustomerPolicy`, `EnsureCustomerAccount` and model policies enforce ownership in normal routes. No privacy request, self-service export, account deletion or erasure workflow/route was found. Billing identity corrections create immutable versioned snapshots; invoice intents and identities explicitly forbid delete/update. Customer account creation follows paid checkout, not public sign-up.

## Proposed request lifecycle

`received → identity_check → scope_review → exceptions_review → approved/partially_approved/refused → processing → provider_pending → verification → completed` with `cancelled`/`manual_review` exits. Persist request type, subject/tenant, received and verified timestamps, due date under approved rules, requester channel, reviewer, policy version, category result, linked provider tasks, completion evidence, and reason codes; never persist a copy of ID document unless counsel and security approve necessity. Request deadline calculation and refusal text are configurable only after legal advice. Email confirmation alone should not authorize irreversible deletion after account takeover.

The request taxonomy should support the applicable rights confirmed by counsel: access, rectification, erasure, restriction, objection, portability where applicable, and correction of inaccurate data. Do not treat each request as a delete operation or infer that portability means a full database dump. Record the legal/contract scope and outcome for each.

## Export

Customer self-serve plus Owner-assisted workflow. Build an explicit versioned export allowlist from the inventory, scoped by authenticated `customer_id` and relationship. Proposed archive: JSON manifest/index; JSON profile/account, websites and subscription facts; CSV/JSON billing identity and invoice references with immutable snapshot distinctions; monitoring/security summaries that are customer-visible; website backup metadata, not raw site archive by default. Exclude other tenants, credentials/hashes, session/reset/pairing material, Stripe raw webhook payloads, operator-only security investigation notes, staff IP, and raw queue failures pending legal scope. Let counsel decide which categories constitute the access response; a UI download is not by itself the full legal response.

Create package asynchronously in private storage; encrypted at rest; random non-guessable ID; short-lived signed URL only after authentication; one-time/limited download; strict tenant authorization, no public bucket; audit requester, scope and completion without recording archive contents. Auto-expire generated package under approved short TTL, verify deletion and handle failed cleanup. CSV/JSON schemas and localized index are versioned and data-minimized.

## Rectification

Editable account/contact/address values update current profile. CustomerBillingIdentity uses immutable versions; changes create new versions and future transactions point to new identity. Existing invoice and `InvoiceIntent` snapshots remain unchanged. Corrections affecting an issued invoice follow an accounting-approved correction document path; do not rewrite records. Provide correction status/audit and propagate to providers only where approved and technically supported.

## Erasure/account closure

Separate cancel subscription, end paid service, disconnect website, close login, and erasure. Suggested stages: authenticated request; fresh authentication/MFA where available; email confirmation; cooling-off only if Owner/legal approves; scope and relationship verification; enumerate account/site/backup/Connector/provider/job/financial dependencies; prevent new jobs/checkout/invoice side effects; revoke sessions, pairing codes, Connector credential and admin access; stop monitoring/alerts; cancel service according to M11; export if requested; erase/anonymize permitted profile/site rows in bounded transactions; create idempotent provider deletion tasks; preserve narrowly justified financial/audit/security evidence; process backup eligibility and historical backup expiry; confirm external provider states; verify no tenant child records or operational commands remain; issue completion/exceptions notice.

Do not synchronously cascade from `Customer`/`Website`: several schema FKs cascade and others restrict/null; uncontrolled deletion can destroy audit/invoice evidence or leave remote objects. Use explicit deletion graph and transactional outbox/task states. A deletion job cannot execute while export, invoice issuance, billing reconciliation, backup/restore/compensation, or security containment is active. Reactivation or a new paid subscription during pending erasure must require an explicit, audited cancellation of the erasure request—not silently undo it.

## Security and tests

Require recent auth and step-up proof; notify established contact; rate-limit and prevent replay; Owner approval for exceptional/high-risk cases; keep appeal/hold path. Test IDOR with guessed UUIDs, simultaneous export/delete, provider timeout, immutable invoice exceptions, account takeover, duplicate requests, cross-tenant rows, restore-after-erasure and tombstone replay. Never produce export link or deletion status to a different tenant.
