# Production helper 2.1.7 and confinement package 1.0.8

Helper 2.1.7 is an immutable successor bound to production-only confinement package 1.0.8. It recognizes only production FPM and Nginx as required service roles; production build/deploy CLI processes remain short-lived transient classes. It does not require staging-next FPM, staging schedulers, supervisor, staging jobs, or demo vhosts. The generic `php8.5-fpm.service` is inventoried as a forbidden sentinel and any process there fails acceptance.

Package 1.0.8 keeps the shared `www-data` UID/GID 33 model and Invariant I. No production group-readable secret or route may exist before every actual UID/GID/supplementary-GID 33 peer has passed enforce-profile binding and live acceptance. Unknown, unconfined, missing, unreadable, or unstable peers fail closed. Production jobs stay fenced. PHP 8.4 denials remain defense in depth; this repository change does not install, stop, or alter PHP 8.4 or other host services.

The Nginx profile is part of the production security domain and is restricted to the production vhost, production public release tree, production FastCGI socket, and `wpcaregrid.com` TLS material. It denies staging/demo web roots, unrelated TLS domains, generic FPM sockets, and PHP 8.4 sockets.

The gate order is: install package/system components; bind profiles in enforce mode; run fixture-only, route-closed live peer acceptance; remove fixtures; place Owner-provided production configuration/secrets; pass separate route-closed DB/application checks; then open the route under a separate Owner GO and verify external HTTPS. No repository change or CI pass authorizes host execution, secret placement, route opening, or production activation.

Ubuntu 26.04 compatibility is checked in an isolated CI runner with AppArmor parser compilation, systemd unit validation, and acceptance tooling tests. Host-side testing is outside this change. Package 1.0.7/helper 2.1.6 and all previously released files remain immutable.


## Pinned artifacts

| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_7.py` | `1d3f9fa36d6a71b1528038a1aab461cc37213ef584bad684a5e60db8d6cef0f3` |
| `scripts/deployment_backend_v2_1_7.py` | `5f5e6955c6b35a98d2dbc36c2cd0dd117bb2c17021977269066bb81dc1c54c24` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_7.py` | `f22f40c11b086ba4ab67f34b76a2ad7ae98e3c1e689bfb708625ba3517305e70` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| `scripts/production_deploy_controller_2_1_7.py` | `0e7f99db19563181b67fd4b7a2306ea0ea7a1894f10054c910b2dcff4dde5739` |
| `scripts/install_production_helper_2_1_7.py` | `f7993ee2bc7755fb0a1a46a7e66c1196dc729d095b20ec6de706e8ec98177f2b` |
| `helper artifact digest` | `bc689bbef1256b04aa7733febe7e08ff5549e3e0ddfea11d835525633f97fb6a` |
