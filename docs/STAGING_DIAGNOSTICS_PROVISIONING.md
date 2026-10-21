# Protected staging diagnostics provisioning

This runbook provisions only the fixed `m12-config-readback` diagnostics operation on staging. It does not deploy the application, read the M12 values, run a scanner, or authorize production access. Provisioning and the first workflow run require separate Owner decisions.

## Trust and release checks

Before installation, the Owner must select the exact reviewed commit that contains this package and verify its successful exact-SHA push CI, including all seven required jobs and the MySQL process-concurrency gate. The installer accepts only that SHA and a clean Git tree, then compares every installed source file with the committed blob. Run it only from a clean checkout of that exact commit. Branch protection is useful for preventing accidental changes, but it does not make workflow code intrinsically trustworthy: the Owner must review the workflow and installer changes at the approved SHA before granting the Environment secrets.

The installer supports Debian and Ubuntu and fails closed if the expected account, paths, SSH policy, host identity, cache metadata, or file permissions do not match. It reads only metadata for `.env` and the Laravel configuration cache. The diagnostic helper later reads the single approved cache file through a non-executing parser. It never includes or evaluates the cache.

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

Generate a fresh ED25519 key pair on an Owner-controlled machine using an approved secure key-generation mechanism. Do not reuse the deploy key. Keep the private key in a user-only protected location, never in the repository, terminal output, issue, artifact, or chat. Add its private portion only to the Environment secret `STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY` using GitHub's secret UI. Transfer only the `.pub` file to the separately authorized server provisioning session; the provisioning file must be a regular file owned by the effective installer user, not symlinked, and not group/world writable.

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

The installer must never be invoked as root from the application checkout or another deploy-writable directory. Its Git check is defense in depth, not a pre-execution trust boundary: Python has already loaded the installer before that check can run.

Use an Owner-controlled machine and a reviewed commit with successful exact-SHA push CI. First confirm the local checkout is exactly that commit and clean. Then copy only the allowlisted package files from the Git object database into a new root-owned, mode-0700 staging directory. This is data transfer only; do not execute any repository code as root during preparation.

Example Bash procedure (replace the repository path, approved SHA, and public-key path with the reviewed values):

```bash
set -euo pipefail
umask 077

REPO=/path/to/wpcaregrid
APPROVED_SHA=APPROVED_40_CHARACTER_COMMIT
STAGE=/var/lib/wpcaregrid-staging-diagnostics-provisioning/source/$APPROVED_SHA
PUBLIC_KEY=/root/secure-staging-diagnostics-key.pub

test "$(git -C "$REPO" rev-parse --verify HEAD)" = "$APPROVED_SHA"
test -z "$(git -C "$REPO" status --porcelain --untracked-files=all)"
if sudo test -e "$STAGE"; then
  echo 'Refusing an existing source staging directory' >&2
  exit 1
fi
sudo install -d -o root -g root -m 0700 "$STAGE"

{
  printf 'commit=%s\n' "$APPROVED_SHA"
  while IFS= read -r relative; do
    [ -n "$relative" ] || continue
    case "$relative" in /*|*..*) echo 'Unsafe fixed package path' >&2; exit 1 ;; esac
    destination=$STAGE/$relative
    sudo install -d -o root -g root -m 0700 "$(dirname "$destination")"
    git -C "$REPO" show "$APPROVED_SHA:$relative" | sudo tee "$destination" >/dev/null
    sudo chown root:root "$destination"
    sudo chmod 0444 "$destination"
    expected=$(git -C "$REPO" show "$APPROVED_SHA:$relative" | sha256sum | cut -d' ' -f1)
    actual=$(sudo sha256sum -- "$destination" | cut -d' ' -f1)
    test "$expected" = "$actual"
    test "$(sudo stat -c '%u:%g:%a:%F' -- "$destination")" = '0:0:444:regular file'
    printf '%s  %s\n' "$expected" "$relative"
  done <<'PACKAGE_FILES'
scripts/staging-diagnostics/dispatch.sh
scripts/staging-diagnostics/provisioning/activation-check.sh
scripts/staging-diagnostics/provisioning/diagnostics-shell.sh
scripts/staging-diagnostics/read-m12-cache.php
scripts/staging-diagnostics/provisioning/run-m12-readback.sh
scripts/staging-diagnostics/provisioning/provision.py
scripts/staging-diagnostics/provisioning/verify.py
scripts/staging-diagnostics/provisioning/rollback.py
PACKAGE_FILES
} | sudo tee "$STAGE/.wpcaregrid-provenance" >/dev/null
sudo chown root:root "$STAGE/.wpcaregrid-provenance"
sudo chmod 0444 "$STAGE/.wpcaregrid-provenance"
test "$(sudo stat -c '%u:%g:%a:%F' -- "$STAGE/.wpcaregrid-provenance")" = '0:0:444:regular file'
test -z "$(sudo find "$STAGE" -type l -print -quit)"

# Run only after the preceding object-to-copy hashes and root ownership checks pass.
sudo /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" \
  --approved-source-sha "$APPROVED_SHA" \
  --public-key-file "$PUBLIC_KEY"
sudo /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/verify.py" \
  --approved-source-sha "$APPROVED_SHA" \
  --public-key-file "$PUBLIC_KEY"
```

The shell block reads Git blobs by the exact approved commit, writes only data into a new root-owned directory, and compares every staged file against the corresponding blob before Python starts. The root-run Python tools require that exact SHA-specific directory, root ownership, non-writable parent directories, regular non-symlink files, a root-owned read-only manifest, and matching hashes. `-I -S` disables environment and user-site Python import paths; `verify.py` and `rollback.py` import `provision.py` only from their own verified package directory. Never use elevated Python against the application checkout.

The installer creates a locked dedicated account, root-owned fixed dispatcher, activation checker, parser and wrapper, and the constrained sudoers rule. The dispatcher and root wrapper both require an intact active record and reject any pending journal. The state directory is root-owned mode 0711; the activation record is root-owned mode 0444 and contains only the approved source SHA. Activation is atomically written after all verification succeeds while the pending journal still exists. The journal is removed last, so missing, malformed, pending or partially written state denies the operation. OpenSSH opens authorized_keys as the target UID, so the root-owned .ssh directory is mode 0711 and the public-key file is mode 0444: the account can traverse/read but cannot alter either.

Any pre-existing SHA-specific package directory is a hard stop; do not overwrite or reuse it. If package preparation, hash comparison, installer validation, or verification fails, do not run the diagnostic workflow. Preserve the fixed failure classification and use the rollback command below from the same root-owned verified package. Do not run installation directly from the deploy-owned application tree.

The verifier checks installed hashes and ownership, SSH effective authentication policy, pinned server host-key fingerprint, relevant file metadata, active state and the effective sudo list result. It does not read the cache or environment file content. Preserve only sanitized pass/fail evidence and the approved source SHA.

## Rotation and revocation

Rotate with a separately approved operation: generate a new dedicated key; install the new public key using a reviewed, narrowly scoped procedure; update the Environment private-key secret; verify the pinned host entry; perform one separately approved diagnostic check; then remove the old authorized key and revoke/delete the old private key. Never replace or reuse the deploy key. If compromise is suspected, immediately remove the matching authorized key, delete the Environment secret, and disable the diagnostics account before investigating. Record key fingerprints and dates, never private material.

## Disable access / recover an interrupted install

The rollback first removes the active state, then removes only the exact managed authorized-key line, exact managed sudoers file, and exact pending marker, and finally locks the account. It deliberately leaves root-owned helper files, the home directory, and the dedicated group for later audited cleanup; access is disabled after the key and sudoers entry are removed and the account is locked.

```sh
sudo /usr/bin/python3 -I -S /var/lib/wpcaregrid-staging-diagnostics-provisioning/source/APPROVED_40_CHARACTER_COMMIT/scripts/staging-diagnostics/provisioning/rollback.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
sudo /usr/bin/python3 -I -S /var/lib/wpcaregrid-staging-diagnostics-provisioning/source/APPROVED_40_CHARACTER_COMMIT/scripts/staging-diagnostics/provisioning/verify.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
```

After rollback, the verifier is expected to fail because the access boundary is intentionally absent. Confirm the fixed missing-access conditions without printing key or configuration content. A later separately approved cleanup may remove only the documented root-owned managed paths after confirming no account, authorized key, sudoers entry, or workflow secret remains. Do not edit application permissions or the active configuration cache as part of rollback.

## Threat model and boundaries

The only SSH command accepted is `m12-config-readback`. The account's forced command, custom shell, root-owned dispatcher, no-argument sudo rule, and fixed PHP helper form separate restrictions. The parser accepts only its supported static cache syntax and emits only the approved six booleans and three deployment identity values; unsupported syntax fails closed. Workflow output is sanitized and bounded. SSH pins the host key and does not enable proxy, forwarding, PTY, or interactive access.
Every provisioning operation must run from the separately hash-verified root-owned package, never from the deploy-writable application checkout. The fixed activation gate is checked both before sudo and again immediately before the cache helper runs.

The Environment secret is still a high-value credential: anyone able to change trusted workflow code on the permitted branch could attempt to misuse it. Keep branch write access minimal, enforce required reviews/status checks where operationally available, review the exact workflow SHA before granting secrets, and revoke the key promptly when it is no longer needed. Do not add arbitrary diagnostic inputs, shell text, deployment commands, scanner operations, database access, backup/restore, or production targets to this interface. Any new diagnostic operation requires its own security review, fixed dispatcher allowlist, parser/output tests, and Owner approval.
