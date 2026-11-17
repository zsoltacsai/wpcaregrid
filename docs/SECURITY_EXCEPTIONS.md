# Security exceptions

Exceptions are temporary, owner-approved, narrowly scoped and enforced by code. The dependency security control stays mandatory: nothing here lowers a threshold, skips a check or ignores a package class.

## npm: GHSA-fc5q-39xc-vjpf (picomatch ReDoS) — Owner GO-20.1

| Item | Value |
|---|---|
| Advisory | GHSA-fc5q-39xc-vjpf, npm advisory source id 1247710, "picomatch vulnerable to ReDoS through single-branch repeated extglobs" (CWE-1333, CVSS 7.5, high) |
| Affected range | picomatch `<=4.0.7`. No patched version exists; the latest published picomatch is 4.0.7. |
| Owner approval | Owner GO-20.1 |
| Expiry | **2026-12-01** (UTC). The gate fails on and after that date. |
| Policy data | `config/security/npm-audit-exceptions.json` (reviewed file; the gate validates its schema) |
| Gate | `scripts/npm_audit_gate.py`, test `scripts/tests/npm-audit-gate-test.py` |

### Accepted risk

A regular-expression denial of service in glob matching can at worst stall a frontend build on a pathological glob pattern. The affected packages are build tooling only; they are not part of the running application, any customer or tenant data path, or any deployed artifact.

### Dependency chain

`npm audit --json` reports six high entries, all explained by this single advisory:

```text
picomatch (advisory) -> fdir -> tinyglobby -> vite (direct devDependency)
picomatch (advisory) -> tinyglobby -> laravel-vite-plugin (direct devDependency)
picomatch (advisory) -> vite-plugin-full-reload -> laravel-vite-plugin (direct devDependency)
```

Allowlist: `picomatch`, `fdir`, `tinyglobby`, `vite-plugin-full-reload`, `laravel-vite-plugin`, `vite`. npm's suggested "fixes" are downgrades (vite 6.2.7, laravel-vite-plugin 1.3.0), not real fixes.

### Compensating controls

- Every affected package is `"dev": true` in `package-lock.json`, and none is in `package.json` `dependencies`, `optionalDependencies` or `peerDependencies`. The gate re-verifies this on every run (also no `optional`, `devOptional`, `peer` or `inBundle` flag, and picomatch within `<=4.0.7`). A production path makes it fail.
- `node_modules` is never part of a production artifact; `public/build` is committed and the build runs only on the deploy-side builder.
- `public/build` is verified byte-for-byte by `scripts/tests/verify-committed-vite-build.py`.
- Glob patterns come from reviewed repository configuration, never from external input.
- No Vite dev server runs in CI or in production.

### What the gate does (CI Quality job, replaces the bare `npm audit --audit-level=high` step)

1. Runs `npm audit --json --include=dev --include=optional --include=peer` itself (the flags override `NODE_ENV=production` and `npm_config_omit=dev`, which would otherwise make npm audit nothing and exit 0) and saves the raw report to `$RUNNER_TEMP/npm-audit-report.json`. The CI step prints `npm --version`, the report and its sha256 in a collapsed log group. The GitLab fallback does the same and keeps the gate's exit status. It is **not** uploaded as an Actions artifact: `scripts/tests/ci-supply-chain-policy-test.py` forbids `upload-artifact` in `ci.yml` ("PR artifacts must not become release inputs"), and that guard was left unchanged.
2. Evaluates only `high` and `critical` (the previous threshold is unchanged; moderate/low behave as before). Critical always fails. For every high entry, the whole transitive `via` closure must consist of the excepted advisory only (source id **and** URL **and** name **and** range, on the `picomatch` entry) and allowlisted packages. Any other advisory, even on the same package, an unknown or missing `via` target, or a package outside the allowlist fails.
3. Fails when `metadata.dependencies.dev` is 0 while `package-lock.json` has `dev: true` entries (dev dependencies were not scanned). Fails closed on empty, non-JSON or truncated output, `auditReportVersion` other than 2, missing `metadata`/`vulnerabilities`, an `error` key, npm exit codes other than 0/1, an exit code that contradicts the report, and any mismatch between `metadata.vulnerabilities` counts and the entries listed.
4. Verifies the exposure conditions above from `package.json` and `package-lock.json`.
5. Auto-invalidation: queries the npm registry (`npm view picomatch versions` and `dist-tags`, three attempts). Any stable (non-prerelease) picomatch version that is not in `known_stable_versions` in the policy fails the gate, as does a covered entry whose `fixAvailable` is `true` or a non-major object (a real fix). Severity is the highest of an entry's own severity and its advisories'; the excepted advisory must still be `high`. Registry failure fails the gate.
6. If the audit no longer reports the advisory, the gate passes and prints `exception unused — remove it`.
7. Fails on and after the expiry date. The policy `expires` may never exceed the hard limit `MAX_EXPIRY` (2026-12-01) in the script, so renewal needs a reviewed script change too.
8. Offline/rehearsal flags (`--audit-json`, `--audit-exit-code`, `--registry-json`, `--policy`) are refused when `CI`, `GITHUB_ACTIONS` or `GITLAB_CI` is set; log output from npm is neutralised so it cannot inject workflow commands.

No `--omit=dev`, `--force`, `|| true`, `continue-on-error`, lowered threshold, or package-wide picomatch/vite exception is used.

### Renewal

Only by an explicit new Owner action: a new approval reference and a new `expires` date in `config/security/npm-audit-exceptions.json`, reviewed in a pull request. Nobody extends it silently; an expired exception fails CI.

### Removal procedure

1. A stable picomatch newer than 4.0.7 appears (the gate then fails by itself), or the audit stops reporting the advisory (the gate prints `exception unused`).
2. Update the tree (`npm update`, rebuild, run `scripts/tests/verify-committed-vite-build.py`, commit `public/build` if it changes).
3. Restore the plain `npm audit --audit-level=high` step in `.github/workflows/ci.yml` and `.gitlab-ci.yml`; delete `config/security/npm-audit-exceptions.json`, `scripts/npm_audit_gate.py`, `scripts/tests/npm-audit-gate-test.py` and this section.
