# PROBONO (internal plan)

PROBONO is a real plan that is **never sold**. The owner assigns it to a customer (for example a charity or a friendly pilot); nobody can buy, select, see or guess it.

## Architecture

- `App\Billing\InternalPlan` (enum, one case today) is **not** a `PlanTier`. `PlanTier` is what the catalogue, the public pricing page, the checkout selection and Stripe Prices are built from; PROBONO is not in it, so it cannot reach any of them by construction.
- An assigned plan is an ordinary `subscriptions` row (there is no second subscription model) with `internal_plan = 'probono'`, **no** Stripe subscription, **no** `plan`, price, interval, market or currency (those columns are nullable now). `Subscription::isInternal()` tells them apart. Status is `active` (or `pending` until a future start date); it follows its own optional start and end dates (`internal_starts_at`, `internal_ends_at`), also between runs of the hourly `internal-plans:sync` command (`grantsAccess()` and `Customer::activeSubscriptions()` look at the dates).
- A null `plan` already meant "not included" for every plan-driven rule, so an internal plan **fails closed** everywhere. On top of that each rule asks the plan explicitly:

## Exact feature boundary

| Included | Not included |
| --- | --- |
| Plugin, theme and WordPress core updates through the existing update job (`RequestUpdate`) | **Backups of any kind**: scheduled, manual, before an update, retention, restore |
| Site health, inventory and licence checks reported by the connector | Scheduled security scans (plan-driven); an operator can still run a scan by hand |
| Customer portal, onboarding, one website per plan | Temporary WordPress admin access for the customer (maintenance or full) |
| Operator and owner tools for the site | Anything described as Business or Secure features |

Support is as agreed by the owner; it is not a code feature. Functional verification is operator work, as for every plan; nothing is claimed that did not run.

## No backups, proved in several places

`InternalPlan::includesBackups()` is `false` and a missing price never means "allowed":

- `BackupPolicy::for()` returns `eligible = false`, refusal `BACKUPS_NOT_INCLUDED`, no frequency, no pre-update requirement, no manual backup, no restore, no retention. It is decided before any config is read, so no `backup.plans` setting can switch it on. That one decision stops `BackupService::request` (manual, scheduled `backup:schedule`, pre-update), restore, retention and remediation (which needs a verified FULL backup).
- `JobQueue::refusal()` also refuses `BACKUP_*` and `RESTORE` jobs for such a website, at queue time **and again at delivery**: a backup job queued while the website was still on a public plan is cancelled instead of released to the connector.
- Updates run **without** a backup first (`preUpdateRequired = false`); the customer page says so in plain words.

## Owner workflow

Owner → Customers → customer → **Internal plan** panel (ability `owner.plans.assign`, owner-only; operators and customers cannot reach it):

1. Choose the product (WordPress or WooCommerce), optional start and end, an internal note, and confirm. One spare PROBONO plan (without a website) per customer at a time; this also stops a double submit.
2. The plan is created for **that** customer only (the customer is in the URL, the plan is fixed on the server; a plan, price, Stripe ID or customer ID in the request is ignored). The customer then adds a website as with any plan.
3. Change dates and note, or **End PROBONO now** (status `canceled`, service operations stop).

Audit: `probono.assigned`, `probono.changed` (field names), `probono.ended`, `probono.started` / `probono.expired` (the scheduled sync). The internal note is never logged and never shown to the customer.

## Customer portal

The subscription and dashboard pages show "PROBONO", status, since / until ("No end date"), and an explicit list of what is and is not included (English and Hungarian). No price, no billing interval, no next billing date.

## Not public

Verified by tests and in a real browser: not on the homepage or pricing page (English or Hungarian), not in selectors, not in config, `?plan=probono` redirects to pricing, a forged `plan=probono` in the checkout form is refused and records nothing.

## Tests

`ProbonoPlanTest` (22). Real browser: `owner-commercial.spec.ts`.
