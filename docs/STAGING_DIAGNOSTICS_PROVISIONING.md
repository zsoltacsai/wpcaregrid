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

## Server installation

Run the following only in a separately approved staging provisioning window, after the source commit and push CI are approved. Do not run it as part of deployment. Use the reviewed checkout at the exact SHA, owned by a trusted administrator, with no modified or untracked files. Supply a path to the public key only; the installer rejects non-ED25519 keys and does not need private-key material.

```sh
sudo /usr/bin/python3 scripts/staging-diagnostics/provisioning/provision.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
sudo /usr/bin/python3 scripts/staging-diagnostics/provisioning/verify.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
```

The installer creates a dedicated locked `caregrid-diagnostics` account, root-owned fixed dispatcher/parser/wrapper files, a root-owned `authorized_keys` entry restricted to that dispatcher, and a sudoers rule for only the no-argument fixed wrapper. A root-owned shell guard rejects other SSH command forms. Forwarding, PTY, agent, X11 and user rc features are disabled by the SSH key restriction. The account is not added to the deploy group and receives no access to backups, databases, deployment credentials, or unrelated application files.

The verifier checks the installed hashes and ownership, SSH effective authentication policy, pinned server host-key fingerprint, relevant file metadata, and the effective `sudo -l -U caregrid-diagnostics` result. It requires exactly the one fixed command. It does not read the cache or `.env` content. Preserve only sanitized pass/fail evidence and the approved source SHA.

If installation stops with a pending journal or any conflict, do not retry installation. Use the same approved source SHA and public key for the explicit rollback below, investigate the fixed failure code, then re-run the full preflight in a new authorization window.

## Rotation and revocation

Rotate with a separately approved operation: generate a new dedicated key; install the new public key using a reviewed, narrowly scoped procedure; update the Environment private-key secret; verify the pinned host entry; perform one separately approved diagnostic check; then remove the old authorized key and revoke/delete the old private key. Never replace or reuse the deploy key. If compromise is suspected, immediately remove the matching authorized key, delete the Environment secret, and disable the diagnostics account before investigating. Record key fingerprints and dates, never private material.

## Disable access / recover an interrupted install

The rollback removes only the exact managed authorized-key line, exact managed sudoers file, and exact pending marker, then locks the account. It deliberately leaves root-owned helper files, the home directory, and the dedicated group for later audited cleanup; access is disabled after the key and sudoers entry are removed and the account is locked.

```sh
sudo /usr/bin/python3 scripts/staging-diagnostics/provisioning/rollback.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
sudo /usr/bin/python3 scripts/staging-diagnostics/provisioning/verify.py \
  --approved-source-sha APPROVED_40_CHARACTER_COMMIT \
  --public-key-file /root/secure-staging-diagnostics-key.pub
```

After rollback, the verifier is expected to fail because the access boundary is intentionally absent. Confirm the fixed missing-access conditions without printing key or configuration content. A later separately approved cleanup may remove only the documented root-owned managed paths after confirming no account, authorized key, sudoers entry, or workflow secret remains. Do not edit application permissions or the active configuration cache as part of rollback.

## Threat model and boundaries

The only SSH command accepted is `m12-config-readback`. The account's forced command, custom shell, root-owned dispatcher, no-argument sudo rule, and fixed PHP helper form separate restrictions. The parser accepts only its supported static cache syntax and emits only the approved six booleans and three deployment identity values; unsupported syntax fails closed. Workflow output is sanitized and bounded. SSH pins the host key and does not enable proxy, forwarding, PTY, or interactive access.

The Environment secret is still a high-value credential: anyone able to change trusted workflow code on the permitted branch could attempt to misuse it. Keep branch write access minimal, enforce required reviews/status checks where operationally available, review the exact workflow SHA before granting secrets, and revoke the key promptly when it is no longer needed. Do not add arbitrary diagnostic inputs, shell text, deployment commands, scanner operations, database access, backup/restore, or production targets to this interface. Any new diagnostic operation requires its own security review, fixed dispatcher allowlist, parser/output tests, and Owner approval.
