# Production-only confinement acceptance compatibility 1.0.13

This versioned compatibility release corrects live acceptance label handling for the unchanged confinement 1.0.12 runtime profiles.

## Scope

The host output showed the production FPM service label as:
`unconfined//&wpcaregrid-php-fpm-production (enforce)`.

The 1.0.12 probe and verifier accepted only the direct form
`wpcaregrid-php-fpm-production (enforce)`. The AppArmor collector records the actual kernel label, so the 1.0.12 verifier would reject the observed service label even though its expected profile is present in enforce mode.

The 1.0.13 acceptance tools accept only either the direct enforce label or the exact observed stack `unconfined//&<expected-profile> (enforce)`. They reject other profiles, complain mode, additional stack members, and unrelated labels. AppArmor stack mediation checks each member and applies the intersection of their permissions. The raw label remains in collected evidence.

## Runtime compatibility

All nine installed runtime files are byte-for-byte identical to confinement 1.0.12. This release does not alter the AppArmor rules, systemd units, Nginx configuration, PHP-FPM configuration, secret handling, or route policy. Do not reinstall or replace the nine installed target files for this acceptance-tool update.

Package pins:

- `SHA256SUMS`: `f27b59eb0c093881d4246d0ac667cf8161ef2d7ed544300b8b674f29e6bc3002`
- `binding.json`: `afe9a539851103c1c7907c72363ddf9de5c25d277d8021435f76eb6ae8f37cfe`
- Git package tree: `6c5d3dd8b77fd9acddbadbc6d0c37445f4069cc7`

The 1.0.12 package remains immutable and unchanged. Gate 2 still requires complete live peer acceptance with the route closed and only fixture canaries present. Acceptance success is not route-opening or production-activation authorization.

## References

- Protected primary: `e9a1bc352d54741ae72e2766f6e07e28e798900d`
- [AppArmor stacked profile semantics](https://manpages.debian.org/unstable/apparmor/aa-exec.1.en.html)
