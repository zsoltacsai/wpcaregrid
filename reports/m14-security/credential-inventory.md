# M14 Credential Inventory and Rotation Design

**Secret values intentionally not read or included.** This identifies source/config surfaces only; it does not establish staging/production values, exact holders or provider IAM.

| Credential class | Source/config surface | At-rest / display evidence | Main consumers / blast radius | Rotation and validation work |
|---|---|---|---|---|
| APP_KEY | Laravel environment | Owner-managed integration credentials encrypted using APP_KEY; deployment docs require secure recovery copy; do not rotate casually | all encrypted app values, sessions/ciphertext | key-version/reencryption and recovery drill; escrow out-of-band; never assume DB restore alone recovers it |
| DB credentials | `config/database.php`, environment | private deployment config; value not read | application, queue, scheduler | least privilege by schema/role, rotate with staged dual-connection test and rollback |
| Cache/queue/session | `config/cache.php`, `queue.php`, `session.php` | no secret values inspected; database queue/session supported | auth/session integrity and jobs | confirm backend ACL/TLS, rotate service credentials, monitor backlog/eviction |
| Stripe secret / publishable / webhook | `config/services.php`, `Owner/IntegrationCredentials.php` | docs say encrypted/masked; staging reports Stripe TEST | payment, portal, webhook | staged endpoint/event secret overlap; test/prod separation; verify events; revoke old key |
| Customer backup S3 | `config/backup.php` | env or Owner-managed credentials; object prefix separated; least privilege described in deployment docs | customer backup objects | prefix-scoped IAM; dual credentials, read/write/checksum test, rotate, revoke old; separately review delete rights |
| Platform backup S3 | `config/filesystems.php` platform disk | separate credential variables, private permissions; APP_KEY and backup-key escrow documented | platform DB backups and tombstone journal | distinct account/role where feasible; test restore/decrypt before revocation; preserve key recovery |
| SFTP backup | `config/backup.php` | password or private key path/passphrase; mandatory host fingerprint | customer backup store | key rotation with host-key pin overlap; test read/write and retained access |
| Mail provider | `config/mail.php`, `config/services.php` | encrypted Owner Integration credential support / environment config | customer/staff notification | rotate provider token and SMTP password; verify test delivery; avoid message content in logs |
| Cloudflare | `config/cloudflare.php`, service client | Owner-managed token flow; staging system check says configured (value not inspected) | DNS read/write depending action | scoped zone/record token, separate read/apply authority, rotate and test planning mode first |
| Connector site credential | `app/Connector`, `AuthenticateConnector`, plugin `CredentialStore` | server stores credential hash; plaintext not expected in audit | one paired site, Connector API and job authority | explicit rotate/re-pair/revoke lifecycle, grace window, audit event, stale token and in-flight request tests |
| Google OAuth / Drive | source search/config | no integration found in audited app/config/routes | none identified | remain unconfigured; if added inventory scope, refresh-token storage, consent and revocation |
| Future AI keys | no provider integration/config found in app/config/routes | not present | none authorized | provider adapter secrets encrypted, write-only, per-provider; test-connection isolated; disabled until purpose/data approval |
| Deployment SSH | `scripts/deploy-staging.sh`, user/host pinned operations | strict host-key verification; key value not inspected; Git deploy alias in script | staging filesystem/deployment, worker control | rotate client key and host key with verified overlap; least privilege; audit sudo; separate production identity |
| GitHub Actions secrets/tokens | `.github/workflows/ci.yml` and deployment integration (provider-side settings not visible) | source uses secret references only where declared; secret values inaccessible/not read | CI and staging deployment | enumerate names/permissions in GitHub settings during supply-chain audit, short-lived OIDC if supported, environment protection, rotate on incident |
| Vulnerability feed token | `config/security.php` | optional bearer credential; not configured on staging per system check | vulnerability data download only | least privilege; refresh monitoring; token rotation and stale-feed failure tests |

## Rotation principles

1. Never emit secret values in reports, audits, CI, exception pages, or health output.
2. Distinguish configured, reachable, authorized and recently verified; a value existing is not health proof.
3. Keep separate test/staging/production identities, and avoid reusing customer backup credentials for platform backups.
4. For credentials that encrypt stored values (APP_KEY), plan explicit key versioning, re-encryption and disaster recovery before change.
5. Verify new credential capability minimally, then revoke old credentials and record actor/time/scope without value.
