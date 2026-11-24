# Production helper 2.1.10 and confinement package 1.0.11

Helper 2.1.10 is an immutable successor bound to production-only confinement package 1.0.11. It recognizes only production FPM and Nginx as required service roles; production build/deploy CLI processes remain short-lived transient classes. It does not require staging-next FPM, staging schedulers, supervisor, staging jobs, or demo vhosts. The generic `php8.5-fpm.service` is inventoried as a forbidden sentinel and any process there fails acceptance.

Package 1.0.11 keeps the shared `www-data` UID/GID 33 model and Invariant I. No production group-readable secret or route may exist before every actual UID/GID/supplementary-GID 33 peer has passed enforce-profile binding and live acceptance. Unknown, unconfined, missing, unreadable, or unstable peers fail closed. Before acceptance, the stock `php8.5-fpm.service` must be absent or stopped/masked, and there must be no unknown process with UID/GID/supplementary-GID 33. Production jobs stay fenced. PHP 8.4 denials remain defense in depth; this repository change does not install, stop, or alter PHP 8.4 or other host services.

The Nginx profile is part of the production security domain and is restricted to the production vhost, production public release tree, production FastCGI socket, and `wpcaregrid.com` TLS material. It denies staging/demo web roots, unrelated TLS domains, generic FPM sockets, and PHP 8.4 sockets.

The gate order is: install package/system components; bind profiles in enforce mode; run fixture-only, route-closed live peer acceptance before installing the helper that provisions root-private control directories; remove fixtures; install/verify the helper; obtain the Owner's explicit acknowledgment of any profile-qualified UNPROVEN IDs using the verifier's exact `--ack-unproven=<matrix-sha256>:<boot-id>:<profile:id list>` argument; only then place Owner-provided production configuration/secrets; pass separate route-closed DB/application checks; then open the route under a separate Owner GO and verify external HTTPS. The verifier exits HOLD when UNPROVEN IDs lack an exact acknowledgment. No repository change or CI pass authorizes host execution, secret placement, route opening, or production activation.

Ubuntu 26.04 compatibility is checked in an isolated CI runner with AppArmor parser compilation, systemd unit validation, and acceptance tooling tests. Host-side testing is outside this change. Package 1.0.7/helper 2.1.6 and all previously released files remain immutable. Package 1.0.11 supersedes unmerged drafts 1.0.8–1.0.10; helper 2.1.10 supersedes unmerged drafts 2.1.7–2.1.9. Do not install artifacts from earlier PR heads, and discard files staged from them before reviewing this package.


## Pinned artifacts

| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_10.py` | `a021e028f62baafd578b8ef427535f3badf41cda56e3954862028030d53b4742` |
| `scripts/deployment_backend_v2_1_10.py` | `de81c0553de1f4af63bc708bb9c3d3e0d9748308bb37541ea4a2ce9cc65425c3` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_10.py` | `f7bc509af549958ddb37925e71e550f83276cb0afc8edce5983d54155fc68b30` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| `scripts/production_deploy_controller_2_1_10.py` | `fda3a7c49cf4ffe5845285125bef69aa7c5793b0d1be1dde19d66a8431183adb` |
| `scripts/install_production_helper_2_1_10.py` | `27fcde0b274de163414eddeb422ca8019e5a34b4a6c1dad5b88d890454a7b037` |
| `helper artifact digest` | `1e21b26753803e8efc46f9c99c409bc95703351c641715e6376cac623f655e77` |
