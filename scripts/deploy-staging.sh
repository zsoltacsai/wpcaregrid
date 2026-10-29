#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077
export PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
unset BASH_ENV ENV CDPATH GIT_CONFIG GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_SSH GIT_SSH_COMMAND GIT_ASKPASS SSH_ASKPASS SSH_AUTH_SOCK
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL='/dev/null'

readonly APP_DIR='/var/www/wpcaregrid'
readonly DEPLOY_BRANCH='claude/ecstatic-wozniak-oemyuf'
readonly ORIGIN_URL='git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git'
readonly APP_URL='https://staging.wpcaregrid.com'
readonly GATE_DIR='/var/lib/wpcaregrid'
readonly GATE_FILE="${GATE_DIR}/deploy-maintenance"
readonly SCHEDULER_LOCK_FILE="${GATE_DIR}/scheduler.lock"
readonly SCHEDULER_PAUSE_FILE="${GATE_DIR}/scheduler-paused"
readonly DEPLOY_PRIV_HELPER='/usr/local/sbin/wpcaregrid-deploy-priv'
readonly CONTROLLER_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
# Always install the scheduler wrapper from the application checkout being deployed,
# even when the reviewed deploy script is invoked from a protected temporary copy.
readonly SCRIPT_DIR="${APP_DIR}/scripts"
readonly BACKUP_DIR='/home/deploy/private-backups'
readonly WORKERS=('wpcaregrid-default' 'wpcaregrid-backups')

GATE_ACTIVE=0
WORKERS_MAY_BE_STOPPED=0
CODE_SWITCHED=0
MIGRATION_ATTEMPTED=0
MIGRATIONS_PENDING=0
SCHEDULER_LOCK_HELD=0
TEMP_BACKUP=''
CHECK_REPORT=''
DEPLOYMENT_MODE='forward'
CI_RUN_ID=''
PHP_BASELINE_PROFILE=''
PHP_TARGET_PROFILE=''
PHP_BIN='/usr/bin/php8.4'
RUNTIME_SWITCH_ATTEMPTED=0
declare -A OLD_WORKER_PIDS=()

log() {
    printf '[%s] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

privileged() {
    sudo -n "$DEPLOY_PRIV_HELPER" "$@"
}

php() {
    "$PHP_BIN" "$@"
}

composer() {
    "$PHP_BIN" /usr/bin/composer "$@"
}

set_php_profile() {
    case "$1" in
        8.4) PHP_BIN='/usr/bin/php8.4' ;;
        8.5) PHP_BIN='/usr/bin/php8.5' ;;
        *) return 1 ;;
    esac
}

assert_privilege_boundary() {
    local sudo_listing command_lines

    sudo_listing="$(sudo -n -l 2>&1)" || return 1
    command_lines="$(grep -E '^[[:space:]]*\(' <<<"$sudo_listing" | sed 's/^[[:space:]]*//')"
    [[ "$command_lines" == "(root) NOPASSWD: ${DEPLOY_PRIV_HELPER} *" ]]
}

fail() {
    log "ERROR: $*" >&2
    exit 1
}

valid_commit_sha() {
    [[ "$1" =~ ^[0-9a-f]{40}$ ]] && [[ "$(git cat-file -t "$1" 2>/dev/null)" == 'commit' ]]
}

capture_baseline_sha() {
    local current_sha

    [[ ! ${BASELINE_SHA+x} ]] || { log 'The deployment baseline was already captured.' >&2; return 1; }
    current_sha="$(git rev-parse --verify 'HEAD^{commit}')" || return 1
    valid_commit_sha "$current_sha" || { log 'The current release has no valid commit SHA.' >&2; return 1; }
    [[ "$(git rev-parse HEAD)" == "$current_sha" ]] || return 1
    readonly BASELINE_SHA="$current_sha"
    log "Pinned pre-deploy baseline: ${BASELINE_SHA}"
}

assert_baseline_unchanged() {
    local current_sha checkout_status

    [[ -n "${BASELINE_SHA:-}" ]] && valid_commit_sha "$BASELINE_SHA" || return 1
    current_sha="$(git rev-parse --verify 'HEAD^{commit}')" || return 1
    [[ "$current_sha" == "$BASELINE_SHA" ]] || {
        log "Live checkout changed from pinned baseline ${BASELINE_SHA} to ${current_sha}." >&2
        return 1
    }
    checkout_status="$(git status --porcelain --untracked-files=all)" || return 1
    [[ "$checkout_status" == '' ]] || {
        log 'The live checkout changed after the deployment baseline was captured.' >&2
        return 1
    }
}

pin_target_sha() {
    local requested_ref="$1" fetched_tip="$2" target_commit mode="$3"

    [[ ! ${TARGET_SHA+x} ]] || return 1
    [[ -n "${BASELINE_SHA:-}" ]] || return 1
    [[ "$requested_ref" =~ ^[0-9a-fA-F]{40}$ ]] || return 1
    valid_commit_sha "$fetched_tip" || return 1
    target_commit="$(git rev-parse --verify "${requested_ref}^{commit}")" || return 1
    valid_commit_sha "$target_commit" || return 1
    git merge-base --is-ancestor "$target_commit" "$fetched_tip" || return 1
    if [[ "$mode" == 'forward' ]]; then
        [[ "$target_commit" == "$fetched_tip" && "$target_commit" != "$BASELINE_SHA" ]] || return 1
    elif [[ "$mode" == 'rollback' ]]; then
        [[ "$target_commit" != "$BASELINE_SHA" ]] || return 1
        git merge-base --is-ancestor "$target_commit" "$BASELINE_SHA" || return 1
        privileged rollback-allowed "$target_commit" || return 1
    else
        return 1
    fi
    readonly TARGET_SHA="$target_commit"
}

worker_status() {
    privileged worker status "$1"
}

worker_pid() {
    local output

    output="$(worker_status "$1")" || return 1
    [[ "$output" =~ RUNNING[[:space:]]+pid[[:space:]]+([0-9]+) ]] || return 1
    printf '%s' "${BASH_REMATCH[1]}"
}

workers_running() {
    local worker

    for worker in "${WORKERS[@]}"; do
        worker_pid "$worker" >/dev/null || return 1
    done
}

install_scheduler_guard() {
    local wrapper_source cron_started_at attempt

    wrapper_source="${SCRIPT_DIR}/run-scheduler.sh"
    [[ -f "$wrapper_source" ]] || fail 'The repository scheduler wrapper is missing beside the deployment script.'
    command -v logger >/dev/null || fail 'The logger utility is required by the scheduler wrapper.'
    command -v flock >/dev/null || fail 'The flock utility is required for scheduler/deployment coordination.'
    command -v pgrep >/dev/null || fail 'The pgrep utility is required to drain a legacy scheduler process safely.'

    privileged gate-dir-init
    if [[ ! -e "$SCHEDULER_LOCK_FILE" ]]; then
        privileged scheduler-lock-init
    fi
    [[ -f "$SCHEDULER_LOCK_FILE" && ! -L "$SCHEDULER_LOCK_FILE" ]] || fail 'The scheduler lock path must be a regular file, not a symlink.'
    [[ "$(stat -c '%U:%G:%a' "$SCHEDULER_LOCK_FILE")" == 'deploy:www-data:660' ]] || fail 'The scheduler lock must be deploy:www-data mode 0660.'
    privileged scheduler-lock-check || fail 'The scheduler lock ownership or mode is invalid.'

    # Replace the installed wrapper atomically so cron never sees a partial script.
    privileged scheduler-install || fail 'Could not safely install the fixed scheduler wrapper.'
    privileged scheduler-cron-ensure || fail 'The www-data crontab did not match the reviewed scheduler entry.'

    # Cron reloads user crontabs asynchronously. Do not proceed until its log
    # proves it invoked the wrapper at least once; all of this happens while the
    # current application and config cache are still healthy.
    cron_started_at="$(date -u +'%Y-%m-%d %H:%M:%S UTC')"
    log 'Waiting for cron to observe the guarded scheduler crontab.'
    for attempt in {1..75}; do
        if privileged cron-seen "$cron_started_at" 2>/dev/null; then
            log 'Cron invoked the guarded scheduler wrapper.'
            return 0
        fi
        sleep 1
    done

    fail 'Cron did not invoke the guarded scheduler wrapper within 75 seconds.'
}

acquire_scheduler_guard() {
    local attempt

    (( SCHEDULER_LOCK_HELD )) && return 0
    exec 8<>"$SCHEDULER_LOCK_FILE" || fail 'Could not open the scheduler/deployment lock.'
    flock -x -w 90 8 || fail 'Could not acquire the scheduler/deployment lock within 90 seconds.'
    SCHEDULER_LOCK_HELD=1

    # The first wrapper installation can overlap one invocation from cron's
    # previously cached crontab. Drain that unguarded process before the gate.
    for attempt in {1..90}; do
        if ! pgrep -u www-data -f 'artisan schedule:run' >/dev/null; then
            break
        fi
        sleep 1
    done
    pgrep -u www-data -f 'artisan schedule:run' >/dev/null && fail 'A legacy or active scheduler process did not exit after the exclusive lock was acquired.'

    privileged scheduler-probe || fail 'The guarded scheduler wrapper failed its deployment-lock probe.'
    log 'Scheduler wrapper probe confirmed no Laravel bootstrap while deployment lock is held.'
}

pause_scheduler() {
    privileged scheduler-pause
    log 'Persistent scheduler pause marker installed.'
}

resume_scheduler() {
    (( SCHEDULER_LOCK_HELD )) || fail 'Cannot resume the scheduler without holding the deployment lock.'
    privileged scheduler-resume
    flock -u 8
    SCHEDULER_LOCK_HELD=0
    exec 8>&-
    log 'Scheduler resumed after the production config cache was verified.'
}

wait_for_restarted_workers() {
    local attempt worker pid all_restarted

    for attempt in {1..90}; do
        all_restarted=1
        for worker in "${WORKERS[@]}"; do
            if ! pid="$(worker_pid "$worker")" || [[ "$pid" == "${OLD_WORKER_PIDS[$worker]:-}" ]]; then
                all_restarted=0
                break
            fi
        done

        if (( all_restarted )); then
            for worker in "${WORKERS[@]}"; do
                log "$worker RUNNING with new PID ${OLD_WORKER_PIDS[$worker]} -> $(worker_pid "$worker")"
            done
            return 0
        fi

        sleep 1
    done

    return 1
}

start_workers() {
    local worker output

    for worker in "${WORKERS[@]}"; do
        output="$(worker_status "$worker")" || return 1
        if [[ "$output" =~ ^${worker}[[:space:]]+STOPPED([[:space:]]|$) ]]; then
            privileged worker start "$worker" >/dev/null || return 1
        elif [[ ! "$output" =~ ^${worker}[[:space:]]+RUNNING([[:space:]]|$) ]]; then
            return 1
        fi
    done

    wait_for_restarted_workers || return 1
    privileged worker-runtime-check "$PHP_TARGET_PROFILE" || return 1
}

check_system_health() {
    local allow_scheduler_pending=${1:-false}
    local quiet=${2:-false}
    local scheduler_arg=''
    local quiet_arg=''

    if [[ "$allow_scheduler_pending" == 'allow-scheduler-pending' ]]; then
        scheduler_arg='allow-scheduler-pending'
    fi
    if [[ "$quiet" == 'quiet' ]]; then
        quiet_arg='quiet'
    fi

    if [[ -n "$CHECK_REPORT" ]]; then
        rm -f -- "$CHECK_REPORT"
    fi
    CHECK_REPORT="$(mktemp /tmp/wpcaregrid-system-check.XXXXXX.json)"
    chmod 600 "$CHECK_REPORT"

    # In staging, NOT CONFIGURED integrations are expected. The critical
    # application/database/queue/scheduler/safety checks must be OK, and no
    # component may report ERROR.
    php artisan system:check --json >"$CHECK_REPORT" || true
    php -r '
$rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$critical = ["application", "database", "queue", "safety"];
if (($argv[2] ?? "") !== "allow-scheduler-pending") {
    $critical[] = "scheduler";
}
$seen = [];
$failed = false;
foreach ($rows as $row) {
    $key = (string) ($row["check"] ?? "");
    $level = (string) ($row["level"] ?? "");
    if ($key === "" || $level === "") {
        fwrite(STDERR, "system:check returned an incomplete result\n");
        exit(1);
    }
    $seen[$key] = true;
    if (($argv[3] ?? "") !== "quiet") {
        printf("system:check %-18s %s\n", $key, $level);
    }
    $failed = $failed || $level === "ERROR" || (in_array($key, $critical, true) && $level !== "OK");
}
foreach ($critical as $key) {
    if (!isset($seen[$key])) {
        fwrite(STDERR, "system:check omitted a critical component\n");
        $failed = true;
    }
}
exit($failed ? 1 : 0);
' "$CHECK_REPORT" "$scheduler_arg" "$quiet_arg"
}

configure_runtime_cache_acls() {
    privileged cache-acl
}

runtime_cache_access_ok() {
    local cache_dir file

    cache_dir='bootstrap/cache'
    while IFS= read -r -d '' file; do
        privileged file-check www-data read "$file" || return 1
        privileged file-check nobody not-readable "$file" || return 1
    done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)

    cache_dir='storage/framework/views'
    while IFS= read -r -d '' file; do
        privileged file-check www-data read "$file" || return 1
    done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)

    privileged file-check www-data read public/build/manifest.json
}

normalize_tracked_checkout_permissions() {
    local entry metadata path mode directory

    # Git stores executable bits, but umask 077 can make freshly checked-out
    # files/directories unreadable to PHP-FPM. Normalize only tracked regular
    # files to Git's recorded mode and their source-tree parent directories to
    # 0755. Runtime/private directories are deliberately excluded.
    while IFS= read -r -d '' entry; do
        metadata="${entry%%$'\t'*}"
        path="${entry#*$'\t'}"
        mode="${metadata%% *}"

        case "$path" in
            artisan|composer.json|composer.lock|package.json|vite.config.*|postcss.config.*|tailwind.config.*|app/*|bootstrap/*|config/*|database/*|lang/*|public/*|resources/*|routes/*|scripts/*|wordpress/*) ;;
            *) continue ;;
        esac
        case "$path" in
            storage/*|bootstrap/cache/*|public/build/*) continue ;;
        esac

        case "$mode" in
            100644) [[ -f "$path" && ! -L "$path" ]] && chmod 0644 -- "$path" || return 1 ;;
            100755) [[ -f "$path" && ! -L "$path" ]] && chmod 0755 -- "$path" || return 1 ;;
            *) continue ;;
        esac

        directory="$(dirname -- "$path")"
        while [[ "$directory" != '.' ]]; do
            case "$directory" in
                storage|storage/*|bootstrap/cache|bootstrap/cache/*|public/build|public/build/*)
                    break
                    ;;
            esac
            chmod 0755 -- "$directory" || return 1
            directory="$(dirname -- "$directory")"
        done
    done < <(git ls-files --stage -z)

    log 'Tracked checkout permissions normalized from Git file modes; private runtime paths were excluded.'
}

runtime_source_access_ok() {
    local path directory entry
    local -a runtime_paths=(
        artisan
        bootstrap/app.php
        app/Owner/IntegrationCredentials.php
        app/Http/Controllers/Owner/CloudflareDnsController.php
        config/app.php
        vendor/autoload.php
        resources/views/owner/dns/cloudflare.blade.php
        public/build/manifest.json
        wordpress/wp-caregrid-connector/release.json
        wordpress/wp-caregrid-connector/wp-caregrid-connector.php
    )

    for path in "${runtime_paths[@]}"; do
        [[ -f "$path" ]] || { log "Required runtime file is missing: ${path}"; return 1; }
        privileged file-check www-data read "$path" || { log "PHP-FPM cannot read required runtime file: ${path}"; return 1; }
        directory="$(dirname -- "$path")"
        while [[ "$directory" != '.' ]]; do
            privileged file-check www-data execute "$directory" || { log "PHP-FPM cannot traverse required runtime directory: ${directory}"; return 1; }
            directory="$(dirname -- "$directory")"
        done
    done

    while IFS= read -r -d '' entry; do
        path="${entry#*$'\t'}"
        case "$path" in
            app/*|bootstrap/*|config/*|database/*|lang/*|public/*|resources/*|routes/*|scripts/*|wordpress/*|artisan) ;;
            *) continue ;;
        esac
        case "$path" in
            *.php|*.sh|artisan) ;;
            *) continue ;;
        esac
        [[ -f "$path" && ! -L "$path" ]] || continue
        privileged file-check www-data read "$path" || { log "PHP-FPM cannot read tracked runtime source: ${path}"; return 1; }
    done < <(git ls-files -z)

    log 'PHP-FPM can read representative runtime files and all tracked PHP/shell source files, including the distributable Connector source.'
}

check_http_status() {
    local path="$1"
    local expected="$2"
    local actual

    actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}${path}")" || return 1
    [[ "$actual" == "$expected" ]] || { log "HTTP ${path} returned ${actual}, expected ${expected}"; return 1; }
    log "HTTP ${path}: ${actual}"
}

check_http_redirect() {
    local path="$1"
    local expected_location="$2"
    local response actual status_line header location='' location_count=0

    # Do not follow redirects: the Location header is part of the deployment contract.
    response="$(curl --silent --show-error --dump-header - --output /dev/null --write-out $'\n%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}${path}")" || return 1
    status_line="${response##*$'\n'}"
    actual="$status_line"
    while IFS= read -r header; do
        [[ "${header,,}" == location:* ]] || continue
        location="${header#*:}"
        location="${location%$'\r'}"
        location="${location#"${location%%[![:space:]]*}"}"
        location_count=$((location_count + 1))
    done <<<"${response%$'\n'*}"

    [[ "$actual" == '301' ]] || { log "HTTP ${path} returned ${actual}, expected 301"; return 1; }
    [[ "$location_count" == '1' && "$location" == "$expected_location" ]] || {
        log "HTTP ${path} Location was '${location:-missing}', expected exactly '${expected_location}'";
        return 1;
    }
    log "HTTP ${path}: 301 -> ${location}"
}

check_http_root_reachable() {
    local response status_line header location='' location_count=0 target_status relative_path

    # A healthy release may serve / directly or redirect it once within the
    # configured staging origin. Never follow an unchecked Location header.
    response="$(curl --silent --show-error --dump-header - --output /dev/null --write-out $'\n%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/")" || return 1
    status_line="${response##*$'\n'}"
    if [[ "$status_line" == '200' ]]; then
        log 'HTTP /: 200'
        return 0
    fi
    [[ "$status_line" == '301' ]] || { log "HTTP / returned ${status_line}, expected 200 or a safe same-origin 301"; return 1; }
    while IFS= read -r header; do
        [[ "${header,,}" == location:* ]] || continue
        location="${header#*:}"
        location="${location%$'\r'}"
        location="${location#"${location%%[![:space:]]*}"}"
        location_count=$((location_count + 1))
    done <<<"${response%$'\n'*}"
    [[ "$location_count" == '1' && "$location" == "${APP_URL}/"* ]] || {
        log 'HTTP / did not provide exactly one same-origin Location header.'
        return 1
    }
    relative_path="${location#"$APP_URL"}"
    [[ "$relative_path" =~ ^/[^/?#][^?#]*$ ]] || { log 'HTTP / redirected to an unsafe path.'; return 1; }
    case "$relative_path" in
        /up|/login|/owner|/owner/*|/account|/account/*|/checkout|/checkout/*|/api|/api/*)
            log 'HTTP / redirected to an internal or private route.'
            return 1
            ;;
    esac
    target_status="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "$location")" || return 1
    [[ "$target_status" == '200' ]] || { log "HTTP / redirect target returned ${target_status}, expected 200"; return 1; }
    log "HTTP /: 301 -> ${location} -> 200"
}

pre_deploy_smoke() {
    assert_baseline_unchanged || return 1
    check_http_root_reachable || return 1
    check_http_status /up 200 || return 1
    check_http_shared baseline "$BASELINE_SHA"
}

post_deploy_smoke() {
    check_http_redirect / "${APP_URL}/en/" || return 1
    check_http_status /en/ 200 || return 1
    check_http_status /up 200 || return 1
    check_http_redirect /pricing "${APP_URL}/en/pricing" || return 1
    check_http_status /en/pricing 200 || return 1
    check_http_status /hu/ 200 || return 1
    check_http_status /hu/pricing 200 || return 1
    check_http_shared target "$TARGET_SHA"
}

release_gate_state_for_commit() {
    local commit_sha="$1" bootstrap_source middleware_path middleware_source security_line gate_line
    local legacy_baseline_sha='761e0e5d37558da808ac961ca2d2a68b4ffab0db'

    [[ "$commit_sha" =~ ^[0-9a-f]{40}$ ]] || return 1
    bootstrap_source="$(git show "${commit_sha}:bootstrap/app.php" 2>/dev/null)" || return 1
    middleware_path="$(git ls-tree -r --name-only "$commit_sha" -- app/Http/Middleware/MarketingOnlyReleaseGate.php)" || return 1

    if [[ "$middleware_path" == 'app/Http/Middleware/MarketingOnlyReleaseGate.php' ]]; then
        middleware_source="$(git show "${commit_sha}:$middleware_path" 2>/dev/null)" || return 1
        [[ "$middleware_source" == *'class MarketingOnlyReleaseGate'* ]] || return 1
        [[ "$(grep -Fxc 'use App\\Http\\Middleware\\MarketingOnlyReleaseGate;' <<<"$bootstrap_source")" == '1' ]] || return 1
        [[ "$(grep -Fxc '        $middleware->append(SecurityHeaders::class);' <<<"$bootstrap_source")" == '1' ]] || return 1
        [[ "$(grep -Fxc '        $middleware->append(MarketingOnlyReleaseGate::class);' <<<"$bootstrap_source")" == '1' ]] || return 1
        security_line="$(grep -Fn '        $middleware->append(SecurityHeaders::class);' <<<"$bootstrap_source" | cut -d: -f1)"
        gate_line="$(grep -Fn '        $middleware->append(MarketingOnlyReleaseGate::class);' <<<"$bootstrap_source" | cut -d: -f1)"
        (( gate_line == security_line + 1 )) || return 1
        printf 'marketing_gated\\n'
        return 0
    fi

    [[ -z "$middleware_path" && "$bootstrap_source" != *'MarketingOnlyReleaseGate'* ]] || return 1
    if [[ "$commit_sha" == "$legacy_baseline_sha" ]]; then
        printf 'legacy_application\\n'
    else
        printf 'unrecognized_application\\n'
    fi
}

check_http_shared() {
    local smoke_stage="$1" commit_sha="${2:-}" path response actual status_line header headers
    local cache_control='' body_size location_count=0 gate_state='' final_status='' location='' malformed_status=0

    case "$smoke_stage" in
        baseline)
            commit_sha="${commit_sha:-$BASELINE_SHA}"
            gate_state="$(release_gate_state_for_commit "$commit_sha")" || { log 'Baseline gate provenance is ambiguous; refusing HTTP expectation.'; return 1; }
            [[ "$gate_state" == 'legacy_application' || "$gate_state" == 'marketing_gated' ]] || {
                log "Unrecognized gate-less baseline commit $commit_sha; refusing HTTP expectation."
                return 1
            }
            ;;
        target)
            commit_sha="${commit_sha:-$TARGET_SHA}"
            gate_state="$(release_gate_state_for_commit "$commit_sha")" || { log 'Target gate provenance is ambiguous.'; return 1; }
            [[ "$gate_state" == 'marketing_gated' ]] || { log 'Target commit lacks the registered marketing gate; refusing deployment.'; return 1; }
            ;;
        *)
            log "Unknown HTTP smoke stage: $smoke_stage"
            return 1
            ;;
    esac

    response="$(curl --silent --show-error --dump-header - --output /dev/null --write-out $'\n%{http_code}\t%{size_download}' --connect-timeout 5 --max-time 20 "${APP_URL}/owner/integrations")" || return 1
    status_line="${response##*$'\n'}"
    actual="${status_line%%$'\t'*}"
    body_size="${status_line#*$'\t'}"
    [[ "$actual" =~ ^[0-9]{3}$ && "$body_size" =~ ^[0-9]+$ ]] || { log 'Curl did not return a valid final status and body size.'; return 1; }
    headers="${response%$'\n'*}"

    while IFS= read -r header; do
        header="${header%$'\r'}"
        if [[ "$header" =~ ^HTTP/[0-9.]+[[:space:]]+([0-9]{3})([[:space:]]|$) ]]; then
            final_status="${BASH_REMATCH[1]}"
            cache_control=''
            location=''
            location_count=0
            continue
        elif [[ "$header" == HTTP/* ]]; then
            malformed_status=1
            continue
        fi

        case "${header,,}" in
            cache-control:*)
                local value="${header#*:}"
                value="${value#"${value%%[![:space:]]*}"}"
                cache_control="${cache_control:+$cache_control, }$value"
                ;;
            location:*)
                location="${header#*:}"
                location="${location#"${location%%[![:space:]]*}"}"
                location_count=$((location_count + 1))
                ;;
        esac
    done <<<"$headers"

    [[ "$malformed_status" == '0' && -n "$final_status" && "$final_status" == "$actual" ]] || {
        log 'Final dumped HTTP header block is malformed or does not match curl final response status.'
        return 1
    }

    if [[ "$smoke_stage" == baseline && "$gate_state" == legacy_application ]]; then
        [[ "$actual" == '302' ]] || { log "Verified legacy baseline returned $actual, expected 302 authentication redirect"; return 1; }
        [[ "$location_count" == '1' && "$location" == "${APP_URL}/owner/login" ]] || {
            log "Verified legacy baseline Location was '${location:-missing}', expected exactly ${APP_URL}/owner/login"
            return 1
        }
        log 'Pinned legacy baseline: exact 302 to same-origin Owner login.'
    else
        [[ "$actual" == '404' ]] || { log "Guest Owner Integrations route returned $actual, expected 404 from the marketing release gate"; return 1; }
        [[ "$body_size" == '0' ]] || { log "Guest Owner Integrations denial returned a non-empty body ($body_size bytes)"; return 1; }
        [[ "$location_count" == '0' ]] || { log 'Guest Owner Integrations denial unexpectedly returned a final-response Location.'; return 1; }
        [[ ",${cache_control,,}," =~ ,[[:space:]]*no-store[[:space:]]*, ]] || { log 'Final 404 response is missing Cache-Control: no-store'; return 1; }
        log 'Guest Owner Integrations route: final 404, empty, no-store (blocked before route dispatch)'
    fi

    for path in /.env /.git/config; do
        actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}$path")" || return 1
        [[ "$actual" == '403' || "$actual" == '404' ]] || { log "Protected path $path returned $actual"; return 1; }
        log "Protected path $path: $actual"
    done
}
rebuild_caches() {
    local file
    local cache_dir
    local -a cache_files=()

    php artisan optimize:clear
    configure_runtime_cache_acls || return 1
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    command -v setfacl >/dev/null || return 1
    for cache_dir in bootstrap/cache storage/framework/views; do
        mapfile -d '' cache_files < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)
        for file in "${cache_files[@]}"; do
            case "$(stat -c '%U' "$file")" in
                deploy) chmod 0600 "$file" || return 1 ;;
                www-data) ;;
                *) return 1 ;;
            esac
            privileged cache-file-secure "$file" || return 1
            privileged file-check www-data read "$file" || return 1
            if [[ "$cache_dir" == 'bootstrap/cache' ]]; then
                privileged file-check nobody not-readable "$file" || return 1
            fi
        done
    done
    [[ -r bootstrap/cache/config.php ]] || return 1
    runtime_cache_access_ok
}

release_gate() {
    privileged gate-off
}

mark_gate_released() {
    GATE_ACTIVE=0
}

rollback_code_to_baseline() {
    local current_sha

    [[ -n "${BASELINE_SHA:-}" && -n "${TARGET_SHA:-}" ]] || return 1
    valid_commit_sha "$BASELINE_SHA" || return 1
    if (( MIGRATION_ATTEMPTED && MIGRATIONS_PENDING )); then
        log 'Target migrations may have changed the database; automatic code rollback is unsafe. Keep the gate active for reviewed recovery.' >&2
        return 1
    fi
    current_sha="$(git rev-parse --verify 'HEAD^{commit}')" || return 1
    [[ "$current_sha" == "$TARGET_SHA" || "$current_sha" == "$BASELINE_SHA" ]] || {
        log 'The checkout changed to an unexpected SHA; refusing automatic rollback.' >&2
        return 1
    }

    log "Restoring the pinned baseline code ${BASELINE_SHA} behind the Nginx gate."
    git checkout --detach "$BASELINE_SHA" || return 1
    [[ "$(git rev-parse HEAD)" == "$BASELINE_SHA" ]] || return 1
    normalize_tracked_checkout_permissions || return 1
    umask 022
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader || return 1
    npm ci --no-audit --no-fund || return 1
    npm run build || return 1
    umask 077
    [[ -f public/build/manifest.json ]] || return 1
    find public/build -type d -exec chmod 0755 {} + || return 1
    find public/build -type f -exec chmod 0644 {} + || return 1
    if [[ -d node_modules ]]; then
        [[ ! -L node_modules && "$(realpath -e -- node_modules)" == "${APP_DIR}/node_modules" ]] || return 1
        rm -rf -- "${APP_DIR}/node_modules"
    fi
    rebuild_caches || return 1
    php artisan connector:package || return 1
    [[ "$(git rev-parse HEAD)" == "$BASELINE_SHA" ]] || return 1
    [[ "$(git status --porcelain --untracked-files=all)" == '' ]] || return 1
    CODE_SWITCHED=0
}

stop_workers_for_rollback() {
    local worker output

    privileged worker-runtime-disable || return 1
    for worker in "${WORKERS[@]}"; do
        output="$(worker_status "$worker")" || return 1
        if [[ "$output" =~ ^${worker}[[:space:]]+RUNNING([[:space:]]|$) ]]; then
            privileged worker stop "$worker" || return 1
        elif [[ ! "$output" =~ ^${worker}[[:space:]]+STOPPED([[:space:]]|$) ]]; then
            return 1
        fi
        output="$(worker_status "$worker")" || return 1
        [[ "$output" =~ ^${worker}[[:space:]]+STOPPED([[:space:]]|$) ]] || return 1
    done
    WORKERS_MAY_BE_STOPPED=1
}

recover_after_failure() {
    local smoke_contract

    log 'Attempting guarded recovery while the Nginx gate is active.'

    acquire_scheduler_guard || return 1
    pause_scheduler || return 1

    # A failure or signal can occur after the marker is removed for smoke
    # checks. Reinstall it before doing any recovery work.
    GATE_ACTIVE=1
    privileged gate-on || return 1

    if (( CODE_SWITCHED )); then
        stop_workers_for_rollback || return 1
        if (( RUNTIME_SWITCH_ATTEMPTED )); then
            privileged php-runtime-switch "$PHP_BASELINE_PROFILE" || return 1
            [[ "$(privileged php-runtime-status)" == "$PHP_BASELINE_PROFILE" ]] || return 1
            set_php_profile "$PHP_BASELINE_PROFILE" || return 1
            PHP_TARGET_PROFILE="$PHP_BASELINE_PROFILE"
            RUNTIME_SWITCH_ATTEMPTED=0
        fi
        rollback_code_to_baseline || return 1
    else
        [[ -r bootstrap/cache/config.php ]] || return 1
        privileged file-check www-data read bootstrap/cache/config.php || return 1
    fi

    php artisan migrate:status --no-interaction | grep -q 'Pending' && return 1

    php artisan up || return 1
    check_system_health allow-scheduler-pending || return 1
    if (( WORKERS_MAY_BE_STOPPED )); then
        php artisan queue:restart || return 1
        privileged worker-runtime-enable || return 1
        privileged php-runtime-supervisor-update || return 1
        start_workers || return 1
        WORKERS_MAY_BE_STOPPED=0
    fi

    resume_scheduler || return 1
    wait_for_scheduler || return 1
    check_system_health || return 1
    workers_running || return 1
    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || return 1
    release_gate || return 1

    if (( CODE_SWITCHED )); then
        smoke_contract=post_deploy_smoke
    else
        smoke_contract=pre_deploy_smoke
    fi
    if ! "$smoke_contract" || ! runtime_cache_access_ok || ! workers_running; then
        privileged gate-on || return 1
        php artisan down --retry=60 || true
        return 1
    fi

    mark_gate_released
    log 'Guarded recovery restored service.'
}

wait_for_scheduler() {
    local attempt

    for attempt in {1..75}; do
        if check_system_health allow-scheduler-pending quiet; then
            if php -r '
$rows = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($rows as $row) {
    if (($row["check"] ?? "") === "scheduler") {
        exit(($row["level"] ?? "") === "OK" ? 0 : 1);
    }
}
exit(1);
' "$CHECK_REPORT"; then
                log 'Scheduler heartbeat is healthy.'
                check_system_health
                return $?
            fi
        fi
        sleep 1
    done

    log 'Scheduler heartbeat did not recover within 75 seconds.' >&2
    return 1
}

on_exit() {
    local status=$1

    trap - EXIT
    [[ -n "$TEMP_BACKUP" ]] && rm -f -- "$TEMP_BACKUP"
    [[ -n "$CHECK_REPORT" ]] && rm -f -- "$CHECK_REPORT"

    if (( status != 0 && GATE_ACTIVE )); then
        log "Deployment failed with status ${status}."
        if ! recover_after_failure; then
            privileged gate-on || log 'Could not confirm the Nginx gate marker; inspect it immediately.' >&2
            php artisan down --retry=60 >/dev/null 2>&1 || log 'Laravel maintenance mode could not be confirmed; the Nginx gate remains the request control.' >&2
            log "Fail-closed: Nginx gate remains active at ${GATE_FILE}; follow the manual recovery steps in docs/DEPLOYMENT.md."
        fi
    elif (( status == 0 && GATE_ACTIVE )); then
        log 'ERROR: deployment ended while the Nginx gate was still active.' >&2
        status=1
    fi

    exit "$status"
}

trap 'on_exit $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

main() {
    local requested_ref fetched_tip env_mode app_url_value db_name timestamp backup_path gate_probe migration_status ci_evidence receipt_name
    local cache_file cache_dir
    local worker

    local requested_profile=''
    if (( $# >= 1 )) && [[ "$1" == '--rollback' ]]; then
        DEPLOYMENT_MODE='rollback'
        shift
    fi
    (( $# >= 1 )) || fail 'Usage: scripts/deploy-staging.sh [--rollback] <full-commit-sha> [--php-runtime 8.4|8.5]'
    requested_ref="$1"
    shift
    while (( $# )); do
        [[ "$1" == '--php-runtime' && $# -ge 2 && -z "$requested_profile" ]] || fail 'Usage: scripts/deploy-staging.sh [--rollback] <full-commit-sha> [--php-runtime 8.4|8.5]'
        requested_profile="$2"
        shift 2
    done
    [[ -z "$requested_profile" || "$requested_profile" == '8.4' || "$requested_profile" == '8.5' ]] || fail 'PHP runtime must be exactly 8.4 or 8.5.'
    [[ "$(id -un)" == 'deploy' ]] || fail 'Run this script as the deploy user.'
    assert_privilege_boundary || fail 'The staging deploy sudo policy is not the reviewed helper-only rule.'
    [[ "$(pwd -P)" == "$APP_DIR" ]] || fail "Run from ${APP_DIR}."
    [[ -d .git && -f artisan && -f composer.json ]] || fail 'Expected Laravel Git application was not found.'
    [[ "$(git remote get-url origin)" == "$ORIGIN_URL" ]] || fail 'The configured origin is not the staging deploy-key repository.'
    [[ -f .env && "$(stat -c '%U:%G:%a' .env)" == 'deploy:deploy:600' ]] || fail '.env must remain deploy:deploy mode 0600.'
    ! git ls-files --error-unmatch .env >/dev/null 2>&1 || fail '.env must not be tracked by Git.'
    [[ -w storage && -w bootstrap/cache ]] || fail 'deploy must be able to write storage/ and bootstrap/cache/.'
    privileged file-check www-data write storage || fail 'www-data must retain its storage/ write access.'
    privileged file-check www-data write bootstrap/cache || fail 'www-data must retain its bootstrap/cache/ write access.'
    [[ -r bootstrap/cache/config.php ]] || fail 'The existing production config cache is missing; repair it before deploying.'
    command -v setfacl >/dev/null || fail 'setfacl is required to preserve private but readable Laravel bootstrap caches.'
    privileged file-check nobody not-readable bootstrap/cache/config.php || fail 'The existing config cache is readable by unrelated users; repair its ACL before deploying.'
    for cache_dir in bootstrap/cache storage/framework/views; do
        while IFS= read -r -d '' cache_file; do
            privileged file-check www-data read "$cache_file" || fail "www-data cannot read ${cache_file}."
        done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)
    done
    [[ ! -e "$GATE_FILE" ]] || fail "A previous deployment gate exists at ${GATE_FILE}; inspect and recover it before retrying."
    [[ ! -e "$SCHEDULER_PAUSE_FILE" ]] || fail "A previous scheduler pause marker exists at ${SCHEDULER_PAUSE_FILE}; inspect and recover it before retrying."
    [[ "$(git status --porcelain --untracked-files=all)" == '' ]] || fail 'The repository has tracked or untracked changes; deploy only from a clean checkout.'
    privileged preflight || fail 'The narrow deploy privilege helper preflight failed.'
    [[ -d "$BACKUP_DIR" && "$(stat -c '%U:%a' "$BACKUP_DIR")" == 'deploy:700' ]] || fail 'The protected staging database backup directory must be deploy-owned mode 0700.'
    exec 9>"${BACKUP_DIR}/.deploy.lock"
    command -v flock >/dev/null || fail 'The flock utility is required to prevent concurrent deployments.'
    flock -n 9 || fail 'Another staging deployment is already running.'
    capture_baseline_sha || fail 'Could not pin the current staging release after acquiring the deployment lock.'
    PHP_BASELINE_PROFILE="$(privileged php-runtime-status)" || fail 'The active PHP runtime profile is absent or invalid.'
    [[ "$PHP_BASELINE_PROFILE" == '8.4' || "$PHP_BASELINE_PROFILE" == '8.5' ]] || fail 'The active PHP runtime profile is not supported.'
    PHP_TARGET_PROFILE="${requested_profile:-$PHP_BASELINE_PROFILE}"
    set_php_profile "$PHP_BASELINE_PROFILE" || fail 'The baseline PHP profile is unsupported.'
    privileged php-runtime-preflight "$PHP_BASELINE_PROFILE" || fail 'The active PHP CLI/FPM/Nginx/Supervisor runtime does not match its profile.'
    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || fail 'The requested PHP CLI/FPM/Nginx/Supervisor runtime is not ready.'
    pre_deploy_smoke || fail 'The pinned baseline staging release is not healthy before deployment.'
    command -v git >/dev/null && [[ -f /usr/bin/composer && ! -L /usr/bin/composer ]] && command -v npm >/dev/null && command -v curl >/dev/null && [[ -x /usr/bin/python3 ]] || fail 'A required deployment tool is missing.'
    php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === (int) substr($argv[1], 2) ? 0 : 1);' "$PHP_BASELINE_PROFILE" || fail 'The selected baseline PHP CLI does not match the active runtime profile.'
    composer --version | grep -q 'Composer version 2\.' || fail 'Composer 2 is required.'
    node -e 'process.exit(Number(process.versions.node.split(".")[0]) === 22 ? 0 : 1)' || fail 'Node.js 22 is required.'
    php artisan about --only=environment >/dev/null || fail 'Laravel cannot boot with the current production configuration.'
    for worker in "${WORKERS[@]}"; do
        OLD_WORKER_PIDS[$worker]="$(worker_pid "$worker")" || fail "${worker} is not RUNNING before deployment."
    done
    check_system_health || fail 'A deployment-critical system check is not healthy before deployment.'

    env_mode="$(php artisan config:show app.env --no-interaction 2>/dev/null | awk 'NF { value = $NF } END { print value }')"
    app_url_value="$(php artisan config:show app.url --no-interaction 2>/dev/null | awk 'NF { value = $NF } END { print value }')"
    [[ "$env_mode" == 'production' && "$app_url_value" == 'https://staging.wpcaregrid.com' ]] || fail 'The environment is not the expected production-mode staging configuration.'

    log "Fetching the staging branch ${DEPLOY_BRANCH}."
    git fetch --no-tags origin "$DEPLOY_BRANCH"
    fetched_tip="$(git rev-parse FETCH_HEAD^{commit})"
    pin_target_sha "$requested_ref" "$fetched_tip" "$DEPLOYMENT_MODE" || fail 'Forward deployment requires the exact current branch tip; rollback requires a root-approved prior SHA.'
    log "Target commit: ${TARGET_SHA}"
    ci_evidence="$(/usr/bin/env -i PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' LANG=C.UTF-8 LC_ALL=C.UTF-8 /usr/bin/python3 "${CONTROLLER_DIR}/verify-release-ci.py" "$TARGET_SHA")" || fail 'The exact target SHA lacks completed successful mandatory primary-push CI.'
    CI_RUN_ID="$(/usr/bin/env -i PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' LANG=C.UTF-8 LC_ALL=C.UTF-8 /usr/bin/python3 -c 'import json,sys; print(json.load(sys.stdin)["workflow_run_id"])' <<<"$ci_evidence")"
    [[ "$CI_RUN_ID" =~ ^[1-9][0-9]*$ ]] || fail 'The exact-target CI run identifier is invalid.'
    log "Exact-target merged-primary CI run verified: ${CI_RUN_ID}."
    assert_baseline_unchanged || fail 'The live checkout changed after baseline capture and before backup.'

    db_name="$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.database");' 2>/dev/null)"
    [[ "$db_name" == 'wpcaregrid' ]] || fail 'The application database does not match the staging-only privileged backup allowlist.'
    privileged db-ping || fail 'Local MySQL administrative access is unavailable for the pre-deploy backup.'
    timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
    backup_path="${BACKUP_DIR}/wpcaregrid-pre-deploy-${timestamp}.sql.gz"
    [[ ! -e "$backup_path" ]] || fail 'A pre-deploy backup already exists with this timestamp.'
    TEMP_BACKUP="$(mktemp "${BACKUP_DIR}/.wpcaregrid-pre-deploy-${timestamp}.XXXXXX")"
    log 'Creating the protected pre-deploy database backup.'
    privileged db-dump | gzip -c >"$TEMP_BACKUP"
    gzip -t "$TEMP_BACKUP"
    chmod 600 "$TEMP_BACKUP"
    mv -- "$TEMP_BACKUP" "$backup_path"
    TEMP_BACKUP=''
    log "Database backup ready: ${backup_path}"

    assert_baseline_unchanged || fail 'The live checkout changed before the deployment gate was installed.'
    install_scheduler_guard
    privileged gate-dir-init
    acquire_scheduler_guard
    GATE_ACTIVE=1
    pause_scheduler
    privileged gate-on
    gate_probe="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/up")"
    [[ "$gate_probe" == '503' ]] || fail "Nginx deployment gate returned ${gate_probe}, expected controlled HTTP 503."
    log 'Nginx gate verified: requests receive HTTP 503 before PHP-FPM.'
    release_gate
    [[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/up")" == '200' ]] || fail 'Staging did not recover after the Nginx gate probe.'
    mark_gate_released

    GATE_ACTIVE=1
    privileged gate-on
    php artisan down --retry=60
    log 'Laravel maintenance mode enabled behind the Nginx gate.'

    WORKERS_MAY_BE_STOPPED=1
    privileged worker-runtime-disable || fail 'Queue workers could not be persistently blocked before drain.'
    privileged worker stop-all
    for worker in "${WORKERS[@]}"; do
        [[ "$(worker_status "$worker")" =~ ^${worker}[[:space:]]+STOPPED([[:space:]]|$) ]] || fail "${worker} did not stop cleanly."
        log "$worker stopped before the code transition."
    done

    assert_baseline_unchanged || fail 'The live checkout changed before the target code switch.'
    log 'Checking out the exact requested commit.'
    CODE_SWITCHED=1
    git checkout --detach "$TARGET_SHA"
    [[ "$(git rev-parse HEAD)" == "$TARGET_SHA" ]] || fail 'Checked-out HEAD does not match the requested commit.'
    normalize_tracked_checkout_permissions || fail 'Could not normalize tracked checkout permissions safely.'
    privileged php-runtime-install || fail 'Could not install the fixed PHP runtime dispatcher and Supervisor command contract.'
    if [[ "$PHP_TARGET_PROFILE" != "$PHP_BASELINE_PROFILE" ]]; then
        RUNTIME_SWITCH_ATTEMPTED=1
        privileged php-runtime-switch "$PHP_TARGET_PROFILE" || fail 'PHP runtime cutover failed; keep the maintenance gate closed for recovery.'
        [[ "$(privileged php-runtime-status)" == "$PHP_TARGET_PROFILE" ]] || fail 'The PHP runtime selector did not match the requested target.'
    fi
    set_php_profile "$PHP_TARGET_PROFILE" || fail 'The target PHP profile is unsupported.'

    log 'Installing production Composer dependencies.'
    umask 022
    composer check-platform-reqs --no-dev --lock || fail 'Composer platform requirements do not pass on the selected target runtime.'
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    umask 077
    privileged vendor-readable || fail 'PHP-FPM cannot read one or more Composer PHP files.'
    privileged file-check www-data read vendor/autoload.php || fail 'PHP-FPM cannot read the Composer autoloader.'

    log 'Building frontend assets.'
    umask 022
    npm ci --no-audit --no-fund
    npm run build
    umask 077
    [[ -f public/build/manifest.json ]] || fail 'Vite build did not produce public/build/manifest.json.'
    find public/build -type d -exec chmod 0755 {} +
    find public/build -type f -exec chmod 0644 {} +
    privileged file-check www-data read public/build/manifest.json || fail 'PHP-FPM cannot read the Vite manifest.'
    if [[ -d node_modules ]]; then
        [[ ! -L node_modules && "$(realpath -e -- node_modules)" == "${APP_DIR}/node_modules" ]] || fail 'Refusing to remove an unexpected node_modules path.'
        rm -rf -- "${APP_DIR}/node_modules"
    fi

    log 'Running database migrations.'
    migration_status="$(php artisan migrate:status --no-interaction)" || fail 'Could not inspect target migration status before running migrations.'
    if grep -q 'Pending' <<<"$migration_status"; then
        MIGRATIONS_PENDING=1
    fi
    MIGRATION_ATTEMPTED=1
    php artisan migrate --force --no-interaction
    if php artisan migrate:status --no-interaction | grep -q 'Pending'; then
        fail 'One or more migrations remain pending.'
    fi

    log 'Rebuilding Laravel production caches while Nginx remains gated.'
    rebuild_caches
    log 'Building or verifying the immutable Connector package from the deployed source.'
    php artisan connector:package
    php artisan about --only=environment >/dev/null
    check_system_health allow-scheduler-pending || fail 'Deployment-critical application checks failed.'
    php artisan schedule:list >/dev/null

    php artisan up
    log 'Laravel is live behind the Nginx gate; restarting workers onto the deployed code.'
    php artisan queue:restart
    privileged worker-runtime-enable || fail 'The fixed runtime worker gate could not be opened under the maintenance gate.'
    privileged php-runtime-supervisor-update || fail 'Supervisor could not reload the fixed PHP runtime worker commands.'
    privileged worker start-all
    wait_for_restarted_workers || fail 'Expected Supervisor workers did not restart with new PIDs.'
    privileged worker-runtime-check "$PHP_TARGET_PROFILE" || fail 'A queue worker is not using the selected PHP runtime.'
    WORKERS_MAY_BE_STOPPED=0

    [[ "$(stat -c '%U:%G:%a' .env)" == 'deploy:deploy:600' ]] || fail '.env ownership or mode changed unexpectedly.'
    [[ -r bootstrap/cache/config.php ]] && privileged file-check www-data read bootstrap/cache/config.php || fail 'PHP-FPM cannot read the rebuilt production config cache.'
    normalize_tracked_checkout_permissions || fail 'Could not normalize tracked checkout permissions after build steps.'
    runtime_source_access_ok || fail 'PHP-FPM cannot read all representative runtime files.'
    [[ "$(git rev-parse HEAD)" == "$TARGET_SHA" ]] || fail 'The deployed Git HEAD changed unexpectedly.'
    [[ "$(git status --porcelain --untracked-files=all)" == '' ]] || fail 'The deployed Git working tree is not clean.'

    resume_scheduler
    wait_for_scheduler || fail 'Scheduler heartbeat did not recover after cache refresh.'
    workers_running || fail 'One or more required Supervisor workers stopped before traffic was reopened.'
    privileged worker-runtime-check "$PHP_TARGET_PROFILE" || fail 'A queue worker changed runtime before traffic was reopened.'
    privileged php-runtime-preflight "$PHP_TARGET_PROFILE" || fail 'The selected PHP-FPM pool or live listener changed before traffic was reopened.'
    release_gate
    if ! post_deploy_smoke || ! runtime_cache_access_ok || ! workers_running; then
        log 'Final staging HTTP/security or runtime-cache permission check failed; closing the Nginx gate again.' >&2
        privileged gate-on
        php artisan down --retry=60 || true
        fail 'Final staging HTTP/security smoke failed.'
    fi
    mark_gate_released
    if ! receipt_name="$(/usr/bin/env -i PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' LANG=C.UTF-8 LC_ALL=C.UTF-8 /usr/bin/python3 "${SCRIPT_DIR}/write-deployment-receipt.py" --baseline "$BASELINE_SHA" --target "$TARGET_SHA" --workflow-run-id "$CI_RUN_ID" --mode "$DEPLOYMENT_MODE" --php-binary "$PHP_BIN")"; then
        log 'Deployment receipt failed; closing the Nginx gate before reporting failure.' >&2
        privileged gate-on || true
        GATE_ACTIVE=1
        php artisan down --retry=60 || true
        fail 'A machine-readable deployment receipt could not be recorded.'
    fi
    [[ "$receipt_name" =~ ^release-[0-9TZ.]+-${TARGET_SHA}\.json$ ]] || fail 'The deployment receipt filename did not match the reviewed format.'
    log "Non-secret deployment receipt recorded: ${receipt_name}."
    log "Deployment complete at ${TARGET_SHA}."
    log "Protected database backup retained at ${backup_path}."
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
