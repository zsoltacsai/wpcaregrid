# M14 dedicated staging security fixture

This runbook defines a separate `m14-security` fixture. It does not reuse the
M8 backup/restore fixture and does not convert or scan the paid
`m14-synthetic.staging.wpcaregrid.com` website. The M14 fixture creates only a
synthetic Customer, Website, and expiring fixture association; it creates no
login, subscription, CheckoutIntent, invoice, billing identity, Stripe object,
payment, or notification.

## Infrastructure prerequisite

The hostname must be explicitly approved and reserved for M14, and must match
`m14-security-<label>.staging.wpcaregrid.com`. It must resolve to a separate,
disposable WordPress installation with its own database and normal Connector
installation. Do not reuse the paid M14 website, the M8 site, or an existing
customer site. Do not create DNS, vhosts, TLS certificates, or a WordPress
installation without following the separately approved staging infrastructure
procedure. The environment-specific `M14_SECURITY_SITE_HOST` has no default;
provisioning fails closed until the dedicated host is configured.

## Configuration and identity binding

All M14 settings are independent and disabled by default. The deployment must
set and verify these values only on the staging application:

| Setting | Purpose |
|---|---|
| `M14_SECURITY_FIXTURE_PROVISIONING_ENABLED` | One-time non-billing fixture creation |
| `M14_SECURITY_FIXTURE_ENABLED` | Enables the exact fixture record after provisioning |
| `M14_SECURITY_SITE_HOST` | Owner-approved dedicated host |
| `M14_SECURITY_SITE_UUID` | Exact provisioned Website UUID |
| `M14_SECURITY_CUSTOMER_UUID` | Exact provisioned synthetic Customer UUID |
| `M14_SECURITY_CONTROLLED_POLLING_ENABLED` | Enables only the bounded M14 poll path |
| `M14_SECURITY_CONNECTOR_UUID` | Exact active Connector UUID, set after normal pairing |
| `M14_SECURITY_SCAN_ENABLED` | Enables the reviewed Integrity operation |

The policy rechecks staging identity, the exact fixture purpose, host, Website,
Customer, active Connector, non-billing state, fixture expiry, scan window,
Connector capability, and fresh readiness. Any absent, mismatched, or expired
value denies the operation. The M14 fixture has no backup/restore entitlement.
Its poll allowlist contains only inventory, health, license check, and the
explicitly configured Integrity scan. M8 flags and M8 identity retain their
existing meanings.

Provision once, after the exact code SHA and CI have been reviewed and deployed
through the protected controller:

```sh
php artisan caregrid:provision-m14-security-fixture
```

The command prints only the new synthetic Customer and Website UUIDs. Record
them in the approved staging configuration, enable the fixture, and pair the
real disposable WordPress Connector through the authenticated Owner workflow.
Verify Connector version, package SHA, Website UUID, and Connector UUID
independently. Bind that exact Connector UUID before enabling controlled
polling. Deliver and complete a fresh `GET_INVENTORY`, health, and license
check; require a current capability snapshot whose
`SECURITY_SCAN_READY` value is `PASS`.

## Exclusive scan window and side-effect safety

M14 permits only one bounded Integrity scan. The persisted staging-wide window
is an exclusive maintenance gate: scan requests and Connector scan-job delivery acquire the same database row lock used when opening the window; new or pre-existing scans for every other Website are refused while it is active. Opening the window requires a fresh inventory for the exact active Connector, a current matching capability snapshot with `SECURITY_SCAN_READY=PASS`, inactive recovery mode, no unresolved M14 incidents/backups, no active scans or undelivered scan jobs, and actual boolean `false` for every incident/backup and privacy safety setting. An expired but unclosed window continues to block scans until
an operator verifies terminal state and closes it. No scan type is permitted
by changing a request payload.

This code gate is not proof that shared application configuration is isolated.
Before any operational scan, the Owner must use the approved staging
maintenance/isolation procedure and verify that the effective application
configuration is safe for the entire window. Do not globally set
`security.incidents.auto_open` or `security.incidents.auto_backup` to false
unless that approved procedure has established isolation from unrelated
staging workloads and provides a restoration check. If that evidence is not
available, keep scanning disabled. The scan window is not permission to alter
those global settings.

When prerequisites and isolation evidence are current, a staging operator may
open the exact bounded window:

```sh
php artisan caregrid:m14-security-scan-window-open <WEBSITE_UUID>
```

Recheck the checklist, then request one Integrity scan through the normal
authenticated workflow. Do not use Artisan to bypass the scan policy. Observe
the actual Connector poll, job lease, result outbox, authenticated result
ingestion, scanner coverage, terminal state, findings/posture, and audit
records. Close the window only after the scan and its Connector job are
terminal:

```sh
php artisan caregrid:m14-security-scan-window-close --reason=completed
```

If the window expires while work is outstanding, the system blocks further
polling and all staging scans. Do not restore any temporary configuration or
open another window until the scan and jobs are terminal and the window is
closed. Preserve audit evidence and use the approved recovery procedure for
stale work; do not delete markers or reset leases manually.

## M14 scanner GO checklist

- Exact merged deployment SHA and its successful seven-job push CI.
- Staging identity and all six M12 privacy gates freshly verified false.
- Dedicated M14 host, WordPress instance, database, TLS, and Connector
  provenance verified; no paid/M8 identity reuse.
- Exact M14 Customer, Website, active Connector, and fixture expiry match the
  reviewed staging configuration.
- No user, billing, subscription, payment, notification, backup, or restore
  side effect is granted to the fixture.
- Fresh inventory and compatibility snapshot; `SECURITY_SCAN_READY=PASS`.
- Incident/backup settings safely verified in the approved isolated execution
  context; platform recovery state is inactive.
- No conflicting scan, job, lease, unresolved incident, or backup.
- Controlled polling, audit trail, result validation, and scan-window cleanup
  are ready.

Only the Integrity scan is enabled in this first M14 path. A malware or full
scan requires separate design review and an explicit allowlist change. A queued
scan, server-side vulnerability lookup, or `JOB_NOT_ALLOWED` response is not
evidence of Connector scanner execution. Record the scan UUID, completed
coverage, accepted result, side-effect deltas, audit events, and cleanup before
making any M14 acceptance decision.
