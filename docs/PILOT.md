# Pilot and end-to-end smoke test

How to take WP CareGrid from a configured server to the first real customer sites: a full end-to-end smoke test on non-production sites, then a controlled pilot with 1–3 websites. Server setup: [DEPLOYMENT.md](DEPLOYMENT.md). Release gates: [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

Nothing here is automated against real customers. No destructive remediation runs during the pilot (`SECURITY_REMEDIATION_ENABLED=false`).

## 1. End-to-end smoke test

On the staging (or production) installation, with Stripe in **test mode** and **non-production WordPress sites** you control (a staging copy or a throw-away site). Record the result of each step.

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
| 9 | Heartbeat | wait for WP-Cron, or "Check connection now" | Last seen updates; connector reporting in |
| 10 | Inventory | automatic after pairing | Plugins, themes, versions, updates in the account and console |
| 11 | Manual backup | Customer → Backups → Backup now | Queued, then running on the site |
| 12 | Verify backup | wait for the `backups` worker | Status **verified**; activity: requested → started → uploaded → verified |
| 13 | Queue a plugin update | Operator → website → Update (a plugin with an update) | "Runs after the required pre-update backup has been verified" |
| 14 | Pre-update backup | automatic | A `pre_update` full backup verified **before** the update starts |
| 15 | Update | automatic | `PLUGIN_UPDATED`; new version reported |
| 16 | Health check | part of the update result | Health and inventory refreshed; Site Health updated |
| 17 | Functional check | **manual**: front page, login, contact form / checkout | Site works; record who checked (the platform does not yet automate functional checks) |
| 18 | Security scan | Operator → Security → Full scan | Completed; integrity and malware covered; vulnerability "not configured" unless a feed is set |
| 19 | Admin access | Customer → Open Admin | WordPress admin opens as "WP CareGrid (maintenance access)"; user management, plugin installation, file editor and settings refused |
| 20 | Audit review | Operator → Activity; Owner → Overview / customer page | Every step above recorded with the right actor (customer, operator, owner, system) |
| 21 | Revoke access | Customer → website → revoke | The site ends the session at its next check-in; `/wp-admin` asks for login again |

Afterwards: `php tests/smoke/http-smoke.php https://<APP_URL>` (read-only HTTP checks) and the restore rehearsal below.

### Restore rehearsal (staging only)

On a staging site only, with `BACKUP_RESTORE_ENABLED=true` on a **staging** installation: Operator → website → backup → Restore, scope "Files and database", type the domain, confirm. Expected: a wrong confirmation is refused (`CONFIRMATION_MISMATCH`); a safety backup of the current site is made and verified **first**; then the restore runs, the site comes back in the backup's state and passes the post-restore checks; the activity log shows restore.requested → backup (pre_restore) verified → restore.started → restore.completed. Then restore the safety backup to undo it. Only after this rehearsal (and its sign-off in RELEASE_CHECKLIST.md) may live restore be enabled in production.

### Local run, 2026-10-04 (development machine)

Run against a local installation (`APP_ENV=e2e`, SQLite, local backup storage, both queue workers and the scheduler running) and a throw-away WordPress 7.1.2 on MySQL 8.4 served on `http://127.0.0.1:8081` with connector 0.4.1 (`WP_CAREGRID_ALLOW_INSECURE_LOCALHOST`, development only). The connector's cron hooks were triggered directly instead of waiting for WP-Cron.

| # | Result |
| --- | --- |
| 1–2 | Owner signed in; System and `system:check` showed the real state (Stripe and mail not configured, as expected locally) |
| 3 | Operator invited from the owner area; the queued invitation email used `APP_URL`; activated through the link; signed in to the console |
| 4 | **Not run:** Stripe is not configured locally. Customer and active subscription were created directly in the database. Must be run with Stripe test mode on staging. |
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

## 2. First customer safety gates

Before the first real customer site, every item must be confirmed **on the production installation** (not locally):

- [ ] Backup storage works: a backup of a test site is **verified** in the production storage (S3 or SFTP), and the object is visible there under `{website_uuid}/{backup_uuid}/`
- [ ] Mail works: an invitation and a password reset arrive, links use the production domain over HTTPS
- [ ] Stripe test flow works end to end (step 4), then the switch to live keys is a separate, deliberate step
- [ ] Connector pairing works over HTTPS (no `ALLOW_INSECURE_LOCALHOST` anywhere)
- [ ] Backup and backup verification work (steps 11–12)
- [ ] Update protection works: an update waits for the verified pre-update backup (steps 13–14)
- [ ] Site Health shows the five dimensions and is not "healthy" for unknown parts
- [ ] Security scan works (step 18)
- [ ] Admin access works and can be revoked (steps 19, 21)
- [ ] Audit logs show every action with the right actor (step 20)
- [ ] Authorization isolation: a second test customer cannot see the first one's website, backups, findings or billing (CI covers this; spot-check once by hand)
- [ ] `php artisan system:check` shows no ERROR; the safety switches are as intended

## 3. Controlled pilot (1–3 websites)

Not a batch. One site at a time, each with the customer's knowledge.

1. Choose 1–3 sites whose owners agree to a pilot; prefer one WordPress and one WooCommerce site; note hosting (Apache/nginx, PHP, MySQL/MariaDB).
2. Install the connector, set `WP_CAREGRID_QUARANTINE_DIR` outside the web root where the host allows it, pair.
3. Inventory and initial health check; review every finding with the customer before acting on it.
4. First backup; check it is verified and has a plausible size.
5. One plugin update with the pre-update backup; manual functional check of the important pages (contact form, checkout on WooCommerce).
6. Security scan; review medium/high findings for false positives (feed improvements back into the rule set).
7. Admin access once, revoked afterwards.
8. Restore rehearsal: **only** on a staging copy of the pilot site, never on the live pilot site during the pilot.
9. Watch for a week: scheduled backups per plan, connector reporting in, Site Health, failed jobs.

Stop criteria: an unverified backup, an update that did not wait for its backup, a scan that changes anything on a site, a cross-customer visibility issue, or any secret in a log. Fix before continuing.

## Known limitations to keep in mind

- Malware detection is rule-based and not perfect; JavaScript skimmers inside `.js` files are not scanned; themes and premium plugins have no authoritative checksums.
- Not every premium plugin license can be checked; "unknown" is never reported as "expired".
- Functional verification after updates is a manual operator step today.
- Automatic remediation is off and is not safe everywhere; quarantine and repairs are operator-initiated, one finding at a time, behind a verified backup.
- A restore can end partially if a site fails midway; the safety backup is the way back.
- International taxes are not calculated; only the Hungarian market has prices.
- Database backups need MySQL/MariaDB on the site (SQLite sites get files-only backups).
