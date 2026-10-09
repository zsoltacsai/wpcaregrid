#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
temp_dir="$(mktemp -d "${TMPDIR:-/tmp}/wpcg-deploy-smoke.XXXXXX")"
trap 'rm -rf -- "$temp_dir"' EXIT
mkdir -p -- "$temp_dir/bin"

cat >"$temp_dir/bin/git" <<'GIT_STUB'
#!/usr/bin/env bash
set -eu
[[ "$#" == 2 && "$1" == 'rev-parse' && "$2" == 'HEAD' ]] || exit 99
[[ "${SMOKE_CASE:-}" != 'wrong_sha' ]] || { printf '%040d\n' 0; exit 0; }
printf '%s\n' "${SMOKE_SHA:-f0194bca2d58b8eafccb1dfd1e3fc6176ed132de}"
GIT_STUB

cat >"$temp_dir/bin/curl" <<'CURL_STUB'
#!/usr/bin/env bash
set -eu

dump_headers=0
for arg in "$@"; do
    [[ "$arg" == '--dump-header' ]] && dump_headers=1
    [[ "$arg" == '-L' || "$arg" == '--location' ]] && { printf 'Redirect following is forbidden in this test.\n' >&2; exit 98; }
done

url="${!#}"
[[ "$url" == https://staging.wpcaregrid.com/* ]] || { printf 'Unexpected smoke origin: %s\n' "$url" >&2; exit 99; }
path="${url#https://staging.wpcaregrid.com}"
status=404
location=''

case "${SMOKE_PHASE}:${path}" in
    baseline:/) status=200 ;;
    target:/) status=301; location='https://staging.wpcaregrid.com/en/' ;;
    target:/pricing) status=301; location='https://staging.wpcaregrid.com/en/pricing' ;;
    target:/en/|target:/en/pricing|target:/hu/|target:/hu/pricing) status=200 ;;
    baseline:/up|target:/up) status=200 ;;
    baseline:/owner/integrations|target:/owner/integrations) status=302 ;;
    baseline:/.env|target:/.env) status=403 ;;
    baseline:/.git/config|target:/.git/config) status=404 ;;
    *) printf 'Unexpected URL requested by smoke check: %s\n' "$url" >&2; exit 99 ;;
esac

case "${SMOKE_CASE}:${path}" in
    baseline_root_error:/) status=500 ;;
    baseline_up_error:/up) status=503 ;;
    baseline_root_redirect:/) status=301; location='https://staging.wpcaregrid.com/en/' ;;
    baseline_wrong_host:/) status=301; location='https://wpcaregrid.com/en/' ;;
    root_200:/) status=200; location='' ;;
    wrong_location:/) location='https://staging.wpcaregrid.com/pricing' ;;
    wrong_host:/) location='https://wpcaregrid.com/en/' ;;
    localhost:/) location='http://localhost/en/' ;;
    wrong_scheme:/) location='http://staging.wpcaregrid.com/en/' ;;
    redirect_loop:/) location='https://staging.wpcaregrid.com/' ;;
    wrong_redirect_status:/) status=302 ;;
    root_error:/) status=500; location='' ;;
    wrong_pricing_location:/pricing) location='https://staging.wpcaregrid.com/hu/pricing' ;;
    missing_en:/en/) status=404 ;;
    missing_hu_pricing:/hu/pricing) status=500 ;;
    up_error:/up) status=503 ;;
    owner_exposed:/owner/integrations) status=200 ;;
    env_exposed:/.env) status=200 ;;
    network_error:/) exit 7 ;;
esac

if (( dump_headers )); then
    printf 'HTTP/1.1 %s Stub\r\n' "$status"
    [[ -z "$location" ]] || printf 'Location: %s\r\n' "$location"
    printf 'Content-Length: 0\r\n\r\n%s' "$status"
else
    printf '%s' "$status"
fi
CURL_STUB

chmod 0700 "$temp_dir/bin/git" "$temp_dir/bin/curl"
export PATH="$temp_dir/bin:$PATH"

source "$repo_root/scripts/deploy-staging.sh"

scenarios=0
run_accepted_case() {
    local phase="$1" scenario="$2" smoke_function="$3"

    if SMOKE_PHASE="$phase" SMOKE_CASE="$scenario" "$smoke_function" >"$temp_dir/${phase}-${scenario}.log" 2>&1; then
        scenarios=$((scenarios + 1))
    else
        cat "$temp_dir/${phase}-${scenario}.log" >&2
        printf 'The %s smoke contract rejected valid scenario: %s\n' "$phase" "$scenario" >&2
        exit 1
    fi
}

run_rejected_case() {
    local phase="$1" scenario="$2" smoke_function="$3" expected_message="$4"

    if SMOKE_PHASE="$phase" SMOKE_CASE="$scenario" "$smoke_function" >"$temp_dir/${phase}-${scenario}.log" 2>&1; then
        printf 'The %s smoke contract accepted invalid scenario: %s\n' "$phase" "$scenario" >&2
        exit 1
    fi
    if [[ -n "$expected_message" ]] && ! grep -Fq -- "$expected_message" "$temp_dir/${phase}-${scenario}.log"; then
        cat "$temp_dir/${phase}-${scenario}.log" >&2
        printf 'Scenario %s failed for an unexpected reason.\n' "$scenario" >&2
        exit 1
    fi
    scenarios=$((scenarios + 1))
}

run_accepted_case baseline valid pre_deploy_smoke
run_rejected_case baseline wrong_sha pre_deploy_smoke 'is not the reviewed baseline'
run_rejected_case baseline baseline_root_error pre_deploy_smoke 'HTTP / returned 500, expected 200'
run_rejected_case baseline baseline_up_error pre_deploy_smoke 'HTTP /up returned 503, expected 200'
run_rejected_case baseline baseline_root_redirect pre_deploy_smoke 'HTTP / returned 301, expected 200'
run_rejected_case baseline baseline_wrong_host pre_deploy_smoke 'HTTP / returned 301, expected 200'
run_rejected_case baseline owner_exposed pre_deploy_smoke 'expected an authentication redirect'
run_rejected_case baseline env_exposed pre_deploy_smoke 'Protected path /.env returned 200'
run_rejected_case baseline network_error pre_deploy_smoke ''
run_rejected_case baseline valid post_deploy_smoke 'HTTP / returned 200, expected 301'

run_accepted_case target valid post_deploy_smoke
run_rejected_case target valid pre_deploy_smoke 'HTTP / returned 301, expected 200'
run_rejected_case target root_200 post_deploy_smoke 'HTTP / returned 200, expected 301'
run_rejected_case target wrong_location post_deploy_smoke 'Location was'
run_rejected_case target wrong_host post_deploy_smoke 'Location was'
run_rejected_case target localhost post_deploy_smoke 'Location was'
run_rejected_case target wrong_scheme post_deploy_smoke 'Location was'
run_rejected_case target redirect_loop post_deploy_smoke 'Location was'
run_rejected_case target wrong_redirect_status post_deploy_smoke 'returned 302, expected 301'
run_rejected_case target root_error post_deploy_smoke 'returned 500, expected 301'
run_rejected_case target wrong_pricing_location post_deploy_smoke 'Location was'
run_rejected_case target missing_en post_deploy_smoke 'HTTP /en/ returned 404, expected 200'
run_rejected_case target missing_hu_pricing post_deploy_smoke 'HTTP /hu/pricing returned 500, expected 200'
run_rejected_case target up_error post_deploy_smoke 'HTTP /up returned 503, expected 200'
run_rejected_case target owner_exposed post_deploy_smoke 'expected an authentication redirect'
run_rejected_case target env_exposed post_deploy_smoke 'Protected path /.env returned 200'
run_rejected_case target network_error post_deploy_smoke ''

printf 'Deployment HTTP smoke contract tests passed: %s scenarios.\n' "$scenarios"
