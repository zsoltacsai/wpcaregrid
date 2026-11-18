# Security exceptions

Exceptions are temporary, owner-approved, narrowly scoped and enforced by code. The dependency security control stays mandatory: nothing here lowers a threshold, skips a check or ignores a package class.

**There are currently no active exceptions.**

## CLOSED — npm: GHSA-fc5q-39xc-vjpf (picomatch ReDoS) — Owner GO-20.1

Closed 2026-11-18. The upstream advisory was withdrawn by GitHub on 2026-11-18
("withdrawn because it is a security issue"; CVE-2026-112366, npm advisory
source id 1247710, picomatch ReDoS through single-branch repeated extglobs,
affected range `<=4.0.7`, no patched version ever published). With the advisory
withdrawn, `npm audit` reports zero vulnerabilities for the current lockfile,
so the exception became unused, and its gate's scope canary — which expected
the lockfile's vulnerable picomatch to remain visible to the audit — failed
closed with "audit scope incomplete".

Per the exception's own documented removal procedure (this register's former
"Removal procedure" section), the gate machinery was removed —
`config/security/npm-audit-exceptions.json`, `scripts/npm_audit_gate.py`,
`scripts/tests/npm-audit-gate-test.py` — and the plain
`npm audit --audit-level=high` CI step was restored in
`.github/workflows/ci.yml` and `.gitlab-ci.yml`. No dependency-tree change was
needed: the lockfile still contains picomatch 4.0.7, which upstream no longer
flags. No threshold was lowered and no package class was excluded by the
closure; the mandatory dependency security control is unchanged.

Historical record (Owner GO-20.1; never renewed, closed by upstream withdrawal
13 days before its 2026-12-01 expiry): the exception covered six dev-only
`high` entries propagating from the single picomatch advisory — `picomatch`,
`fdir`, `tinyglobby`, `vite`, `vite-plugin-full-reload`, `laravel-vite-plugin` —
all `"dev": true` in `package-lock.json` and none in `package.json`
`dependencies`/`optionalDependencies`/`peerDependencies`; `node_modules` was
never part of a production artifact; the committed `public/build` was verified
byte-for-byte by `scripts/tests/verify-committed-vite-build.py`; glob patterns
came only from reviewed repository configuration; no Vite dev server ran in CI
or production. The gate machinery (two AppSec review rounds, F1-F8 and R1-R7
hardening) was itself reviewed and is preserved in git history.
