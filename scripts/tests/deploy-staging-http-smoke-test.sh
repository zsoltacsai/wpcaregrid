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
case "$1" in
    rev-parse)
        if [[ "${2:-}" == 'HEAD' || ( "${2:-}" == '--verify' && "${3:-}" == 'HEAD^{commit}' ) ]]; then
            [[ "${SMOKE_CASE:-}" != 'missing_sha' ]] || exit 1
            [[ "${SMOKE_CASE:-}" != 'invalid_sha' ]] || { printf 'invalid-sha\n'; exit 0; }
            [[ "${SMOKE_CASE:-}" != 'wrong_sha' ]] || { printf '%s\n' "$TARGET_FIXTURE"; exit 0; }
            cat "$SMOKE_HEAD_FILE"
        elif [[ "${2:-}" == '--verify' && "${3:-}" == *'^{commit}' ]]; then
            printf '%s\n' "${3%%^*}"
        else
            exit 99
        fi
        ;;
    cat-file)
        [[ "${2:-}" == '-t' && "${3:-}" =~ ^[0-9a-f]{40}$ ]] || exit 1
        printf 'commit\n'
        ;;
    status)
        [[ "${2:-}" == '--porcelain' && "${3:-}" == '--untracked-files=all' ]] || exit 99
        [[ "${SMOKE_CASE:-}" != 'status_failure' ]] || exit 1
        [[ "${SMOKE_CASE:-}" != 'dirty_checkout' ]] || printf ' M scripts/deploy-staging.sh\n'
        ;;
    merge-base)
        [[ "${2:-}" == '--is-ancestor' ]] || exit 99
        [[ "${SMOKE_CASE:-}" != 'not_ancestor' ]]
        ;;
    checkout)
        [[ "${2:-}" == '--detach' && "${3:-}" =~ ^[0-9a-f]{40}$ ]] || exit 99
        printf '%s\n' "$3" >"$SMOKE_HEAD_FILE"
        ;;
    *) exit 99 ;;
esac
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
    baseline:/) status=301; location='https://staging.wpcaregrid.com/en/' ;;
    baseline:/en/) status=200 ;;
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
    root_200:/) status=200; location='' ;;
    baseline_root_error:/) status=500 ;;
    baseline_up_error:/up) status=503 ;;
    baseline_wrong_host:/) status=301; location='https://wpcaregrid.com/en/' ;;
    baseline_redirect_to_up:/) status=301; location='https://staging.wpcaregrid.com/up' ;;
    baseline_redirect_target_error:/en/) status=500 ;;
    baseline_redirect_loop:/) status=301; location='https://staging.wpcaregrid.com/' ;;
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
export SMOKE_HEAD_FILE="$temp_dir/current-head"
export TARGET_FIXTURE='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
printf '%s\n' '175ba57985557a8cf26ebe7b7817a03cbae3097a' >"$SMOKE_HEAD_FILE"

source "$repo_root/scripts/deploy-staging.sh"
# Sourcing the controller applies its production PATH; restore the isolated test stubs afterward.
export PATH="$temp_dir/bin:$PATH"

scenarios=0
if (SMOKE_CASE=missing_sha capture_baseline_sha >/dev/null 2>&1); then
    printf 'Missing baseline SHA was accepted.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
if (SMOKE_CASE=invalid_sha capture_baseline_sha >/dev/null 2>&1); then
    printf 'Malformed baseline SHA was accepted.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
capture_baseline_sha >"$temp_dir/baseline-capture.log"
[[ "$BASELINE_SHA" == '175ba57985557a8cf26ebe7b7817a03cbae3097a' ]] || exit 1
scenarios=$((scenarios + 1))
if (capture_baseline_sha >/dev/null 2>&1); then
    printf 'The pinned baseline was captured twice.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
if (pin_target_sha "$BASELINE_SHA" "$TARGET_FIXTURE" forward >/dev/null 2>&1); then
    printf 'A no-op target was accepted.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
if (pin_target_sha 'latest' "$TARGET_FIXTURE" forward >/dev/null 2>&1); then
    printf 'A moving target ref was accepted.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
if (SMOKE_CASE=not_ancestor pin_target_sha "$TARGET_FIXTURE" "$TARGET_FIXTURE" forward >/dev/null 2>&1); then
    printf 'A target outside the fetched branch was accepted.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))
pin_target_sha "$TARGET_FIXTURE" "$TARGET_FIXTURE" forward
[[ "$TARGET_SHA" == "$TARGET_FIXTURE" && "$TARGET_SHA" != "$BASELINE_SHA" ]] || exit 1
scenarios=$((scenarios + 1))
if (pin_target_sha "$TARGET_FIXTURE" "$TARGET_FIXTURE" forward >/dev/null 2>&1); then
    printf 'The pinned target was replaced.\n' >&2; exit 1
fi
scenarios=$((scenarios + 1))

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
run_accepted_case baseline root_200 pre_deploy_smoke
run_rejected_case baseline wrong_sha pre_deploy_smoke 'changed from pinned baseline'
run_rejected_case baseline dirty_checkout pre_deploy_smoke 'checkout changed after'
run_rejected_case baseline status_failure pre_deploy_smoke ''
run_rejected_case baseline baseline_root_error pre_deploy_smoke 'expected 200 or a safe same-origin 301'
run_rejected_case baseline baseline_up_error pre_deploy_smoke 'HTTP /up returned 503, expected 200'
run_rejected_case baseline baseline_wrong_host pre_deploy_smoke 'same-origin Location'
run_rejected_case baseline baseline_redirect_to_up pre_deploy_smoke 'internal or private route'
run_rejected_case baseline baseline_redirect_target_error pre_deploy_smoke 'redirect target returned 500'
run_rejected_case baseline baseline_redirect_loop pre_deploy_smoke 'unsafe path'
run_rejected_case baseline owner_exposed pre_deploy_smoke 'expected an authentication redirect'
run_rejected_case baseline env_exposed pre_deploy_smoke 'Protected path /.env returned 200'
run_rejected_case baseline network_error pre_deploy_smoke ''

run_accepted_case target valid post_deploy_smoke
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

# Simulate the target checkout and both automatic and fail-closed recovery.
mkdir -p -- "$temp_dir/fixture/public/build"
: >"$temp_dir/fixture/public/build/manifest.json"
normalize_tracked_checkout_permissions() { :; }
rebuild_caches() { :; }
composer() { :; }
npm() { :; }
php() { [[ "$#" == 2 && "$1" == 'artisan' && "$2" == 'connector:package' ]]; }
worker_status() { cat "$temp_dir/$1.status"; }
sudo() {
    [[ "$#" == 5 && "$1" == '-n' && "$2" == '/usr/local/sbin/wpcaregrid-deploy-priv' && "$3" == 'worker' && "$4" == 'stop' ]] || return 1
    printf '%s STOPPED\n' "$5" >"$temp_dir/$5.status"
}

(
    printf 'wpcaregrid-default RUNNING pid 101\n' >"$temp_dir/wpcaregrid-default.status"
    printf 'wpcaregrid-backups STOPPED\n' >"$temp_dir/wpcaregrid-backups.status"
    stop_workers_for_rollback
    [[ "$WORKERS_MAY_BE_STOPPED" == 1 ]]
    [[ "$(worker_status wpcaregrid-default)" == 'wpcaregrid-default STOPPED' ]]
    [[ "$(worker_status wpcaregrid-backups)" == 'wpcaregrid-backups STOPPED' ]]
) || { printf 'Partial worker restart was not drained before rollback.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

(
    printf 'wpcaregrid-default FATAL\n' >"$temp_dir/wpcaregrid-default.status"
    if stop_workers_for_rollback >/dev/null 2>&1; then exit 1; fi
) || { printf 'An unknown worker state was accepted for rollback.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

(
    cd "$temp_dir/fixture"
    git checkout --detach "$TARGET_SHA"
    [[ "$(git rev-parse HEAD)" == "$TARGET_SHA" ]]
    MIGRATION_ATTEMPTED=0
    MIGRATIONS_PENDING=0
    CODE_SWITCHED=1
    rollback_code_to_baseline
    [[ "$(git rev-parse HEAD)" == "$BASELINE_SHA" && "$CODE_SWITCHED" == 0 ]]
) || { printf 'Code rollback did not restore the pinned M7 baseline.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

(
    cd "$temp_dir/fixture"
    git checkout --detach "$TARGET_SHA"
    MIGRATION_ATTEMPTED=1
    MIGRATIONS_PENDING=0
    CODE_SWITCHED=1
    if SMOKE_PHASE=target SMOKE_CASE=root_error post_deploy_smoke >"$temp_dir/post-smoke-failure.log" 2>&1; then exit 1; fi
    rollback_code_to_baseline
    [[ "$(git rev-parse HEAD)" == "$BASELINE_SHA" ]]
) || { printf 'Post-deploy smoke failure did not restore the pinned baseline.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

(
    cd "$temp_dir/fixture"
    git checkout --detach "$TARGET_SHA"
    MIGRATION_ATTEMPTED=1
    MIGRATIONS_PENDING=1
    if rollback_code_to_baseline >"$temp_dir/migration-rollback.log" 2>&1; then exit 1; fi
    [[ "$(git rev-parse HEAD)" == "$TARGET_SHA" ]]
) || { printf 'Migration uncertainty did not fail closed.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

(
    cd "$temp_dir/fixture"
    git checkout --detach 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
    MIGRATION_ATTEMPTED=0
    if rollback_code_to_baseline >"$temp_dir/unexpected-head.log" 2>&1; then exit 1; fi
    [[ "$(git rev-parse HEAD)" == 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ]]
) || { printf 'Unexpected checkout SHA was silently accepted.\n' >&2; exit 1; }
scenarios=$((scenarios + 1))

printf '%s\n' "$BASELINE_SHA" >"$SMOKE_HEAD_FILE"

printf 'Deployment HTTP smoke contract tests passed: %s scenarios.\n' "$scenarios"
