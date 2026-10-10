# Milestone 8 synthetic fixture controls

This internal path creates at most one synthetic M8 customer and WordPress site. It is not a customer signup, plan, subscription, payment, or billing path. The synthetic entitlement exists only on the server-created `synthetic_site_fixtures` row and does not change PROBONO or any paid plan.

All capabilities default off. The command is available only to a server operator with Artisan access and requires all of these deployment identities to match: `CAREGRID_DEPLOYMENT_ENV=staging`, `CAREGRID_DEPLOYMENT_HOSTNAME=stg1.wpcaregrid.com`, and `APP_URL=https://staging.wpcaregrid.com`. The application intentionally uses `APP_ENV=production` on staging; `APP_ENV` is not used as the deployment identity. Production must retain its own deployment marker and URL. Missing or mismatched identity refuses provisioning and synthetic operations.

## Provisioning

After the code is separately reviewed and deployed to staging, an operator may enable `SYNTHETIC_FIXTURE_PROVISIONING_ENABLED=true` for the controlled provisioning window and run:

```sh
php artisan caregrid:provision-m8-fixture
```

The command only accepts the one configured synthetic customer, reserved `example.test` email, and `m8-e2e.staging.wpcaregrid.com` HTTPS host. It creates no user, subscription, billing identity, checkout intent, invoice, provider object, or notification. It refuses duplicate reserved identities. It prints only customer and website UUIDs. Pairing remains the normal one-time pairing-code protocol. An authenticated Owner with `owner.websites.operate` may issue a code only for the active synthetic fixture whose UUID exactly matches `M8_SYNTHETIC_SITE_UUID`; ordinary sites remain unavailable on this Owner action. The route uses the normal `PairingCodes` service and displays the plaintext code only in the one-time website-detail flash. The stored code and audit log contain no plaintext secret.

## Site-scoped connector execution

Ordinary Connector installs keep normal WP-Cron polling. The M8 installation must separately opt in through local `wp-config.php` constants, with the exact paired site UUID:

```php
define('WP_CAREGRID_CONTROLLED_EXECUTION', true);
define('WP_CAREGRID_CONTROLLED_SITE_UUID', 'the-paired-site-uuid');
```

These constants alone cannot authorize test execution. The authenticated server schedule must also identify the same paired website as synthetic and controlled, and the Laravel deployment identity, fixture entitlement, configured site UUID, and feature flags must match. If any identity does not match, the Connector refuses the dedicated runner and keeps ordinary polling available. Do not set these constants on ordinary or production sites.

Before controlled mode is authorized, the normal Connector poll may execute only the non-destructive pairing checks (health, inventory, and license). Once both local and server authorization are active, automatic WebsiteJob polling is suppressed for that installation; heartbeat continues, and queued WebsiteJobs wait for the dedicated runner. The dedicated Linux process runs the existing Connector path once:

```sh
wp caregrid jobs poll --once
```

The CLI accepts no target site UUID. Where WP-CLI is installed, use `wp caregrid jobs poll --once`. The Connector package also provides a project-local PHP entrypoint for fixtures without a global WP-CLI installation:

```sh
php wp-content/plugins/wp-caregrid-connector/bin/caregrid-controlled-poll.php
```

The entrypoint requires CLI SAPI, loads the adjacent WordPress installation through `wp-load.php`, and invokes the same `ControlledPollCommand::runOnce()` used by WP-CLI. It uses the Connector's stored pairing, normal authenticated API, site UUID header, existing atomic poll lease, WebsiteJob claim, reviewed job handler, durable restore mutation state, and result outbox. The server returns at most one job to a controlled poll. There is no public execution or process-control endpoint. A separately approved rollout must verify the exact pairing and keep restore disabled before running a harmless poll.

Controlled mode is separately gated from restoration. `SYNTHETIC_FIXTURE_CONTROLLED_EXECUTION_ENABLED=true` and `M8_SYNTHETIC_SITE_UUID` are required for the site-scoped runner; `SYNTHETIC_FIXTURE_RESTORE_ENABLED` remains false until a separate, explicit M8 restore authorization. No restore is started by enabling controlled polling alone.

## Safe rollout and rollback

This document does not authorize deployment or fixture creation. A later separately approved rollout must deploy the exact CI-validated SHA, review and apply the additive fixture-table migration, verify all flags are off, set the explicit staging deployment marker, enable only the provisioning flag, create the reserved fixture once, and verify the no-billing/no-email record state. Pair through the normal Operator flow, verify heartbeat/inventory/health and the paired UUID, then enable controlled mode only for that UUID. Start with a harmless one-job health/inventory job. Keep restore disabled until its separately approved failure-matrix task.

To roll back, stop the dedicated CLI process, disable controlled and restore flags, remove the exact local controlled-mode constants from the synthetic fixture only, then use the normal reviewed release rollback. Preserve audit and fixture evidence. Do not delete fixture records or backup artifacts as part of code rollback.
