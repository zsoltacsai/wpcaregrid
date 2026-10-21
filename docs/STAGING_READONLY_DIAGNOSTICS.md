# Staging read-only diagnostics

This is a manually triggered, one-operation workflow for reading the six M12
privacy gates and three staging identity values from Laravel's existing
configuration cache. It does not boot Laravel and does not deploy, synchronize,
scan, access a database, or change application state.

## Security model

- `.github/workflows/staging-read-only-diagnostics.yml` accepts only the fixed
  `m12-config-readback` operation, only from
  `claude/ecstatic-wozniak-oemyuf`, only when started by the Owner account
  `zsoltacsai`, and only through `workflow_dispatch`. The job checks out
  `github.workflow_sha`, so its scripts and parser come from the exact commit
  containing the workflow version GitHub accepted for this run.
- The job requests `contents: read` only and runs in the protected
  `staging-diagnostics` GitHub environment. `deployment: false` avoids a
  deployment record for this read-only diagnostic while retaining environment
  protection and secret access controls.
- SSH key and known-hosts secrets are injected only into the one diagnostic
  step, not checkout or future job steps. The workflow grants no deployment,
  Actions-write, OIDC, database, or production permissions.
- Configure the environment to allow only the
  `claude/ecstatic-wozniak-oemyuf` branch. For solo-owner operation, set the
  Owner account as the required reviewer and leave **Prevent self-review** off.
  The Owner can start and approve their own diagnostic run; no independent
  reviewer is required. Add another reviewer only if the Owner later chooses
  to do so. Do not store credentials as repository-level secrets.
- In GitHub Actions execution-protection settings, restrict this workflow path
  to the Owner actor and `workflow_dispatch`. The repository ruleset protects
  the designated branch by requiring a pull request and all seven CI checks,
  with no bypass actor. That controls how code reaches the branch; it does not
  establish that workflow code is trustworthy. The Owner must inspect workflow,
  parser, dispatcher, runner, and sanitizer changes before merging them.
- Environment secrets are available to any job that references that
  environment; GitHub does not provide a per-workflow secret ACL on an
  Environment. This must be the only workflow referencing
  `staging-diagnostics`. The policy test enforces that repository invariant in
  CI. The Owner must review changes to this invariant along with workflow
  changes. Never grant this Environment to an unrelated workflow.
- Use a dedicated `caregrid-diagnostics` Unix account and a dedicated SSH key.
  Do not reuse the deploy key. The authorized key must use OpenSSH `restrict`
  and a root-owned forced-command dispatcher. The dispatcher accepts only the
  exact `m12-config-readback` request and always calls one fixed sudo command.
- The sudoers entry may authorize only `/usr/bin/php` running
  `/usr/local/libexec/wpcaregrid-staging-m12-readback.php` without arguments.
  The dispatcher, PHP helper, parent directories, and sudoers file must be
  root-owned and not writable by the diagnostics or deploy accounts.
- Install the helper from a reviewed, merged commit only after a separate Owner
  approval. Verify the source SHA and file hashes before installation. This PR
  does not install server files, create accounts, modify SSH configuration, or
  configure secrets.
- The helper reads only
  `/var/www/wpcaregrid/bootstrap/cache/config.php`. It parses Laravel's static
  `var_export` cache grammar using PHP tokens; it never includes, requires, or
  evaluates the cache. It checks the fixed path, symlinks, file type, owner,
  permissions, inode, size, and parser limits. Unsupported structures fail
  closed with fixed codes.
- The helper reports actual booleans only as `TRUE`, `FALSE`, or `UNKNOWN`. It
  prints the expected staging identity strings only when they match; otherwise
  it prints `MISMATCH` or `UNKNOWN`. It does not print other cache values.
- The runner pins the supplied ED25519 known-hosts record to
  `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`, disables proxies,
  forwarding, host-key updates, and interactive authentication, and allows one
  connection with a 45-second timeout. SSH stderr is discarded. Output is
  capped during transfer and strictly validated before it reaches the Actions
  log. No artifact is uploaded; temporary runner files are removed at exit.

## Required protected environment values

Configure these as **environment-scoped secrets** in `staging-diagnostics`,
only after the separate server-side restricted account and forced command have
been provisioned and reviewed:

- `STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY`: the dedicated private key for the
  restricted diagnostics account.
- `STAGING_DIAGNOSTICS_KNOWN_HOSTS`: exactly one `stg1.wpcaregrid.com`
  `ssh-ed25519` known-hosts record matching the pinned fingerprint.

The workflow fails closed when either secret is absent, the host key does not
match, the selected ref is wrong, or the operation ID is unsupported.

## Operations and incident handling

- Before activation, configure repository or organization Actions log retention
  to the shortest period allowed by the project evidence policy. This workflow
  uploads no artifacts; GitHub log retention is controlled by repository or
  organization settings, not by this workflow. Restrict repository access
  because run logs are visible to people with repository access.
- The workflow is manual and this PR does not grant an Actions-dispatch token
  to Codex. The Owner starts and approves the protected environment job using
  the same account. Review the exact workflow commit and successful CI before
  starting or approving. Do not add a persistent Actions-write credential
  merely to automate dispatch.
- Rotate the diagnostics key under a separately approved change: install the
  replacement public key, update the protected environment secret, validate
  the new key through a separately authorized diagnostic run, then remove the
  old authorized key. Never place private key material in a commit, issue,
  workflow output, or application log.
- On suspected key or host compromise, disable the protected environment or
  remove its SSH secret, revoke the authorized key on staging through the
  approved server process, and follow the project incident procedure. Preserve
  only the sanitized workflow log needed for incident evidence.
- Add a future diagnostic only as a new fixed operation identifier with a
  matching exact server-side dispatcher branch, one fixed helper invocation,
  a bounded output schema, sanitizer allowlist, negative tests, and reviewed
  exact-SHA CI. Never pass workflow inputs through as shell text, SSH commands,
  PHP arguments, paths, or sudo targets. Do not add a general-purpose shell.
- The server-side sudo policy must retain `env_reset`, reject `SETENV`, and
  allow only the exact helper command. Keep the helper and dispatcher root-owned
  and non-writable by both staging accounts.

## One-shot procedure

1. Confirm the target branch contains the reviewed helper and sanitizer and its
   exact SHA has successful required CI.
2. Confirm the separate server provisioning review: restricted account, forced
   command, fixed sudoers rule, root-owned helper files, and unchanged pinned
   host identity.
3. Confirm the GitHub environment allows only the designated branch, requires
   Owner approval while permitting Owner self-approval, and holds only the
   environment-scoped diagnostics secrets above. Confirm the repository
   Actions execution policy restricts this workflow to the Owner and
   `workflow_dispatch`.
4. In GitHub Actions, run **Staging read-only diagnostics** manually from
   `claude/ecstatic-wozniak-oemyuf` with `m12-config-readback`.
5. Approve the protected environment job as the Owner. The workflow makes one SSH
   connection and emits only the six gate statuses, three identity statuses,
   and fixed failure classifications.
6. Review and retain the sanitized Actions log only for the project’s approved
   operational retention period. Do not enable a schedule or re-run a failed
   workflow without a new Owner authorization.

The reported values describe the cached configuration snapshot. Treat them as
effective application configuration only after separately verifying cache
currency and runtime-override semantics. Scanner readiness and deployment
authorization remain separate decisions.
