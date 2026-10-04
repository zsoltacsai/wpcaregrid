# WP CareGrid — Project Status

> Kept up to date at the end of every milestone (see CLAUDE.md §27).
> A new session should read CLAUDE.md, this file and the relevant docs, then run `git status` and `git log --oneline -12`.

Last updated: 2026-10-04

## Branch and HEAD

- Branch: `claude/ecstatic-wozniak-oemyuf` (also the main branch used for PRs)
- Last milestone commit: **Owner Admin & Content Management** (the commit after `4076684`; run `git log --oneline -3` for its hash)
- Milestone commits since `ba2d1db` are local only; nothing is pushed until a push is requested.

## Completed milestones

| Milestone | Commit | Docs |
| --- | --- | --- |
| Public homepage, pricing engine | earlier | README.md |
| Stripe Checkout MVP, customer accounts, subscription lifecycle | earlier | README.md |
| Customer onboarding, WordPress Connector | earlier | docs/CONNECTOR.md |
| Backup Engine | `7ed8ade` | docs/BACKUP_ENGINE.md |
| Secure WordPress Admin Access | `cac19b1`, `d4dcda0` | docs/ADMIN_ACCESS.md |
| Internal Operator Console | `ba2d1db` | docs/OPERATOR_CONSOLE.md |
| Unified Site Health + Security & Malware Engine | `4076684` | docs/SECURITY_ENGINE.md, MALWARE_SCANNING.md, SECURITY_FINDINGS.md, SECURITY_INCIDENTS.md |
| Owner Admin & Content Management | after `4076684` | docs/OWNER_ADMIN.md, CONTENT_MANAGEMENT.md, PRICING_ADMIN.md |

## In progress

Nothing. The next milestone has not been started.

## Test baseline (Windows dev machine, after Owner Admin)

- Server suite: 572 tests, 565 pass, 6 skipped (no S3/SFTP test servers), 1 known Windows-only failure (`BackupFlowTest::test_staging_files_are_private`, Unix file permissions).
- Owner tests (`tests/Feature/Owner`): 98, all pass. Security 91, operator 73, all pass.
- Mutation check of owner safeguards: 21 mutations, all caught (script kept outside the repo).
- WordPress connector tests were not rerun for Owner Admin (no connector changes). At `4076684`: SQLite 168 tests with 3 known Windows symlink/permission failures; MySQL 8.4 the same 3 plus `test_full_restore` (fails at the earlier commit too).
- Browser: all 17 owner pages at 320/375/390/768/1024/1280/1440 px without horizontal overflow; homepage and pricing page unchanged in layout.
- Run with `vendor/bin/phpunit -c phpunit.xml` (`php artisan test` breaks when the path contains `=`).

Linux CI should be authoritative for filesystem behaviour; it is not set up yet.

## Next milestone

Production hardening / CI / deployment (CLAUDE.md §29). Not started.

## Blockers

None in code. Production use depends on the manual configuration below.

## Pending manual configuration

- `php artisan migrate` (Owner Admin adds `users.operator_disabled_at`, `staff_invitation_tokens`, `admin_audit_events`, `content_entries`, `price_definitions`).
- First owner: `php artisan owner:user owner@… --name="…"` (prints a single-use invitation link and emails it).
- Stripe test-mode keys, webhook secret and Price IDs (`STRIPE_*`); then "Check prices in Stripe" in the owner area or `php artisan pricing:verify-stripe`.
- Backup storage (`BACKUP_STORAGE` plus S3 or SFTP settings).
- Transactional mail (`MAIL_*`); operator and owner invitations are emailed.
- Scheduler (`php artisan schedule:run` every minute; the system page shows its heartbeat) and a queue worker.
- Vulnerability feed (`SECURITY_VULNERABILITY_*`), or tell customers vulnerability checks are not included.
- Hungarian content: written and published in the owner area (nothing is auto-translated); Hungarian interface translation files (`lang/hu`) do not exist yet.

## Important decisions

- Conservative defaults stay: `SECURITY_REMEDIATION_ENABLED=false`, `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`; checklist in docs/SECURITY_INCIDENTS.md.
- Unified Site Health is the only health calculation; unknown is never healthy. The owner dashboard uses it too.
- Customer, operator and owner areas use separate guards; one login never opens another area. Owners are created only from the CLI.
- Owner website actions are limited to backup and security scan, through the same services as operators; restores, updates, admin access and remediation stay operator-only.
- Operators are invited (they set their own password); permissions are explicit; disabling keeps permissions but blocks access at once.
- Content: one record per section and locale (`content_entries`), typed by `App\Content\Schema\ContentSchema`; draft/published; public fallback is whole sections in English, never mixed per field.
- Prices: config/pricing.php is the baseline; owner-published prices override it for future checkouts only; earlier Stripe Price IDs stay recognised for existing subscriptions.
