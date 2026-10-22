# P3 staging diagnostics closeout evidence

**Status: P3 CLOSED — VERIFIED**  
**Evidence baseline:** `claude/ecstatic-wozniak-oemyuf` at
`309bf5a36d4e13cf5450e9b27f804892cc63cb80`  
**Scope:** the protected staging diagnostics installation and one M12 cached
configuration readback. This does not establish Wordfence scanner coverage or
production vulnerability coverage.

## Reviewed release and CI

The development branch contains the approved diagnostics workflow and server
implementation at the evidence baseline above.

- Exact-SHA push CI: [run 39742533178](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39742533178) — success.
- All seven required jobs passed. The Quality job's Debian 12 staging
  diagnostics integration passed, including real OpenSSH and sudo behavior.
- The required MySQL process-concurrency gate passed in the PHP 8.4 MySQL
  server-test job.
- The OpenSSH integration exercises the fixed diagnostic command, pending and
  invalid activation denial, alternate authentication denial, arbitrary command
  and interactive shell denial, SFTP denial, PTY request rejection, forwarding
  denial, sudo privilege expansion denial, and key revocation after rollback.

## Staging installation and privilege cleanup

The Owner-provided staging installation evidence reported:

- `DIAGNOSTICS_PROVISIONING=INSTALLED_OR_ALREADY_VALID`
- `STAGING_DIAGNOSTICS_BOOTSTRAP=PASS`
- `P3_INSTALL_EXIT=0`

The Owner later confirmed temporary provisioning cleanup. A subsequent
root-console check reported the temporary provisioner account and group,
temporary bootstrap paths, and sudo references absent. The permanent
diagnostics account and restricted operation remain installed.

A root-console inspection showed one root-owned diagnostics authorized-key
entry with OpenSSH `restrict` and the forced-command dispatcher. The
dispatcher accepts only `m12-config-readback`; its other request path returns
the fixed rejection status. The effective sudo listing contained only the
fixed no-argument M12 helper. The staging SSH allowlist contains `deploy` and
`caregrid-diagnostics`; the Owner separately confirmed deploy SSH remained
usable. No change to the Laravel cache ACL or application ownership was part
of this diagnostics activation.

## Staging access tests

- The approved M12 request completed successfully through the restricted
  workflow, demonstrating authentication, forced dispatch, activation checks,
  the fixed helper, and sanitized result delivery.
- A direct arbitrary-command probe using the diagnostics key returned SSH
  status 64. The reviewed dispatcher returns 64 for any request other than the
  single allowed operation; this is consistent with the observed rejection.
- A separate local PTY probe returned client status 255. The server journal
  showed the diagnostics public key was accepted but did not record a session
  start or an explicit PTY-denial message, so that individual staging probe is
  inconclusive. The root-owned authorized-key entry uses OpenSSH `restrict`,
  which disables PTY and forwarding, and the exact approved implementation
  passed the disposable Debian 12 real-OpenSSH integration test that requests
  a PTY and requires rejection. No policy or SSH setting was weakened to
  complete validation.

## Protected GitHub workflow and M12 readback

The Owner-approved `staging-diagnostics` Environment contained only the
environment-scoped diagnostics credentials. Its branch restriction was the
designated development branch, with the Owner as reviewer and self-review
allowed for solo-owner operation. No repository-level copies of these
credentials were used.

The manually dispatched read-only run used the same exact approved SHA:

- [M12 run 39750450797](https://github.com/zsoltacsai/wpcaregrid/actions/runs/39750450797) — success.
- The job returned only the approved sanitized fields; no artifact was
  uploaded, and no credential or raw cache content appeared in the result.

Verified output:

| Configuration value | Result |
| --- | --- |
| `privacy.destructive_retention_enabled` | `FALSE` |
| `privacy.profile_anonymization_enabled` | `FALSE` |
| `privacy.backup_deletion_enabled` | `FALSE` |
| `privacy.provider_deletion_enabled` | `FALSE` |
| `privacy.bulk_retention_execution_enabled` | `FALSE` |
| `privacy.synthetic_profile_anonymization_enabled` | `FALSE` |
| `app.deployment_environment` | `staging` |
| `app.deployment_hostname` | `stg1.wpcaregrid.com` |
| `app.url` | `https://staging.wpcaregrid.com` |

These are values from the existing cached configuration snapshot. This
readback did not execute Laravel, query a database, run a scanner, synchronize
Wordfence, deploy code, or change application data.

## Closure boundary

The required P3 staging diagnostics installation, restriction checks, cleanup,
protected credential use, and first authorized M12 readback are evidenced
above. P3 is closed for this bounded diagnostics milestone.

This result does **not** claim a successful Wordfence feed import, scanner
readiness, production vulnerability coverage, or production readiness.
Future staging diagnostics remain limited to the reviewed fixed operation.
Any new remote operation, deployment, scanner run, credential rotation, or
production access requires its own review and authorization.
