# GO-13 Workstream B — PHP-FPM confinement (AppArmor + systemd), version 1.0.1

Status: **source preparation only.** This document and `deploy/php-fpm-confinement/1.0.1/` do not authorize any host change. Nothing is loaded, installed, restarted or reloaded by the repository, and no active service, profile, drop-in, Nginx file or helper is altered by this change. Every host step below needs its own exact-scope Owner GO after merge, independent QA and AppSec review, and a fresh read-only inventory. Version 1.0.0 was never installed anywhere and is superseded by 1.0.1 (a new directory so any consumer pinned to 1.0.0 hashes fails closed).

## Why this exists

Production and the existing staging pools run PHP-FPM as the **same UID/GID, `www-data` (33)**. File mode and ownership therefore cannot keep one environment out of the other's files. The deploy tooling already makes shared secrets group-readable by that GID (`shared/.env` mode `0640`, `shared/storage` mode `2770`, see `scripts/deployment_backend_v2.py`), so any `www-data` process that can open a path can read it. Confinement has to come from the kernel LSM and the service's mount namespace, selected per systemd unit.

**No new users, groups or FPM UID** are introduced. That is a hard requirement of GO-13.

## The invariant that orders every stage

> **Invariant I.** Production group-readable secrets must not exist, and no production traffic route may be opened, until **every same-UID (`www-data`) peer is enforce-attached to a confinement profile and live-accepted**. Rollback must fence production first so the invariant still holds after any peer is restored to unconfined.

"Peer" means every process whose real or effective UID is 33, not only the three FPM services. `acceptance/collect-peers.py` enumerates all of them; `verify-acceptance.py` refuses unless the enumeration is complete and every process carries one of the three profile labels in enforce mode. The set of processes allowed to stay unconfined is **empty** (`ACCEPTED_UNCONFINED_SAME_UID_COMM`). That has consequences surfaced in Known conflicts: nginx workers, Supervisor queue workers and the scheduler also run as `www-data` and are not covered by any 1.0.1 profile.

## Artifacts (all under `deploy/php-fpm-confinement/1.0.1/`)

| Artifact | Active path on host (installed only at its stage) | Purpose |
| --- | --- | --- |
| `apparmor/abstractions/wpcaregrid-php-fpm-common` | `/etc/apparmor.d/abstractions/` | Deny-only rules shared by every profile: audited `ptrace`, dangerous capabilities, `mount`, `dbus`, raw/packet sockets, **abstract-namespace unix sockets**, cross-process `/proc` entries, control plane, deploy state, backups, DB data, credentials. A deny beats any allow. Rules are `audit deny` so each denial leaves a kernel record the acceptance run correlates. |
| `apparmor/wpcaregrid-php-fpm-production` | `/etc/apparmor.d/` | **Allowlist** for the production pool. |
| `apparmor/wpcaregrid-php-fpm-staging-next` | `/etc/apparmor.d/` | **Allowlist** for the existing `php8.5-fpm.service`. |
| `apparmor/wpcaregrid-php-fpm-staging-legacy` | `/etc/apparmor.d/` | **Deny-overlay** for the existing shared `php8.4-fpm.service`. See Known conflicts. |
| `systemd/wpcaregrid-production-php8.4-fpm.service` | `/etc/systemd/system/` | New dedicated production unit, runs as `www-data`, no capabilities. |
| `systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf` | `/etc/systemd/system/php8.5-fpm.service.d/` | Selects the staging-next profile. Touches no `User`, `Exec*`, socket or ordering. |
| `systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf` | `/etc/systemd/system/php8.4-fpm.service.d/` | Selects the overlay. |
| `php-fpm/production/php-fpm.conf`, `pool.d/wpcaregrid-production.conf` | `/etc/php/8.4/fpm-wpcaregrid-production/` | Production-only FPM master and pool. |
| `acceptance/matrix.json` | not installed | Single access matrix for the model test and the live probe; also the fixture manifest. |
| `acceptance/fixtures.py` | not installed | Creates/removes **non-secret canary fixtures** with DAC-equivalent ownership and modes. |
| `acceptance/probe.py`, `derive-probe-profile.py`, `fd-sender.py` | not installed | Live probe (baseline and confined modes), probe-profile derivation, unconfined descriptor sender. |
| `acceptance/collect-peers.py`, `verify-acceptance.py` | not installed | Root-side read-only peer inventory and the offline acceptance verdict. |
| `SHA256SUMS` | with the package | Pins every file above. |

## Layers and what each one is for

1. **AppArmor profile per unit** (`AppArmorProfile=`, no attachment path). Path rules apply to the *resolved* path, so a symlink does not widen access. This separates the shared-UID pools.
2. **Mount namespace per unit** (`InaccessiblePaths=-…`, `ProtectSystem=strict` + `ReadWritePaths=`, `ProtectHome=yes`, `PrivateTmp=yes`). Independent of AppArmor; checked separately from it.
3. **Process visibility and tracing**: `audit deny ptrace,` in every profile (also gates cross-process reads of `/proc/<pid>/{environ,mem,maps,fd,…}` and `process_vm_readv`), explicit denies for `cmdline`/`environ`/`fd`/`root`/`exe`, `ProtectProc=invisible`, `ProcSubset=pid`. `ProtectProc=invisible` hides only **other-UID** processes; every pool is `www-data`, so it adds nothing between pools and is not relied on for that. systemd 255 on Ubuntu 24.04 has no `PrivatePIDs`; on systemd 256 or newer it is a stronger replacement and would be a new artifact version.
4. **Abstract unix sockets** are denied (connect/send/receive/bind/listen/accept) because path rules and `InaccessiblePaths=` cannot see them and systemd has no per-unit switch short of a private network namespace, which the database connection forbids.
5. **FPM pool hygiene** (production): own socket, `clear_env`, no `env[]`, no TCP listener.

## What the source tests prove, and what they do not

Tests: `python3 scripts/tests/php-fpm-confinement-test.py` and `python3 scripts/tests/deployment-environment-contract-2-1-3-test.py` (isolated; no host access, no profile loading; real symlinks only inside temporary directories; the fixture tool runs in a temporary root).

Proven by source tests:

- Exact file set and `SHA256SUMS`; the compiled hashes in the 2.1.3 contract equal the committed artifacts.
- An AppArmor path-rule model evaluates `acceptance/matrix.json` against each profile **in both directions** (secrets, config, storage, code, state, backups, control plane, sockets, exec). Deny beats allow; unlisted paths default to deny. Symlink escapes (absolute, relative, chained) are denied on the resolved path.
- `ptrace`, `/proc`, capability, mount, `dbus`, raw-socket, abstract-socket and signal rules exist in every profile; signals cannot cross pools.
- A guard compares each matrix check under the **original** profile and the **derived probe** profile: any check the two answer differently (the probe profile grants python exec) must carry `probe_scope: original_profile`, so a probe-only grant can never masquerade as a tested denial (`exec-python` is tested against the original profile, see acceptance step 7).
- The acceptance tooling itself: the probe emits exactly the result ids the verifier requires; the verifier refuses on any mutation (errno without audit, wrong audit profile/pid/operation/target, masked baseline, confined success, unconfined or missing same-UID peers, incomplete inventory, pid reuse, foreign descriptors, uncalibrated ptrace, descriptor-transfer bypass, unexplained denials); the fixture tool refuses real data and never deletes changed files; the collector is read-only and fails closed on unreadable processes.
- Unit and drop-in directives are an exact reviewed set; no `+`/`!`/`-` exec prefix, no `ConditionSecurity`, no `OpenFile`/`Sockets`/`BindPaths`, `FileDescriptorStoreMax=0`, `StandardInput=null`.
- Mutation tests: widening an allow, removing a deny, adding `ptrace`, bare `signal`, raw network, exec/write/link grants, capabilities, an unparseable rule, an unreviewed include, `User=root`, a fail-open `AppArmorProfile=-…`, and pool/unit/matrix edits each produce a finding.
- If `apparmor_parser` exists it compiles every profile and every derived probe profile (`-Q -K`, no kernel load, no cache). CI requires it (`PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1`); a local run without it proves nothing about syntax.

**NOT PROVEN by source tests.** Source tests cannot prove kernel enforcement. The model is not the kernel, and the verifier tests run on synthetic evidence. Unproven until the live acceptance below passes on the host:

- That the host kernel enforces AppArmor `ptrace`, `signal`, `unix` (including abstract addresses and `file_receive`) and file mediation for these profiles, and writes the audit records the verifier requires. Record formats are matched generically and have not been observed on a real kernel.
- The content of the external abstractions (`base`, `nameservice`, `ssl_certs`).
- What happens to descriptors inherited across `execve` (see Inherited descriptors).
- Mount-namespace behavior on the host.
- That FPM and the packaged helpers run under these profiles.
- Anything about TCP ports: **AppArmor 1.0.1 does not separate TCP ports.** All pools can reach `127.0.0.1` database ports; separation rests on distinct database credentials and users.

The artifacts fail closed: an unloaded profile makes systemd refuse to start the service, unknown access is denied, the probe refuses unless it runs in the expected label, and the verifier refuses unless baseline, audit and peer enumeration all agree.

## Contract binding (source policy for helper 2.1.3 preflight)

`scripts/deployment_environment_contract_2_1_3.py` (tests: `scripts/tests/deployment-environment-contract-2-1-3-test.py`) is the successor to `scripts/deployment_environment_contract.py`, which stays byte-identical because seven release manifests pin its hash. The original refuses shared `www-data` with `production_unix_identity_not_isolated` and requires a dedicated `php_fpm_uid`/`php_fpm_gid`; the successor keeps `www-data` 33/33 (required: any other value is `production_php_fpm_identity_must_be_shared_www_data`) and binds isolation to the exact confinement artifacts. **Helper 2.1.3 must load the successor and call both functions below in preflight, failing closed. The contract check is not sufficient on its own:** it validates declared hashes and recorded observations, it cannot create or prove the live evidence, and no pass of it replaces the staged cutover and live acceptance below.

1. **Policy check (`resolve_environment("production", production_config=…)`).** Production policy `schema` is `2` and adds exactly one key, `php_fpm_confinement`, that must echo the compiled values: `schema` (1), `package_version` (`1.0.1`), `package_sha256sums_sha256`, `files` (installed absolute path → SHA-256, exact nine-path set) and `services` (`production`, `staging-next`, `staging-legacy`, each exactly `unit`, `apparmor_profile`, `profile_path`, `attachment_path`). Stable refusals: `production_confinement_missing`, `…_schema_invalid`, `…_package_mismatch`, `…_file_set_invalid`, `…_hash_mismatch`, `…_service_binding_invalid`, `production_php_fpm_identity_must_be_shared_www_data`, `production_profile_schema_invalid` (schema 1), `production_php_version_invalid` (non-8.4). `resolve_environment("staging-next")` carries the compiled confinement requiring roles `staging-next` and `staging-legacy`.
2. **Observation check (`verify_confinement_observation(contract, observation)`).** The helper preflight collects as root `{schema: 1, files: {path: {sha256, uid, gid, mode, type, nlink}}, services: {role: {unit, active, apparmor_profile_property, loaded_profile_mode, process_labels}}, same_uid_peers: {complete, processes: [{pid, comm, label, unit}]}}`. It refuses unless every file of each required role is a regular, single-link, `root:root` `0644` file with the compiled hash, the unit's effective `AppArmorProfile=` equals the bound profile, the loaded mode is `enforce`, every master/worker label is `<profile> (enforce)`, **the same-UID inventory is complete and contains no unconfined or complain-mode peer**, and every active role has at least one identified peer process. Refusals include `confinement_service_unattached`, `…_service_profile_mismatch`, `…_profile_not_enforced`, `…_process_not_confined`, `confinement_peer_inventory_incomplete`, `confinement_same_uid_peer_unconfined`, `confinement_peer_label_outside_unit` and the file codes (`confinement_file_missing`, `…_hash_mismatch`, `…_not_root_owned`, `…_mode_invalid`, `…_not_regular`). An unmanaged staging service is never treated as isolated.

## Known conflicts and limitations (surface before any GO)

1. **Non-FPM same-UID peers are not covered (blocker for Invariant I).** nginx workers, Supervisor queue workers (`wpcaregrid-staging-next-*`), the scheduler wrapper's CLI PHP and any `www-data` cron job run as UID 33 and execute staging or edge code unconfined. No 1.0.1 profile covers them, and `ACCEPTED_UNCONFINED_SAME_UID_COMM` is deliberately empty, so the inventory gate **fails on a real host today**. Resolving it needs an Owner decision per class: a reviewed CLI/worker profile (new artifact version), keeping the workers fenced and uninstalled while production secrets exist, or an explicit, recorded acceptance of a named class in a reviewed contract revision. Do not edit the empty set to make a run pass.
2. **The legacy overlay is weaker by design.** `php8.4-fpm.service` serves legacy staging and sibling vhosts whose paths are not enumerated in this repository, so it keeps its existing access and only gains denials. A real allowlist needs the sibling inventory first.
3. **`/run/php` is one shared directory.** AppArmor denies the production profile access to other sockets; the systemd layer cannot.
4. **Signals from unconfined `www-data` processes** (systemd itself) are accepted so stop/reload works; availability, not secrecy, is affected.
5. **PHP 8.4 only for production, PHP 8.5 only for staging-next.** A different version is a new artifact version.
6. **The packaged `php8.5-fpm.service` is not in the repository.** The profile grants `php-fpm-socket-helper` and `dash` only. If the host unit runs other helpers (for example `update-alternatives`), enforce mode fails closed and the service does not start. Record `systemctl cat php8.5-fpm.service` first; do not widen the profile without reviewed complain-mode evidence.
7. `ProcSubset=pid` and the `/proc` denies may be stricter than PHP needs; treat a new denial as a stop, not a reason to loosen rules.
8. **Some matrix checks are model-only** (`live: false`): root-owned write targets that DAC and read-only mounts also refuse, `/root`, pid-1 `/proc` entries and the like cannot show an AppArmor-specific denial, and are never presented as live evidence. Control-plane/home/shadow/MySQL fixtures are `optional`: if the real parent directory is not traversable by `www-data`, the check is reported **UNPROVEN**, never PASS, and confined success there still fails.
9. **Real staging paths are opened, never read.** Deny targets in the live staging trees use the real files (only `open()`); only exactly-canary-sized fixture files are ever read.

## Preconditions (all must hold; any failure means STOP)

1. Exact reviewed merge SHA; independent QA and AppArmor/systemd review; required CI green including the real-parser run.
2. Fresh sanitized read-only host inventory: `apparmor_parser --version`, `aa-status --enabled`, `/sys/kernel/security/apparmor/features/` (ptrace, signal, network/unix), kernel and systemd versions, `/proc/sys/kernel/yama/ptrace_scope`, `systemctl cat php8.4-fpm.service php8.5-fpm.service`, `systemctl show -p DropInPaths php8.4-fpm.service php8.5-fpm.service`, and the output of `acceptance/collect-peers.py` (expect unconfined peers: it records the baseline of Known conflict 1).
3. **Production secrets do not exist yet.** `/var/lib/wpcaregrid-deploy/production/shared/.env` and `shared/storage` are absent or are exactly the fixture canary. If real production secrets already exist while any peer is unconfined, Invariant I is already violated: STOP and fence production (R1–R2 below) before anything else.
4. Owner decisions on Known conflict 1 recorded.
5. Fresh verified backups and the staging maintenance window for any restart of a serving service.

## Installation and staged cutover (each numbered stage needs its own Owner GO)

Order is dictated by Invariant I: **both staging peers are confined and live-accepted before production secrets or a production route can exist.** Run only as the approved root console from a verified copy of the package:

```bash
cd "$PKG"            # root:root 0700 copy of deploy/php-fpm-confinement/1.0.1 from the reviewed SHA
sha256sum -c --strict SHA256SUMS
```

**Stage 0 — verify and stage the package (no unit search path, no AppArmor path, no load).** Copy the verified package to `/usr/local/libexec/wpcaregrid-php-fpm-confinement/1.0.1/` (`root:root 0700`), which is neither a systemd unit/drop-in search path nor an AppArmor profile directory. Stage 0 does not install any profile, unit, drop-in, FPM config or fixture, and does not run `daemon-reload`. Record the Preconditions inventory. Nothing about any service changes.

**Stage 1 — staging-legacy overlay (`php8.4-fpm.service`).** Copy `apparmor/abstractions/wpcaregrid-php-fpm-common` and `apparmor/wpcaregrid-php-fpm-staging-legacy` into `/etc/apparmor.d/` (no-overwrite, `root:root 0644`; note `apparmor.service` loads enforce-mode profiles from that directory at the next start or reload, which is harmless until a unit names the profile). Run `apparmor_parser -Q -K -I /etc/apparmor.d <profile>`. Create the fixtures (`fixtures.py create`, Acceptance step 1). Load in **complain** mode (`apparmor_parser -r -C …`). Only now copy `php8.4-fpm.service.d/90-wpcaregrid-confinement.conf` into `/etc/systemd/system/php8.4-fpm.service.d/`, verify with `systemctl show -p DropInPaths php8.4-fpm.service`, and `systemctl daemon-reload`. Restart `php8.4-fpm.service` in its window, soak in complain mode for at least one full business day of sibling-vhost traffic, review `apparmor="ALLOWED"` records (each is a missing rule or a protected-tree access: any protected-tree access is a STOP), then reload in enforce mode, restart, and run Acceptance for `wpcaregrid-php-fpm-staging-legacy`.

**Stage 2 — staging-next (`php8.5-fpm.service`).** Same sequence with `wpcaregrid-php-fpm-staging-next` and `php8.5-fpm.service.d/90-wpcaregrid-confinement.conf`, inside the staging maintenance gate: record the baseline unit text, complain mode, drop-in, `daemon-reload` (only now), restart, smoke test, review, enforce, restart, smoke test, Acceptance for `wpcaregrid-php-fpm-staging-next`. On a start or smoke failure: Rollback immediately (R1–R3).

**Stage 3 — same-UID peer gate.** Run `collect-peers.py`. Every process with UID 33 must carry one of the three profile labels in enforce mode. Any other process (Known conflict 1) is a STOP: Stages 4–5 may not begin, production secrets may not be provisioned and no production route may be opened.

**Stage 4 — production unit, unrouted.** Only after Stage 3 passes. Copy the production profile, unit and FPM config (`php-fpm/production/*`) to their active paths; load the profile in complain mode, `daemon-reload`, start `wpcaregrid-production-php8.4-fpm.service`, exercise it by a local FastCGI request to its own socket only, review ALLOWED records, enforce, restart, and run Acceptance for `wpcaregrid-php-fpm-production`, where the staging peers are already confined.

**Stage 5 — remove fixtures; release the production gate.** `fixtures.py remove`, then `fixtures.py status` must report none. Only then may a separate Owner GO provision real production secrets and open the production Nginx route, and that GO requires the 2.1.3 preflight (`resolve_environment` plus `verify_confinement_observation`, with the same-UID inventory) to pass, a fresh `collect-peers.py` with no unconfined peer, and the Acceptance evidence from Stages 1, 2 and 4. The production Nginx route is never changed before this stage.

Never combine stages in one GO. Do not restart any serving FPM service outside its approved window.

## Live acceptance (required; kernel enforcement is shown here, not in source tests)

A denial counts only when the unconfined baseline reached the same target, the confined run was refused with `EACCES`/`EPERM`, a kernel `apparmor="DENIED"` record names the probe profile, the probe pid, an appropriate operation and the target, and the same-UID inventory was complete before and after. Record sanitized output only.

1. **Fixtures.** `python3 "$PKG/acceptance/fixtures.py" create "$PKG/acceptance/matrix.json"` creates canary files (constant non-secret text) with the same owner/group/mode a real secret would have: for example `production/shared/.env` is `root:33 0640`, storage is `33:33 2770`. It refuses before changing anything if any target already holds other content (for example a real `.env`). Required because an AppArmor denial proves nothing where DAC would deny anyway. `status` must list none before Stage 5 ends.
2. **Attribution.** For the master and every worker: `cat /proc/<pid>/attr/current` equals `<profile> (enforce)`.
3. **Fail closed.** With the profile unloaded, starting the new **production unit** must fail; reload afterwards. Not performed on a serving pool.
4. **Mount namespace.** `nsenter -t <MainPID> -m -- ls <other environment path>` shows the path absent or inaccessible (separate from AppArmor).
5. **Unit descriptors.** `collect-peers.py` records every descriptor of every master/worker; the verifier requires only expected targets and none under another environment, the control plane, `/home` or `/var/lib/mysql`. Inherited descriptors the unit is not given are never relied upon to be revoked: see below.
6. **Probe run (per profile; three profiles).** A same-UID peer inventory is taken first, as root: `python3 "$PKG/acceptance/collect-peers.py" "$PKG/acceptance/matrix.json" > inv-before.json`. Pick one www-data **worker** pid (never a root master) of each *other* role from it. Then, for the baseline and again for the confined run, start a fresh sender and the probe:

   ```bash
   python3 "$PKG/acceptance/derive-probe-profile.py" < /etc/apparmor.d/<profile> > /run/wpcg-probe-profile
   # load once: apparmor_parser -r /run/wpcg-probe-profile  (name <profile>-probe, enforce)
   systemd-run --collect --unit wpcg-fd-sender -p User=www-data -p PrivateTmp=no \
     /usr/bin/python3 -B "$PKG/acceptance/fd-sender.py" "$N" /home/wpcg-fixture/canary
   systemd-run --wait --collect --pipe -p User=www-data -p PrivateTmp=no -p NoNewPrivileges=yes \
     -p StandardInput=file:/home/wpcg-fixture/canary [-p AppArmorProfile=<profile>-probe  # confined run only] \
     /usr/bin/python3 -I -c "$(cat "$PKG/acceptance/probe.py")" <baseline|confined> "$(cat "$PKG/acceptance/matrix.json")" \
     <profile> '{"peers":{"<role>":[<worker pid>]},"scm_socket":"/tmp/wpcg-probe-'$N'/scm.sock","abstract":"wpcg-probe-'$N'"}' > <baseline|confined>.json
   journalctl -k --since "$T0" -o cat | grep 'apparmor="DENIED"' > audit.txt
   python3 "$PKG/acceptance/collect-peers.py" "$PKG/acceptance/matrix.json" > inv-after.json
   python3 "$PKG/acceptance/verify-acceptance.py" "$PKG/acceptance/matrix.json" <profile> baseline.json confined.json inv-before.json inv-after.json audit.txt
   ```

   The derived probe profile is the real profile plus only python exec/library reads and `attr/current`. It does **not** replace testing the real profile (next step). The sender and the baseline probe are unconfined same-UID processes: take both inventories while they are not running. The probe never reads content except exactly-canary-sized files, and never ptrace-attaches to a real peer: ptrace is calibrated on a descendant of the probe (which DAC and Yama permit, so a refusal is AppArmor's); real-peer `process_vm_readv` refused by Yama in the baseline is reported as masked and is valid only together with that calibration. It reads `/proc/<peer>/{environ,cmdline,maps,mem,fd/,fdinfo/,root/,cwd/,exe,ns/mnt,auxv}`, signals the peer, tests symlink escapes, a pathname-socket `SCM_RIGHTS` descriptor transfer from the unconfined sender, an abstract-socket connect, and **actually reads the inherited descriptor on fd 0** (`StandardInput=file:` above), reporting whether the kernel revoked it.
7. **Original-profile exec denial.** The probe profile grants python, so `exec-python` is not a probe check. Run `systemd-run --wait --collect -p User=www-data -p AppArmorProfile=wpcaregrid-php-fpm-production /usr/bin/python3 --version` (and the equivalent for the other profiles that deny it): it must fail to exec, and the audit log must show `operation="exec"` `profile="wpcaregrid-php-fpm-production"` DENIED for `/usr/bin/python3`.
8. **Kernel features and audit.** Record the AppArmor feature files; if `ptrace`, `signal` or unix mediation is absent the corresponding claims are void and the stage stops. `DENIED` records for the probe pid with no matching check fail the run as unexplained.
9. **Application smoke.** Existing deployment smoke and health checks unchanged from the recorded baseline.

**Inherited descriptors.** Path rules are evaluated when a file is **opened** (and when a descriptor is received over a socket). A descriptor already open keeps working if the path would now be denied, so a confined process that inherits an open descriptor to another environment's file can still read it. The assumption to verify, **never rely on exec-time revocation**: the kernel may revalidate or close descriptors a new profile does not permit at an `execve` transition, but this repository cannot demonstrate it, and the behavior does not cover descriptors a process opened before a profile *reload* of a running service (only a restart applies a profile). Design consequences:

- The units pass no extra descriptors: `StandardInput=null`, no `Sockets=`/`ListenStream=`/`OpenFile=`, `FileDescriptorStoreMax=0`, no `PassFileDescriptorsToExec`, no credentials. The profile is selected before the first `execve`, so master and workers start confined.
- Acceptance reads fd 0 in both runs and records the outcome; whichever it is, safety rests on the unit descriptor inventory (step 5) showing nothing foreign. Inspect `/proc/<pid>/fd` of the master and every worker; any descriptor outside the allowed set fails the run.
- `SCM_RIGHTS` from an unconfined same-UID peer reading as a confined process is a **failure** unless explicitly acknowledged with `--ack-fd-transfer-bypass` in a reviewed record; the abstract-socket and `/run/php` path denials are what keep a rendezvous from existing.

## Rollback

Rollback uses only reversible, hash-checked operations and never touches databases or application files. **Invariant I governs the order: production is fenced before any same-UID peer is returned to unconfined.** Restoring a staging peer to unconfined while production secrets are readable would reopen the exposure.

- **R1 — fence production traffic and service.** Close the production route (Nginx maintenance gate, existing marker procedure), stop the production workers and scheduler under their fence markers, then `systemctl stop wpcaregrid-production-php8.4-fpm.service`. Record states. If production has never been provisioned or routed, record that and continue.
- **R2 — fence production secrets.** Record the owner, group, mode and ACL of `production/shared/.env` and `production/shared/storage`, then remove access for `www-data` (restore `root:root 0600` / drop the `www-data` ACL entries) so an unconfined `www-data` peer can no longer read them. Fixtures only: `fixtures.py remove`. Verify with the unconfined baseline probe (run as `www-data`) that the production secret paths are no longer readable.
- **R3 — only now restore staging peers**, newest first: remove `php8.5-fpm.service.d/90-wpcaregrid-confinement.conf`, `daemon-reload`, restart `php8.5-fpm.service`, confirm `/proc/<pid>/attr/current` is `unconfined` and the smoke test passes; then the same for `php8.4-fpm.service.d`. Removing a drop-in or unloading a profile does **not** change a running process; the restart reverts it.
- **R4 — remove artifacts.** `apparmor_parser -R` each profile, delete only files whose SHA-256 matches `SHA256SUMS`, `daemon-reload`. Production stays fenced (R1–R2) until a new GO re-runs the cutover.
- **Partial rollback.** Reverting any single peer (for example only staging-next) requires R1–R2 first, because any one unconfined peer already violates Invariant I.
- **Failed install (Stage 0/1):** delete only files whose hash matches; leave any non-matching file untouched and report it.

Stop conditions: a file or label that differs from the recorded baseline, a unit that will not restart, a production secret that stays readable after R2, or a failed smoke test. Keep the maintenance gate, do not retry blindly, and escalate with the recorded evidence.

## What the next engineer must know

- Changes to installed profiles always need a new artifact version directory (`1.0.2`, …), a new `SHA256SUMS`, new compiled hashes in a new contract version, a new test run and a new GO. Do not edit `1.0.1` in place after review.
- `acceptance/matrix.json` is the single source for the model test, the live probe, the verifier and the fixture manifest; update it with every access change.
- `scripts/tests/php-fpm-confinement-test.py` and `deployment-environment-contract-2-1-3-test.py` are wired into CI; the real-parser compile runs there.
- Related: `docs/PRODUCTION_MARKETING_RELEASE_RUNBOOK.md` (production identity inputs), `docs/PHP85_STAGING_MIGRATION.md` (staging FPM), `docs/PRODUCTION_G1_MYSQL_GO54A_APPARMOR_HOTFIX.md` (precedent: parser-validated, source-only AppArmor change; do not widen on abbreviated audit descriptions).
