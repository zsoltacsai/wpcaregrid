# M14.2 Outbound Request and SSRF Matrix

| Caller / destination | Source and controls observed | Required proof |
|---|---|---|
| Plugin API client | WP_CAREGRID_API_URL, default https://wpcaregrid.com/api/v1/connector; HTTPS except explicit localhost development opt-in; TLS verification; redirects 0; auth added only for authenticated call | production override/host validation; reject user-controlled host, credentials in URL; ensure auth never reaches redirect |
| Homepage / REST probes | home_url and rest_url; finite timeout; homepage allows up to three redirects; TLS filter is WordPress https_local_ssl_verify | Treat as same-site probes. Test redirects/DNS rebinding to private/link-local/metadata; define legitimate local-proxy exception |
| Integrity checksums | Fixed WordPress.org URLs, slug/version encoded, redirects disabled | response cap and JSON/schema verification; no attacker-controlled origin |
| Clean source remediation | wp_remote_get receives URL; redirect disabled and response max defined | trace construction to fixed/allowlisted origin, bind checksum, reject URL influenced by site/job payload |
| Laravel S3 / SFTP | deployment configuration; server-side credentials; SFTP host fingerprint required | test bad/missing fingerprint, timeouts and config validation; no Connector URL influence |
| Stripe SDK | fixed server API host and server key; Connector does not supply URL | TEST only; no live charge |

Source search found six relevant Connector/plugin HTTP request call sites: central API client, homepage/REST probes, two checksum requests and clean-source fetch. This counts call sites, not runtime requests. Do not create generic fetch API without need. For effectful fetches validate destination at connection time, disable/revalidate each redirect, address DNS rebinding, cap bytes/time, and document intentional local probes. No SSRF vulnerability is confirmed by this planning review.

## Implementation evidence update — 2026-10-16

Current Connector egress remains separated by trust class: the API client uses the deployment-configured `WP_CAREGRID_API_URL`, requires HTTPS except an explicit loopback development opt-in, verifies TLS, sends the bearer only on that request, sets redirects to zero and bounds response size; homepage/REST probes target the configured same-site URL without a bearer and now set redirects to zero plus a 1 MiB response cap; checksum and clean-source calls use fixed WordPress.org HTTPS endpoints, explicit TLS verification, zero redirects and 10 MiB caps. Fake-HTTP tests assert response caps and no redirects. Laravel website probes retain `PublicOrigin` address resolution and `SafeRedirect` validation. No real hostile DNS, private-address or cloud-metadata connection was attempted; source/fake-transport evidence is not a live network test. Exact CI and staging checks remain pending.
