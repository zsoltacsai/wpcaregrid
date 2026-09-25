# Secure WordPress admin access

An authorised person opens a customer's WordPress admin from WP CareGrid **without anyone knowing, storing or sending a WordPress password**. Access is temporary, revocable, tied to one website and one customer, single-use per link and fully audited.

## Architecture

```
Customer browser ──(1) POST /account/websites/{uuid}/admin-access──▶ WP CareGrid
                 ◀─(2) "Opening WordPress admin…" page (auto-submitting form)
Customer browser ──(3) POST wp-login.php?action=wp_caregrid_access {request, token}──▶ WordPress (connector)
                                   WordPress ──(4) POST /api/v1/connector/admin-access/claim──▶ WP CareGrid
                                             ◀─(5) CREATE_ADMIN_SESSION job (one request, one level, one expiry)
                                   WordPress: JobRunner → handler → wp_set_auth_cookie() for a managed account
                                   WordPress ──(6) job result──▶ WP CareGrid (request becomes "active")
Customer browser ◀─(7) redirect to /wp-admin/ with WordPress's own auth cookies
```

- WP CareGrid **never logs in to WordPress** and never sees a WordPress cookie. It only authorises; the connector creates the session inside WordPress with WordPress's own API.
- The connector stays **outbound-only**: step 4 is the connector calling WP CareGrid with its own credential, as it does for polls.
- The browser token and the connector credential are **separate security domains**. The token is useless without the site's connector (only it can claim), and the connector credential alone cannot create a session (a claim needs the token). The connector credential never appears in a browser, and the token is never a bearer credential for the API.
- The session is created by a new whitelisted job, `CREATE_ADMIN_SESSION`, run through the normal `JobRunner` (strict payload validation, capability check, never executed twice). There is no `RUN_PHP`, `EXECUTE_CODE`, `RUN_COMMAND` or `EVAL`.

| Where | Code |
| --- | --- |
| Server domain | `app/AdminAccess/` (`AdminAccessService`, `AccessLevel`, `AdminAccessStatus`, `ActorType`), `app/Models/AdminAccessRequest.php` |
| Customer routes | `app/Http/Controllers/Account/AdminAccessController.php`, `routes/web.php` |
| Connector API | `app/Http/Controllers/Api/V1/AdminAccessController.php`, `routes/api.php` |
| Expiry | `php artisan admin-access:expire` (scheduled every minute) |
| Plugin | `includes/AdminAccess/` (`Landing`, `AdminAccess`, `Accounts`), `includes/Jobs/Handlers/CreateAdminSessionHandler.php` |
| Configuration | `config/admin_access.php` |

## Access types

| | Maintenance access | Temporary full admin access |
| --- | --- | --- |
| Default action | yes ("Open Admin") | no; a separate link and an explicit confirmation |
| WordPress account | `wp-caregrid-maintenance` | `wp-caregrid-admin` |
| Role while active | `wp_caregrid_maintenance` | `administrator` |
| Default duration | 30 minutes (`ADMIN_ACCESS_MAINTENANCE_MINUTES`) | 15 minutes (`ADMIN_ACCESS_FULL_ADMIN_MINUTES`) |
| Hard cap in the plugin | 60 minutes | 30 minutes |
| Who may request | the customer who owns the website; an operator | the customer only (`FULL_ADMIN_REQUIRES_CUSTOMER` for operators) |
| Extra rate limit | – | 5 per hour per user |

Full admin requires the confirmation page ("Grant temporary full administrator access for 15 minutes?") and a checked confirmation box; the POST without it is refused with `CONFIRMATION_REQUIRED`. Which levels a plan allows is set in `config/admin_access.php` (`plans`); `ADMIN_ACCESS_FULL_ADMIN_ALLOWED=false` turns full admin off for every plan.

## WordPress role and capabilities

Role `wp_caregrid_maintenance` is created with an **explicit capability list**; it is not an administrator with hidden menus. The list is re-synchronised exactly (added and removed) whenever a session starts, so a capability added by another plugin does not survive.

Granted:

| Capability | For |
| --- | --- |
| `read` | the dashboard |
| `update_plugins`, `update_themes`, `update_core`, `update_languages` | installing updates WordPress itself offers |
| `activate_plugins` | activating/deactivating installed plugins (checking a conflict) |
| `view_site_health_checks` | Tools → Site Health |

Not granted (verified in tests and end to end): user management (`list_users`, `edit_users`, `create_users`, `delete_users`, `promote_users`), `install_plugins`/`upload_plugins` (no ZIP uploads), `install_themes`/`upload_themes`, `edit_plugins`/`edit_themes`/`edit_files` (no file editors), `manage_options`, `unfiltered_html`, `delete_plugins`, `delete_themes`, `switch_themes`, `import`, `export`. Nothing grants code execution or filesystem access.

### Managed accounts

- Two accounts, `wp-caregrid-maintenance` and `wp-caregrid-admin`, created on first use and marked with user meta `wp_caregrid_managed_account`. **An existing user with that login that is not marked is never touched**: the session is refused with `ACCOUNT_CONFLICT`. No existing administrator is replaced, no existing user's password is changed, no existing account is used.
- When idle they have **no role** and no sessions. The role is assigned when a session starts and removed when it ends; `wp-caregrid-admin` is an administrator only while its session is active.
- Their password is 64 random characters generated in WordPress and never shown, stored elsewhere, sent or logged. On top of that, password login (`authenticate` filter), password reset (`allow_password_reset`) and application passwords are refused for them: they cannot be used as a normal login.
- Uninstalling the plugin strips them, deletes them if they own no content, and removes the role.

## Token lifecycle

1. **Created** when the customer clicks Open Admin: 32 random bytes (`random_bytes`), sent to the browser as 64 hex characters. Only its **SHA-256 hash** is stored (`admin_access_requests.token_hash`); the plain token exists only in that one response.
2. **Delivered** in the body of an auto-submitting POST form, never in a URL, query string or redirect. The page is sent with `Cache-Control: no-store`, `Referrer-Policy: no-referrer` and `X-Frame-Options: DENY`. The form target is the site's `admin_access_url`, reported by the connector and accepted only on the website's own host (HTTPS in production).
3. **Claimed** by the connector within `ADMIN_ACCESS_TOKEN_TTL` (120 s). The server checks, inside a locked transaction: the request belongs to **the authenticated connector's website** (another website's request is indistinguishable from an unknown one), `hash_equals` on the hash, status `pending`, not expired, subscription active, capability and plan level.
4. **Consumed**: status becomes `authorized` in the same transaction, so a second claim gets `ACCESS_ALREADY_USED`. Five wrong tokens for one request fail it (`TOO_MANY_ATTEMPTS`).

A token is tied to one request, which is tied to one website, one access level, one requester and one expiry.

## Session lifecycle

Server statuses (`AdminAccessStatus`, transitions enforced):

```
pending ──claim──▶ authorized ──job success──▶ active ──▶ expired | revoked
   │                    │
   └──▶ expired | revoked | failed ◀──┘
```

In WordPress:

1. `Landing` (the `login_form_wp_caregrid_access` action, POST only) validates the format, claims the token, checks that the response is a `CREATE_ADMIN_SESSION` job for the same request, and runs it through `JobRunner::process`.
2. The handler refuses to run outside that browser request (`ADMIN_SESSION_NEEDS_BROWSER`, so a job arriving by poll does nothing; the server also never delivers this type by poll) and refuses a payload for another website (`WRONG_WEBSITE`).
3. `AdminAccess::start` prepares the managed account, calls `wp_clear_auth_cookie()` and `wp_set_auth_cookie()` with the session length set through the `auth_cookie_expiration` filter, and tags the WordPress session token with the request UUID. Cookies are WordPress's own (secure on HTTPS, `HttpOnly`); nothing about their format is hard-coded.
4. The result is reported; the server marks the request `active` and the browser is redirected to `/wp-admin/`.

A job UUID is never executed twice, so replaying the browser POST shows "This access link has already been used".

## Expiration

Every session ends by itself, even if WP CareGrid is unreachable:

- **Server:** `admin-access:expire` (every minute) expires unclaimed tokens and ended sessions and records `admin_access.expired`.
- **WordPress cron:** a single event `wp_caregrid_admin_access_expire` at the earliest expiry.
- **WordPress `init` guard** (priority 1): on every request, a session past its expiry is ended before anything else runs. This works even when WP-Cron does not run.
- **Hard caps** in the plugin (60 / 30 minutes) whatever the server asks for.
- **Reconciliation:** every poll and heartbeat response lists the requests the server still considers live; the plugin ends any local session not on the list.

Ending a session removes the role, destroys all of the account's WordPress sessions (`WP_Session_Tokens::destroy_all`) and, when possible, tells WP CareGrid (`POST /admin-access/{uuid}/ended`).

## Revocation

| From | Effect |
| --- | --- |
| Customer area, **Revoke** | request `revoked` at once; the site ends the session at its next poll/heartbeat (reconciliation) or at expiry, whichever comes first |
| A new request for the same website and level | the previous open request is revoked (`superseded`) |
| Disconnect (either side), re-pairing, connector revocation | `Connector::revoke()` revokes every open request of the website; the plugin ends all sessions when it forgets its credential |
| WordPress, **Settings → WP CareGrid → Revoke admin access** | all sessions end immediately and WP CareGrid is told (`revoked_on_site`) |
| Plugin deactivation | all sessions end (`deactivated`) |

## Audit logging

Events in the website's audit log (`website_events`, linked by `admin_access_request_id`), shown in the customer area:

| Event | When |
| --- | --- |
| `admin_access.requested` | a request is created (level, actor type) |
| `admin_access.authorized` | the connector claimed a valid token |
| `admin_access.started` | the session exists in WordPress |
| `admin_access.expired` | token or session expired |
| `admin_access.revoked` | revoked (by whom, why) |
| `admin_access.failed` | refused at request or claim time, or the session could not be created (error code, stage) |

Each request records `requested_by_user_id`, `actor_type` (`customer` / `operator`), `revoked_by_user_id`, timestamps for every step, `end_reason` and `failure_code`.

**Never logged, on either side:** passwords, the connector credential, access tokens (plain or hashed), session cookies or session tokens. The plugin's logger additionally redacts keys like `token`, `password`, `cookie`, `auth`.

## Security model

| Threat | Control |
| --- | --- |
| Password exposure | No password is asked for, stored, sent or logged; managed accounts have random passwords nobody knows, and password login is refused for them |
| Stolen or leaked link | Token in a POST body only, 120 s lifetime, single use, stored hashed; `no-store` and `no-referrer` |
| Replay | Server: status `pending` required, consumed atomically. Plugin: job UUIDs never run twice; a replay is shown as "already used" |
| Using a token on another site | Only the website's own connector can claim; lookups are scoped to its website; payload `website_uuid` checked again in the plugin |
| Server compromise → code execution | Only the whitelisted `CREATE_ADMIN_SESSION` job with four validated fields; no generic execution |
| Poll-delivered session jobs | Not delivered by poll; refused by the plugin if received that way |
| Privilege creep | Maintenance role with an exact capability list; full admin only by explicit, confirmed, short, customer-only request |
| Taking over real users | Only marked managed accounts; conflicts refused |
| Sessions outliving authorisation | Server expiry, WordPress cron, `init` guard, hard caps, reconciliation, revoke, disconnect |
| Brute force | Route limits (5/min per user, 20/hour per website, full admin 5/hour per user, connector claim 20/min), 10 requests/hour per website, 5 wrong tokens per request, 10 failed landings per 15 min per IP |
| Cross-tenant access | `WebsitePolicy` on every customer route, scoped route bindings for revoke, website-scoped connector lookups; covered by tests |

Error pages say what happened in plain words (expired, already used, revoked, subscription inactive, not reachable) without internals.

## Customer and operator authorisation

- **Customer:** must be logged in, own the website (`WebsitePolicy::adminAccess`), have an active subscription, and the site must be connected, online and advertise the `admin_access` capability. Revoking needs only view access to the website, so a customer can always revoke, even after the subscription ends.
- **Operator:** `AdminAccessService::request(..., ActorType::Operator, AccessLevel::Maintenance)` is supported and audited as an operator; operators can never request full admin. There is no operator console yet; operator access must be wired to one when it exists, with its own authentication.
- The request is always tied to the requesting user (`requested_by_user_id`).

## Configuration

```
ADMIN_ACCESS_TOKEN_TTL=120
ADMIN_ACCESS_MAINTENANCE_MINUTES=30
ADMIN_ACCESS_FULL_ADMIN_MINUTES=15
ADMIN_ACCESS_MAX_REQUESTS_PER_HOUR=10
ADMIN_ACCESS_FULL_ADMIN_ALLOWED=true
```

The scheduler must run (`admin-access:expire`). Sites need connector 0.3.0 or newer; older connectors do not advertise `admin_access`, and the Open Admin button explains that the plugin must be updated.
