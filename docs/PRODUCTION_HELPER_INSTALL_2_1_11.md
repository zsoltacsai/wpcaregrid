# Production helper 2.1.11 and confinement package 1.0.12

Helper 2.1.11 is an immutable successor bound to production-only confinement package 1.0.12. It recognizes only production FPM and Nginx as required service roles; production build/deploy CLI processes remain short-lived transient classes. It does not require staging-next FPM, staging schedulers, supervisor, staging jobs, or demo vhosts. The generic `php8.5-fpm.service` is inventoried as a forbidden sentinel and any process there fails acceptance.

Package 1.0.12 keeps the shared `www-data` UID/GID 33 model and Invariant I. No production group-readable secret or route may exist before every actual UID/GID/supplementary-GID 33 peer has passed enforce-profile binding and live acceptance. Unknown, unconfined, missing, unreadable, or unstable peers fail closed. Before acceptance, the stock `php8.5-fpm.service` must be absent or stopped/masked, and there must be no unknown process with UID/GID/supplementary-GID 33. Production jobs stay fenced. PHP 8.4 denials remain defense in depth; this repository change does not install, stop, or alter PHP 8.4 or other host services.

The Nginx profile is part of the production security domain and is restricted to the production vhost, production public release tree, production FastCGI socket, and `wpcaregrid.com` TLS material. It denies staging/demo web roots, unrelated TLS domains, generic FPM sockets, and PHP 8.4 sockets.

The gate order is: install package/system components; bind profiles in enforce mode; run fixture-only, route-closed live peer acceptance before installing the helper that provisions root-private control directories; remove fixtures; install/verify the helper; obtain the Owner's explicit procedural acknowledgment of any profile-qualified UNPROVEN IDs with the exact matrix, boot, phase, profile, and evidence digest; only then place Owner-provided production configuration/secrets; pass separate route-closed DB/application checks; then open the route under a separate Owner GO and verify external HTTPS. The verifier exits HOLD when UNPROVEN IDs lack an exact acknowledgment. Its token binds the evidence for replay resistance; it is not a cryptographic signature. No repository change or CI pass authorizes host execution, secret placement, route opening, or production activation.

Ubuntu 26.04 compatibility is checked in an isolated CI runner with AppArmor parser compilation, systemd unit validation, and acceptance tooling tests. Host-side testing is outside this change. Package 1.0.7/helper 2.1.6 and all previously released files remain immutable. Unmerged draft packages 1.0.8–1.0.11 and helpers 2.1.7–2.1.10 are removed from this PR; only package 1.0.12/helper 2.1.11 are proposed. Do not use files or host staging copied from earlier PR heads.


## Pinned artifacts

| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_11.py` | `fb6e0816e71ebb1d92acec9d85b780308219943c6ae3847ef099cbcd450b3fbb` |
| `scripts/deployment_backend_v2_1_11.py` | `d767174823c6065e7e41d8978412a612158b97fa960628cf872f4935afd878f5` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_11.py` | `46b7a1f60085b9c50fa7a52bfef78c979e4178cd7c695ff9277c019c7b3190c5` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| `scripts/production_deploy_controller_2_1_11.py` | `8900d827fbd29b8ff99d89a146a0da24bde0ee8c4b08159e6adbeb3378ce7625` |
| `scripts/install_production_helper_2_1_11.py` | `4c629597be59a7f77996d51ee354209c918a5d2338ba1485c80e1ed0ffedfe6f` |
| `helper artifact digest` | `d82892eabb1231937b1e21d5b8c76216659069698bf09f579225e480b51bc32b` |
