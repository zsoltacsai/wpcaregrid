# GitHub Actions Pin Matrix

Source: `.github/workflows/ci.yml` at `291f486…`. Eight remote `uses:` occurrences, three unique actions. SHA resolutions below were read from the official GitHub repositories' tag refs on 2026-10-17; they are audit evidence, not an implementation pin authorization. Re-resolve and verify tag ownership/release notes in each implementation PR.

| Action | Current ref and count | Official current tag resolution observed | Job use / secret surface | Planned rule and residual trust |
|---|---|---|---|---|
| `actions/checkout` | `@v7` ×3 | `3d3c42e5aac5ba805825da76410c181273ba90b1` (tag ref points directly to commit) | Quality, Server, Connector; checks out public source. Read-only `GITHUB_TOKEN`; no secrets. | Pin complete 40-hex SHA and annotate `# v7.x.y`. Validate SHA is in `actions/checkout`, review source. Action can still fetch/execute downstream content and GitHub platform remains trusted. |
| `actions/setup-node` | `@v7` ×2 | `949feb2413d6458794dcd2491c4babbbce0c15c1` | Quality and Server; enables Node 22 and npm cache using lockfile. | Pin full SHA, version comment, reviewed update PR. Downloaded Node distribution and runner remain external inputs. |
| `shivammathur/setup-php` | `@v2` ×3 | Annotated tag object dereferences to `eb7c497e18156a6bbabfef1d3a82760b9eda3962` | Quality, Server, Connector; requests PHP 8.4/extensions and `composer:v2`. | Pin full SHA. Review action workflow/source and update process. Pin does not freeze PHP extension binaries, runner packages, Composer v2 patch, or their upstream sources. Keep this residual explicit. |

No action publisher status or tag comment should be treated as integrity proof. Full commit SHA is authoritative; review that commit in the named upstream repository. Immutable pins mitigate tag retargeting, not a malicious already-pinned commit, compromised downloads performed by the action, or GitHub compromise. GitHub documents full-length SHA pinning as the immutable action reference and recommends verifying the SHA belongs to the action repository: [GitHub secure use reference](https://docs.github.com/en/actions/reference/security/secure-use).

## Update process

Add Dependabot `github-actions` PRs, or use an equivalent scheduled manual review. No auto-merge. PR must show old ref/SHA, proposed release tag/new SHA, upstream URL, changelog/security notes, SHA provenance, required seven-job CI, and review. Keep a static test that rejects every non-local `uses:` lacking exactly 40 hex characters. No third-party dependency-review action is required for this control.

`setup-php` is a high-leverage third-party provisioner because it installs PHP/extensions/Composer tooling; action-source pinning is necessary but not complete binary provenance. Track it for M14.6 update and M14.6/M14.7 threat review. Do not replace it or add self-hosted runners absent evidence.
