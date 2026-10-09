#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

readonly APP_DIR='/var/www/wpcaregrid'
readonly DEPLOY_BRANCH='claude/ecstatic-wozniak-oemyuf'
readonly ORIGIN_URL='git@github.com-wpcaregrid:zsoltacsai/wpcaregrid.git'
readonly APP_URL='https://staging.wpcaregrid.com'
# The old release serves / with 200; future release transitions need an explicit reviewed baseline.
readonly PRE_DEPLOY_BASELINE_SHA='f0194bca2d58b8eafccb1dfd1e3fc6176ed132de'
readonly GATE_DIR='/var/lib/wpcaregrid'
readonly GATE_FILE="${GATE_DIR}/deploy-maintenance"
readonly SCHEDULER_LOCK_FILE="${GATE_DIR}/scheduler.lock"
readonly SCHEDULER_PAUSE_FILE="${GATE_DIR}/scheduler-paused"
readonly SCHEDULER_WRAPPER='/usr/local/bin/wpcaregrid-scheduler'
readonly LEGACY_SCHEDULER_CRON='* * * * * cd /var/www/wpcaregrid && php artisan schedule:run >> /dev/null 2>&1'
readonly GUARDED_SCHEDULER_CRON="* * * * * ${SCHEDULER_WRAPPER} >> /dev/null 2>&1"
# Always install the scheduler wrapper from the application checkout being deployed,
# even when the reviewed deploy script is invoked from a protected temporary copy.
readonly SCRIPT_DIR="${APP_DIR}/scripts"
readonly BACKUP_DIR='/home/deploy/private-backups'
readonly WORKERS=('wpcaregrid-default' 'wpcaregrid-backups')

GATE_ACTIVE=0
WORKERS_MAY_BE_STOPPED=0
CODE_SWITCHED=0
COMPOSER_READY=0
ASSETS_READY=0
MIGRATIONS_READY=0
CONNECTOR_READY=0
SCHEDULER_LOCK_HELD=0
TEMP_BACKUP=''
CHECK_REPORT=''
CRON_TEMP=''
CRON_TEMP_OUTPUT=''
declare -A OLD_WORKER_PIDS=()

log() {
    printf '[%s] %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"
}

fail() {
    log "ERROR: $*" >&2
    exit 1
}

worker_status() {
    sudo -n supervisorctl status "$1"
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
    local wrapper_source current_cron cron_started_at attempt

    wrapper_source="${SCRIPT_DIR}/run-scheduler.sh"
    [[ -f "$wrapper_source" ]] || fail 'The repository scheduler wrapper is missing beside the deployment script.'
    command -v logger >/dev/null || fail 'The logger utility is required by the scheduler wrapper.'
    command -v flock >/dev/null || fail 'The flock utility is required for scheduler/deployment coordination.'
    command -v pgrep >/dev/null || fail 'The pgrep utility is required to drain a legacy scheduler process safely.'

    sudo -n install -d -o root -g root -m 0755 "$GATE_DIR"
    if [[ ! -e "$SCHEDULER_LOCK_FILE" ]]; then
        sudo -n install -o deploy -g www-data -m 0660 /dev/null "$SCHEDULER_LOCK_FILE"
    fi
    [[ -f "$SCHEDULER_LOCK_FILE" && ! -L "$SCHEDULER_LOCK_FILE" ]] || fail 'The scheduler lock path must be a regular file, not a symlink.'
    [[ "$(stat -c '%U:%G:%a' "$SCHEDULER_LOCK_FILE")" == 'deploy:www-data:660' ]] || fail 'The scheduler lock must be deploy:www-data mode 0660.'
    sudo -n -u www-data test -r "$SCHEDULER_LOCK_FILE" || fail 'www-data cannot read the scheduler lock file.'

    # Replace the installed wrapper atomically so cron never sees a partial script.
    sudo -n install -o root -g root -m 0755 "$wrapper_source" "${SCHEDULER_WRAPPER}.new"
    sudo -n mv -f -- "${SCHEDULER_WRAPPER}.new" "$SCHEDULER_WRAPPER"

    current_cron="$(sudo -n crontab -u www-data -l 2>/dev/null)" || fail 'Could not read the www-data crontab.'
    if grep -Fxq "$GUARDED_SCHEDULER_CRON" <<<"$current_cron"; then
        [[ "$(grep -Fc 'schedule:run' <<<"$current_cron")" == '0' ]] || fail 'A second unguarded schedule:run crontab entry exists.'
        log 'The www-data scheduler already uses the guarded wrapper.'
        return 0
    fi

    [[ "$(grep -Fc "$LEGACY_SCHEDULER_CRON" <<<"$current_cron")" == '1' ]] || fail 'The existing www-data scheduler crontab does not match the reviewed legacy entry.'
    [[ "$(grep -Fc 'schedule:run' <<<"$current_cron")" == '1' ]] || fail 'Expected exactly one Laravel scheduler crontab entry.'
    CRON_TEMP="$(mktemp /tmp/wpcaregrid-crontab.XXXXXX)"
    CRON_TEMP_OUTPUT="${CRON_TEMP}.new"
    chmod 0600 "$CRON_TEMP"
    printf '%s\n' "$current_cron" >"$CRON_TEMP"
    awk -v old="$LEGACY_SCHEDULER_CRON" -v new="$GUARDED_SCHEDULER_CRON" \
        '$0 == old { print new; changed++; next } { print } END { if (changed != 1) exit 1 }' \
        "$CRON_TEMP" >"$CRON_TEMP_OUTPUT" || fail 'Could not safely replace the reviewed scheduler crontab entry.'
    chmod 0600 "$CRON_TEMP_OUTPUT"
    sudo -n crontab -u www-data "$CRON_TEMP_OUTPUT"
    current_cron="$(sudo -n crontab -u www-data -l 2>/dev/null)" || fail 'Could not verify the updated www-data crontab.'
    grep -Fxq "$GUARDED_SCHEDULER_CRON" <<<"$current_cron" || fail 'The guarded scheduler crontab entry was not installed.'
    [[ "$(grep -Fc 'schedule:run' <<<"$current_cron")" == '0' ]] || fail 'The old direct schedule:run entry remains active.'
    rm -f -- "$CRON_TEMP" "$CRON_TEMP_OUTPUT"
    CRON_TEMP=''
    CRON_TEMP_OUTPUT=''

    # Cron reloads user crontabs asynchronously. Do not proceed until its log
    # proves it invoked the wrapper at least once; all of this happens while the
    # current application and config cache are still healthy.
    cron_started_at="$(date -u +'%Y-%m-%d %H:%M:%S UTC')"
    log 'Waiting for cron to observe the guarded scheduler crontab.'
    for attempt in {1..75}; do
        if sudo -n journalctl -u cron --since "$cron_started_at" -o cat --no-pager 2>/dev/null | grep -Fq "$SCHEDULER_WRAPPER"; then
            log 'Cron invoked the guarded scheduler wrapper.'
            return 0
        fi
        sleep 1
    done

    fail 'Cron did not invoke the guarded scheduler wrapper within 75 seconds.'
}

acquire_scheduler_guard() {
    local attempt scheduler_probe

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

    scheduler_probe="$(sudo -n -u www-data "$SCHEDULER_WRAPPER" 2>&1)" || fail 'The guarded scheduler wrapper failed its deployment-lock probe.'
    [[ "$scheduler_probe" == 'wpcaregrid scheduler skipped: deployment guard active' ]] || fail 'The scheduler wrapper did not prove that it skips Laravel while the deployment lock is held.'
    log 'Scheduler wrapper probe confirmed no Laravel bootstrap while deployment lock is held.'
}

pause_scheduler() {
    sudo -n install -o root -g root -m 0644 /dev/null "$SCHEDULER_PAUSE_FILE"
    log 'Persistent scheduler pause marker installed.'
}

resume_scheduler() {
    (( SCHEDULER_LOCK_HELD )) || fail 'Cannot resume the scheduler without holding the deployment lock.'
    sudo -n rm -f -- "$SCHEDULER_PAUSE_FILE"
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
            sudo -n supervisorctl start "$worker" >/dev/null || return 1
        elif [[ ! "$output" =~ ^${worker}[[:space:]]+RUNNING([[:space:]]|$) ]]; then
            return 1
        fi
    done

    wait_for_restarted_workers
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
    sudo -n setfacl -k bootstrap/cache storage/framework/views || return 1
    sudo -n setfacl -m 'd:u::rwx,d:u:deploy:rwx,d:u:www-data:rwx,d:g::---,d:m::rwx,d:o::---' bootstrap/cache storage/framework/views
}

runtime_cache_access_ok() {
    local cache_dir file

    cache_dir='bootstrap/cache'
    while IFS= read -r -d '' file; do
        sudo -n -u www-data test -r "$file" || return 1
        sudo -n -u nobody test ! -r "$file" || return 1
    done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)

    cache_dir='storage/framework/views'
    while IFS= read -r -d '' file; do
        sudo -n -u www-data test -r "$file" || return 1
    done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)

    sudo -n -u www-data test -r public/build/manifest.json
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
            artisan|composer.json|composer.lock|package.json|vite.config.*|postcss.config.*|tailwind.config.*|app/*|bootstrap/*|config/*|database/*|lang/*|public/*|resources/*|routes/*|scripts/*) ;;
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
    )

    for path in "${runtime_paths[@]}"; do
        [[ -f "$path" ]] || { log "Required runtime file is missing: ${path}"; return 1; }
        sudo -n -u www-data test -r "$path" || { log "PHP-FPM cannot read required runtime file: ${path}"; return 1; }
        directory="$(dirname -- "$path")"
        while [[ "$directory" != '.' ]]; do
            sudo -n -u www-data test -x "$directory" || { log "PHP-FPM cannot traverse required runtime directory: ${directory}"; return 1; }
            directory="$(dirname -- "$directory")"
        done
    done

    while IFS= read -r -d '' entry; do
        path="${entry#*$'\t'}"
        case "$path" in
            app/*|bootstrap/*|config/*|database/*|lang/*|public/*|resources/*|routes/*|scripts/*|artisan) ;;
            *) continue ;;
        esac
        case "$path" in
            *.php|*.sh|artisan) ;;
            *) continue ;;
        esac
        [[ -f "$path" && ! -L "$path" ]] || continue
        sudo -n -u www-data test -r "$path" || { log "PHP-FPM cannot read tracked runtime source: ${path}"; return 1; }
    done < <(git ls-files -z)

    log 'PHP-FPM can read representative runtime files and all tracked PHP/shell source files.'
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

pre_deploy_smoke() {
    local current_sha

    current_sha="$(git rev-parse HEAD)" || return 1
    [[ "$current_sha" == "$PRE_DEPLOY_BASELINE_SHA" ]] || {
        log "Pre-deploy HEAD ${current_sha} is not the reviewed baseline ${PRE_DEPLOY_BASELINE_SHA}";
        return 1;
    }
    check_http_status / 200 || return 1
    check_http_status /up 200 || return 1
    check_http_shared
}

post_deploy_smoke() {
    check_http_redirect / "${APP_URL}/en/" || return 1
    check_http_status /en/ 200 || return 1
    check_http_status /up 200 || return 1
    check_http_redirect /pricing "${APP_URL}/en/pricing" || return 1
    check_http_status /en/pricing 200 || return 1
    check_http_status /hu/ 200 || return 1
    check_http_status /hu/pricing 200 || return 1
    check_http_shared
}

check_http_shared() {
    local path actual

    actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/owner/integrations")" || return 1
    [[ "$actual" =~ ^30[1278]$ ]] || { log "Guest Owner Integrations route returned ${actual}, expected an authentication redirect"; return 1; }
    log "Guest Owner Integrations route: ${actual} (authentication redirect)"

    for path in /.env /.git/config; do
        actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}${path}")" || return 1
        [[ "$actual" == '403' || "$actual" == '404' ]] || { log "Protected path ${path} returned ${actual}"; return 1; }
        log "Protected path ${path}: ${actual}"
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
                www-data) sudo -n chmod 0600 "$file" || return 1 ;;
                *) return 1 ;;
            esac
            sudo -n setfacl -b "$file" || return 1
            sudo -n setfacl -m u:www-data:r--,g::--- "$file" || return 1
            sudo -n -u www-data test -r "$file" || return 1
            if [[ "$cache_dir" == 'bootstrap/cache' ]]; then
                sudo -n -u nobody test ! -r "$file" || return 1
            fi
        done
    done
    [[ -r bootstrap/cache/config.php ]] || return 1
    runtime_cache_access_ok
}

release_gate() {
    sudo -n rm -f -- "$GATE_FILE"
}

mark_gate_released() {
    GATE_ACTIVE=0
}

recover_after_failure() {
    local smoke_contract

    log 'Attempting guarded recovery while the Nginx gate is active.'

    acquire_scheduler_guard || return 1
    pause_scheduler || return 1

    # A failure or signal can occur after the marker is removed for smoke
    # checks. Reinstall it before doing any recovery work.
    GATE_ACTIVE=1
    sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE" || return 1

    if (( CODE_SWITCHED )); then
        if (( !COMPOSER_READY || !ASSETS_READY || !MIGRATIONS_READY || !CONNECTOR_READY )); then
            log 'Recovery is unsafe before Composer, assets, migrations, and any connector package all completed.'
            return 1
        fi
        rebuild_caches || return 1
    else
        [[ -r bootstrap/cache/config.php ]] || return 1
        sudo -n -u www-data test -r bootstrap/cache/config.php || return 1
    fi

    php artisan migrate:status --no-interaction | grep -q 'Pending' && return 1

    php artisan up || return 1
    if (( WORKERS_MAY_BE_STOPPED )); then
        php artisan queue:restart || return 1
        start_workers || return 1
        WORKERS_MAY_BE_STOPPED=0
    fi

    resume_scheduler || return 1
    wait_for_scheduler || return 1
    check_system_health || return 1
    workers_running || return 1
    release_gate || return 1

    if (( CODE_SWITCHED )); then
        smoke_contract=post_deploy_smoke
    else
        smoke_contract=pre_deploy_smoke
    fi
    if ! "$smoke_contract" || ! runtime_cache_access_ok || ! workers_running; then
        sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE" || return 1
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
    [[ -n "$CRON_TEMP" ]] && rm -f -- "$CRON_TEMP"
    [[ -n "$CRON_TEMP_OUTPUT" ]] && rm -f -- "$CRON_TEMP_OUTPUT"

    if (( status != 0 && GATE_ACTIVE )); then
        log "Deployment failed with status ${status}."
        if ! recover_after_failure; then
            sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE" || log 'Could not confirm the Nginx gate marker; inspect it immediately.' >&2
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
    local requested_ref target_commit fetched_tip env_mode app_url_value db_name timestamp backup_path gate_probe
    local previous_connector_version current_connector_version cache_file cache_dir
    local worker

    (( $# <= 1 )) || fail 'Usage: scripts/deploy-staging.sh [full-commit-sha]'
    [[ "$(id -un)" == 'deploy' ]] || fail 'Run this script as the deploy user.'
    [[ "$(pwd -P)" == "$APP_DIR" ]] || fail "Run from ${APP_DIR}."
    [[ -d .git && -f artisan && -f composer.json ]] || fail 'Expected Laravel Git application was not found.'
    [[ "$(git remote get-url origin)" == "$ORIGIN_URL" ]] || fail 'The configured origin is not the staging deploy-key repository.'
    [[ -f .env && "$(stat -c '%U:%G:%a' .env)" == 'deploy:deploy:600' ]] || fail '.env must remain deploy:deploy mode 0600.'
    ! git ls-files --error-unmatch .env >/dev/null 2>&1 || fail '.env must not be tracked by Git.'
    [[ -w storage && -w bootstrap/cache ]] || fail 'deploy must be able to write storage/ and bootstrap/cache/.'
    sudo -n -u www-data test -w storage || fail 'www-data must retain its storage/ write access.'
    sudo -n -u www-data test -w bootstrap/cache || fail 'www-data must retain its bootstrap/cache/ write access.'
    [[ -r bootstrap/cache/config.php ]] || fail 'The existing production config cache is missing; repair it before deploying.'
    command -v setfacl >/dev/null || fail 'setfacl is required to preserve private but readable Laravel bootstrap caches.'
    sudo -n -u nobody test ! -r bootstrap/cache/config.php || fail 'The existing config cache is readable by unrelated users; repair its ACL before deploying.'
    for cache_dir in bootstrap/cache storage/framework/views; do
        while IFS= read -r -d '' cache_file; do
            sudo -n -u www-data test -r "$cache_file" || fail "www-data cannot read ${cache_file}."
        done < <(find "$cache_dir" -maxdepth 1 -type f -name '*.php' -print0)
    done
    [[ ! -e "$GATE_FILE" ]] || fail "A previous deployment gate exists at ${GATE_FILE}; inspect and recover it before retrying."
    [[ ! -e "$SCHEDULER_PAUSE_FILE" ]] || fail "A previous scheduler pause marker exists at ${SCHEDULER_PAUSE_FILE}; inspect and recover it before retrying."
    [[ "$(git status --porcelain --untracked-files=all)" == '' ]] || fail 'The repository has tracked or untracked changes; deploy only from a clean checkout.'
    sudo -n true || fail 'Non-interactive sudo is required.'
    sudo -n nginx -t >/dev/null || fail 'Nginx configuration is invalid.'
    sudo -n nginx -T 2>/dev/null | grep -Fq 'if (-f /var/lib/wpcaregrid/deploy-maintenance)' || fail 'The staging Nginx deployment gate is not configured.'
    sudo -n systemctl is-active --quiet cron || fail 'The cron scheduler service is not active.'
    [[ -d "$BACKUP_DIR" && "$(stat -c '%U:%a' "$BACKUP_DIR")" == 'deploy:700' ]] || fail 'The protected staging database backup directory must be deploy-owned mode 0700.'
    pre_deploy_smoke || fail 'The reviewed baseline staging release is not healthy before deployment.'
    install_scheduler_guard
    command -v git >/dev/null && command -v composer >/dev/null && command -v npm >/dev/null && command -v curl >/dev/null || fail 'A required deployment tool is missing.'
    php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' || fail 'PHP 8.4 CLI is required.'
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
    previous_connector_version="$(php artisan config:show connector.latest_version --no-interaction 2>/dev/null | awk 'NF { value = $NF } END { print value }')"
    [[ -n "$previous_connector_version" ]] || fail 'Could not read the current connector package version.'

    requested_ref="${1:-}"
    log "Fetching the staging branch ${DEPLOY_BRANCH}."
    git fetch --no-tags origin "$DEPLOY_BRANCH"
    fetched_tip="$(git rev-parse FETCH_HEAD^{commit})"
    if [[ -n "$requested_ref" ]]; then
        [[ "$requested_ref" =~ ^[0-9a-fA-F]{40}$ ]] || fail 'Supply a full 40-character commit SHA, not a moving ref.'
        target_commit="$(git rev-parse --verify "${requested_ref}^{commit}")" || fail 'Requested commit is not available locally after fetch.'
        git merge-base --is-ancestor "$target_commit" "$fetched_tip" || fail 'Requested commit is not in the fetched staging branch history.'
    else
        target_commit="$fetched_tip"
    fi
    log "Target commit: ${target_commit}"

    exec 9>"${BACKUP_DIR}/.deploy.lock"
    command -v flock >/dev/null || fail 'The flock utility is required to prevent concurrent deployments.'
    flock -n 9 || fail 'Another staging deployment is already running.'
    db_name="$(php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config("database.connections.mysql.database");' 2>/dev/null)"
    [[ "$db_name" =~ ^[A-Za-z0-9_]+$ ]] || fail 'Could not resolve a safe MySQL database name from Laravel configuration.'
    sudo -n mysql -NBe 'SELECT 1' >/dev/null || fail 'Local MySQL administrative access is unavailable for the pre-deploy backup.'
    timestamp="$(date -u +'%Y%m%dT%H%M%SZ')"
    backup_path="${BACKUP_DIR}/wpcaregrid-pre-deploy-${timestamp}.sql.gz"
    [[ ! -e "$backup_path" ]] || fail 'A pre-deploy backup already exists with this timestamp.'
    TEMP_BACKUP="$(mktemp "${BACKUP_DIR}/.wpcaregrid-pre-deploy-${timestamp}.XXXXXX")"
    log 'Creating the protected pre-deploy database backup.'
    sudo -n mysqldump --single-transaction --routines --triggers --databases "$db_name" | gzip -c >"$TEMP_BACKUP"
    gzip -t "$TEMP_BACKUP"
    chmod 600 "$TEMP_BACKUP"
    mv -- "$TEMP_BACKUP" "$backup_path"
    TEMP_BACKUP=''
    log "Database backup ready: ${backup_path}"

    sudo -n install -d -o root -g root -m 0755 "$GATE_DIR"
    acquire_scheduler_guard
    GATE_ACTIVE=1
    pause_scheduler
    sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE"
    gate_probe="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/up")"
    [[ "$gate_probe" == '503' ]] || fail "Nginx deployment gate returned ${gate_probe}, expected controlled HTTP 503."
    log 'Nginx gate verified: requests receive HTTP 503 before PHP-FPM.'
    release_gate
    [[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 "${APP_URL}/up")" == '200' ]] || fail 'Staging did not recover after the Nginx gate probe.'
    mark_gate_released

    GATE_ACTIVE=1
    sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE"
    php artisan down --retry=60
    log 'Laravel maintenance mode enabled behind the Nginx gate.'

    WORKERS_MAY_BE_STOPPED=1
    sudo -n supervisorctl stop "${WORKERS[@]}"
    for worker in "${WORKERS[@]}"; do
        [[ "$(worker_status "$worker")" =~ ^${worker}[[:space:]]+STOPPED([[:space:]]|$) ]] || fail "${worker} did not stop cleanly."
        log "$worker stopped before the code transition."
    done

    log 'Checking out the exact requested commit.'
    CODE_SWITCHED=1
    git checkout --detach "$target_commit"
    [[ "$(git rev-parse HEAD)" == "$target_commit" ]] || fail 'Checked-out HEAD does not match the requested commit.'
    normalize_tracked_checkout_permissions || fail 'Could not normalize tracked checkout permissions safely.'

    log 'Installing production Composer dependencies.'
    umask 022
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
    umask 077
    if sudo -n -u www-data find vendor -type f -name '*.php' ! -readable -print -quit | grep -q .; then
        fail 'PHP-FPM cannot read one or more Composer PHP files.'
    fi
    sudo -n -u www-data test -r vendor/autoload.php || fail 'PHP-FPM cannot read the Composer autoloader.'
    COMPOSER_READY=1

    log 'Building frontend assets.'
    umask 022
    npm ci --no-audit --no-fund
    npm run build
    umask 077
    [[ -f public/build/manifest.json ]] || fail 'Vite build did not produce public/build/manifest.json.'
    find public/build -type d -exec chmod 0755 {} +
    find public/build -type f -exec chmod 0644 {} +
    sudo -n -u www-data test -r public/build/manifest.json || fail 'PHP-FPM cannot read the Vite manifest.'
    if [[ -d node_modules ]]; then
        [[ ! -L node_modules && "$(realpath -e -- node_modules)" == "${APP_DIR}/node_modules" ]] || fail 'Refusing to remove an unexpected node_modules path.'
        rm -rf -- "${APP_DIR}/node_modules"
    fi
    ASSETS_READY=1

    log 'Running database migrations.'
    php artisan migrate --force --no-interaction
    if php artisan migrate:status --no-interaction | grep -q 'Pending'; then
        fail 'One or more migrations remain pending.'
    fi
    MIGRATIONS_READY=1

    log 'Rebuilding Laravel production caches while Nginx remains gated.'
    rebuild_caches
    current_connector_version="$(php artisan config:show connector.latest_version --no-interaction 2>/dev/null | awk 'NF { value = $NF } END { print value }')"
    [[ -n "$current_connector_version" ]] || fail 'Could not read the deployed connector package version.'
    if [[ "$current_connector_version" != "$previous_connector_version" ]]; then
        log 'Connector version changed; rebuilding its private ZIP package.'
        php artisan connector:package
    else
        log 'Connector version is unchanged; keeping the existing private package.'
    fi
    CONNECTOR_READY=1
    php artisan about --only=environment >/dev/null
    check_system_health allow-scheduler-pending || fail 'Deployment-critical application checks failed.'
    php artisan schedule:list >/dev/null

    php artisan up
    log 'Laravel is live behind the Nginx gate; restarting workers onto the deployed code.'
    php artisan queue:restart
    sudo -n supervisorctl start "${WORKERS[@]}"
    wait_for_restarted_workers || fail 'Expected Supervisor workers did not restart with new PIDs.'
    WORKERS_MAY_BE_STOPPED=0

    [[ "$(stat -c '%U:%G:%a' .env)" == 'deploy:deploy:600' ]] || fail '.env ownership or mode changed unexpectedly.'
    [[ -r bootstrap/cache/config.php ]] && sudo -n -u www-data test -r bootstrap/cache/config.php || fail 'PHP-FPM cannot read the rebuilt production config cache.'
    normalize_tracked_checkout_permissions || fail 'Could not normalize tracked checkout permissions after build steps.'
    runtime_source_access_ok || fail 'PHP-FPM cannot read all representative runtime files.'
    [[ "$(git rev-parse HEAD)" == "$target_commit" ]] || fail 'The deployed Git HEAD changed unexpectedly.'
    [[ "$(git status --porcelain --untracked-files=all)" == '' ]] || fail 'The deployed Git working tree is not clean.'

    resume_scheduler
    wait_for_scheduler || fail 'Scheduler heartbeat did not recover after cache refresh.'
    workers_running || fail 'One or more required Supervisor workers stopped before traffic was reopened.'
    release_gate
    if ! post_deploy_smoke || ! runtime_cache_access_ok || ! workers_running; then
        log 'Final staging HTTP/security or runtime-cache permission check failed; closing the Nginx gate again.' >&2
        sudo -n install -o root -g root -m 0644 /dev/null "$GATE_FILE"
        php artisan down --retry=60 || true
        fail 'Final staging HTTP/security smoke failed.'
    fi
    mark_gate_released
    log "Deployment complete at ${target_commit}."
    log "Protected database backup retained at ${backup_path}."
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
