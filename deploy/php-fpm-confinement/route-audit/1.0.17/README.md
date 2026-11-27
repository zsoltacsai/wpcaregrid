# Production Nginx route review aid 1.0.17

This artifact reviews an origin configuration for the WP CareGrid 1.0.16 Gate 2.
It is a bounded, redacted review aid only: `route_verdict` can be `HOLD` or
`REVIEW_REQUIRED`, never `PASS`, and its output is not a route attestation.
An authorized Owner must manually compare every route relationship with the
effective configuration and the accepted provider evidence.

## Pins and trust

The package contains `route_summary.py` and the exact primary-source parser
`pinned_deploy_priv.py`. The latter is loaded only after its SHA-256 matches
the constant embedded in the summary tool. In privileged mode the parser must
be root-owned, regular, single-link, and free of group/other write bits. It is
opened with `O_NOFOLLOW` and the opened bytes are checked against the exact
digest before execution. Owner-write on root-owned files and ancestors is
allowed because root is the trusted administrator in this threat model. Do not
use this tool if any manifest, source, ownership, mode, link, or parser pin
check fails.

## Include resolution

The summary reads each `nginx -T` source through the pinned parser's trusted
source reader and requires its bytes to match the captured section (allowing only
the documented final framing newline). It also uses the pinned parser's trusted
filesystem glob resolver and compares actual matches with the files named by
`nginx -T`. It reports
include source-to-target edges in `include_edges` without exposing the raw
include pattern. A zero-match wildcard is accepted only when the pinned
resolver also confirms that the trusted filesystem has no matching file; the
summary then records `empty_glob_confirmed: true`. Any mismatch, unresolved
literal include, unsupported glob, or filesystem uncertainty is `HOLD`.

## Read-only invocation

Run from the exact, root-owned, verified artifact directory on the host. Keep
`nginx -T` output in the pipe only; never save or print the raw configuration.
Create the summary destination in an existing root-only evidence envelope with
a restrictive umask. The `DEST` path must be the absent-safe verified location
for this artifact release:

```sh
set -euo pipefail
set -o noclobber
umask 077
DEST='/usr/local/libexec/wpcg-gate2-route-audits/1.0.17'
EVIDENCE='/root/<existing-root-only-evidence-envelope>'
OUT="$EVIDENCE/nginx-route-summary-1.0.17.json"
test "$(stat -c '%u:%g:%a' "$DEST")" = '0:0:755'
(cd "$DEST" && sha256sum -c --strict SHA256SUMS)
test ! -e "$OUT" && test ! -L "$OUT"
if /usr/sbin/nginx -t >/dev/null 2>&1; then
  printf '%s\n' 'NGINX_TEST_RC=0'
else
  rc=$?
  printf 'NGINX_TEST_RC=%s\n' "$rc"
  exit "$rc"
fi
if /usr/sbin/nginx -T 2>&1 | /usr/bin/python3 -B "$DEST/route_summary.py" "$DEST/pinned_deploy_priv.py" >"$OUT"; then
  printf '%s\n' 'NGINX_SUMMARY_RC=0'
else
  rc=$?
  printf 'NGINX_SUMMARY_RC=%s\n' "$rc"
  exit "$rc"
fi
test "$(stat -c '%u:%g:%a:%h' "$OUT")" = '0:0:600:1'
```

The output includes the effective config digest, source files, include edges,
route contexts, selected approved path classes, variable relationships, and
unsupported syntax. Arbitrary directive values, upstream endpoint values,
and include patterns are not printed. `summary_parse=PASS` means only that the
bounded parser understood the input; it is not a route verdict. Every server
and location relationship still requires manual Owner review. This command
does not prove provider state, create `route-closure.json`, or modify Nginx,
AppArmor, services, fixtures, or application state.

## Tests

The regression suite is `scripts/tests/production-route-audit-1-0-17-test.py`.
It uses fake Nginx config and fake filesystem include resolution. It does not
invoke Nginx or perform host operations.
