# M14 dedicated staging security fixture

## Acceptance paths and Owner-approved alternative

This document remains the runbook for **Path A**, the optional dedicated,
non-billing `m14-security` synthetic fixture and its site-scoped test window.
The fixture implementation, exact identity checks, isolation controls, and
cleanup rules below remain in force whenever Path A is used.

For the M14 operational **Integrity-scan execution criterion only**, the Owner
approved **Path B** on 2026-10-24: the already completed, bounded Integrity
scan on the Owner-authorized, actively subscribed `kiszov-szeged.hu` staging
registration may satisfy the end-to-end Connector scan evidence. Path B does
not convert that ordinary paid Website into a synthetic fixture, change its
subscription or entitlements, or grant it M14-only suppression, backup,
restore, or polling permissions. It uses the normal authenticated Owner /
Connector / Website authorization path. It is an acceptance alternative for
the completed Integrity execution criterion, not a waiver of the independent
security audits, synthetic negative tests, malware coverage, or destructive
operation tests listed elsewhere in the M14 matrix.

The accepted scan and its scope limitations are recorded in
[`reports/m14/owner-real-site-integrity-acceptance-2026-10-24.md`](../reports/m14/owner-real-site-integrity-acceptance-2026-10-24.md).
Do not repeat the live scan solely to produce a synthetic Website UUID. A new
live operation requires its own current preflight and authorization.

This runbook defines a separate `m14-security` fixture. It does not reuse the
M8 backup/restore fixture and does not convert or scan the paid
`m14-synthetic.staging.wpcaregrid.com` website. The fixture creates only a
synthetic Customer, Website, and expiring fixture association; it creates no
login, subscription, CheckoutIntent, invoice, billing identity, Stripe object,
payment, or notification.

## External disposable WordPress prerequisite

`M14_SECURITY_SITE_HOST` must be explicitly approved by the Owner and contain
only a canonical lowercase ASCII DNS hostname. It may be hosted outside
`wpcaregrid.com`. Do not configure a URL, wildcard, IP, port, path, user info,
alias list, trailing dot, or customer-supplied runtime value. The application
constructs exactly `https://<host>` and provisions only when the configured host
passes the shared M14 hostname validator.

The endpoint must be a separate disposable WordPress installation with its own
files and database, valid HTTPS, non-sensitive synthetic data, no payments, no
customer notifications, and no production credentials. Never reuse the paid
M14 website, the M8 site, or an existing customer site. Normal authenticated
Connector pairing against the exact registered URL proves control of the
Connector installation; hostname approval remains an Owner action. Configure
the same canonical host for the Website before pairing.

At network-probe time, the application resolves all addresses, rejects private,
reserved, and non-public IPs, pins an approved public address for the request,
disables automatic redirects, and permits only same-host HTTPS redirects. A
customer cannot provide or override the M14 URL. Do not create DNS, vhosts, TLS
certificates, or a WordPress installation without the separately approved
infrastructure procedure.

## Configuration and identity binding

M14 settings are independent and disabled by default. Configure and verify them
only on the staging application:

| Setting | Purpose |
|---|---|
| `M14_SECURITY_FIXTURE_PROVISIONING_ENABLED` | One-time non-billing fixture creation |
| `M14_SECURITY_FIXTURE_ENABLED` | Enables the exact fixture record after provisioning |
| `M14_SECURITY_SITE_HOST` | Owner-approved canonical host |
| `M14_SECURITY_SITE_UUID` | Exact provisioned Website UUID |
| `M14_SECURITY_CUSTOMER_UUID` | Exact synthetic Customer UUID |
| `M14_SECURITY_CONTROLLED_POLLING_ENABLED` | Enables the bounded M14 poll path |
| `M14_SECURITY_CONNECTOR_UUID` | Exact active Connector UUID, configured after normal pairing |
| `M14_SECURITY_SCAN_ENABLED` | Enables the reviewed Integrity operation |
| `M14_SECURITY_SIDE_EFFECT_ISOLATION_ENABLED` | Enables exact-scan M14-only automatic incident suppression; defaults off |

The policy rechecks staging identity, fixture purpose, exact host and URL,
Website, Customer, Connector, non-billing state, fixture expiry, scan window,
Connector capability, and fresh readiness. The M14 fixture has no backup or
restore entitlement. Its allowlist contains inventory, health, license check,
and the explicitly configured Integrity scan. M8 flags and identity retain
their existing meanings.

Provision only after the exact code SHA and CI have been reviewed and deployed
through the protected controller:

```sh
php artisan caregrid:provision-m14-security-fixture
```

The command prints only the new synthetic Customer and Website UUIDs. Record
them in approved staging configuration, enable the fixture, and pair the
disposable WordPress Connector through the authenticated Owner workflow. Verify
Connector version, package SHA, Website UUID, and Connector UUID independently.
Bind that exact Connector UUID before enabling controlled polling. Deliver and
complete fresh `GET_INVENTORY`, `SITE_HEALTH`, and `LICENSE_CHECK` jobs. Require
a current capability snapshot whose `SECURITY_SCAN_READY` is `PASS`.

## Global safety settings and site-scoped scan isolation

The global `security.incidents.auto_open` and
`security.incidents.auto_backup` values are independent customer protections.
This procedure never changes them and never calls `true` or `unknown` false.
The effective values are recorded as `true`, `false`, or `unknown` in the M14
window audit.

An M14 window is an exclusive staging scan gate for one bounded Integrity scan.
Opening it requires current inventory for the exact active Connector, a fresh
matching capability snapshot with `SECURITY_SCAN_READY=PASS`, inactive platform
recovery mode, no unresolved M14 incident or backup, no active scan or
undelivered scan job, all six M12 privacy gates actually boolean false, and
`M14_SECURITY_SIDE_EFFECT_ISOLATION_ENABLED=true`. The M14 isolation setting is
off by default.

When the exact active M14 fixture requests its Integrity scan, the transaction
binds its scan UUID, synthetic Website and Customer UUIDs, active Connector,
and bounded window timestamps into durable authorization evidence. Only that
bound scan suppresses the automatic incident-detection side effect. The
suppression is enforced before an existing incident is linked or a new one is
opened, and writes a sanitized audit event with a finding count and the actual
global setting values. Durable binding preserves suppression for a delayed
failure/completion callback after window expiry or closure. The ordinary
incident path for other Websites is unchanged. The central backup service
independently refuses every M14 backup request, and the M14 job allowlist never
grants backup or restore. These controls do not disable or alter global
customer protections.

The scan request and delivery paths both require the exact scan UUID, Integrity
type, and current scan part bound to the active window. A request payload cannot
select another Website or scan type. The window does not grant general job
execution or bypass capability/readiness checks.

Open the bounded window only after the full checklist passes:

```sh
php artisan caregrid:m14-security-scan-window-open <WEBSITE_UUID>
```

Request one Integrity scan through the normal authenticated workflow. Do not use
Artisan to bypass scan policy. Observe the actual Connector poll, lease, result
outbox, authenticated result ingestion, coverage, terminal state,
findings/posture, and audit records. Close only after scan and Connector job
are terminal:

```sh
php artisan caregrid:m14-security-scan-window-close --reason=completed
```

An expired but unclosed window continues to block staging scans. If work is
outstanding, preserve evidence and use the approved recovery procedure; do not
delete markers or reset leases manually.

## M14 scanner GO checklist

- Exact merged deployment SHA and its successful seven-job push CI.
- Staging identity and all six M12 privacy gates freshly verified false.
- Owner-approved external hostname, separate disposable WordPress files and
  database, valid TLS, and authenticated Connector pairing verified; no paid or
  M8 identity reuse.
- Exact synthetic Customer, Website, active Connector, and fixture expiry match
  reviewed staging configuration.
- No user, billing, subscription, payment, notification, backup, or restore
  entitlement is granted to the fixture.
- Fresh inventory and compatibility snapshot; `SECURITY_SCAN_READY=PASS`.
- Site-scoped M14 isolation enabled; global auto-open and auto-backup values
  recorded without changing them; platform recovery inactive.
- No conflicting scan, job, lease, unresolved incident, or backup.
- Controlled polling, audit trail, result validation, and window cleanup ready.

Only Integrity is enabled in this first M14 path. Malware or full scanning
requires separate design review and an explicit allowlist change. A queued scan,
server-side vulnerability lookup, or `JOB_NOT_ALLOWED` response is not evidence
of Connector scanner execution. Record the scan UUID, accepted result,
completed coverage, side-effect deltas, audit events, and cleanup before making
an acceptance decision.

After the scan, confirm global incident/backup configuration is unchanged, no
M14 incident or backup was created by the test path, its suppression audit event
exists, and the scan window is closed. Decommission by disabling the M14
fixture, polling, scan, and site-isolation flags; revoke the Connector pairing
through the normal flow; then remove WordPress files/database and DNS/TLS
resources through that provider's approved process. Preserve the synthetic
Customer, Website, fixture, scan, binding, and audit records as expired
provenance: this application does not provide an M14 fixture deletion command.
Do not manually delete those records or any paid/M8 site as fixture cleanup.
