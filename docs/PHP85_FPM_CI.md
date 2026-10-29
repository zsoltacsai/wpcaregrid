# Isolated PHP 8.5 FPM compatibility lane

This lane adds PHP-FPM-path compatibility evidence to the existing PHP 8.5 CLI/MySQL and Connector matrices. It is **not** staging or production readiness evidence.

## Runtime identity

- PHP Official Image: `php:8.5.12-fpm`, pinned in the test Dockerfile to the immutable multi-platform index digest `sha256:8b4b4a764f7985137b13e4cb49dd59e967d9daa7946a731f9d06e31e748e39bb`; CI selects `linux/amd64`.
- Official image tag and digest: [Docker Hub PHP 8.5.12 FPM image](https://hub.docker.com/_/php/tags?name=8.5.12-fpm).
- Runtime output records the exact PHP patch, FPM SAPI, effective loaded extension names, OPcache state, built image ID, and SHA-256 of `composer.lock` in the Actions step summary.
- The FPM image compiles the application extension set used by the server matrix. The FPM request itself verifies that PHP 8.5.12 is running and extensions are loaded.
- The build context is assembled from explicit application directories and `vendor/`; it excludes `.env`, `.git`, `.github`, runner credentials and unrelated workspace files.

## Isolation contract

The ephemeral hosted runner builds the image before the sealed test phase. Dependency installation and apt package retrieval occur only during this build phase. The application test container then runs with `--network none`, all Linux capabilities dropped, `no-new-privileges`, read-only root filesystem, private PID namespace, bounded memory/process count, and only tmpfs write locations. It has no host bind mounts, published ports, Docker socket, runner token, SSH key, production credential or customer data. FPM and the HTTP gateway run as UID/GID 33; FPM's `clear_env` is enabled and its pool receives an explicit allowlist.

The only app database is a fresh SQLite database created in the container's tmpfs by migrations. `QUEUE_CONNECTION=database` leaves queued jobs durable but no worker is started. Mail uses the array sink, filesystem is local, Stripe live mode is false with no credentials, the release profile remains marketing-only, and the test probe asserts these values. Cron, Laravel scheduler, Supervisor, Connector polling, billing, invoicing, notifications, backup transfer and restore are not started.

A loopback-only Nginx test gateway sends an HTTP request over a Unix FastCGI socket to the actual PHP-FPM process. A separate non-public probe exercises Laravel bootstrap and a SQLite query through FPM. It reports only sanitized versions, extension names, booleans and row counts.

## Network denial evidence

Before sealing, the runner starts controlled HTTP witnesses on its IPv4 and IPv6 loopback and verifies they are reachable from the runner shell. The PHP-FPM request then attempts those same witness addresses from its separate `--network none` namespace and must fail. The FPM request also requires external DNS resolution (after runner-side DNS control), explicit HTTP/HTTPS proxy routing, cloud metadata, host service, staging hostname, and production hostname probes to fail. The host-service, staging, and production names are mapped inside the test container to its loopback address and tested against the same controlled runner witness port; the witness is confirmed reachable from the runner before the sandbox starts. No request is sent to a live site. Any successful probe fails CI. No privileged network capability is granted to the FPM container.

This proves the sandbox's tested egress boundary for this workflow run. It does not claim that all CI runners, host networks, or live services share the same boundary.

## Side-effect and failure behavior

The policy test rejects mutations enabling inline `sync` queues, external mail/storage, Stripe live mode, scheduler, workers, Connector polling or backup operations. The live FPM probe checks effective Laravel configuration, denies inherited runner/proxy credential variables, verifies the Docker socket is absent, and confirms that no queue/scheduler/Supervisor/Connector consumer runs. Startup/config errors, warnings, fatal errors, failed egress denial, unexpected listener, probe timeout, or non-graceful process shutdown fail the lane.

The additional CI job does not change ruleset #25300175 or its existing seven required contexts. All existing PHP 8.4/8.5 server suites, both MySQL process-concurrency executions, SQLite regression gate and full Connector matrix remain in place.

## Limits

Passing this lane establishes only source/dependency compatibility through a disposable PHP 8.5 FPM/FastCGI path with synthetic data and disabled external effects. It does not establish staging PHP-FPM configuration, Nginx integration, runtime package provenance on a host, controller/helper readiness, queue quiescence, Connector completion, backup safety, rollback readiness, deployment authorization, or production readiness. PHP 8.5 installation and cutover on existing staging remain NO-GO.
