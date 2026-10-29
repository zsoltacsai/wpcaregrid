#!/usr/bin/env bash
set -euo pipefail
umask 077

repo_root="${GITHUB_WORKSPACE:-$(pwd)}"
base_image='php:8.5.12-fpm@sha256:8b4b4a764f7985137b13e4cb49dd59e967d9daa7946a731f9d06e31e748e39bb'
[[ "${PHP_FPM_BASE:-$base_image}" == "$base_image" ]] || { echo "Unexpected FPM base image identity." >&2; exit 1; }
: "${RUNNER_TEMP:?RUNNER_TEMP is required}"
: "${GITHUB_STEP_SUMMARY:?GITHUB_STEP_SUMMARY is required}"
: "${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"

context="$RUNNER_TEMP/php85-fpm-context"
ready_file="$RUNNER_TEMP/php85-fpm-witness.json"
witness_log="$RUNNER_TEMP/php85-fpm-witness.log"
witness_pid=''
cleanup() {
    if [[ -n "$witness_pid" ]]; then
        kill "$witness_pid" 2>/dev/null || true
        wait "$witness_pid" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

rm -rf -- "$context"
mkdir -p "$context/scripts/tests/php-fpm"
for path in app bootstrap config database lang public resources routes storage vendor; do
    [[ -d "$repo_root/$path" ]] || { echo "Missing isolated build input: $path" >&2; exit 1; }
    cp -a -- "$repo_root/$path" "$context/"
done
for path in artisan composer.json composer.lock; do
    cp -a -- "$repo_root/$path" "$context/"
done
cp -a -- "$repo_root/scripts/tests/php-fpm/." "$context/scripts/tests/php-fpm/"
[[ ! -e "$context/.env" && ! -e "$context/.git" && ! -e "$context/.github" ]] || {
    echo "The isolated build context contains a forbidden environment or repository directory." >&2
    exit 1
}
python3 "$repo_root/scripts/tests/php-fpm/policy-test.py"

docker build --file "$context/scripts/tests/php-fpm/Dockerfile" --pull --no-cache --platform linux/amd64 \
    --build-arg "PHP_FPM_BASE=$base_image" \
    --tag "php85-fpm-lane:$GITHUB_RUN_ID" "$context"
image_id="$(docker image inspect --format '{{.Id}}' "php85-fpm-lane:$GITHUB_RUN_ID")"
lock_sha="$(sha256sum "$repo_root/composer.lock" | awk '{print $1}')"
[[ "$lock_sha" =~ ^[0-9a-f]{64}$ && "$image_id" =~ ^sha256:[0-9a-f]{64}$ ]] || {
    echo "Could not record immutable runtime or dependency identity." >&2
    exit 1
}

python3 "$repo_root/scripts/tests/php-fpm/witness.py" start "$ready_file" >"$witness_log" 2>&1 &
witness_pid=$!
for _ in $(seq 1 100); do
    [[ -s "$ready_file" ]] && break
    kill -0 "$witness_pid" 2>/dev/null || { cat "$witness_log" >&2; exit 1; }
    sleep 0.1
done
[[ -s "$ready_file" ]] || { echo "Controlled IPv4/IPv6 witnesses did not start." >&2; exit 1; }
python3 "$repo_root/scripts/tests/php-fpm/witness.py" verify "$ready_file"
port4="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["ipv4"])' "$ready_file")"
port6="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["ipv6"])' "$ready_file")"
app_key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"

evidence="$(docker run --rm --pull=never --platform linux/amd64 \
    --network none \
    --pid private \
    --cap-drop ALL \
    --security-opt no-new-privileges:true \
    --pids-limit 64 \
    --memory 1g \
    --read-only \
    --user 33:33 \
    --tmpfs /tmp:rw,noexec,nosuid,nodev,size=128m,uid=33,gid=33,mode=1770 \
    --tmpfs /app/storage:rw,noexec,nosuid,nodev,size=128m,uid=33,gid=33,mode=0750 \
    --add-host host.docker.internal:127.0.0.1 \
    --add-host staging.wpcaregrid.com:127.0.0.1 \
    --add-host wpcaregrid.com:127.0.0.1 \
    --env "APP_KEY=$app_key" \
    --env "FPM_WITNESS_PORT4=$port4" \
    --env "FPM_WITNESS_PORT6=$port6" \
    "php85-fpm-lane:$GITHUB_RUN_ID")"
printf '%s\n' "$evidence"
python3 -c 'import json,sys; data=json.loads(sys.stdin.read()); assert data["php_version"] == "8.5.12" and data["sapi"] == "fpm-fcgi" and data["worker_uid"] == 33 and data["fpm_fastcgi_http_200"] and data["background_consumers"] == "none"; print("FPM evidence validated.")' <<<"$evidence"

{
    echo "## Isolated PHP-FPM 8.5 compatibility lane"
    echo
    echo "- Base image: $base_image (linux/amd64)"
    echo "- Built disposable image ID: $image_id"
    echo "- Composer lock SHA-256: $lock_sha"
    echo "- FPM runtime evidence (sanitized):"
    echo
    echo '  ```json'
    printf '%s\n' "$evidence" | python3 -m json.tool | sed 's/^/  /'
    echo '  ```'
    echo
    echo "The FPM container had no network, host bind mounts, Docker socket, runner credentials, public listener, scheduler, or queue consumer. The lane is compatibility evidence only."
} >>"$GITHUB_STEP_SUMMARY"
