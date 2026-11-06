#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
run_id="$(cat /proc/sys/kernel/random/uuid | tr -d '-')"
network="wpcg-deploy-e2e-${run_id}"
mysql_name="wpcg-deploy-mysql-${run_id}"
restore_name="wpcg-deploy-restore-${run_id}"
php_image="wpcg-deploy-php85-${run_id}:local"
mysql_image="mysql:8.4.11@sha256:6ea90827b1100f8f2ae306a539f86d2c264a26ed435a2a9f75551dd5c3aeb242"
created_network=0
created_mysql=0
created_restore=0
created_image=0
app_user="wpcg_runtime"
app_password="synthetic-runtime-${run_id}"

cleanup() {
    status=$?
    trap - EXIT
    cleanup_failed=0
    remove_container() {
        name=$1
        if ! docker container inspect "$name" >/dev/null 2>&1; then return 0; fi
        label="$(docker container inspect -f '{{ index .Config.Labels "com.wpcg.e2e-run" }}' "$name" 2>/dev/null || true)"
        if [[ "$label" != "$run_id" ]]; then
            printf 'CLEANUP=FAIL;CODE=CONTAINER_IDENTITY_MISMATCH\n' >&2
            cleanup_failed=1
            return 0
        fi
        docker rm -f "$name" >/dev/null || cleanup_failed=1
        if docker container inspect "$name" >/dev/null 2>&1; then cleanup_failed=1; fi
    }
    if (( created_mysql )); then remove_container "$mysql_name"; fi
    if (( created_restore )); then remove_container "$restore_name"; fi
    if (( created_network )) && docker network inspect "$network" >/dev/null 2>&1; then
        label="$(docker network inspect -f '{{ index .Labels "com.wpcg.e2e-run" }}' "$network" 2>/dev/null || true)"
        if [[ "$label" != "$run_id" ]]; then
            printf 'CLEANUP=FAIL;CODE=NETWORK_IDENTITY_MISMATCH\n' >&2
            cleanup_failed=1
        else
            docker network rm "$network" >/dev/null || cleanup_failed=1
            if docker network inspect "$network" >/dev/null 2>&1; then cleanup_failed=1; fi
        fi
    fi
    if (( created_image )) && docker image inspect "$php_image" >/dev/null 2>&1; then
        label="$(docker image inspect -f '{{ index .Config.Labels "com.wpcg.e2e-run" }}' "$php_image" 2>/dev/null || true)"
        if [[ "$label" != "$run_id" ]]; then
            printf 'CLEANUP=FAIL;CODE=IMAGE_IDENTITY_MISMATCH\n' >&2
            cleanup_failed=1
        else
            docker image rm "$php_image" >/dev/null || cleanup_failed=1
            if docker image inspect "$php_image" >/dev/null 2>&1; then cleanup_failed=1; fi
        fi
    fi
    if (( cleanup_failed )); then
        printf 'CLEANUP=FAIL;CODE=RESOURCE_REMAINS\n' >&2
        (( status != 0 )) || status=1
    else
        printf 'CLEANUP=PASS\n'
    fi
    exit "$status"
}
trap cleanup EXIT

if docker network inspect "$network" >/dev/null 2>&1 || docker container inspect "$mysql_name" >/dev/null 2>&1 \
    || docker container inspect "$restore_name" >/dev/null 2>&1; then
    echo "E2E=HOLD;CODE=UNIQUE_RESOURCE_COLLISION"
    exit 3
fi

docker build --pull=false --label "com.wpcg.e2e-run=$run_id" -f "$repo_root/scripts/tests/staging-next-deploy-e2e.Dockerfile" \
    -t "$php_image" "$repo_root"
created_image=1
docker network create --label "com.wpcg.e2e-run=$run_id" "$network" >/dev/null
created_network=1
docker run -d --label "com.wpcg.e2e-run=$run_id" --name "$mysql_name" --network "$network" --network-alias wpcg-mysql \
    -e MYSQL_ROOT_PASSWORD=synthetic-only-password \
    -e MYSQL_DATABASE=wpcg_staging_next \
    --health-cmd='mysqladmin ping -h 127.0.0.1 -uroot -psynthetic-only-password --silent' \
    --health-interval=2s --health-timeout=2s --health-retries=30 \
    "$mysql_image" --skip-log-bin >/dev/null
created_mysql=1
docker run -d --label "com.wpcg.e2e-run=$run_id" --name "$restore_name" --network "$network" --network-alias wpcg-mysql-restore \
    -e MYSQL_ROOT_PASSWORD=synthetic-only-password \
    -e MYSQL_ROOT_HOST=% \
    -e MYSQL_DATABASE=wpcg_staging_next \
    --health-cmd='mysqladmin ping -h 127.0.0.1 -uroot -psynthetic-only-password --silent' \
    --health-interval=2s --health-timeout=2s --health-retries=30 \
    "$mysql_image" --skip-log-bin >/dev/null
created_restore=1

ready=0
for service in "$mysql_name" "$restore_name"; do
    ready=0
    for _ in $(seq 1 60); do
        health="$(docker inspect -f '{{.State.Health.Status}}' "$service")"
        if [[ "$health" == healthy ]]; then ready=1; break; fi
        if [[ "$health" == unhealthy ]]; then
            echo "E2E=FAIL;CODE=MYSQL_UNHEALTHY"
            exit 1
        fi
        sleep 1
    done
    if (( ! ready )); then
        echo "E2E=FAIL;CODE=MYSQL_START_TIMEOUT"
        exit 1
    fi
done

docker exec -e MYSQL_PWD=synthetic-only-password "$mysql_name" \
    mysql --no-defaults -uroot --batch --skip-column-names \
    -e "CREATE USER '${app_user}'@'%' IDENTIFIED BY '${app_password}'; GRANT ALL PRIVILEGES ON wpcg_staging_next.* TO '${app_user}'@'%';"
docker exec -e MYSQL_PWD=synthetic-only-password "$restore_name" \
    mysql --no-defaults -uroot --batch --skip-column-names \
    -e "CREATE USER '${app_user}'@'%' IDENTIFIED BY '${app_password}'; GRANT ALL PRIVILEGES ON wpcg_staging_next.* TO '${app_user}'@'%';"
mysql_uuid="$(docker exec -e MYSQL_PWD=synthetic-only-password "$mysql_name" \
    mysql --no-defaults -uroot --batch --skip-column-names -e 'SELECT @@server_uuid')"
mysql_uuid_sha256="$(printf '%s' "$mysql_uuid" | sha256sum | cut -d' ' -f1)"

docker run --rm --network "$network" \
    -e WPCG_PHP_MYSQL_E2E=1 \
    -e WPCG_MYSQL_HOST=wpcg-mysql \
    -e WPCG_MYSQL_DATABASE=wpcg_staging_next \
    -e WPCG_MYSQL_USER="$app_user" \
    -e WPCG_MYSQL_PASSWORD="$app_password" \
    -e WPCG_MYSQL_UUID_SHA256="$mysql_uuid_sha256" \
    -e WPCG_RESTORE_MYSQL_HOST=wpcg-mysql-restore \
    -e WPCG_RESTORE_MYSQL_USER=root \
    -e WPCG_RESTORE_MYSQL_PASSWORD=synthetic-only-password \
    -e WPCG_BACKUP_MYSQL_PASSWORD="$app_password" \
    -v "$repo_root:/repo:ro" -w /repo "$php_image" \
    sh -ceu 'php -r '\''if (!str_starts_with(PHP_VERSION, "8.5.")) exit(1); echo "PHP=".PHP_VERSION."\n";'\''; python3 scripts/tests/staging-next-helper-consistency-test.py && python3 scripts/tests/staging-next-deploy-backend-test.py && python3 scripts/tests/staging-next-deploy-controller-test.py && python3 scripts/tests/staging-next-helper-contract-test.py && python3 scripts/tests/staging-next-laravel-deploy-e2e-test.py'

db_state="$(docker exec -e MYSQL_PWD=synthetic-only-password "$mysql_name" \
    mysql --no-defaults -uroot --batch --skip-column-names \
    -e "SELECT VERSION(), COUNT(*), (SELECT COUNT(*) FROM wpcg_staging_next.migrations) FROM information_schema.tables WHERE table_schema='wpcg_staging_next' AND table_type='BASE TABLE'")"
IFS=$'\t' read -r mysql_version table_count migration_count <<<"$db_state"
migration_files="$(find "$repo_root/database/migrations" -maxdepth 1 -type f -name '*.php' | wc -l | tr -d '[:space:]')"
if [[ "$mysql_version" != "8.4.11" || ! "$table_count" =~ ^[0-9]+$ || "$table_count" -lt 1 \
    || ! "$migration_count" =~ ^[0-9]+$ || "$migration_count" != "$migration_files" ]]; then
    printf 'E2E=FAIL;CODE=MYSQL_POSTCHECK_MISMATCH\n'
    exit 1
fi
printf 'MYSQL_VERSION=8.4.11\nMYSQL_APPLICATION_TABLES=%s\nMYSQL_MIGRATIONS=%s\nE2E=PASS\n' \
    "$table_count" "$migration_count"
