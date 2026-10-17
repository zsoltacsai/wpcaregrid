# M14.6 Fault-Injection Plan

All tests execute on disposable CI or isolated staging fixtures only. No production setting, real customer mutation, production secret, customer backup deletion, provider deletion, or AI credential is involved.

| Area | Injected condition | Expected fail-safe result / evidence |
|---|---|---|
| Workflow policy | Mutable action tag/branch; missing required job; removed MySQL process gate; widened token; `pull_request_target`; deployment command on PR; custom self-hosted runner; fork context inserted into `run` | Static checker fails with file/line; all safe fixtures pass. Checker runs independently of workflow's own CI logic where practical. |
| Workflow context | Newline/`::error::`/`::add-mask::` in path/title/branch/dispatch data; option-like/multiline `connector_release_base`; untrusted string to shell or `$GITHUB_ENV` | No command/output injection; no raw untrusted log annotation; dispatch revision is constrained to expected commit syntax before use. |
| Action pin integrity | Wrong SHA, SHA from unrelated fork, mutable ref | Fail before merge; release comment is informational only; SHA verified in upstream repo. |
| Gitleaks supply | Download failure, truncated archive, wrong checksum, unsafe archive member | No execution, no extraction outside temp, job fails clearly, no secret printed. |
| Dependencies | Composer/Packagist unavailable, lock mismatch, npm registry failure, integrity mismatch, Moto index failure | Install/build fails before release; no lockfile mutation; job fails; no artifact becomes deployment candidate. |
| Connector release | Source changed without version bump, mismatched release metadata, ZIP corruption, source/package hash mismatch | Package command fails; no private package replacement/serving; old exact artifact remains intact. |
| Git origin | Remote origin changed to fork/HTTPS/unexpected alias; SSH key untrusted; host key missing/changed | Deploy aborts before backup/code switch; no blind acceptance; no credential output. |
| Forward release authorization | Parent/PR-head SHA, not current branch tip, missing/wrong-event run, run queued/canceled/failed, any mandatory job absent/failed/duplicated, MySQL process gate failed, incomplete API pagination, timeout, API error, redirect or oversized JSON | Fail before backup/gate/checkout; no GitHub token or staging mutation. |
| Target SHA | Short/nonhex SHA, tag/tree/blob, unrelated branch commit, old non-ancestor, same as baseline, deleted/raced object | Reject before backup/switch; exact full SHA only. Historical rollback uses separately approved path. |
| Explicit rollback approval | No approval file; malformed SHA; symlink/wrong owner/wrong mode file; SHA mismatch; target not ancestor of current baseline/primary; failed exact-SHA push CI | Reject rollback before backup/gate. Approval must be a separate root/admin action. |
| Baseline | Dirty tracked/untracked file, symlink surprise, branch/HEAD drift after capture | Abort and leave current release unchanged; no `git clean`/reset. |
| Privilege | Target build attempts `sudo id`, writes root-owned path, changes users/services, malformed helper arguments | After F02 fix, no elevation beyond explicit helper operations; root path attempt denied and audited. |
| Concurrent deploy | Two deploys start simultaneously | One lock winner; second exits before mutation; no interleaved checkout, dependency tree, migration, scheduler or worker state. |
| Backup/gate | Disk full, MySQL backup failure, corrupt gzip, backup path symlink, gate install/probe failure | No target code switch; fail closed; no unsafe gate release. Existing backups preserved. |
| Build/migration | Composer failure, npm/build/Vite manifest failure, migration failure/partial nontransactional failure, unexpected pending migration | Before migration safe rollback to pinned baseline; after migration attempted, keep gate/pause and require reviewed recovery; no auto migration rollback/database restore. |
| Runtime transition | Worker stop/start failure, scheduler lock contention/heartbeat failure, HTTP/system health failure | Nginx gate/maintenance remains active; workers/scheduler state checked; never report successful deployment. |
| Rollback | Baseline dependency rebuild fails, baseline HEAD mismatch, rollback database ambiguity | Keep service gated, preserve backup and receipt, require human recovery; no forced reopen. |
| Evidence | CI run on parent/sibling only; canceled merged SHA run; runtime HEAD mismatch; receipt write failure | Deployment denied or not claimed complete. Exact candidate CI and actual runtime SHA required. |
| Receipt provenance | Existing destination, symlinked artifacts/lockfiles, mismatched Connector source revision/package digest, missing Vite manifest, write/fsync failure | Fail closed; if after gate release, immediately re-engage Nginx gate and Laravel maintenance before exit. Never include `.env` or raw exception content. |

Every destructive-style condition is tested with fakes or no-op sentinels; this milestone never tests production, does not exercise provider deletion, and does not enable M12 gates.
