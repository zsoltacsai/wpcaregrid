# Protected staging diagnostics provisioning

This runbook provisions only the fixed `m12-config-readback` diagnostics operation on staging. It does not deploy the application, read the M12 values, run a scanner, or authorize production access. Provisioning and the first workflow run require separate Owner decisions.

## Trust and release checks

Before installation, the Owner must select the exact reviewed commit that contains this package and verify its successful exact-SHA push CI, including all seven required jobs and the MySQL process-concurrency gate. The installer accepts only that SHA and a clean Git tree, then compares every installed source file with the committed blob. Run it only from a clean checkout of that exact commit. Branch protection is useful for preventing accidental changes, but it does not make workflow code intrinsically trustworthy: the Owner must review the workflow and installer changes at the approved SHA before granting the Environment secrets.

The installer supports Debian and Ubuntu and fails closed if the expected account, paths, SSH policy, host identity, cache metadata, or file permissions do not match. It reads only metadata for `.env` and the Laravel configuration cache. The diagnostic helper later reads the single approved cache file through a non-executing parser. It never includes or evaluates the cache.

The configuration cache must be a regular, non-symlinked file owned by `deploy`, no larger than 1 MiB, with one of two exact access-control forms: owner-only mode `0600` with ACL entries `user::rw-`, `group::---`, `other::---`; or mode `0640` with the exact ACL entries `user::rw-`, `user:www-data:r--`, `group::---`, `mask::r--`, `other::---`. The second form allows the PHP-FPM runtime user to read the cache without granting read access to the owning group or other users. The installer and readback helper validate ACLs on the opened file descriptor and reject any additional group or named-user access as `CACHE_METADATA_INVALID`. The `getfacl` utility from the `acl` package is required. Provisioning does not change cache permissions. If the cache fails validation, stop and obtain separate Owner approval for any permission remediation; verify the PHP runtime can still read the cache after an approved change. The protected deployment controller creates the PHP-FPM ACL and verifies runtime-user access during cache rebuild.

## GitHub controls to verify before adding secrets

Configure the `staging-diagnostics` Environment in repository settings:

1. Restrict deployments to `claude/ecstatic-wozniak-oemyuf`.
2. For this solo-Owner repository, do not require an independent reviewer. If the Owner later adds another reviewer, re-evaluate the review rule; do not enable self-review prevention when it would make the sole Owner unable to run the diagnostic.
3. Do not add a wait timer unless the Owner deliberately chooses one.
4. Add only the Environment secrets `STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY` and `STAGING_DIAGNOSTICS_KNOWN_HOSTS`. Do not create repository-level copies.
5. Confirm that only `.github/workflows/staging-read-only-diagnostics.yml` references this Environment, and review its exact code at the approved commit before any run. The workflow permits only manual dispatch by `zsoltacsai` from the designated branch and the fixed `m12-config-readback` choice. It checks out `github.workflow_sha`, uses read-only repository permissions, and does not upload artifacts.
6. Set the shortest practical Actions log retention allowed by repository policy. Logs still require access control and periodic review.

If a required Environment restriction or workflow execution control is unavailable, do not add credentials or run the workflow. Branch protection reduces unauthorized change risk only when its rules are configured and enforced; the job-level actor/ref/input checks are additional guards, not a substitute for reviewing the workflow code.

This repository is public. GitHub documents Environments, Environment secrets, deployment branch restrictions, and protection rules as available for public repositories on current plans, with legacy-plan exceptions. The exact account plan and effective settings are not established by this package and must be checked in repository settings. `deployment: false` suppresses deployment records; GitHub still applies Environment branch rules, wait timers and required reviewers, but custom deployment protection apps are incompatible with it. Workflow execution protections are a separate Actions policy; inspect **Settings → Actions → Policies** for an actor/event allowlist that limits `workflow_dispatch` to the Owner. GitHub documents actor and event allowlists, including `workflow_dispatch`, but this package does not verify that the repository's account can enable them. The workflow also rejects other actors, refs, and inputs in its job condition, but that code check is not an administrator-level execution policy. If the account lacks the central control, do not represent the job condition as equivalent protection; keep the Environment secrets absent until the Owner accepts and documents that residual risk or an eligible policy is enabled. See [GitHub Environment plan eligibility](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments), [environment behavior without deployment records](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments), and [Actions execution policies](https://docs.github.com/en/actions/concepts/about-actions-policies).

## Dedicated key creation and secret handling

Generate a fresh ED25519 key pair on the Owner-controlled Windows machine using an approved secure key-generation mechanism. Do not reuse the deploy key. Keep the private key only on that machine and later in the protected GitHub Environment secret `STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY`; it must never reach staging or the repository. Transfer only the `.pub` file to the separately approved staging provisioning session. Install it at `/var/lib/wpcaregrid-staging-diagnostics-provisioning/input/diagnostics.pub` as a root-owned, regular, non-symlinked file, mode `0644`, beneath root-owned non-writable directories. The root-owned bootstrap reads that path. The unprivileged preparer does not need access to the public key.

For OpenSSH on a trusted Linux or Git Bash workstation, an example is:

```sh
umask 077
ssh-keygen -q -t ed25519 -N '' -C 'wpcaregrid-staging-diagnostics' \
  -f "$HOME/.ssh/wpcaregrid-staging-diagnostics"
chmod 600 "$HOME/.ssh/wpcaregrid-staging-diagnostics"
chmod 644 "$HOME/.ssh/wpcaregrid-staging-diagnostics.pub"
```

The empty passphrase is needed for the non-interactive GitHub runner; compensate with the Environment restriction, one-operation server command boundary, and prompt revocation. Copy only the public key to the controlled provisioning channel. Enter the private key in the Environment secret using the GitHub UI, then securely remove the local private-key copy after verifying the secret was saved. Never print it with `cat` or pass it as a command-line argument.

Set `STAGING_DIAGNOSTICS_KNOWN_HOSTS` to the pinned `stg1.wpcaregrid.com` ED25519 known-hosts record only after checking its fingerprint against the independently confirmed value:

`SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`

Never use `ssh-keyscan` output as the trust source. Keep the private key out of command arguments and logs. After provisioning, verify the Environment secret names and scope without displaying their values.

## Trusted package preparation and server installation

Proceed only after the exact reviewed source commit has successful push CI for that SHA, including all seven mandatory jobs and the MySQL process-concurrency gate. The installation checkout must be root-owned and read-only to the temporary operator, on root-owned non-writable parent directories. It must not be the staging application checkout. The application checkout and `deploy` sudo policy are not changed.

The only temporary operator privilege is one no-argument sudo command: `/usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap`. Do not grant `NOPASSWD: ALL` or sudo rights to `install`, `tee`, `chown`, `chmod`, `cat`, `find`, Python, Git or a shell. Create a locked `wpcaregrid-provisioner` system account with `/usr/sbin/nologin`, no SSH key and no extra groups. The Owner invokes the preparer under that identity from the reviewed root-owned source checkout. Remove the operator's sudoers entry and account immediately after successful verification or on any failure.

The one-time root setup is a distinct Owner-controlled checkpoint after review and CI. It installs `bootstrap.py` as a root-owned executable from its exact Git blob (verify the commit, tree entry, blob type/mode and `git hash-object` before `install`; never execute a file from the checkout as root), then creates `/etc/wpcaregrid-staging-diagnostics/bootstrap.json` as root:root mode `0600` with exactly these keys: `repository`, `commit`, `public_key`. `repository` is the absolute root-owned source checkout, `commit` is the exact reviewed 40-character source SHA, and `public_key` is the root-owned public-key path above. Reject symlinked files and any writable parent. This bootstrap configuration is immutable during the operation. The temporary operator sudoers line is exactly:

```sudoers
Defaults:wpcaregrid-provisioner env_reset, !setenv
wpcaregrid-provisioner ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap ""
```

Create the key input parent and install only the public file with `/usr/bin/install -d -o root -g root -m 0700 /var/lib/wpcaregrid-staging-diagnostics-provisioning/input` and `/usr/bin/install -o root -g root -m 0644 /approved/transfer/diagnostics.pub /var/lib/wpcaregrid-staging-diagnostics-provisioning/input/diagnostics.pub`. The source checkout is readable by the temporary account through a dedicated group, but that account cannot modify it.

For the source checkout, keep the repository and every parent root-owned; set directories to `root:wpcaregrid-provisioner` mode `0750`, regular non-executable files to `0640`, and executable files to `0750`. Preserve executable bits so the clean-worktree check does not report permission changes. Do not run any checkout script as root.

The following is the Owner's one-time root-console bootstrap procedure after the PR and exact-SHA CI have been reviewed. Substitute only the reviewed SHA and root-owned checkout/key paths. It uses Git plumbing to copy one exact blob; it never runs repository code as root:

```bash
set -euo pipefail
REPO=/var/lib/wpcaregrid-staging-diagnostics-source/repo
APPROVED_SHA=APPROVED_40_CHARACTER_COMMIT
BOOTSTRAP_PATH=scripts/staging-diagnostics/provisioning/bootstrap.py

git_clean() {
  /usr/bin/env -i PATH=/usr/bin:/bin HOME=/root \
    GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    GIT_NO_REPLACE_OBJECTS=1 GIT_NO_LAZY_FETCH=1 GIT_OPTIONAL_LOCKS=0 \
    /usr/bin/git --no-replace-objects -C "$REPO" "$@"
}

test "$(git_clean rev-parse --verify HEAD)" = "$APPROVED_SHA"
test "$(git_clean rev-parse --verify "$APPROVED_SHA^{commit}")" = "$APPROVED_SHA"
entry=$(git_clean ls-tree "$APPROVED_SHA" -- "$BOOTSTRAP_PATH")
metadata=${entry%%$'\t'*}
path=${entry#*$'\t'}
read -r mode kind blob_oid <<< "$metadata"
test "$path" = "$BOOTSTRAP_PATH"
test "$kind" = blob
test "$mode" = 100644
tmp=$(/usr/bin/mktemp /run/wpcg-bootstrap.XXXXXX)
trap '/usr/bin/rm -f -- "$tmp"' EXIT
git_clean cat-file blob "$blob_oid" > "$tmp"
test "$(git_clean hash-object "$tmp")" = "$blob_oid"
/usr/bin/install -o root -g root -m 0755 -- "$tmp" /usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap

/usr/bin/install -d -o root -g root -m 0700 /etc/wpcaregrid-staging-diagnostics
printf '{"repository":"%s","commit":"%s","public_key":"/var/lib/wpcaregrid-staging-diagnostics-provisioning/input/diagnostics.pub"}\n' \
  "$REPO" "$APPROVED_SHA" > /run/wpcg-bootstrap.json
/usr/bin/chown root:root /run/wpcg-bootstrap.json
/usr/bin/chmod 0600 /run/wpcg-bootstrap.json
/usr/bin/install -o root -g root -m 0600 -- /run/wpcg-bootstrap.json /etc/wpcaregrid-staging-diagnostics/bootstrap.json
/usr/bin/rm -f -- /run/wpcg-bootstrap.json
```

Before running this, ensure the source checkout and all parents are root-owned and not writable by the operator; only the read/execute group bits needed for the temporary operator may be granted. Put the public `.pub` file at the exact configured path using `install -o root -g root -m 0644`; do not copy the private key. Create the temporary group and locked operator, then install the exact sudoers rule:

```sh
/usr/sbin/groupadd --system wpcaregrid-provisioner
/usr/sbin/useradd --system --gid wpcaregrid-provisioner --home-dir /nonexistent --no-create-home --shell /usr/sbin/nologin wpcaregrid-provisioner
/usr/bin/passwd --lock wpcaregrid-provisioner
printf '%s\n' 'Defaults:wpcaregrid-provisioner env_reset, !setenv' 'wpcaregrid-provisioner ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap ""' > /run/wpcaregrid-provisioner.sudoers
/usr/sbin/visudo -cf /run/wpcaregrid-provisioner.sudoers
/usr/bin/install -o root -g root -m 0440 /run/wpcaregrid-provisioner.sudoers /etc/sudoers.d/wpcaregrid-provisioner
/usr/sbin/visudo -c
/usr/bin/sudo -l -U wpcaregrid-provisioner
```

The source checkout at `/var/lib/wpcaregrid-staging-diagnostics-source/repo` must be readable by this account through a dedicated group but not writable by it. Keep it separate from `/var/lib/wpcaregrid-staging-diagnostics-provisioning/source`, which is reserved for the immutable root package. Run the helper through `runuser -u wpcaregrid-provisioner`; it uses the single sudo command above. Verify the account has no authorized key and its shell is `/usr/sbin/nologin`. These are separate Owner-controlled server mutations; do not run them before the code review gate.

Git may reject this root-owned checkout for an unprivileged reader with its dubious-ownership protection. `prepare-package.sh` handles that only for the exact canonical path above: it validates the spelling, resolves no symlink aliases, and checks root ownership and non-writable mode bits on the checkout and its parent directories before adding one command-scoped `-c safe.directory=/var/lib/wpcaregrid-staging-diagnostics-source/repo` to its fixed Git calls. The helper clears Git configuration environment variables and disables system/global configuration; it rejects repository-local `safe.directory` and `alias.*` entries. It never sets a wildcard, writes Git configuration, changes ownership, or grants checkout write access. A different root-owned path, symlink alias, unsafe parent, or Git configuration override fails closed. The root-owned bootstrap does not use this exception: it already owns the checkout and keeps its independent canonical-object checks.

Run the exact reviewed `prepare-package.sh` unprivileged with the same root-pinned repository and SHA:

```sh
sudo -u wpcaregrid-provisioner -- /bin/bash /var/lib/wpcaregrid-staging-diagnostics-source/repo/scripts/staging-diagnostics/provisioning/prepare-package.sh --prepare-only /var/lib/wpcaregrid-staging-diagnostics-source/repo APPROVED_40_CHARACTER_COMMIT
sudo -u wpcaregrid-provisioner -- /bin/bash /var/lib/wpcaregrid-staging-diagnostics-source/repo/scripts/staging-diagnostics/provisioning/prepare-package.sh /var/lib/wpcaregrid-staging-diagnostics-source/repo APPROVED_40_CHARACTER_COMMIT
```

The first command performs only a local, unprivileged provenance preflight. The second repeats that check and invokes only the fixed bootstrap with no arguments. The root-owned bootstrap ignores helper-provided paths and values; its root-only config pins the source repo, exact commit and public-key location. It rejects Git environment overrides, replace refs, alternates, grafts, partial-clone/promisor and unsafe local config. It reads exact committed blob objects, validates their Git SHA-1 identities, writes only the allowlisted package into a new root:root mode `0700` SHA directory with mode `0444` files and a provenance manifest, then invokes `python3 -I -S` from that immutable package. It never executes the checkout or a deploy-writable file as root. Any existing SHA package is a hard stop.

After both installer and verifier report success, remove the temporary privilege immediately:

```sh
/usr/bin/rm -f /etc/sudoers.d/wpcaregrid-provisioner /etc/wpcaregrid-staging-diagnostics/bootstrap.json /usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap
/usr/sbin/visudo -c
/usr/sbin/userdel wpcaregrid-provisioner
/usr/sbin/groupdel wpcaregrid-provisioner
```

If installation or verification fails, keep the immutable package for recovery; use its `rollback.py` as root, verify revocation, then remove the temporary sudoers/account/bootstrap configuration. Never leave the temporary operator privilege active after the provisioning session.

The public key is deliberately not passed through a non-root command or `sudo` argument. The bootstrap reads it from its root-pinned configuration; this resolves the earlier ownership contradiction. The private key remains only on the Owner machine and in the separately provisioned GitHub Environment secret.

The installer creates the locked diagnostics account, root-owned fixed dispatcher, activation checker, parser and runner. The diagnostics account's own sudo rule remains one exact no-argument readback command. The dispatcher and root runner require a valid active record and reject both the installer journal and the outer bootstrap journal. The root bootstrap writes `provisioning.bootstrap-pending` before starting either privileged Python stage and removes it only after both the installer and verifier succeed. This outer journal keeps access disabled even if the installer has finished activating the account but verification has not completed, or the root bootstrap is interrupted. A failed installer or verifier triggers rollback from the immutable package; if rollback cannot complete, the outer journal remains and access stays denied. Activation is atomically written only after installation verification while the installer journal remains; that journal is removed last. A missing, malformed or pending state denies access. Install, verify and rollback share the lifecycle lock under `/run/wpcaregrid-staging-diagnostics-lock`; no system lock directory permissions are altered. The root-owned public key file is installed under the diagnostics home with the permissions required by OpenSSH, without granting write access.

The installer does not modify SSHD or add a `Match` block. It verifies the global authentication requirements and rejects unprovable `Match` configurations. Diagnostic-only channel restrictions come from the exact installed `authorized_keys` line, which must retain OpenSSH's `restrict` option and fixed forced command. This blocks TCP/agent/X11 forwarding, tunnels, PTY, user rc and related channel features for this key without changing `deploy` or Codex SSH behavior. `PermitUserEnvironment` must remain `no`. The disposable Debian integration test exercises the real OpenSSH server with permissive global forwarding/TTY settings and confirms the restricted diagnostics key still cannot use those features. If a host configuration cannot meet the checked authentication requirements, stop; do not weaken global settings or modify SSHD in this provisioning operation.

If extraction fails, no provisioner code has run; retain only fixed failure evidence and remove a partial SHA package only after root ownership/path checks. If provision or verification fails after the immutable package has been executed, bootstrap attempts rollback from that package automatically. Confirm that access is disabled. If the bootstrap journal remains, run the rollback below from the immutable package as root; it removes the journal only after deactivation and removal of the managed key and sudoers entry. Keep the package for audit. Do not retry installation or run diagnostics until rollback/recovery and verification are complete.

The verifier checks installed hashes and ownership, SSH effective authentication policy, pinned server host-key fingerprint, relevant file metadata, active state and the effective sudo list result. It does not read the cache or environment file content. Preserve only sanitized pass/fail evidence and the approved source SHA.

The SSH preflight also requires effective `UsePAM yes`. Debian 12 OpenSSH refuses public-key login for a locked account when PAM account checks are disabled; the installer therefore stops before changing system files when this setting is unavailable.

## Rotation and revocation

Rotate with a separately approved operation: generate a new dedicated key; install the new public key using a reviewed, narrowly scoped procedure; update the Environment private-key secret; verify the pinned host entry; perform one separately approved diagnostic check; then remove the old authorized key and revoke/delete the old private key. Never replace or reuse the deploy key. If compromise is suspected, immediately remove the matching authorized key, delete the Environment secret, and disable the diagnostics account before investigating. Record key fingerprints and dates, never private material.

## Disable access / recover an interrupted install

The rollback first removes the active state, then removes only the exact managed authorized-key line, exact managed sudoers file, and exact pending marker, and finally locks the account. It deliberately leaves root-owned helper files, the home directory, and the dedicated group for later audited cleanup; access is disabled after the key and sudoers entry are removed and the account is locked.

```sh
/usr/bin/python3 -I -S /var/lib/wpcaregrid-staging-diagnostics-provisioning/source/APPROVED_40_CHARACTER_COMMIT/scripts/staging-diagnostics/provisioning/rollback.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /var/lib/wpcaregrid-staging-diagnostics-provisioning/input/diagnostics.pub
/usr/bin/python3 -I -S /var/lib/wpcaregrid-staging-diagnostics-provisioning/source/APPROVED_40_CHARACTER_COMMIT/scripts/staging-diagnostics/provisioning/verify.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /var/lib/wpcaregrid-staging-diagnostics-provisioning/input/diagnostics.pub
```

After rollback, the verifier is expected to fail because the access boundary is intentionally absent. Confirm the fixed missing-access conditions without printing key or configuration content. A later separately approved cleanup may remove only the documented root-owned managed paths after confirming no account, authorized key, sudoers entry, or workflow secret remains. Do not edit application permissions or the active configuration cache as part of rollback.

## Threat model and boundaries

The only SSH command accepted is `m12-config-readback`. The account's forced command, custom shell, root-owned dispatcher, no-argument sudo rule, and fixed PHP helper form separate restrictions. The parser accepts only its supported static cache syntax and emits only the approved six booleans and three deployment identity values; unsupported syntax fails closed. Workflow output is sanitized and bounded. SSH pins the host key and does not enable proxy, forwarding, PTY, or interactive access.
Every provisioning operation must run from the separately hash-verified root-owned package, never from the deploy-writable application checkout. The fixed activation gate is checked both before sudo and again immediately before the cache helper runs.

The Environment secret is still a high-value credential: anyone able to change trusted workflow code on the permitted branch could attempt to misuse it. Keep branch write access minimal, enforce required reviews/status checks where operationally available, review the exact workflow SHA before granting secrets, and revoke the key promptly when it is no longer needed. Do not add arbitrary diagnostic inputs, shell text, deployment commands, scanner operations, database access, backup/restore, or production targets to this interface. Any new diagnostic operation requires its own security review, fixed dispatcher allowlist, parser/output tests, and Owner approval.
