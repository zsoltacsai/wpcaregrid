# Staging-next helper 1.4.12 — installation contract

This document describes source-qualified installation inputs. **It does not
claim that the helper is installed or that staging has been deployed.** No host,
database, migration or service operation is authorized merely by merging this
source change. Use the reviewed [1.4.12 operator runbook](STAGING_NEXT_HELPER_1_4_12_RUNBOOK.md)
for the exact HP-to-VPS transfer and root transition sequence.

## Versioned artifact identity

The immutable profile is `staging-next`, helper `1.4.12`, contract
`wpcaregrid-staging-next-deploy-v16`. The manifest SHA-256 is
`602f8427974d7ebe7f30837bd8dc5304c9fe82334d4a8b2ba9c2e63c648caa7e`.
The manifest pins the following five helper files:

| Source artifact | SHA-256 |
|---|---|
| `scripts/wpcaregrid-staging-next-deploy-priv-1_4_12.py` | `8b1b9d3802762a5ff3986162b43e20fedd374bc05a44ead0dfa6bbfa5b286324` |
| `scripts/staging_next_deploy_backend_1_4_12.py` | `06ad66b01810d2a81c789472b9faf432d46d3421df4a6731f8bc9400a40cacf5` |
| `scripts/staging_next_db_transaction_1_4_12.py` | `5580ebb2c7a0a34b1f1f3aef7dd5f0603ed2361092c91b083b7724b1bf885272` |
| `scripts/deployment_target_binding_1_4_12.py` | `cc7f29742e1bcc44f72b068bf24e674a5bf591a07a339636dacd636e322fd52a` |
| `scripts/deployment_environment_contract_1_4_12.py` | `a116824d7f5fa66a1983d468ad002a3431372b2c86d71dcbd84072c156b5ea6a` |

Combined helper artifact SHA-256: `29a01edb67de50a6387e1c87207f502f5c55c73d716035710a29d9b77181c913`.
The root installer SHA-256 is
`a6126bd9f43d53f69dc213b466b66a15402e21adbbe3c88ab95590dab890aecc`.
The installer is transferred as a separate root-operation input and validates
all five helper files again before publication. Prior 1.4.10 and 1.4.11 source
bytes are unchanged.

## Application and activation paths

The existing `/var/www/wpcaregrid-staging-new` remains the permanent application
root and is preserved. The replaceable active-release path is the distinct,
root-owned sibling symlink `/var/www/wpcaregrid-staging-next-current`. Nginx's
staging-next document root is
`/var/www/wpcaregrid-staging-next-current/public`, using the existing PHP 8.5
FPM socket `/run/php/php8.5-fpm.sock`. The installer verifies this contract and
accepts the activation link as absent or correctly shaped; it does not create
or replace it. A dedicated, receipt-producing transition utility owns the
one-time Nginx path change and can roll it back only against its exact receipt.

The first deployment intentionally refuses with
`bootstrap_baseline_requires_authenticated_release_receipt`. No baseline
adoption receipt is created by this helper or the path transition. The existing
application tree must be authenticated and bound to its source/config/database
identity by a separate reviewed recovery-backed procedure before first deploy.

## Installation scope and fail-closed conditions

The installer publishes only the versioned helper files, profile, and exact
restricted sudoers entry. It publishes sudoers last. It does not install
Laravel, alter the application tree or database, execute migrations, enable the
Phase B policy, or start workers/schedulers. Phase B stays disabled after
installation. Installation refuses if any old Phase B policy or sudoers control
for versions 1.4.5 through 1.4.11 remains; it does not edit or remove those
controls or old helper bytes. If one is present, stop and use a separately
reviewed, exact-path transition. Do not disable a route by guessing its content.

The separately pinned host operations are:

| File | SHA-256 |
|---|---|
| `scripts/wpcg_staging_next_runtime_layout.py` | `a37b3346d354350a239e83b39253f48edf1f4f42fa201c714fdbf56353779957` |
| `scripts/wpcg-staging-next-path-transition-1_0.py` | `84ae27a8b725d17160716740cc31e2749fe4638e944a4b2ab4d3b1b9cbe2b9f2` |

They and the installer are sent to the separate deploy-owned operations folder,
then executed as root only after a same-open-file hash, ownership, mode, link-count
and size verification. No `sha256sum`-then-reopen execution pattern is used.

Any unexpected identity, file, mode, hash, symlink, Nginx route, PHP-FPM socket,
legacy Phase B route or installer result is STOP. Preserve partial artifacts and
receipts for review; do not delete or overwrite by pathname.

## Remaining gates

Helper installation does not qualify a database-changing deployment. The
following remain separate prerequisites: authenticated current backup and
protected configuration recovery reference, approved isolated MySQL target,
actual restore receipt, complete migration-ledger reconciliation, restored-clone
migration rehearsal, and an approved durable least-privilege DEFINER/DBA handoff.
The helper deliberately does not manufacture baseline or recovery evidence.
