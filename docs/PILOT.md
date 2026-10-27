# M15 pilot readiness and controlled non-production smoke test

**Current disposition (2026-10-26): NOT READY TO START A PILOT.** This runbook defines evidence and decision gates; it does not authorize a staging/customer operation, production activity, scan, update, backup, restore, Connector pairing, or deployment.

This runbook applies only to an explicitly approved non-production pilot target. Production readiness and release authorization are separate gates; this document must not be used to authorize production tests.

Server setup: [DEPLOYMENT.md](DEPLOYMENT.md). Release gates: [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md). Connector compatibility: [CONNECTOR_COMPATIBILITY.md](CONNECTOR_COMPATIBILITY.md). Backup behavior: [BACKUP_ENGINE.md](BACKUP_ENGINE.md). Restore fail-closed contract: [M15 RestoreIsolationGate contract](../reports/m15-restore-isolation-gate-contract.md). Current evidence reconciliation: [M15 evidence report](../reports/m15-evidence-reconciliation-2026-10-25.md).

## Evidence labels and operating boundaries

Use one of these labels for every target-specific readiness claim:

- **VERIFIED** — current, timestamped evidence from the exact environment, site, Connector and operation.
- **STALE** — evidence exists but exceeds its freshness window, predates a relevant code/configuration change, or cannot be tied to the current target.
- **UNKNOWN** — evidence is absent, ambiguous, unreadable, or was not safely collected.
- **NOT CONFIGURED** — the required capability or operational integration is confirmed absent.
- **BLOCKED** — a required safety, authorization, compatibility, recovery, or side-effect condition is unmet.

Unknown or stale evidence is never a pass. Repository defaults, CI results, old screenshots, and historical site observations do not establish current staging state.

Before any pilot action, require:

1. Exact non-production target, tenant, Website and Connector identities, named site owner, authorized operator, time window, approved operation list, stop authority, and evidence/retention method.
2. Written site-owner consent and Owner authorization scoped to the exact site and operation. Existing subscription, previous consent, an earlier M14 scan, or this runbook is not blanket pilot consent.
3. A fresh application/Connector release identity and operation-specific compatibility evidence. The repository's Connector version is an expectation, not proof of the installed artifact; record installed version and approved artifact fingerprint.
4. Queue, incident, backup, notification and external-integration effects understood and bounded before any action that can trigger them.
5. A verified backup before a site-changing operation. Backup verification is not proof that restoration will work; the recovery path must be independently credible for the target.
6. Explicit per-operation Owner GO. One operation's approval does not authorize another.

Never use synthetic identities to bypass authorization. Do not probe customer sites, reconnect a Connector, create a pairing, trigger work, or poll a Connector merely to establish status.

## 1. End-to-end smoke test

On one explicitly approved non-production Laravel installation and a non-production WordPress site that the site owner has authorized. Production is out of scope. Stripe/payment testing is a separate approval and environment; do not create a real payment or subscription as part of this pilot.

| # | Step | How | Expected |
| --- | --- | --- | --- |
| 1 | Owner sign-in | `/owner/login` | Overview with real (or zero) figures |
| 2 | System status | Owner → System, and `php artisan system:check` | No ERROR; only parts you deliberately left unconfigured are NOT CONFIGURED |
| 3 | Operator | Owner → Operators → invite; open the emailed link; set a password; sign in at `/operator/login` | Operator active with exactly the ticked permissions |
| 4 | Customer and subscription | Buy a plan on `/pricing` with a Stripe test card | Account created only after the signed webhook; activation email received |
| 5 | Website | Customer → Websites → add the site's URL | Website listed, "not connected" |
| 6 | Pairing code | Website page → Generate pairing code | 12-character code, valid 30 minutes, shown once |
| 7 | Install connector | Download the plugin ZIP from the account; Plugins → Add New → Upload | Connector active |
| 8 | Pair | Settings → WP CareGrid → enter the code | "Connected", capabilities listed (database backups need MySQL/MariaDB) |
| 9 | Connector status | Read an already persisted, safely exposed status snapshot; do not trigger a poll to test liveness | Pairing and last-seen/poll evidence are current for this exact Connector/site; queued work is known before any separately approved poll |
| 10 | Inventory | automatic after pairing | Plugins, themes, versions, updates in the account and console |
| 11 | Manual backup | Only after separate operation approval and preflight | Exact site-bound backup job is authorized; storage and temporary capacity are isolated and sufficient |
| 12 | Verify backup | wait for the `backups` worker | Status **verified**; activity: requested → started → uploaded → verified |
| 13 | Queue a plugin update | Operator → website → Update (a plugin with an update) | "Runs after the required pre-update backup has been verified" |
| 14 | Pre-update backup | automatic | A `pre_update` full backup verified **before** the update starts |
| 15 | Update | automatic | `PLUGIN_UPDATED`; new version reported |
| 16 | Health check | part of the update result | Health and inventory refreshed; Site Health updated |
| 17 | Functional check | **manual**: front page, login, contact form / checkout | Site works; record who checked (the platform does not yet automate functional checks) |
| 18 | Security scan | Only the exact scan type/scope in a separate operation approval | Record each scanner's version, covered/missing/unavailable paths and side effects. Integrity success does not prove complete malware coverage; vulnerability coverage requires a separately verified feed |
| 19 | Admin access | Customer → Open Admin | WordPress admin opens as "WP CareGrid (maintenance access)"; user management, plugin installation, file editor and settings refused |
| 20 | Audit review | Operator → Activity; Owner → Overview / customer page | Every step above recorded with the right actor (customer, operator, owner, system) |
| 21 | Revoke access | Customer → website → revoke | The site ends the session at its next check-in; `/wp-admin` asks for login again |

Any HTTP smoke test must target the exact approved non-production URL and stay within its documented read-only checks. It does not authorize a poll, scan, backup, restore, update, or production request.


### Restore and recovery (blocked unless the host is supported)

The merged [RestoreIsolationGate contract](../reports/m15-restore-isolation-gate-contract.md) is fail-closed: without a present, reviewed provider, live files-only, database, full, recovery and compensating restore operations are refused before maintenance activation or live mutation. A provider ID or capability snapshot alone is not proof of an active host gate. The wider host-provider architecture and implementation remain open under [issue #189](https://github.com/zsoltacsai/wpcaregrid/issues/189), and require separate Owner architecture approval, independent review, and target-host compatibility evidence.

A later isolated restore rehearsal requires a disposable copy, exact approved provider and host profile, verified safety backup, documented recovery authorization, explicit Owner approval, and independent observation of gate/receipt/reconciliation behavior. This runbook does not authorize a restore or enable restore on production. If any prerequisite is unsupported or unknown, do not run the rehearsal and keep the site operation blocked.

### Local run, 2026-10-04 (development machine)

Run against a local installation (`APP_ENV=e2e`, SQLite, local backup storage, both queue workers and the scheduler running) and a throw-away WordPress 7.1.2 on MySQL 8.4 served on `http://127.0.0.1:8081` with connector 0.4.1 (`WP_CAREGRID_ALLOW_INSECURE_LOCALHOST`, development only). The connector's cron hooks were triggered directly instead of waiting for WP-Cron.

| # | Result |
| --- | --- |
| 1–2 | Owner signed in; System and `system:check` showed the real state (Stripe and mail not configured, as expected locally) |
| 3 | Operator invited from the owner area; the queued invitation email used `APP_URL`; activated through the link; signed in to the console |
| 4 | Stripe was not configured for this recorded local run; customer and subscription records were created directly in the local database. A separate Stripe test-mode exercise through an HTTPS tunnel was reported on 2026-10-04, but it is not evidence for this run or for current staging readiness. |
| 5 | Website created directly (the customer form only accepts public addresses; this site was on 127.0.0.1) |
| 6–10 | Pairing code generated in the account; paired from WordPress admin; heartbeat, inventory, health and license check reported |
| 11–12 | Manual FULL backup (13 MB) uploaded, transferred by the `backups` worker, **verified** |
| 13–16 | Update of a sample plugin 1.0.0 → 1.1.0 queued by the operator; pre-update FULL backup verified first; `PLUGIN_UPDATED`; post-update health and inventory in the result; Site Health healthy |
| 17 | Manual functional check: front page and admin load, plugin active at 1.1.0 |
| 18 | Full scan completed (integrity, malware); vulnerability "unavailable" (no feed); one real finding (`CONFIG_FILE_EDITOR_ENABLED`) |
| 19 | Maintenance access opened ("Howdy, WP CareGrid (maintenance access)"); users, plugin install, theme editor, settings refused (403) |
| 20 | Activity log complete and correctly attributed (customer, operator, system), owner audit recorded the invitation and sign-in |
| 21 | Revoked by the customer; the site ended the session at its next check-in; `/wp-admin` asked for login |
| Restore | Wrong confirmation refused; safety backup verified first; full restore `RESTORED`; plugin back at 1.0.0; connector still connected |

Found and fixed during this milestone's verification (not by this run alone): customer disconnect failed on MySQL (column too short, now widened and transactional); file restores on Windows hosts refused every file (connector 0.4.1); backup transfers could be picked up twice by a second worker (own queue connection). The in-app browser pane cannot display the admin access hand-over page (it is `X-Frame-Options: DENY` on purpose); a normal browser tab is not affected.



## 2. Non-production pilot entry gates

Before any pilot operation, every applicable item must be **VERIFIED for that exact target and operation**. Keep missing items UNKNOWN or BLOCKED; do not use production to fill an evidence gap.

- [ ] Site owner and tenant are identified; written consent names the exact site, operation, scope, exclusions and time window.
- [ ] Owner authorization names the exact operator, stop authority, escalation contact, allowed operation and its side effects.
- [ ] Application revision, WordPress version, Connector version and approved package fingerprint are recorded. A version string alone does not prove package identity.
- [ ] Pairing identity, heartbeat/poll evidence and inventory collection time are current for the same site and Connector, read from an approved persisted-status view. Do not poll, reconnect or re-pair to establish status.
- [ ] Connector capabilities and queued/reserved work are known; no unapproved job can be delivered by an approved poll. If this cannot be determined without a state-changing poll, the operation is BLOCKED pending separate authorization.
- [ ] Runtime, database, filesystem/layout and capacity facts meet the operation's compatibility requirements and are fresh for the target.
- [ ] Any required backup is verified and bound to this site; manifest/checksum, required objects, age and isolated destination are evidenced. A successful upload or status label alone is insufficient.
- [ ] Recovery readiness is separately evidenced. Backup verification does not prove restoration. Without a reviewed supported host provider for the exact host, every live restore and recovery operation remains BLOCKED under #182/#189.
- [ ] Incident auto-open, automatic backup, email/webhook notification, queue routing and external integrations are known and isolated; every possible side effect is within the approved operation scope.
- [ ] Monitoring owner, observation window, alert path and manual functional-check owner are assigned; monitoring and routing have been verified without sending unauthorized test notifications.
- [ ] Free disk capacity is known for the exact operation, including temporary scan, backup and update requirements where applicable. No generic threshold should be invented if the implementation does not define one.
- [ ] Per-operation authorization, evidence retention, cleanup owner and stop criteria are written down before execution. Approval of one operation does not authorize another.

## 3. Controlled pilot (one site at a time)

This is a proposed bounded pilot sequence, not a batch authorization or standing consent. Start with one explicitly approved non-production site. A second site requires review of the first site's evidence, residual risks and a new target-specific decision.

1. Reconfirm the exact approved site, tenant, operator, consent, operation, exclusions and execution window.
2. Do not install, update, pair or re-pair a Connector unless that precise operation is separately approved and the release artifact is verified.
3. Review existing persisted health, inventory and status evidence. Do not trigger a poll, reconnect or retry to refresh it without separate operation authorization; first establish the queued-work boundary.
4. Request a backup only when separately authorized. Verify exact site binding, manifest/checksum, required objects, age, destination isolation and sufficient capacity.
5. Perform one named update only after a verified pre-update backup, fresh compatibility evidence, site-owner consent, operation-specific Owner authorization and a credible recovery path. Manually check only the approved critical pages.
6. Run only the explicitly approved scan type and scope. Record scanner versions, covered/missing/unavailable paths, findings and incident, backup, queue and notification deltas. An Integrity scan does not establish malware coverage or a malware-free site.
7. Use temporary admin access only if specifically approved; verify least privilege and revoke it at the agreed time.
8. Do not perform restore or recovery unless a reviewed, supported provider exists for the exact host and a separately approved isolated rehearsal is in place. Otherwise record BLOCKED and do not attempt it.
9. Observe only after monitoring, routing and capacity are verified, using the approved window and named operator.

Stop immediately for uncertain target or authorization, stale/unknown prerequisites, unexpected queued work, unverified backup, missing provider/recovery path, unexpected mutation or incident/backup/notification, cross-tenant disclosure, partial result, lost lease, unhealthy site, insufficient capacity or secret in logs. Do not automatically retry, clean up, restore or change configuration; preserve sanitized evidence and escalate.

## Known limitations and backlog

- Historical Integrity results are point-in-time checks of the configured file baseline. They do not prove malware-free status or complete malware coverage. Malware detection has rule and path limitations; JavaScript skimmers inside `.js` files are not scanned, and themes/premium plugins may lack authoritative checksums.
- The 2026-10-04 local smoke-test record above is historical development evidence only (Connector 0.4.1 and WordPress 7.1.2). It is not evidence of current staging or customer-site state and is not a current pilot acceptance result. Its reported scan result does not establish complete malware coverage.
- The 2026-10-24 `kiszov-szeged.hu` Integrity result in the evidence report is an Owner-reported historical observation, not current liveness, current inventory, or a malware verdict. Its missing/unavailable counts and notification delta remain limitations; do not label it a current VERIFIED state.
- Backup success or integrity verification does not prove restore capability. Restore/recovery stays fail-closed for unsupported or unknown hosts until the #189 provider architecture has separate Owner approval and a reviewed implementation supports the exact host.
- Current Connector heartbeat, polling and inventory freshness must be established from fresh target-specific evidence. Historical job completion does not prove current connectivity. Do not automatically reconnect or operate a customer site to refresh status.
- Monitoring and notification routing are not established merely because application monitoring code exists. The current release checklist records operational monitoring/alerting as not configured or not ready; require fresh evidence and accountable ownership before a pilot operation.
- `EnvironmentFacts` reports point-in-time free disk bytes and backup code performs a capacity precheck; this is not a continuous disk-space monitoring or warning capability. Add the following to the future operational backlog, with scope/ownership assigned before implementation: (1) Connector-side disk-space reporting, (2) customer-visible WordPress warning, (3) WP CareGrid dashboard warning, and (4) backup/update/restore capacity implications and safeguards. This runbook does not assign implementation ownership across M15/M16/M17 or authorize implementation.
- Not every premium plugin license can be checked; "unknown" is never reported as "expired".
- Functional verification after updates is a manual operator step today.
- Automatic remediation is off and is not safe everywhere; quarantine and repairs require their own authorization and must not be inferred from scan approval.
- Database backups need MySQL/MariaDB on the site; SQLite sites receive files-only backups.
- PHP 8.5 staging cutover remains NO-GO until separately validated and approved; CI compatibility alone is not staging approval.

