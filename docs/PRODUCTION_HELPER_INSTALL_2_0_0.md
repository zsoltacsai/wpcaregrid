# Production deployment helper 2.0.0 — administrator installation

Status: **PREPARATION ONLY**. This procedure does not authorize host changes,
production activation, application deployment, database work, or public traffic.

## Pinned source

Protected-primary source: `cf2a944c27a2a24dbdf1097bf0ba766e6edd48fe`.
Recompute every digest from that exact Git tree immediately before source staging.

| Source file | SHA-256 |
| --- | --- |
| `scripts/wpcaregrid-deploy-production-priv.py` | `dde7ee7370e4968284cc10fed8dae8cf33786d93830f9f65c7157e19bbf572a1` |
| `scripts/deployment_backend_v2.py` | `d1cbde99eef1168e42797268ce170e451b28051d7d980876a6167fb19bf85576` |
| `scripts/deployment_transaction_v2.py` | `ba4c9fd3ea821cb8a1aa5581a74da8c1477b5e30484fc2e09546a26fe60f2d00` |
| `scripts/deployment_environment_contract.py` | `f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1` |
| Combined helper artifact (derived) | `0e50791ee708de571dbcaa5420bd06b31698e3b7a8683f7501bee6880ead96fc` |
| `scripts/production_deploy_controller.py` | `a94865d3e6318c7278b6d72e797fc94d5274faeca0ea2073ecc84fad74166283` |
| `deploy/sudoers/wpcaregrid-production-v2.0.0` | `fb7716132c9d48a6a55c305ecff932a1bd518a575e7dd974162bc0d4f89fc963` |
| Root installer `scripts/install_production_helper_2_0_0.py` | `ec44d404fc994369bba113eac12f0a902cb5a53e2ef73c12e3ab07af73701986` |

The six helper/controller/policy-template inputs must be staged under
`/home/deploy/.private/wpcg-production-helper-2.0.0-cf2a944c/`, owned by
`deploy:deploy`, directory mode `0700`, files mode `0600`. The root installer is
a separate file at
`/home/deploy/.private/wpcg-production-helper-installer-2.0.0-cf2a944c/install_production_helper_2_0_0.py`,
owned by `deploy:deploy`, mode `0600`. Do not add the installer to the exact
six-file source directory and never execute it directly from that deploy-owned
path as root.

## Installation effect, if separately authorized

The installer requires root and a pre-existing `deploy-production` identity
whose UID differs from `deploy` and `deploy-staging-next`. It does not create
accounts, databases, application directories, services, network routes, or
credentials. It refuses any existing destination rather than replacing it.

It may create only these absent root-owned mode-`0755` directories:

* `/usr/local/libexec/wpcaregrid-deploy/production/`
* `/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/`

It installs the entrypoint at mode `0755`, the pinned Python modules, controller,
and disabled production profile at mode `0644` under the versioned helper
directory, then installs the versioned sudoers fragment at mode `0440` as the
final publication step. Every file is
root-owned. The profile has `enabled=false`, all environment-specific values
unset, and no Phase B policy or credentials. Consequently, the helper refuses
all production operations until a separately reviewed and authorized
environment policy is installed.

The controller is installed as
`/usr/local/libexec/wpcaregrid-deploy/production/2.0.0/production_deploy_controller.py`.
The installer does not create or own the controller's separate Git source
checkout at `/var/lib/wpcaregrid-deploy/production/source`; a future production
operator setup must provision a clean, exact-origin checkout owned by the
dedicated `deploy-production` identity and verify its primary/CI provenance.
The controller refuses to run until that checkout and the separate
deploy-owned incoming directory meet its fixed contract.

Before running it, an administrator must verify the exact primary and CI/review
evidence, source directory path/ownership/modes/content, the absence of every
destination and Phase B credential/policy, the existing root-owned parent
directories, current sudo policy, and validity of `visudo`. The installer
performs these checks itself, validates the sudoers candidate, publishes with
atomic no-overwrite links, reads back hashes/owners/modes, checks the exact
allowlist, and tests denial for the ordinary `deploy` identity and invalid
production operations. It does not call `preflight`, `verify-install`,
`authorize`, `stage`, or `deploy`.

Command sequence for a separately authorized root console. It copies the
installer into a private root-owned directory and verifies the copied bytes
before execution, so the deploy-owned source is not executed as root:

```sh
(
  set -eu
  src=/home/deploy/.private/wpcaregrid-production-helper-installer-2.0.0-cf2a944c/install_production_helper_2_0_0.py
  expected=ec44d404fc994369bba113eac12f0a902cb5a53e2ef73c12e3ab07af73701986
  d=$(/usr/bin/mktemp -d /root/wpcg-production-installer.XXXXXX)
  trap '/usr/bin/rm -f -- "$d/install.py"; /usr/bin/rmdir -- "$d"' EXIT
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d")" = root:root:700
  test ! -e "$d/install.py" && test ! -L "$d/install.py"
  /usr/bin/install -o root -g root -m 700 -- "$src" "$d/install.py"
  test "$(/usr/bin/stat -c '%U:%G:%a' "$d/install.py")" = root:root:700
  test "$(/usr/bin/sha256sum "$d/install.py" | /usr/bin/cut -d' ' -f1)" = "$expected"
  /usr/bin/python3 -I "$d/install.py"
)
```

Expected output is a sanitized `INSTALL=PASS`, `PRODUCTION_PROFILE=DISABLED`,
`PHASE_B_POLICY=DISABLED`, and `SUDOERS_ALLOWLIST=PASS`. On failure, the
installer attempts rollback only for objects it created and only when each
object still matches its recorded device, inode, owner, mode, and hash (files)
or identity and emptiness (directories). `ROLLBACK=HOLD` means stop and retain
state for administrator review; never rerun blindly. Successful execution
must be independently recorded by the administrator. No installation has
occurred as part of this source change.

## Current readiness constraint

The current read-only SSH account cannot verify root-only service, database, or
sudo state. The `deploy-production` account was not present in the
deploy-readable account lookup during the current discovery. As a result, this
installer is expected to fail closed until that identity is separately
provisioned and all production isolation / recovery evidence is accepted. This
document does not authorize that provisioning.
