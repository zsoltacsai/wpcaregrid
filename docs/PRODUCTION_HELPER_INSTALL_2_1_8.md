# Production helper 2.1.8 and confinement package 1.0.9

Helper 2.1.8 is an immutable successor bound to production-only confinement package 1.0.9. It recognizes only production FPM and Nginx as required service roles; production build/deploy CLI processes remain short-lived transient classes. It does not require staging-next FPM, staging schedulers, supervisor, staging jobs, or demo vhosts. The generic `php8.5-fpm.service` is inventoried as a forbidden sentinel and any process there fails acceptance.

Package 1.0.9 keeps the shared `www-data` UID/GID 33 model and Invariant I. No production group-readable secret or route may exist before every actual UID/GID/supplementary-GID 33 peer has passed enforce-profile binding and live acceptance. Unknown, unconfined, missing, unreadable, or unstable peers fail closed. Production jobs stay fenced. PHP 8.4 denials remain defense in depth; this repository change does not install, stop, or alter PHP 8.4 or other host services.

The Nginx profile is part of the production security domain and is restricted to the production vhost, production public release tree, production FastCGI socket, and `wpcaregrid.com` TLS material. It denies staging/demo web roots, unrelated TLS domains, generic FPM sockets, and PHP 8.4 sockets.

The gate order is: install package/system components; bind profiles in enforce mode; run fixture-only, route-closed live peer acceptance before installing the helper that provisions root-private control directories; remove fixtures; install/verify the helper and place Owner-provided production configuration/secrets; pass separate route-closed DB/application checks; then open the route under a separate Owner GO and verify external HTTPS. No repository change or CI pass authorizes host execution, secret placement, route opening, or production activation.

Ubuntu 26.04 compatibility is checked in an isolated CI runner with AppArmor parser compilation, systemd unit validation, and acceptance tooling tests. Host-side testing is outside this change. Package 1.0.7/helper 2.1.6 and all previously released files remain immutable.


## Pinned artifacts

| Artifact | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv-2_1_8.py` | `d5e1dc150bfd95d5a2a0686e32a1031c5e2bbf0c24e6cf365932af66ce51e78d` |
| `scripts/deployment_backend_v2_1_8.py` | `643c2c0cdc773914719ab4fe9538d310307deac4902064d6419e54c1f8b7d7a1` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract_2_1_8.py` | `4286f45740307bf96a0d08c12a379fc8ade8ddd39db006bfa4690d95e00146d4` |
| `scripts/release_review_policy_v3_1.py` | `5559217003066b77fb2533c8a113fa6d19c497924add59e1db3e023641143d6f` |
| `scripts/production_deploy_controller_2_1_8.py` | `b2dd8c26e36cb9d41c2da488678ed9a5117169401f2c93bc46bbc853755eedb0` |
| `scripts/install_production_helper_2_1_8.py` | `b8629c113accc5275783b82f2f54bd594b474de9575db73d6a440afe5ca917ce` |
| `helper artifact digest` | `37a8251a24116454d5bed680a05913ea578c5f35fc66dd9769eae7b1fbdf1611` |
