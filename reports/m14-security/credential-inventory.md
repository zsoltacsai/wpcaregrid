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
| Future AI keys | At verified M14.4 primary `0ed9025dca2ee7bfa23e9a6869b159f06c700659`, no AI provider client, key ID, route or product data flow identified. Existing `IntegrationCredentials` is a fixed-ID DB-backed store using `Crypt::encryptString`/`decryptString`; `resolve()` supports configured-path fallback and `apply()` hydrates most fields into global config. | No AI values or status exist in source; no deployed secret state inferred. | None authorized. M14.5 planning recommends three Owner-managed-only IDs, runtime-resolved only, no environment fallback and no global config path. | Reuse APP_KEY-encrypted store; opaque generation-bound health metadata; write-only, Owner-only, explicit fixed-host metadata GET. Preserve matching APP_KEY with platform DB recovery; never export AI keys plaintext. |
| Deployment SSH | `scripts/deploy-staging.sh`, user/host pinned operations | strict host-key verification; key value not inspected; Git deploy alias in script | staging filesystem/deployment, worker control | rotate client key and host key with verified overlap; least privilege; audit sudo; separate production identity |
| GitHub Actions secrets/tokens | `.github/workflows/ci.yml` and deployment integration (provider-side settings not visible) | source uses secret references only where declared; secret values inaccessible/not read | CI and staging deployment | enumerate names/permissions in GitHub settings during supply-chain audit, short-lived OIDC if supported, environment protection, rotate on incident |
| Vulnerability feed token | `config/security.php` | optional bearer credential; not configured on staging per system check | vulnerability data download only | least privilege; refresh monitoring; token rotation and stale-feed failure tests |

## Rotation principles

1. Never emit secret values in reports, audits, CI, exception pages, or health output.
2. Distinguish configured, reachable, authorized and recently verified; a value existing is not health proof.
3. Keep separate test/staging/production identities, and avoid reusing customer backup credentials for platform backups.
4. For credentials that encrypt stored values (APP_KEY), plan explicit key versioning, re-encryption and disaster recovery before change.
5. Verify new credential capability minimally, then revoke old credentials and record actor/time/scope without value.

## M14.2 Connector / webhook source reconciliation — 2026-10-16

This dated addendum refines the earlier Connector row from current source at primary 18ed2da5b0862b701ba19da6ef40b183ed6c44fb; it does not establish deployed secret values.

| Credential | Current verified source model | M14.2 work |
|---|---|---|
| Connector site bearer | cgc_ plus 48-character Str::random; Laravel stores SHA-256 only and returns raw value once. Connector stores libsodium secretbox ciphertext in a non-autoloaded WordPress option using a key derived from the secure-auth salt. One Connector belongs to one website; middleware checks active state and exact website UUID. | Test entropy/issue/response-loss/re-pair/revoke/disconnect/in-flight behavior; token absent from logs, UI, exceptions, server plaintext and artifacts. No standalone rotation/overlap API was found; no periodic expiry assumed. |
| Stripe webhook signing secret | services.stripe.webhook_secret; signature verified by Stripe SDK; no values inspected. | Verify timestamp tolerance, TEST mode, endpoint rotation procedure and whether dual-secret overlap is supported. External dashboard rotation remains an operational action. |

Do not claim full host compromise is mitigated by plugin DB encryption: a compromised WordPress runtime or salts can use/decrypt the bearer. Explicit revocation/re-pair is the recovery boundary. See m14-2-credential-lifecycle-plan.md.

## M14.5 source audit addendum — 2026-10-17

The M14.5 planning audit confirms the generic credential table is keyed by fixed ID and stores Laravel-encrypted values; the Owner page receives configured booleans and has blank password inputs. The current integration controller uses Owner ability and `owner-sensitive`, but has no recent current-password confirmation; M14.5 should apply the existing Owner current-password pattern to AI create/replace/remove. AI keys must not use generic environment fallback or `apply()` config hydration. No AI key should be resolved during boot/config-cache generation. Provider-health state, if persisted, stores only normalized status/time/error category and an opaque generation ID, never raw provider output or secret fingerprint. No runtime AI credential is present or claimed by this audit.

## M14.2 implementation validation update — 2026-10-16

Local regression evidence now includes lost pairing response recovery, fresh-code re-pair, server-side previous-token revocation, plugin encrypted-option persistence, response-size bounds, and Stripe single-secret cutover/stale timestamp rejection. The Connector release version is `0.4.6`; no plaintext credential, key value, or customer identifier is included in these reports. The mechanism remains a one-time credential returned once, hash-only on the server and encrypted at rest by the plugin. No overlapping token rotation or periodic expiry is claimed. Exact-head CI, staging synthetic pairing and post-deploy health remain pending.

## M14.2 credential evidence closure — 2026-10-16

The Connector plugin release at the merged implementation is 0.4.6, API v1. Existing re-pair/revocation and response-loss regressions passed in CI and isolated synthetic tests. Stripe webhook freshness/body-size/single-secret cutover checks also passed; no secret values were read into these reports. The deployed synthetic M8 site still reports Connector 0.4.5; no live token replacement was attempted without a reversible recovery path. See [M14.2 closure report](m14-2-closure-report.md). No token overlap/periodic expiry or live Stripe rotation is claimed.
