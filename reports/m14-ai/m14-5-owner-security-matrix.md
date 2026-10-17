# M14.5 Owner security matrix

| Surface/action | Required control | Negative/abuse proof |
|---|---|---|
| Owner Integrations GET | Owner auth + `owner.integrations.manage`; configured boolean and normalized status only | unauthenticated redirect; Owner without ability denied |
| Save/replace/remove | Owner-only; CSRF; existing current-password confirmation pattern; `owner-sensitive`; transaction | missing/wrong password, invalid provider ID, oversized/control-character key, stale permission => no DB update/audit success/probe |
| Test connection | Owner-only; CSRF; `owner-sensitive` plus dedicated low-rate external probe limit; exactly one fixed adapter call | Customer, Operator, guest and unauthorized Owner cannot call or trigger network |
| Secret input | password type, blank always, bounded; no old-input rehydration | invalid validation must not flash `value`; HTML/session/redirect contain no recognizable secret |
| Configured display | status only, no prefix/suffix/length/hash/fingerprint | refresh/browser-back never restores key |
| Health display | safe translated enum, checked time, generic next step | no raw provider text/header/request IDs/account metadata |
| Audit | allowlisted provider/action/status/generation only | query audit after all lifecycle and failure cases; no key/header/body/customer sentinel |
| Logs/exceptions | normalize before logging; discard response/HTTP exception context | inject fake key, auth-like string, customer sentinel into fake exception/body; search captured logs, failed_jobs and rendered error |
| Removal semantics | “Remove from WP CareGrid”; provider-side revocation is separate customer account action | env fallback remains off; remove cannot reactivate key or retain prior health |
| Provider destination | fixed official HTTPS host/path; redirect off | unknown ID never resolves arbitrary adapter/URL; redirect target receives no credential |
| Feature boundary | no enable/default/feature authorization | assert no service client, completion call, AI queue, or integration with product data domain |

Owner-facing provider names, configured/not configured, not tested, healthy, credential rejected, unavailable, timed out, save/replace/remove/test labels and the “configuration does not enable AI / no customer data is sent” notice must use the existing English and Hungarian localization architecture. Customer surfaces receive no AI status or provider control.

Existing ability `owner.integrations.manage` and Owner Integrations page are the smallest fitting boundary. The route currently uses Owner ability and `owner-sensitive`; re-auth is a new planned control for AI credential create/replace/remove, not a current property. No Customer or Operator provider visibility is justified. The generic Owner page should get no broad AI controls beyond key lifecycle and explicit test.
