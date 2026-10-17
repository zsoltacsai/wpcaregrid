# Staging Deployment Trust Chain

Audited source: `scripts/deploy-staging.sh`, `scripts/run-scheduler.sh`, `docs/DEPLOYMENT.md`, current staging host over strict SSH. This is not a production review or change.

## Observed chain

1. Human runs `bash scripts/deploy-staging.sh <full SHA>` as `deploy` from `/var/www/wpcaregrid`; script path is the currently checked-out trusted baseline. There is no GitHub Actions deployment job.
2. Script requires deploy identity, exact app directory, fixed origin `git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git`, `.env` ownership/mode 0600, clean checkout, existing healthy staging state and expected staging environment.
3. It locks deployment; captures immutable baseline; fetches only fixed authoritative branch; accepts exactly 40-hex commit and requires object type commit, different from baseline, ancestor of fetched branch tip. It stores target SHA; no later pull/branch checkout substitutes it.
4. Creates a mode-0600 gzip-verified DB dump in `deploy`-owned mode-0700 directory; coordinates scheduler lock/pause, Nginx gate and named queue workers before source switch.
5. Checks out exact target, runs Composer production install, npm clean install/build, migrations, caches and Connector build/verification; checks exact HEAD, working tree, PHP-FPM readability, workers, scheduler, health and HTTP security smoke.
6. On safe pre-migration failure it can restore pinned baseline code/dependencies/assets; after migration attempt it does not automatically rollback data and deliberately keeps service gated if recovery is unproven. No automated database restore.

## Host readback

- Client→staging SSH host key uses strict checking and matches the previously approved fingerprint (`SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`). SSH authentication succeeded without trust changes.
- Staging is `stg1`; current runtime HEAD equals reported staging SHA `3debba99…`; origin exactly matches script; tree clean.
- GitHub SSH alias `github.com-wpcaregrid` resolves to `github.com`, `User git`, `IdentitiesOnly yes`, `StrictHostKeyChecking yes`, known_hosts pinned; dedicated `github_wpcaregrid_ed25519` key mode 0600. GitHub Deploy Key API identifies `stg1.wpcaregrid.com deployment`, `read_only:true`.
- `/var/www/wpcaregrid` and `app` owned by deploy; `www-data` cannot write app source. `.env` is `deploy:deploy` 0600 and unreadable to `www-data`. Private backup directory is deploy-owned 0700.
- **Before cutover:** `sudo -n -l` listed `(ALL : ALL) ALL` / `NOPASSWD: ALL`, and deploy belonged to `sudo` group. **After cutover:** only `(root) NOPASSWD: /usr/local/sbin/wpcaregrid-deploy-priv *` remains; deploy is no longer in `sudo`. The helper and protected controller are `root:root 0755` and non-writable to deploy/www-data. Helper source SHA-256 `cb71e5ef3a9cdf685cd02515f8484ceffad53750c6748a1335cf1251ab377af1` derives from merged PR #106 SHA `854a26832d864146fc493e75225cf6438ed932b9`. M146-F02 is closed.
- No private values, `.env` content, or private key were read or emitted. Deployment receipt is not structured: logs record target and completion; runtime checks HEAD, but there is no durable machine-readable receipt containing baseline, target, CI run, migration marker, Connector/package digest and health.

## Containment deployment attempt and recovery

The root-owned reviewed controller from merge `854a26832d864146fc493e75225cf6438ed932b9` was invoked against the clean baseline only after narrow sudo was verified. It passed initial checks, created a private pre-deploy DB backup, guarded scheduler/Nginx, and stopped both named workers. It then aborted before git checkout because helper v1 treated Supervisor's nonzero status for a valid `STOPPED` state as an operation failure (M146-F10). Controller recovery left the gate closed; recovery used only helper `worker start-all`, `scheduler-resume` and `gate-off`. Post-recovery: HEAD remains `3debba99c677b68465cf439733f440442fbd0f22`, tree clean, both workers RUNNING, scheduler resumed, no gate/pause markers, `/up=200`, and `php artisan system:check` passed deploy-critical parts. The baseline was restored without code switch or target-controlled install.

## Target-code timing and trust-controller finding

The running deployment shell is loaded from the checked-out baseline before target switch; target source does not replace that running shell. The reviewed PR #106 controller installs only the exact hash-pinned `scripts/run-scheduler.sh` through the fixed helper, then executes target `composer install` (including allowed Composer scripts), `npm run build`, Artisan migrations, cache commands and Connector packaging as deploy. The active sudo policy no longer grants arbitrary root commands to that user. A helper status bug currently blocks this release before the code switch; M146-F10 tracks the correction.

The exact-SHA/ancestry checks establish commit identity and branch ancestry, not that the SHA was merged through an approved PR or has green checks. Combined with absent branch protection/rulesets and an operator-supplied target, direct primary commits are promotable. Do not treat “ancestor of primary” as equivalent to “reviewed release”.

## Future release protocol

- Require reviewed PR, all seven PR-head checks, merge to authoritative branch, then a successful CI run attached to the exact merged primary SHA. The deployment receipt records both run ID and candidate SHA.
- Deploy only that merged full SHA after read-only verification of branch ancestry, repository origin/key scope and clean baseline. Use a separate audited rollback authorization for historical SHA, without weakening forward-release checks.
- Run target-controlled build/migration steps without root. Replace broad sudo with a small root-owned helper taking enumerated fixed actions and validated literals; do not allow shell strings, arbitrary paths, or target-controlled command names.
- Preserve strict client→staging and staging→GitHub host-key checks. Track key/fingerprint scope and rotation through reviewed admin evidence; no blind host-key acceptance.
- Add a private machine-readable receipt outside app tree: requested SHA, actual HEAD, baseline SHA, branch/origin identity, deploy actor/time, CI run ID/conclusion, lockfile hashes, PHP/Composer/Node versions, migration batch/status, Connector version/source and ZIP SHA-256, build manifest hash, health result. No `.env`, token or credential values.
- Keep current in-place build-on-staging approach for now: it avoids CI artifact substitution and preserves exact source deploy, but it executes builds on a privileged host. After sudo isolation, this is acceptable for staging; do not shift to CI artifacts without provenance verification, source SHA binding, checksum validation, rollback and storage policy.

Future fault matrix is in `m14-6-fault-injection-plan.md`. Staging-only; no production test or customer mutation.
