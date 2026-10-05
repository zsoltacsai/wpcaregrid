# Owner administration

The owner area (`/owner`) is where the business owner of WP CareGrid manages customers, websites, operators, pricing, public content, SEO and sees system status. It is separate from the customer portal (`/account`) and the operator console (`/operator`, see OPERATOR_CONSOLE.md). Content and pricing are covered in CONTENT_MANAGEMENT.md and PRICING_ADMIN.md.

## Owner role and sign-in

- An owner is a `users` row with `role = "owner"`, a verified email and **no** customer record. Owners are not operators (they hold no operator permissions) and not customers.
- Own guard `owner` (config/auth.php) and own login page `/owner/login`. Signing in to the customer area or the operator console never signs anyone in here, and the reverse. The session is shared, so signing out of one area ends the whole session.
- `EnsureOwner` (middleware `owner`) checks the role, the verified email and the absence of a customer on every request, and signs out anyone who does not pass. Responses carry `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`.
- Every sign-in is audited (`owner.signed_in`).

### Creating owners

Owners are created only on the server, never from a web page:

```bash
php artisan owner:user owner@example.com --name="Owner"
```

This creates the login with an unusable random password and prints a single-use invitation link (also emailed); the owner sets their own password at `/staff/activate/{token}` and then signs in at `/owner/login`. Running the command again for an owner who has not activated yet issues a new link (the old one stops working). `--remove` takes the owner role away. The command refuses emails that belong to a customer or operator login.

## Authorization

Every owner route has `auth:owner`, `owner` and exactly one ability `can:owner.*` (`App\Owner\OwnerAbility`); a test fails if a route is missing one. Changes are `PUT`/`POST` with CSRF (web middleware group) and rate limits.

| Ability | Routes |
| --- | --- |
| `owner.dashboard.view` | `/owner` |
| `owner.customers.view` / `owner.customers.manage` | customer list and detail / editing customer details |
| `owner.websites.view` / `owner.websites.operate` | website list and detail / backup and security scan |
| `owner.operators.manage` | operators, invitations, permissions, disable/enable |
| `owner.pricing.manage` | prices, plan wording links, Stripe price check |
| `owner.coupons.manage` | coupons: create, edit rules, activate / deactivate, delete if unused ([COUPONS.md](COUPONS.md)) |
| `owner.plans.assign` | assign, change and end PROBONO, the internal plan ([PROBONO.md](PROBONO.md)) |
| `owner.offers.manage` | custom recovery offers: create, send, edit, cancel, complete ([RECOVERY_OFFERS.md](RECOVERY_OFFERS.md)) |
| `owner.content.manage` | homepage, navigation, footer, plan wording, draft preview |
| `owner.seo.manage` | SEO metadata |
| `owner.system.view` | system status |

All abilities pass only for a verified owner without a customer record; customers and operators never pass, whatever permissions they hold. There is deliberately no ability to read secrets, impersonate customers, or restore, update, open admin access or remediate websites.

Rate limiters: `owner-sensitive` (20/min, 200/hour per owner) for customer, operator, price and content changes; `owner-external` (5/min) for actions that reach Stripe or a website; `staff-invitation` (5/min, 30/hour) for invitations; `owner-coupons` (10/min, 60/hour) for the coupon actions that reach Stripe.

Commercial operations (coupons, PROBONO, recovery offers) are owner-only: operators never receive these abilities, whatever operator permissions they hold, and customers cannot reach any of the routes.

## Dashboard

`App\Owner\BusinessMetrics`, counted from stored data only; an empty system shows zeros.

- **MRR**: subscriptions with status `active` (trialing ones pay nothing yet), local amount per month (annual ÷ 12), one total per currency; currencies are never added together.
- **Active customers / subscriptions / websites**: a subscription that grants access (active or trialing), on an active customer.
- **Healthy / attention / critical websites**: Unified Site Health (`App\SiteHealth\SiteHealth`) of active websites; no second health calculation. Websites with nothing known are counted separately as unknown.
- **Failed backups**: websites whose latest backup failed (as in the operator console). **Open security findings**: the security engine's count. **Expired licenses**: sum of detected expired licenses in inventories.

## Customers

List with server-side pagination (25), search by email, company or website URL, filters by subscription state (or none), billing country, product type and plan. Detail: customer and billing details, subscriptions (plan, interval, amount, status, next charge), websites with Site Health, recent website activity and owner changes.

The owner may change first and last name, customer type, company, billing country, tax ID and billing address. Not editable (and not shown, where secret): login email, password, payment methods and card data (in Stripe), Stripe data other than the customer reference, subscriptions, connector and storage credentials. Unknown form fields are ignored. Each change is audited as `customer.updated` with the **names** of the changed fields, not their values. Changing the billing country affects future checkouts only. Local changes are not pushed to Stripe.

The Stripe customer ID is shown on the detail page as a reference for finding the customer in the Stripe dashboard; it is not a secret.

The detail page also holds the **Internal plan** panel (assign PROBONO, change its dates, end it; [PROBONO.md](PROBONO.md)) and a **New offer** button for a custom recovery offer ([RECOVERY_OFFERS.md](RECOVERY_OFFERS.md)). Internal plans show as "PROBONO" in the customer and website lists.

## Coupons, internal plan and recovery offers

The **Coupons** and **Recovery offers** menu items open the coupon management and the offers list. Descriptions: [COUPONS.md](COUPONS.md), [PROBONO.md](PROBONO.md), [RECOVERY_OFFERS.md](RECOVERY_OFFERS.md). PROBONO has no menu item of its own and is nowhere public.

## Websites

Every website with URL, customer, type, plan, Site Health, connector, latest backup, security state, pending updates and last seen; search, filters and sorting reuse the operator console's `WebsiteDirectory`. The detail page shows all Site Health dimensions with their reason codes.

The owner can **run a backup** and **run a security scan**. Both go through `BackupService` and `SecurityScanService` with the actor `owner:{id}`, so plan, connector, capability, cooldown and in-progress checks apply exactly as for operators, and the website audit log records `actor_type = owner`. Restores, updates, admin access and remediation are not available to the owner: they stay with operators and their explicit permissions.

## Operators

`App\Owner\OperatorAccounts`.

- **Invite**: name, email and the permissions ticked (nothing is ticked by default; there is no "grant all"). An operator login is created with an unusable random password and `email_verified_at = null`; an email with a single-use link (72 h, `staff_invitations` broker, own token table) lets the operator set their own password. Until then the operator console refuses the login. An email that already has any login is refused.
- **Permissions**: the 15 operator permissions as checkboxes, grouped, with their codes. Newly granting `backups.restore`, `security.remediate` or `admin_access.full` needs an explicit confirmation. `admin_access.full` still has no effect while `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false` (the default); the page says so.
- **Disable / enable**: sets `users.operator_disabled_at`. A disabled operator holds no permissions, is signed out of the console on the next request, cannot sign in and cannot accept an invitation; permissions are kept for re-enabling.
- **Resend invitation**: a new link; the previous one stops working.

Audit: `operator.invited` (with permissions), `operator.permissions_changed` (granted and revoked), `operator.disabled`, `operator.enabled`, `operator.invitation_resent`.

`php artisan operator:user` keeps working as before (it now also refuses owner logins).

## System status

`App\Owner\SystemStatus`, one card per part, each `healthy`, `attention`, `critical` or `unknown`:

| Part | What is checked |
| --- | --- |
| Application | environment, debug (critical in production), APP_KEY set |
| Database | reachable, pending migrations |
| Queue | driver, failed jobs, jobs waiting more than 15 minutes (database driver) |
| Scheduler | heartbeat written every minute by the scheduler (`system:scheduler-heartbeat`); none yet = unknown, older than 5 minutes = critical |
| Connector API | websites reporting / not reporting, latest connector version |
| Stripe | secret, publishable key and webhook secret set (never shown), test or live mode, catalogue valid, prices on sale without a Price ID, last Stripe price check |
| Backup storage | provider selected and fully configured, a backup verified in the last 7 days |
| Security scanner | scanner version, vulnerability data configured, last scan, remediation on/off |
| Mail | driver (log/array is not sending), sender address |

The page reads configuration and stored data only; it does not contact Stripe or storage. Secret values are read only to tell whether they are set; a test asserts that none appears on the page.

## Audit log

`admin_audit_events` (`App\Owner\AdminAudit`): owner user, actor type, event, subject type and key (content key and locale, price key, customer UUID, operator ID), data (identifiers, field names, codes), IP address and time. Never passwords, tokens, secrets or card data. Events: `owner.signed_in`, `content.*`, `price.*`, `customer.updated`, `operator.*`, `stripe.verified`. Website operations started by an owner are recorded in the website audit log (`website_events`) by the services.

The dashboard shows the latest owner changes; customer and operator pages show their own.

## Security considerations

- Separate guard, role check on every request, one explicit ability per route, CSRF, rate limits, audit.
- Identifiers in URLs: customers and websites by UUID, operators by ID bound to operator logins only (a customer or owner ID is a 404), prices by validated key, content by schema key and configured locale.
- No secrets in pages: password hashes, remember tokens, connector credentials, storage credentials and Stripe keys are never rendered; tests check this.
- No customer impersonation, no Stripe or storage credential editing, no server configuration, no bulk operations.
