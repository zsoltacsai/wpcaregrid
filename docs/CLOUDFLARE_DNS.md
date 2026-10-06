# Cloudflare DNS integration

WP CareGrid's Owner-only Cloudflare screen is an optional, reusable control plane for DNS inspection and individually approved record changes. It performs no changes during deployment, `system:check`, or application boot.

## Token setup and least privilege

Store a Cloudflare API Token at Owner → Integrations → Cloudflare API token. Values are encrypted using Laravel's `APP_KEY`, never returned to the browser after save, and excluded from logs and audit data. A token may instead be supplied through `CLOUDFLARE_API_TOKEN` as an environment fallback.

Create a scoped token with **Zone:Read** and **DNS:Read / DNS:Edit**, restricted to the exact zones configured in `CLOUDFLARE_ALLOWED_ZONES` (default: `wpcaregrid.com`). Do not use a Global API Key or account-wide token. The application enforces its own exact-name allowlist in addition to Cloudflare's token scope. The API resolves every zone ID by exact name; a submitted zone ID is never trusted.

## Owner operations

The dedicated `owner.dns.manage` ability protects the Cloudflare DNS page and all read/write routes. Owner sessions, CSRF, per-owner throttles, and audit records are required. The page displays public DNS record values, not Cloudflare account details or credentials. Initial types are A, AAAA, CNAME, TXT, and MX. MX requires a priority; proxying is restricted to Cloudflare-supported A/AAAA/CNAME records, mail-related names and `wp-test.staging.wpcaregrid.com` must remain DNS-only. `TXT` payloads are preserved as submitted, with control characters rejected.

Add/update uses an idempotent ensure operation: no matching value means create, identical configuration means no change, and one differing match means update; ambiguous duplicates mean conflict. A/AAAA/TXT/MX values can coexist. Their edit forms bind to a server-resolved record ID, while CNAMEs are unique by name and are checked against conflicting record types. Record names must remain inside the selected zone. Every mutation has a short-lived preview followed by a separate explicit confirmation. The service re-resolves the zone and replans immediately before apply. There is no bulk-delete operation. Built-in protected names cannot be created, changed, or deleted; this includes `wpcaregrid.com`, `www`, staging, the VPS hostname, the staging test host, and `mail.wpcaregrid.com`. `CLOUDFLARE_PROTECTED_RECORD_NAMES` can add more protected names but cannot remove these built-in safeguards. Records outside the supported A/AAAA/CNAME/TXT/MX set are read-only.

DNS mutations are audited with actor, operation, zone, record ID, type/name and result/error category. DNS record contents are not copied into audit metadata. API tokens, headers, response bodies and secrets are never audited. Read-only status checks verify the token and read access to approved zones; DNS edit permission is intentionally reported as unknown until a change is explicitly applied. `system:check` reports absent Cloudflare as optional/not configured and an API/read failure as a warning; neither prevents application boot or fails the normal production check. `--strict` still flags optional missing configuration.

## Infrastructure desired state

`App\Cloudflare\WpCareGridInfrastructureDns` describes two staging A records (`wp-test.staging.wpcaregrid.com` and `mail.wpcaregrid.com`, both DNS-only at `85.155.243.164`) using the generic record representation. It only describes desired state and does not call Cloudflare. No mail MX, SPF, DKIM or DMARC value is defined here; those records must wait for the mail architecture's authoritative values. The generic service supports these TXT/MX operations when explicitly planned and confirmed.

The Owner → Cloudflare DNS → Managed infrastructure DNS page exposes only those fixed server-defined staging records. It resolves and previews current provider state read-only; applying requires a fresh server-side plan and explicit confirmation. The generic record editor continues to block these protected names, and the managed path has no delete or arbitrary-value operation. Opening the page, calculating a preview, deployment, and `system:check` do not change DNS. Mail host desired state is listed for a later explicit approval; it is not created during deployment or validation.

Owner-managed credentials are encrypted with `APP_KEY`. Losing that key makes them undecryptable; replacing it without re-encryption is destructive. Protect database backups containing `integration_credentials` together with the matching `APP_KEY` and preserve their relationship in disaster recovery.
