# Internal Operator Console

The interface the WP CareGrid service team uses to look after customer websites: health, connectors, updates, backups, restores, WordPress admin access and the audit log. It is internal. Customers never reach it, and it never reaches into the customer area.

Scope: operator authentication, explicit permissions, dashboard, website list and detail, single-website actions (backup, restore, update, admin access, re-pair, disconnect), activity log, and a placeholder for security findings. Not included: malware scanning or removal, a vulnerability engine, automated cleanup, customer impersonation, bulk updates or backups, editing storage credentials, arbitrary code execution.

The console adds no business logic of its own. Every change goes through the service the customer area already uses:

| Action | Goes through | Never |
| --- | --- | --- |
| Backup | `BackupService::request()` → `BACKUP_*` connector job | a second backup mechanism |
| Restore | `RestoreService::request()` → safety backup → `RESTORE` job | skipping verification, confirmation or the safety backup |
| Update | `RequestUpdate` → `JobQueue::queue()` → `PreUpdateBackups` | calling WordPress from the controller |
| Admin access | `AdminAccessService::request()` / `revoke()` | creating sessions in the controller |
| Disconnect | `DisconnectWebsite` → `Connector::revoke()` | touching connector rows directly |
| Re-pair | `PairingCodes::issue()` | showing or storing a credential |

## Where things live

| What | Where |
| --- | --- |
| Routes | `routes/operator.php` (loaded with the `web` group in `bootstrap/app.php`) |
| Controllers | `app/Http/Controllers/Operator/` |
| Guard, role check | `config/auth.php` (`operator` guard), `app/Http/Middleware/EnsureOperator.php` (alias `operator`) |
| Permissions | `app/Operator/Permission.php`, gates registered in `AppServiceProvider` |
| Read models | `app/Operator/WebsiteDirectory.php` (search, filter, sort), `Overview.php` (dashboard), `ConnectorState.php`, `BackupState.php`, `Refusal.php` (wording) |
| Update requests | `app/Connector/RequestUpdate.php` |
| Disconnect | `app/Connector/DisconnectWebsite.php` (shared with the customer area) |
| Security placeholder | `app/Security/` (`SecurityScanner`, `NotConfiguredScanner`, `SecuritySummary`, `SecurityState`, `Severity`) |
| Audit actor | `app/Websites/Actor.php`, `app/Websites/AuditLog.php` |
| Managing operators | `php artisan operator:user` (`app/Console/Commands/ManageOperator.php`) |
| Views, wording | `resources/views/operator/`, `resources/views/components/operator/`, `resources/views/components/layouts/operator.blade.php`, `lang/en/operator.php` |
| Tests | `tests/Feature/Operator/` |

## Operator authentication

- **Separate guard.** Operators sign in at `/operator/login` on the `operator` session guard. Being signed in to the customer area (`web` guard) never signs anyone in to the console, and the other way round. `/operator/*` sends guests to the operator login, never the customer login.
- **Same user table, a role.** An operator is a `users` row with `role = operator`, a verified email and **no customer**. No separate account table. `role` and `operator_permissions` are not mass-assignable. A customer login can never be an operator: login, `EnsureOperator` and `operator:user` all refuse it.
- **Login:** rate limited (`throttle:login`: 5/min per email and IP, 20/min per IP). The session ID is regenerated on sign-in (session fixation). Wrong password, customer login, non-operator and unverified accounts all get the same message. An "intended" URL is followed only if it is inside the console.
- **Every console request** passes `auth:operator` and `operator` (`EnsureOperator`: role, verified, no customer; otherwise it signs the session out). Responses carry `Cache-Control: no-store` and `X-Robots-Tag: noindex`.
- Operators cannot use the customer area. `EnsureCustomerAccount` signs out any login without an active customer, and `WebsitePolicy` only grants a website to its own customer.

### Managing operators

There is deliberately no UI for granting access:

```sh
php artisan operator:user ops@wpcaregrid.com --name="Ops" --standard   # every permission except admin_access.full
php artisan operator:user ops@wpcaregrid.com --grant=admin_access.full
php artisan operator:user ops@wpcaregrid.com --revoke=backups.restore
php artisan operator:user ops@wpcaregrid.com --disable                 # removes the role and all permissions
```

A new operator gets a random initial password, printed once and stored only as a hash.

## Permissions

Being an operator grants nothing by itself: an operator with no permissions can sign in and sees "Not permitted" everywhere. Permissions are explicit and stored per user in `users.operator_permissions`. Each one is a gate named `operator.{permission}`.

| Permission | Allows |
| --- | --- |
| `websites.view` | Dashboard, website list, website detail (customer, plan, connector, WordPress, plugins, themes, WooCommerce) |
| `websites.manage` | Re-pair (new pairing code), disconnect the connector |
| `backups.view` | Backup history (per website and `/operator/backups`), restores, retention |
| `backups.run` | Request a backup |
| `backups.restore` | Restore a verified backup |
| `updates.view` | Update history and last result |
| `updates.run` | Queue a plugin, theme or core update |
| `security.view` | Security section and `/operator/security` |
| `security.scan` | Reserved for the Security/Malware milestone (no scanner yet) |
| `admin_access.request` | Open WordPress admin with **maintenance** access, revoke any open access |
| `admin_access.full` | Request **temporary full administrator** access (see below) |
| `licenses.view` | Plugin license states and details |
| `audit.view` | Activity (`/operator/activity`, dashboard and website activity) |

Not an enterprise RBAC: no roles-of-roles, no per-customer scoping. Operators see every website, which is the job.

### Full WordPress administrator access

Full admin is a separate capability from maintenance access and is checked **inside `AdminAccessService`**, not only on the route:

1. the route needs `can:operator.admin_access.full`;
2. the service refuses operator full admin unless `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=true` (**default `false`**, `FULL_ADMIN_REQUIRES_CUSTOMER`), so full admin stays customer-only until the business enables it;
3. with it enabled, the service still requires `admin_access.full` on the requesting operator, the plan to allow the level, and the explicit confirmation (`CONFIRMATION_REQUIRED`).

Maintenance access needs `admin_access.request`, checked by the route and again by the service. A request without an identified operator, or from a customer login claiming to be an operator, is refused with `NOT_AUTHORIZED`.

## Routes

| Method | URI | Name | Permission | Limit |
| --- | --- | --- | --- | --- |
| GET/POST | `/operator/login` | `operator.login(.store)` | guest | `login` |
| POST | `/operator/logout` | `operator.logout` | signed in | – |
| GET | `/operator` | `operator.dashboard` | `websites.view` | – |
| GET | `/operator/websites` | `operator.websites.index` | `websites.view` | – |
| GET | `/operator/websites/{uuid}` | `operator.websites.show` | `websites.view` | – |
| POST | `/operator/websites/{uuid}/pairing-code` | `….pairing-code` | `websites.manage` | `operator-connector` |
| POST | `/operator/websites/{uuid}/disconnect` | `….disconnect` | `websites.manage` | `operator-connector` |
| POST | `/operator/websites/{uuid}/updates` | `….updates.store` | `updates.run` | `operator-update` |
| POST | `/operator/websites/{uuid}/backups` | `….backups.store` | `backups.run` | `operator-backup` |
| GET/POST | `/operator/websites/{uuid}/backups/{uuid}/restore` | `….backups.restore(.store)` | `backups.restore` | `operator-restore` |
| POST | `/operator/websites/{uuid}/admin-access` | `….admin-access.maintenance` | `admin_access.request` | `admin-access` |
| GET/POST | `/operator/websites/{uuid}/admin-access/full-admin` | `….admin-access.full-admin(.store)` | `admin_access.full` | `admin-access`, `admin-access-full` |
| POST | `/operator/websites/{uuid}/admin-access/{uuid}/revoke` | `….admin-access.revoke` | `admin_access.request` | `operator-action` |
| GET | `/operator/backups` | `operator.backups.index` | `backups.view` | – |
| GET | `/operator/security` | `operator.security.index` | `security.view` | – |
| GET | `/operator/activity` | `operator.activity.index` | `audit.view` | – |

Every model is bound by UUID. Nested models use scoped bindings (a backup or access request of another website is a 404). A test fails if any operator route is missing `auth:operator`, `operator` or exactly one `can:operator.*`, or if a POST route has no rate limit.

## Pages

**Dashboard** (`/operator`): total websites, healthy / attention / critical (connector-reported health), backups failed (websites whose latest backup failed or is corrupt), security findings (from the scanner; 0 without one), pending updates (plugins + themes + core, from inventories), expired licenses (reported by the plugins; unknown is never counted), connectors offline (stale or disconnected). Every figure is a database count; with no data they are all 0. Below: websites in attention or critical state and recent activity.

**Websites** (`/operator/websites`): columns Website, Customer, Type, Status, Connector, Backup, Security, Updates, Last seen. Filters: status, type, backup state, security state, connector state. Search: website URL, customer email, company (case-insensitive, wildcards escaped; nothing else is searchable: not UUIDs, Stripe IDs, tax IDs or credential hints). Sort: most urgent (default), website, customer, last seen, pending updates. 25 per page. Everything is filtered, sorted and paginated in the database. Pending updates and expired licenses are stored counts on `website_inventories` (`updates_pending`, `licenses_expired`), kept up to date whenever an inventory or license check arrives. Phones get a card list, tablets and up a table.

**Website detail** (`/operator/websites/{uuid}`), sections:

- **Overview:** customer, company, email, country, plan and subscription status, website type and status, connector version and state, last heartbeat, WordPress, PHP, WooCommerce, last verified backup, pending updates, security state, health findings.
- **Connector:** Connected / Stale / Disconnected / Revoked / Not connected, `last_seen_at`, `last_successful_poll_at`, version, capabilities, "Connector update required" when older than `CONNECTOR_LATEST_VERSION`. Re-pair and Disconnect (with confirmation) with `websites.manage`. The credential is never shown; only its hash exists.
- **WordPress, Plugins, Themes:** installed and available versions, one Update button per item the site reports as updatable (`updates.run`), update history and last result (`updates.view`), licenses with "View details" (`licenses.view`).
- **WooCommerce:** version and state from the inventory.
- **Backups** (`backups.view`): schedule, retention, storage provider name, history with verification time, errors, expiry; "Request backup" (`backups.run`); "Restore" (`backups.restore`).
- **Security:** see [Future Security/Malware integration](#future-securitymalware-integration).
- **Admin access:** "No active access", "Maintenance access active · expires in …", or "Temporary full admin active · expires in …", with who requested it. Open Admin, Revoke Access and Request full admin, each only with its permission. When Open Admin is not possible the reason is shown (site offline, connector outdated…).
- **Activity** (`audit.view`): this website's audit log.

**Backups** (`/operator/backups`): every backup, filterable by status and reason, paginated.

**Activity** (`/operator/activity`): the audit log across websites: time, website, actor (customer / operator / system, with the operator's email), event, result. Filter by event group, actor type and website URL.

## Available actions and their safety model

Actions work on one explicitly chosen website. There is no "Update all" or "Backup all".

- **Backup:** `BackupService::request(…, BackupReason::Manual, 'operator:{id}')`. Plan, subscription, storage, connector capability, "already in progress" and cooldown rules apply exactly as for customers.
- **Update:** `RequestUpdate` accepts only a plugin basename, theme stylesheet or core that **the site's own inventory reports as updatable**, refuses a second update for the same target while one is queued, waiting or running (`UPDATE_IN_PROGRESS`), and queues through `JobQueue::queue()`. Authorisation, capability checks and the plan's pre-update backup (`PreUpdateBackups`: the update waits for a verified backup, or is blocked) are unchanged. Core updates use the `minor` policy unless the site offers a new major release, which the service policy refuses unless `CONNECTOR_ALLOW_MAJOR_CORE_UPDATES` is set. The site installs the update at its next poll.
- **Restore:** offered only for **verified backups of this website while its connector is reporting in** and the operator has `backups.restore`. The form requires the scope, the website address typed exactly, and "I understand". `RestoreService` checks ownership, verification, expiry, plan, capability and connector presence again, makes and verifies a **safety backup** first, and only then queues `RESTORE`. The connector runs its post-restore health checks. Nothing about this is weakened for operators.
- **Admin access:** as in [ADMIN_ACCESS.md](ADMIN_ACCESS.md). The one-time token goes to the operator's browser once, in an auto-submitting POST form (`no-store`, `no-referrer`, `DENY` framing); the site's connector claims it. Operator requests are recorded with `actor_type = operator` and `requested_by_user_id`.
- **Revoke admin access:** `AdminAccessService::revoke()` with `end_reason = revoked_by_operator` and `revoked_by_user_id`.
- **Disconnect:** requires a confirmation checkbox. Revokes the connector credential (`disconnected_by_operator`), invalidates unused pairing codes, cancels queued jobs and ends admin access.
- **Re-pair:** issues a one-time pairing code, shown once through the session flash. The current connector keeps working until the code is used on the site.

### Errors

A refusal is shown as its kind, a plain sentence and the code, for example "Site offline: The website's connector is not connected and reporting in. `NOT_CONNECTED`". Kinds: *Not permitted* (permission or plan), *Site offline*, *Connector outdated*, *Backup problem*, *Already in progress*, *Check the request*, *Unknown state*. Job and backup failures appear in the histories with their result codes. A missing permission is a branded 403 page, never a stack trace; the refusal is logged server-side with the operator and route.

### Rate limits

On top of the services' own limits (backup cooldown, admin access per website per hour, and so on):

| Limiter | Limit |
| --- | --- |
| `operator-backup` | 6/min per operator, 10/hour per website |
| `operator-update` | 10/min per operator, 30/hour per website |
| `operator-restore` | 3/min per operator, 5/hour per website |
| `operator-connector` (re-pair, disconnect) | 5/min per operator, 10/hour per website |
| `operator-action` (revoke) | 30/min per operator |
| `admin-access`, `admin-access-full` | shared with the customer area: 5/min per user, 20/hour per website; full admin 5/hour per user |

## Authorization model

```
request ─▶ web (session, CSRF) ─▶ auth:operator ─▶ operator (role, verified, no customer)
        ─▶ can:operator.<permission> ─▶ throttle ─▶ scoped UUID binding
        ─▶ controller ─▶ service (BackupService / RestoreService / JobQueue / AdminAccessService)
                          └─ business rules and, for admin access, the permission again
```

- Customer routes stay on `auth` + `customer` + `WebsitePolicy`; operators pass none of them.
- The route gate is the first check; the services remain authoritative for everything they already decide (subscription, plan, capability, presence, verification, confirmation).
- CSRF protection is the standard `web` group middleware, covered by a test that enforces it as in production.

## Secrets

Never rendered in the console, and covered by a test over every page: connector credentials and their hash or hint, admin access tokens and their hash, Stripe IDs and keys, tax IDs, passwords and password hashes, storage credentials, storage paths. The audit log stores codes and identifiers only (see [ADMIN_ACCESS.md](ADMIN_ACCESS.md) and [BACKUP_ENGINE.md](BACKUP_ENGINE.md)).

## Audit logging

The existing audit log (`website_events`, written only through `App\Websites\AuditLog`) is used; nothing is logged twice.

**Actor model.** `website_events` now stores who acted as data: `actor_type` (`customer` | `operator` | `system`) and `actor_user_id`, next to the existing `actor` label (`operator:7`). Existing rows are backfilled from their label by the migration. A label naming a user that no longer exists keeps its type but no user ID.

Events added in this milestone, recorded where the action already happens:

| Event | Recorded by |
| --- | --- |
| `update.requested` | `JobQueue::queue()` for update jobs (console and `connector:queue-job`) |
| `update.started` | `JobLifecycle::delivered()` (first delivery only) |
| `update.completed` / `update.failed` | `JobLifecycle::finished()`; blocked updates stay `update.blocked` |
| `connector.paired` | `PairConnector` |
| `connector.disconnected` | `Connector::revoke()` (every disconnect path), with the reason and actor |
| `license.expired` | `InventoryIngest`, once, when a plugin itself starts reporting its license as expired |

Existing events (`backup.*`, `restore.*`, `admin_access.*`) now carry the operator as actor when an operator acted. The customer's "Backup activity" panel shows backup, restore and update events only.

## Future Security/Malware integration

The console reads security data only through `App\Security\SecurityScanner`:

```php
interface SecurityScanner
{
    public function isConfigured(): bool;
    public function summaries(iterable $websites): array;   // website id => SecuritySummary (one query per page)
    public function openFindings(): int;                    // dashboard figure
    public function whereState(Builder $websites, SecurityState $state): void; // list filter, in SQL
}
```

`SecuritySummary` holds a `SecurityState` (`not_configured`, `not_scanned`, `no_issues`, `findings`), a findings count, the highest `Severity` (`low`…`critical`) and the last scan time. Today `NotConfiguredScanner` is bound in `AppServiceProvider`. Every website shows "No security scanner configured yet", the dashboard shows 0 findings, and nothing is invented.

The Security/Malware milestone binds its own implementation (backed by its findings table) instead. The dashboard metric, list column and filter, website Security section and `/operator/security` then show real data without changes (a test proves this with a fake scanner). `security.scan` is reserved for starting scans; the `security.*` audit events and scan jobs belong to that milestone.

## Configuration

```
ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false   # operators with admin_access.full may request full admin only when true
```

Deploying: run `php artisan migrate` (adds `users.role`, `users.operator_permissions`, `website_events.actor_type`, `website_events.actor_user_id`, `website_inventories.updates_pending`, `website_inventories.licenses_expired`, and backfills them), then create operators with `php artisan operator:user`.
