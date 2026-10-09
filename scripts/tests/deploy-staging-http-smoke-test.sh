#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/wpcg-deploy-smoke.XXXXXX")"
trap 'rm -rf -- "$temp_dir"' EXIT
mkdir -p -- "$temp_dir/bin"

cat >"$temp_dir/bin/curl" <<'CURL_STUB'
#!/usr/bin/env bash
set -eu

dump_headers=0
for arg in "$@"; do
    [[ "$arg" == '--dump-header' ]] && dump_headers=1
    [[ "$arg" == '-L' || "$arg" == '--location' ]] && { printf 'Redirect following is forbidden in this test.\n' >&2; exit 98; }
done

url="${!#}"
path="${url#https://staging.wpcaregrid.com}"
status=404
location=''

case "$path" in
    /) status=301; location='https://staging.wpcaregrid.com/en/' ;;
    /en/|/up|/en/pricing|/hu/|/hu/pricing) status=200 ;;
    /pricing) status=301; location='https://staging.wpcaregrid.com/en/pricing' ;;
    /owner/integrations) status=302 ;;
    /.env) status=403 ;;
    /.git/config) status=404 ;;
    *) printf 'Unexpected URL requested by smoke check: %s\n' "$url" >&2; exit 99 ;;
esac

case "$SMOKE_CASE:$path" in
    wrong_location:/) location='https://staging.wpcaregrid.com/pricing' ;;
    wrong_host:/) location='https://wpcaregrid.com/en/' ;;
    redirect_loop:/) location='https://staging.wpcaregrid.com/' ;;
    wrong_redirect_status:/) status=302 ;;
    wrong_pricing_location:/pricing) location='https://staging.wpcaregrid.com/hu/pricing' ;;
    root_error:/) status=500; location='' ;;
    missing_en:/en/) status=404 ;;
    missing_hu_pricing:/hu/pricing) status=500 ;;
    network_error:/) exit 7 ;;
esac

if (( dump_headers )); then
    printf 'HTTP/1.1 %s Stub\r\n' "$status"
    [[ -z "$location" ]] || printf 'Location: %s\r\n' "$location"
    printf 'Content-Length: 0\r\n\r\n\n%s' "$status"
else
    printf '%s' "$status"
fi
CURL_STUB
chmod 0700 "$temp_dir/bin/curl"
export PATH="$temp_dir/bin:$PATH"

source "$repo_root/scripts/deploy-staging.sh"

scenarios=0
if SMOKE_CASE=valid check_http >"$temp_dir/valid.log" 2>&1; then
    scenarios=$((scenarios + 1))
else
    cat "$temp_dir/valid.log" >&2
    printf 'The expected routing contract was rejected.\n' >&2
    exit 1
fi

run_rejected_case() {
    local scenario="$1"
    local expected_message="$2"

    if SMOKE_CASE="$scenario" check_http >"$temp_dir/${scenario}.log" 2>&1; then
        printf 'The smoke check accepted invalid scenario: %s\n' "$scenario" >&2
        exit 1
    fi
    if [[ -n "$expected_message" ]] && ! grep -Fq -- "$expected_message" "$temp_dir/${scenario}.log"; then
        cat "$temp_dir/${scenario}.log" >&2
        printf 'Scenario %s failed for an unexpected reason.\n' "$scenario" >&2
        exit 1
    fi
    scenarios=$((scenarios + 1))
}

run_rejected_case wrong_location 'Location was'
run_rejected_case wrong_host 'Location was'
run_rejected_case redirect_loop 'Location was'
run_rejected_case wrong_redirect_status 'returned 302, expected 301'
run_rejected_case root_error 'returned 500, expected 301'
run_rejected_case wrong_pricing_location 'Location was'
run_rejected_case missing_en 'HTTP /en/ returned 404, expected 200'
run_rejected_case missing_hu_pricing 'HTTP /hu/pricing returned 500, expected 200'
run_rejected_case network_error ''

printf 'Deployment HTTP smoke contract tests passed: %s scenarios.\n' "$scenarios"
