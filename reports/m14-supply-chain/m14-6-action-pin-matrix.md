# GitHub Actions Pin Matrix

Implementation evidence: eight remote `uses:` occurrences across three unique Actions. Each reference is pinned to the reviewed official upstream commit SHA; the version text is informational. The exact SHA is authoritative. PR #108 exact-head and merged-primary CI passed 7/7 (merged-primary run `39034312524`, attempt 2); latest M14.6 target CI run `39051589381` also passed 7/7.

| Action | Candidate ref / count | Reviewed upstream release mapping | Job use and secret surface | Residual trust |
|---|---|---|---|---|
| `actions/checkout` | `3d3c42e5aac5ba805825da76410c181273ba90b1` x3 (`v7.0.1`) | Official tag resolves directly to this commit. | Quality, Server, Connector; public source checkout; read-only token; no secrets. | Pinned source can still fetch downstream content; GitHub platform and runner remain trusted. |
| `actions/setup-node` | `949feb2413d6458794dcd2491c4babbbce0c15c1` x2 (`v7.1.0`) | Official tag resolves to this commit. | Quality and Server; Node 22 and lockfile-keyed npm cache; no secrets. | Node distribution and hosted runner remain external inputs. Fork PR cache writes are isolated to PR merge refs; default/base cache contents are readable but contain package-cache data only. |
| `shivammathur/setup-php` | `eb7c497e18156a6bbabfef1d3a82760b9eda3962` x3 (`2.40.0`) | Official annotated release tag dereferences to this commit. | Quality, Server, Connector; PHP 8.4/extensions and Composer v2; no secrets. | The action installs maintained PHP/extensions/tools from external distributions; pinning action source does not freeze those binaries or Composer v2 patch. |

These full-SHA pins mitigate tag retargeting and future compromised tags. They do not protect against malicious code already at a pinned commit, downstream downloads, compromised package sources, or GitHub platform compromise. GitHub recommends full-length commit SHA references and verifying that the SHA belongs to the action repository: [GitHub secure-use guidance](https://docs.github.com/en/actions/reference/security/secure-use).

## Update process

`.github/dependabot.yml` requests weekly GitHub Actions PRs. No auto-merge is configured. Review each update as a PR showing old SHA, intended tag/version, new SHA, upstream repository and release notes; verify the tag-to-commit mapping independently; run all seven required CI jobs. Do not use the release comment as integrity evidence. The policy test rejects remote `uses:` references that are not exactly 40 hexadecimal characters; local `./` actions remain allowed.

`setup-php` is a high-leverage third-party toolchain provisioner. Continue reviewing its source and the tool/binary downloads it triggers. No self-hosted runner or SDK is introduced.

## Candidate policy validation

`scripts/tests/ci-supply-chain-policy-test.py` checks every workflow for immutable remote Action refs, forbids `pull_request_target`, enforces top-level read-only token permission, protects the seven current job contexts and matrices including the Server MySQL process gate, rejects obvious deployment references and direct event-context interpolation into shell `run` blocks, checks strict manual SHA grammar, and reviews locked package sources. Local adversarial fixtures exercise mutable tags/branches, unsafe event interpolation, write permission, a missing required job/gate, and deployment inclusion. Exact-head CI remains required before merge.
