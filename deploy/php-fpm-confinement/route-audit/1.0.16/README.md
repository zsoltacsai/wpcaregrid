# Production Nginx route review aid 1.0.16

This pinned tool emits a bounded, redacted summary of the effective Nginx
configuration. It is a review aid only: `route_verdict` can be `HOLD` or
`REVIEW_REQUIRED`, never `PASS`, and its output is not a route attestation.
An authorized Owner must manually compare the summary with the complete
effective route behavior and the current provider evidence before creating
the Gate 2 route-closure record.

## Pins and trust

The package contains `route_summary.py` and the exact primary-source parser
`pinned_deploy_priv.py`. The latter is loaded only after its SHA-256 matches
the constant embedded in the summary tool. In privileged mode the parser must
be root-owned, regular, single-link, non-writable, and opened with
`O_NOFOLLOW`; each ancestor must be root-owned and not group/other-writable.
Owner-write on root-owned ancestors is allowed because root is the trusted
administrator in this threat model. Do not use this tool if any manifest,
source, ownership, mode, link, or parser pin check fails.

## Read-only invocation

Run from the exact, root-owned, verified artifact directory on the host. Keep
`nginx -T` output in the pipe only; never save or print the raw configuration.
Create the summary destination in the existing root-only evidence envelope
with a restrictive umask. Example:

```sh
set -euo pipefail
set -o noclobber
umask 077
DEST='/usr/local/libexec/wpcg-gate2-route-audits/1.0.16'
EVIDENCE='/root/<existing-root-only-evidence-envelope>'
OUT="$EVIDENCE/nginx-route-summary.json"
test "$(stat -c '%u:%g:%a' "$DEST")" = '0:0:755'
test "$(stat -c '%u:%g:%a' "$DEST/route_summary.py")" = '0:0:644'
test "$(stat -c '%u:%g:%a' "$DEST/pinned_deploy_priv.py")" = '0:0:644'
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

The output summarizes route directives, contexts, selected path classes,
variable relationships, and unsupported syntax. It intentionally omits
arbitrary directive values and upstream endpoints. It also includes
`effective_config_sha256`, computed from the exact decoded `nginx -T` input
bytes represented as UTF-8, so the reviewed source can be identified without
retaining the raw dump. A parser `HOLD`, unresolved
variable, unsupported directive/block, unusual syntax, or any ambiguity means
the Owner must review the exact source configuration through a separately
approved method; it is never safe to infer a closed route from a partial
summary. This command does not read or prove CDN/provider state, does not
create `route-closure.json`, and does not modify Nginx, AppArmor, services,
fixtures, or application state.

## Tests

The regression suite is `scripts/tests/production-route-audit-1-0-16-test.py`.
It uses fake Nginx configuration and fake file metadata. It does not invoke
Nginx or host operations.
