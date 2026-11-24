# Production helper 2.1.9 and confinement package 1.0.10

Helper 2.1.9 is an immutable successor bound to production-only confinement package 1.0.10. It recognizes only production FPM and Nginx as required service roles; production build/deploy CLI processes remain short-lived transient classes. It does not require staging-next FPM, staging schedulers, supervisor, staging jobs, or demo vhosts. The generic `php8.5-fpm.service` is inventoried as a forbidden sentinel and any process there fails acceptance.

Package 1.0.10 keeps the shared `www-data` UID/GID 33 model and Invariant I. No production group-readable secret or route may exist before every actual UID/GID/supplementary-GID 33 peer has passed enforce-profile binding and live acceptance. Unknown, unconfined, missing, unreadable, or unstable peers fail closed. Before acceptance, the stock `php8.5-fpm.service` must be absent or stopped/masked, and there must be no unknown process with UID/GID/supplementary-GID 33. Production jobs stay fenced. PHP 8.4 denials remain defense in depth; this repository change does not install, stop, or alter PHP 8.4 or other host services.

The Nginx profile is part of the production security domain and is restricted to the production vhost, production public release tree, production FastCGI socket, and `wpcaregrid.com` TLS material. It denies staging/demo web roots, unrelated TLS domains, generic FPM sockets, and PHP 8.4 sockets.

The gate order is: install package/system components; bind profiles in enforce mode; run fixture-only, route-closed live peer acceptance before installing the helper that provisions root-private control directories; remove fixtures; install/verify the helper and place Owner-provided production configuration/secrets; pass separate route-closed DB/application checks; then open the route under a separate Owner GO and verify external HTTPS. The verifier lists every UNPROVEN probe ID and count; each exact ID requires explicit Owner acknowledgment before the secret gate. Unacknowledged IDs keep that gate on HOLD. No repository change or CI pass authorizes host execution, secret placement, route opening, or production activation.

Ubuntu 26.04 compatibility is checked in an isolated CI runner with AppArmor parser compilation, systemd unit validation, and acceptance tooling tests. Host-side testing is outside this change. Package 1.0.7/helper 2.1.6 and all previously released files remain immutable. Package 1.0.10 supersedes unmerged drafts 1.0.8/1.0.9; helper 2.1.9 supersedes unmerged drafts 2.1.7/2.1.8. Do not install artifacts from earlier PR heads, and discard files staged from them before reviewing this package.


## Pinned artifacts

| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_9.py` | `cda174ad614cd7e3a8b56d32ab88f0ff30e2bea5f66bf1b9deea1259f92f521a` |
| `scripts/deployment_backend_v2_1_9.py` | `e50235c48cfc769f2ba5b14e6b0ba285b940e0b9ea0e1c5b6f186bf04ec976d5` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_9.py` | `5298c49b6a91c781b0df2146312438142742ebc89b70d9e632cd20c591406fba` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| `scripts/production_deploy_controller_2_1_9.py` | `35795946e70bc595eb25d47ad1f10235eea1dc4a47e08b9bd2d758e0ed70c36b` |
| `scripts/install_production_helper_2_1_9.py` | `6a1782a9c19eb179bea7ee295722baf88b9cbd30c93eaf0e8042dccf4d07491a` |
| `helper artifact digest` | `8d944502447e6e0b108dc44d393f430b38bb2db6ea6a33b968a4d47b44ab30b5` |
