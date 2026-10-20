#!/usr/bin/env bash
set -euo pipefail
set +x

readonly expected_fingerprint='SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ'
readonly host='stg1.wpcaregrid.com'
readonly user='caregrid-diagnostics'
readonly target="${user}@${host}"

if [[ "${GITHUB_REF-}" != 'refs/heads/claude/ecstatic-wozniak-oemyuf' ]]; then
    printf '%s\n' 'DIAGNOSTIC_STATUS=WRONG_REF'
    exit 2
fi

if [[ "${DIAGNOSTIC_ID-}" != 'm12-config-readback' ]]; then
    printf '%s\n' 'DIAGNOSTIC_STATUS=UNSUPPORTED_OPERATION'
    exit 2
fi

if [[ -z "${STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY-}" || -z "${STAGING_DIAGNOSTICS_KNOWN_HOSTS-}" ]]; then
    printf '%s\n' 'DIAGNOSTIC_STATUS=ENVIRONMENT_NOT_PROVISIONED'
    exit 2
fi

umask 077
tmp_dir="$(mktemp -d "${RUNNER_TEMP:-/tmp}/caregrid-m12.XXXXXXXX")"
trap 'rm -rf -- "$tmp_dir"' EXIT
chmod 700 "$tmp_dir"

key_file="$tmp_dir/diagnostics_key"
known_hosts_file="$tmp_dir/known_hosts"
public_key_file="$tmp_dir/host_key.pub"
raw_output_file="$tmp_dir/raw_output"

printf '%s\n' "$STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY" > "$key_file"
printf '%s\n' "$STAGING_DIAGNOSTICS_KNOWN_HOSTS" > "$known_hosts_file"
chmod 600 "$key_file" "$known_hosts_file"

host_key="$(ssh-keygen -F "$host" -f "$known_hosts_file" 2>/dev/null | awk '$2 == "ssh-ed25519" { count++; key = $2 " " $3 } END { if (count == 1) print key; else exit 1 }')" || {
    printf '%s\n' 'DIAGNOSTIC_STATUS=HOST_KEY_RECORD_INVALID'
    exit 2
}
printf '%s\n' "$host_key" > "$public_key_file"
fingerprint="$(ssh-keygen -lf "$public_key_file" -E sha256 2>/dev/null | awk '{ print $2 }')" || {
    printf '%s\n' 'DIAGNOSTIC_STATUS=HOST_KEY_RECORD_INVALID'
    exit 2
}
if [[ "$fingerprint" != "$expected_fingerprint" ]]; then
    printf '%s\n' 'DIAGNOSTIC_STATUS=HOST_KEY_MISMATCH'
    exit 2
fi

set +e
timeout --signal=TERM --kill-after=2 45s ssh -T -p 22 \
    -i "$key_file" \
    -o BatchMode=yes \
    -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$known_hosts_file" \
    -o GlobalKnownHostsFile=/dev/null \
    -o UpdateHostKeys=no \
    -o ConnectTimeout=10 \
    -o ConnectionAttempts=1 \
    -o ClearAllForwardings=yes \
    -o ForwardAgent=no \
    -o PermitLocalCommand=no \
    -o ProxyCommand=none \
    -o ProxyJump=none \
    "$target" 'm12-config-readback' 2>/dev/null \
    | head -c 4097 > "$raw_output_file"
ssh_status=${PIPESTATUS[0]}
set -e

output_bytes="$(wc -c < "$raw_output_file")"
if (( output_bytes > 4096 )); then
    printf '%s\n' 'DIAGNOSTIC_STATUS=OUTPUT_LIMIT_EXCEEDED'
    exit 2
fi

if (( ssh_status != 0 )); then
    set +e
    python3 scripts/staging-diagnostics/sanitize_m12_output.py < "$raw_output_file"
    sanitizer_status=$?
    set -e
    if (( sanitizer_status == 1 )); then exit 1; fi
    printf '%s\n' 'DIAGNOSTIC_STATUS=SSH_OR_REMOTE_FAILURE'
    exit 1
fi

python3 scripts/staging-diagnostics/sanitize_m12_output.py < "$raw_output_file"
