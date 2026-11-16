# GO-13 Workstream B — PHP-FPM confinement (AppArmor + systemd), version 1.0.0

Status: **source preparation only.** This document and `deploy/php-fpm-confinement/1.0.0/` do not authorize any host change. Nothing is loaded, installed, restarted or reloaded by the repository, and no active service, profile, drop-in, Nginx file or helper is altered by this change. Every step below that touches a host needs its own exact-scope Owner GO after merge, independent QA and AppSec review, and a fresh read-only inventory.

## Why this exists

Production and the existing staging pools run PHP-FPM as the **same UID/GID, `www-data` (33)**. File mode and ownership therefore cannot keep one environment out of the other's files. The deploy tooling already makes the shared secrets group-readable by that GID (`shared/.env` mode `0640`, `shared/storage` mode `2770`, see `scripts/deployment_backend_v2.py`), so any `www-data` process that can open a path can read it. Confinement has to come from the kernel LSM and the service's mount namespace, selected per systemd unit.

**No new users, groups or FPM UID** are introduced. That was a hard requirement for this workstream.

## Artifacts (all under `deploy/php-fpm-confinement/1.0.0/`)

| Artifact | Install path (host) | Purpose |
| --- | --- | --- |
| `apparmor/abstractions/wpcaregrid-php-fpm-common` | `/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common` | Deny-only rules shared by every profile: `ptrace`, dangerous capabilities, `mount`, `dbus`, raw/packet sockets, cross-process `/proc` entries, control plane, deploy state, backups, DB data, credentials. A deny beats any allow. |
| `apparmor/wpcaregrid-php-fpm-production` | `/etc/apparmor.d/wpcaregrid-php-fpm-production` | **Allowlist** for the production pool. |
| `apparmor/wpcaregrid-php-fpm-staging-next` | `/etc/apparmor.d/wpcaregrid-php-fpm-staging-next` | **Allowlist** for the existing `php8.5-fpm.service` (staging-next). |
| `apparmor/wpcaregrid-php-fpm-staging-legacy` | `/etc/apparmor.d/wpcaregrid-php-fpm-staging-legacy` | **Deny-overlay** for the existing shared `php8.4-fpm.service` (legacy staging and sibling vhosts). See Limitations. |
| `systemd/wpcaregrid-production-php8.4-fpm.service` | `/etc/systemd/system/` | New dedicated production unit (name/socket per `deployment_environment_contract.py`), runs as `www-data`, no capabilities. |
| `systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf` | `/etc/systemd/system/php8.5-fpm.service.d/` | Drop-in selecting the staging-next profile. Touches no `User`, `Exec*`, socket or ordering. |
| `systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf` | `/etc/systemd/system/php8.4-fpm.service.d/` | Drop-in selecting the overlay. |
| `php-fpm/production/php-fpm.conf`, `pool.d/wpcaregrid-production.conf` | `/etc/php/8.4/fpm-wpcaregrid-production/` | Production-only FPM master and pool (own socket, `clear_env`, `open_basedir` as defense in depth only). |
| `acceptance/matrix.json`, `probe.py`, `derive-probe-profile.py` | not installed; run from the verified package | Single access matrix used by both the source model test and the live probe. |
| `SHA256SUMS` | with the package | Pins every file above. |

## Layers and what each one is for

1. **AppArmor profile per unit** (`AppArmorProfile=`, no attachment path). Path rules are evaluated on the *resolved* path, so a symlink does not widen access. This is the layer that separates the shared-UID pools.
2. **Mount namespace per unit** (`InaccessiblePaths=-…`, `ProtectSystem=strict` + `ReadWritePaths=`, `ProtectHome=yes`, `PrivateTmp=yes`). Independent of AppArmor: the other environment's trees are not present in the service's namespace, and `/tmp` is not shared between pools.
3. **Process visibility and tracing**: `deny ptrace,` in every profile (this also gates cross-process reads of `/proc/<pid>/{environ,mem,maps,fd,…}` and `process_vm_readv`), explicit denies for `cmdline`/`environ`/`fd`/`root`/`exe`, `ProtectProc=invisible`, `ProcSubset=pid`. `ProtectProc=invisible` hides only **other-UID** processes; every pool is `www-data`, so it adds nothing between pools and is not relied on for that.
4. **FPM pool hygiene** (production): own socket, `clear_env = yes`, no `env[]`, no TCP listener.

systemd 255 on Ubuntu 24.04 has no `PrivatePIDs`, so a private PID namespace is **not available** here. If a later host runs systemd 256 or newer, `PrivatePIDs=yes` is a stronger replacement for the `/proc` rules and should be reviewed as a new artifact version.

## What the source tests prove, and what they do not

Test: `python3 scripts/tests/php-fpm-confinement-test.py` (isolated; no host access, no loading, no network; real symlinks only inside a temporary directory).

Proven by source tests:

- The exact file set and `SHA256SUMS`.
- A small AppArmor path-rule model evaluates `acceptance/matrix.json` against each committed profile **in both directions** (production vs staging-next vs legacy): secrets (`.env`), config, storage read/write, release trees, deploy state and backups, control plane, sockets, exec. Deny beats allow; unlisted paths default to deny.
- Symlink escape using real symlinks in a temporary tree (absolute, relative `..`, chained, and via the release `.env`/`storage`/`vendor` links): the *resolved* path is denied, while the lexical path is shown to sit under an allowed prefix.
- `ptrace`, `/proc`, capability, mount, `dbus`, raw-socket and signal rules exist in every profile; signals cannot be sent across pools; foreign sockets are denied.
- Unit/drop-in directives are an exact reviewed set (any unknown directive is a finding), include no `+`/`!`/`-` prefix that would bypass confinement, no `ConditionSecurity` (fail-open), no `OpenFile`/`Sockets`/`BindPaths`, `FileDescriptorStoreMax=0`, `StandardInput=null`.
- Mutation tests: widening an allow, removing a deny, adding `ptrace`, bare `signal`, raw network, exec/write/link grants, capabilities, an unparseable rule, an unreviewed include, `User=root`, a `+` exec prefix, a fail-open `AppArmorProfile=-…`, and pool/unit/matrix edits each produce a finding.
- If `apparmor_parser` exists, it compiles every profile and every derived probe profile (`-Q -K`, no kernel load, no cache). CI requires it (`PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1`); a local run without it prints a skip and proves nothing about syntax.

**NOT PROVEN by source tests.** Source tests cannot prove kernel enforcement. The model is not the kernel. Specifically unproven until the live acceptance below passes:

- That the host kernel enforces AppArmor `ptrace`, `signal`, `unix` and file mediation for these profiles.
- The content of the external abstractions (`base`, `nameservice`, `ssl_certs`); the model cannot see them and treats them as unreviewed.
- Behavior of descriptors inherited across `execve` (next section).
- Mount-namespace behavior on the host (`InaccessiblePaths`, `ProtectSystem=strict`).
- That PHP 8.4/8.5 FPM and the packaged helpers actually run under these profiles (rules for `/proc`, `/run/systemd/notify`, helper exec are best-effort and verified only in complain mode).
- Any claim about TCP ports: **AppArmor 1.0.0 does not separate TCP ports.** All pools can reach `127.0.0.1` database ports; separation rests on distinct database credentials and users, not on this change.

If any of these cannot be shown, the correct outcome is that the rollout stays at the stage already reached. The artifacts fail closed: an unloaded profile makes systemd refuse to start the service, unknown access is denied, and the probe refuses to report success unless it ran in the enforced probe profile.

## Inherited descriptors

Path rules are evaluated when a file is **opened** (and when a descriptor is received over a socket). A descriptor that is already open keeps working if the path would now be denied, so a confined process that inherits an open descriptor to another environment's file can still read it. Assumption to verify, **never rely on exec-time revocation**: the kernel may revalidate or close descriptors that a new profile does not permit at an `execve` transition, but this repository cannot demonstrate it, and the behavior does not cover descriptors a process opens before a *reload* of a running service (a profile load does not change an already-running process; only a restart applies it).

Design consequences, enforced by the source tests:

- The units pass **no** extra descriptors: `StandardInput=null`, no `Sockets=`/`ListenStream=`/`OpenFile=`, `FileDescriptorStoreMax=0`, no `PassFileDescriptorsToExec`, no credentials.
- The profile is selected by systemd before the first `execve`, so the FPM master and every worker start confined; no unconfined parent opens files first.
- Live acceptance (below) lists `/proc/<pid>/fd` of the master and every worker and requires only the expected descriptors, and records what the kernel does with `StandardInput=file:` pointing at a denied file in the probe profile. Whatever it shows is recorded; the design does not depend on it.

## Contract binding (source policy for helper 2.1.3 preflight)

`scripts/deployment_environment_contract_2_1_3.py` (tests: `scripts/tests/deployment-environment-contract-2-1-3-test.py`) compiles in the exact SHA-256 of every installed confinement file (`CONFINEMENT_FILE_SHA256`, nine paths), the SHA-256 of `SHA256SUMS` (`CONFINEMENT_SUMS_SHA256`) and the three service bindings (`CONFINEMENT_SERVICES`). A test recomputes them from `deploy/php-fpm-confinement/1.0.0/` and fails on drift; changing any artifact requires a new package version and a new contract version.

1. **Policy check (`resolve_environment("production", production_config=…)`).** Production policy `schema` is `2` and adds exactly one key, `php_fpm_confinement`, that must echo the compiled values. Fields: `schema` (1), `package_version`, `package_sha256sums_sha256`, `files` (installed absolute path → SHA-256, exact nine-path set), `services` (`production`, `staging-next`, `staging-legacy`; each exactly `unit`, `apparmor_profile`, `profile_path`, `attachment_path`). `php_fpm_uid`/`php_fpm_gid` must be 33/33. Stable refusals: `production_confinement_missing`, `…_schema_invalid`, `…_package_mismatch`, `…_file_set_invalid`, `…_hash_mismatch`, `…_service_binding_invalid`, `production_php_fpm_identity_must_be_shared_www_data`, `production_profile_schema_invalid` (schema 1), `production_php_version_invalid` (non-8.4). `resolve_environment("staging-next")` also carries compiled confinement requiring roles `staging-next` and `staging-legacy`.
2. **Observation check (`verify_confinement_observation(contract, observation)`).** The helper preflight collects, as root, `{schema: 1, files: {path: {sha256, uid, gid, mode, type, nlink}}, services: {role: {unit, active, apparmor_profile_property, loaded_profile_mode, process_labels}}}` and the function refuses unless every file of each required role is a regular, single-link, `root:root` `0644` file with the compiled hash, the unit's effective `AppArmorProfile=` equals the bound profile, the loaded mode is `enforce`, and (when active) every master/worker label is `<profile> (enforce)`. An unmanaged service (empty or other `AppArmorProfile=`, missing role, unconfined or complain labels) is refused with `confinement_service_unattached`, `…_service_unobserved`, `…_service_profile_mismatch`, `…_profile_not_enforced`, `…_process_labels_missing` or `…_process_not_confined`; file problems with `confinement_file_missing`, `…_hash_mismatch`, `…_not_root_owned`, `…_mode_invalid`, `…_not_regular`.

A pass of either check is **source policy and recorded observation only**. It is not evidence that a kernel enforces the profile; staged cutover and the live acceptance below remain separate host gates, and the helper must not turn a pass into an isolation claim without them. Nothing in this contract applies a profile or starts a service.

## Known conflicts and limitations (surface before any GO)

1. **Production identity contract (resolved in source by the 2.1.3 successor contract).** The original `scripts/deployment_environment_contract.py` requires a dedicated `php_fpm_uid`/`php_fpm_gid` and refuses `www-data` with `production_unix_identity_not_isolated`. That file is pinned by seven release manifests and is left byte-identical. The successor `scripts/deployment_environment_contract_2_1_3.py` keeps `www-data` 33/33 (required, not merely allowed: any other value is `production_php_fpm_identity_must_be_shared_www_data`) and binds isolation to the exact confinement artifacts instead. It is **not yet wired to any helper**: helper 2.1.3 preflight must load it and call both functions below. See "Contract binding".
2. **The legacy overlay is weaker by design.** `php8.4-fpm.service` serves legacy staging and sibling vhosts whose paths are not enumerated in this repository, so it keeps its existing access and only gains denials. It closes the *unconfined → production* direction but is not an allowlist. A real allowlist for it needs the sibling inventory first.
3. **`/run/php` is one shared directory** (the production unit needs write access to create its socket). AppArmor denies the production profile access to other sockets; a systemd-only second layer cannot.
4. **Signals from unconfined `www-data` processes** (systemd itself, any unconfined sibling) are accepted so stop/reload works; availability, not secrecy, is affected.
5. **PHP 8.4 only for production, PHP 8.5 only for staging-next.** A different PHP version is a new artifact version (executable path, socket name and unit name are pinned).
6. **The packaged `php8.5-fpm.service` is not in the repository.** The profile grants `php-fpm-socket-helper` and `dash` only. If the host unit runs other helpers (for example `update-alternatives`), enforce mode fails closed and the service does not start. Record `systemctl cat php8.5-fpm.service` first; do not widen the profile without reviewed complain-mode evidence.
7. `ProcSubset=pid` and the `/proc` denies may be stricter than PHP needs. Treat any new denial as a stop, not as a reason to loosen rules.
8. The `RestrictAddressFamilies`/capability set is minimal and untested on a host.

## Preconditions (all must hold; any failure means STOP)

1. Exact reviewed merge SHA; independent QA and AppArmor/systemd review of this directory; required CI green including the real-parser run.
2. Fresh sanitized read-only host inventory: `apparmor_parser --version`, `aa-status --enabled`, `/sys/kernel/security/apparmor/features/{ptrace,signal,network_v8}` or `network`, kernel version, systemd version, `/proc/sys/kernel/yama/ptrace_scope` (expect `1` or higher), `systemctl cat php8.4-fpm.service php8.5-fpm.service`, current `/proc/<pid>/attr/current` of all FPM processes (expect `unconfined`), `getfacl`/`stat` of the shared `.env` and storage, and an inventory of every Nginx vhost using `/run/php/php8.4-fpm.sock`.
3. Owner decision on conflict 1 (production identity contract) recorded.
4. Fresh verified backups and the standard staging maintenance window for any restart of a serving service.

## Installation and staged cutover (each numbered stage needs its own Owner GO)

Run only as the approved root console, from a verified copy of the package. Never copy by hand; verify first:

```bash
cd "$PKG"            # root:root 0700 copy of deploy/php-fpm-confinement/1.0.0 from the reviewed SHA
sha256sum -c --strict SHA256SUMS
```

Order is deliberate: the new production unit cannot regress anything, staging-next is next, the shared legacy pool last.

**Stage 0 — install files and syntax-check (no load, no restart).** Install with no-overwrite semantics, `root:root 0644`, to the paths in the table. Refuse if a target already exists with different content. Then `apparmor_parser -Q -K -I /etc/apparmor.d <profile>` for each profile. Nothing is loaded by this step and no unit selects a profile, so no service changes. Note that files in `/etc/apparmor.d/` are loaded in enforce mode by the next `apparmor.service` start or reload (for example at boot); this is harmless until a unit names the profile, and Stage 0 must record that fact. `systemctl daemon-reload` is not run yet.

**Stage 1 — production unit, unrouted (no traffic).** With separate GO: load the production profile in **complain** mode (`apparmor_parser -r -C /etc/apparmor.d/wpcaregrid-php-fpm-production`), `systemctl daemon-reload`, start `wpcaregrid-production-php8.4-fpm.service`, exercise it with a local FastCGI request to its socket only (Nginx is not pointed at it), then review `journalctl -k` for `apparmor="ALLOWED"` records. Every ALLOWED record is a missing rule or evidence a rule is unnecessary: review each; add rules only through a new reviewed artifact version. When complain records are clean, reload in enforce mode (`apparmor_parser -r …`) and restart the unit.

**Stage 2 — acceptance probe on production profile** (below). Only after it passes may the production Nginx route be changed, in a separate GO that also covers Nginx.

**Stage 3 — staging-next.** In the staging window with the maintenance gate and workers fenced as the staging procedure requires: record the baseline unit text, load `wpcaregrid-php-fpm-staging-next` in complain mode, install the drop-in, `daemon-reload`, restart `php8.5-fpm.service`, smoke test, review ALLOWED records, then enforce, restart, smoke test, run the probe. If the unit fails to start or the smoke test fails: rollback immediately (below).

**Stage 4 — legacy shared pool.** Soak in complain mode for at least one full business day of sibling-vhost traffic before enforce. Any ALLOWED/denied record that is not a deliberate protected-tree access is a STOP. Restart is required to apply (a reload of `php8.4-fpm.service` does not change process labels).

Never combine stages in one GO. Do not restart any serving FPM service outside its approved window.

## Live acceptance (required; kernel enforcement is shown here, not in source tests)

Evidence per stage. Record outputs sanitized (no secret values; paths and result codes only).

1. **Attribution.** For the master and every worker: `cat /proc/<pid>/attr/current` equals `<profile> (enforce)`. Any `unconfined` or `(complain)` process is a failure.
2. **Fail closed.** With the profile unloaded (`apparmor_parser -R`), `systemctl start` of the **production unit** must fail; then reload the profile. (Not performed on a serving pool.)
3. **Mount namespace.** `nsenter -t <MainPID> -m -- ls <other environment path>` shows the path is absent or inaccessible. Separate from AppArmor.
4. **Descriptor inventory.** `ls -l /proc/<pid>/fd` for master and workers lists only expected targets (null, journal, own socket, own log, own storage files). Anything else is a failure.
5. **Probe** (bidirectional secrets/config/storage, symlink escape, sockets, `/proc`, ptrace, signals). Derive and run:

   ```bash
   python3 "$PKG/acceptance/derive-probe-profile.py" < /etc/apparmor.d/wpcaregrid-php-fpm-production > /run/wpcg-probe-profile
   apparmor_parser -r /run/wpcg-probe-profile      # name: wpcaregrid-php-fpm-production-probe (enforce)
   # <peer-pid>: a www-data WORKER of a different pool (never a root master)
   systemd-run --wait --collect --pipe -p User=www-data -p Group=www-data \
     -p AppArmorProfile=wpcaregrid-php-fpm-production-probe -p PrivateTmp=yes -p NoNewPrivileges=yes \
     -p StandardInput=null /usr/bin/python3 -I -c "$(cat "$PKG/acceptance/probe.py")" \
     "$(cat "$PKG/acceptance/matrix.json")" wpcaregrid-php-fpm-production <peer-pid> ...
   apparmor_parser -R /run/wpcg-probe-profile; rm -f /run/wpcg-probe-profile
   ```

   The probe profile is the real profile plus only python exec/library reads and `attr/current`; every file, ptrace, signal and socket rule is identical. The probe refuses (exit 2) if it is not inside the enforced probe profile, exits 1 on any denied access that succeeds, any allowed access that fails, or any inconclusive check (a deny target that does not exist), and prints `OK` only if every check is conclusive. Create the paths a deny check needs, or STOP. Repeat for `wpcaregrid-php-fpm-staging-next` and `wpcaregrid-php-fpm-staging-legacy`. The probe creates and removes `wpcg-probe` files in storage and `/tmp` only.
6. **Inherited descriptors.** In the probe profile, run once with `-p StandardInput=file:<a denied path on a test host or disposable copy>` and record whether a read of fd 0 succeeds; this documents the kernel behavior only. Do not use a real secret.
7. **Kernel features.** Record the feature files from Preconditions item 2; if `ptrace` or `signal` mediation is absent, the ptrace/signal claims are void and the stage stops.
8. **Audit review.** `journalctl -k | grep apparmor` shows `DENIED` records only for deliberate probe attempts; no `DENIED` for the service's normal request path.
9. **Application smoke.** Existing deployment smoke (`/up`, `/hu`, `/en`, protected-route expectations) and Laravel `system:check` equivalent for the stage's environment unchanged from the recorded baseline.

## Rollback

Rollback for each stage uses only reversible, hash-checked operations and does not touch databases or application files.

- **Production unit (stages 1–2):** `systemctl stop wpcaregrid-production-php8.4-fpm.service`; `systemctl disable` if enabled; remove the unit file; `apparmor_parser -R` for the profile; remove the profile file; `systemctl daemon-reload`. Nginx must not reference the production socket before this step; if it does, restore the previous Nginx file by the same recorded hash first.
- **staging-next (stage 3):** remove `php8.5-fpm.service.d/90-wpcaregrid-confinement.conf`, `systemctl daemon-reload`, restart `php8.5-fpm.service`, confirm `/proc/<pid>/attr/current` is `unconfined`, smoke test, then `apparmor_parser -R` and remove the profile and abstraction (if no other profile uses it). Removing a profile file or unloading it does **not** change a running process; the restart is what reverts it.
- **Legacy pool (stage 4):** same sequence for `php8.4-fpm.service.d`. Sibling vhost health must be re-verified.
- **Failed install (stage 0):** delete only files whose SHA-256 matches `SHA256SUMS`; any non-matching file is left untouched and reported.

Stop conditions during rollback: a file or label that differs from the recorded baseline, a unit that will not restart, or a failed smoke test. In each case keep the maintenance gate, do not retry blindly, and escalate with the recorded evidence.

## What the next engineer must know

- Installed-profile changes always require a new artifact version directory (`1.0.1`, …), a new `SHA256SUMS`, a new test run and a new GO. Do not edit `1.0.0` in place after review.
- `acceptance/matrix.json` is the single source for both the model test and the live probe; update it with every access change.
- `scripts/tests/php-fpm-confinement-test.py` is wired into CI; the real-parser compile runs there with `PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1`.
- Related: `docs/PRODUCTION_MARKETING_RELEASE_RUNBOOK.md` (production identity inputs), `docs/PHP85_STAGING_MIGRATION.md` (staging FPM), `docs/PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md` (precedent: parser-validated, source-only AppArmor change; do not widen on abbreviated audit descriptions).
