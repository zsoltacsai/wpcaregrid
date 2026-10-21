#!/usr/bin/env bash
set -euo pipefail
umask 077

if [ ! -f /.dockerenv ] || [ "$(id -u)" -ne 0 ]; then
  echo "INTEGRATION_REQUIRES_DISPOSABLE_ROOT_CONTAINER" >&2
  exit 77
fi
for tool in sshd ssh-keygen ssh sudo visudo php python3 git useradd usermod getfacl setfacl; do
  command -v "$tool" >/dev/null || { echo "MISSING_TOOL=$tool" >&2; exit 77; }
done

PYTHONDONTWRITEBYTECODE=1 python3 scripts/tests/staging-diagnostics-provisioning-test.py
PYTHONDONTWRITEBYTECODE=1 python3 scripts/tests/staging-diagnostics-package-preparation-test.py
PYTHONDONTWRITEBYTECODE=1 python3 scripts/tests/staging-diagnostics-policy-test.py
PYTHONDONTWRITEBYTECODE=1 python3 scripts/tests/staging-diagnostics-output-test.py

APP=/var/www/wpcaregrid
APPROVED_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
STAGE=/var/lib/wpcaregrid-staging-diagnostics-provisioning/source/$APPROVED_SHA
REPO=/workspace
PACKAGE_FILES='scripts/staging-diagnostics/dispatch.sh
scripts/staging-diagnostics/provisioning/activation-check.sh
scripts/staging-diagnostics/provisioning/diagnostics-shell.sh
scripts/staging-diagnostics/read-m12-cache.php
scripts/staging-diagnostics/provisioning/run-m12-readback.sh
scripts/staging-diagnostics/provisioning/provision.py
scripts/staging-diagnostics/provisioning/verify.py
scripts/staging-diagnostics/provisioning/rollback.py'

if ! getent passwd deploy >/dev/null; then
  getent group deploy >/dev/null || groupadd --system deploy
  useradd --system --gid deploy --home-dir /var/lib/deploy --create-home --shell /bin/sh deploy
fi
mkdir -p "$APP/bootstrap/cache" /var/lib/wpcaregrid-staging-diagnostics-provisioning/source
mkdir -p /usr/local/libexec /usr/local/sbin /etc/sudoers.d /run
chmod 0755 /usr/local/libexec /usr/local/sbin /etc/sudoers.d /run
[[ "$(stat -c '%a' /run/lock)" == 1777 ]] || { echo "DEBIAN12_RUN_LOCK_DEFAULT_UNEXPECTED" >&2; exit 1; }
echo "DEBIAN12_RUN_LOCK_DEFAULT=1777"
chown -R deploy:deploy "$APP"
cat > "$APP/bootstrap/cache/config.php" <<'CONFIG_FIXTURE'
<?php return array (
    'privacy' => array (
        'destructive_retention_enabled' => false,
        'profile_anonymization_enabled' => false,
        'backup_deletion_enabled' => false,
        'provider_deletion_enabled' => false,
        'bulk_retention_execution_enabled' => false,
        'synthetic_profile_anonymization_enabled' => false,
    ),
    'app' => array (
        'deployment_environment' => 'staging',
        'deployment_hostname' => 'stg1.wpcaregrid.com',
        'url' => 'https://staging.wpcaregrid.com',
    ),
);
CONFIG_FIXTURE
printf '%s\n' 'APP_KEY=synthetic-test-only' > "$APP/.env"
chown deploy:deploy "$APP/bootstrap/cache/config.php" "$APP/.env"
chmod 0600 "$APP/bootstrap/cache/config.php" "$APP/.env"
setfacl -m u:www-data:r--,g::--- "$APP/bootstrap/cache/config.php"
[[ "$(stat -c '%a' "$APP/bootstrap/cache/config.php")" == 640 ]] || {
  echo "PHP_FPM_CACHE_ACL_MODE_UNEXPECTED" >&2
  exit 1
}

# Exercise the real non-root Git-to-root-stage helper with a disposable clean
# repository. The isolated test account receives temporary sudo only in this
# container; --prepare-only must stop before any root Python installer starts.
groupadd --system wpcg-preparer-test
useradd --system --gid wpcg-preparer-test --home-dir /var/lib/wpcg-preparer-test \
  --create-home --shell /bin/bash wpcg-preparer-test
printf 'wpcg-preparer-test ALL=(root) NOPASSWD: ALL\n' > /etc/sudoers.d/wpcg-preparer-test
chmod 0440 /etc/sudoers.d/wpcg-preparer-test
visudo -c >/dev/null
PREP_REPO=/var/tmp/wpcg-canonical-source
mkdir -p "$PREP_REPO"
while IFS= read -r relative; do
  [ -n "$relative" ] || continue
  mkdir -p "$PREP_REPO/$(dirname "$relative")"
  cp "$REPO/$relative" "$PREP_REPO/$relative"
done <<< "$PACKAGE_FILES"
mkdir -p "$PREP_REPO/scripts/staging-diagnostics/provisioning"
cp "$REPO/scripts/staging-diagnostics/provisioning/prepare-package.sh" \
  "$PREP_REPO/scripts/staging-diagnostics/provisioning/prepare-package.sh"
git -C "$PREP_REPO" init -q
git -C "$PREP_REPO" config user.name 'Synthetic Provisioning Test'
git -C "$PREP_REPO" config user.email 'synthetic@example.invalid'
git -C "$PREP_REPO" add .
git -C "$PREP_REPO" commit -qm 'synthetic approved diagnostics source'
PREP_SHA=$(git -C "$PREP_REPO" rev-parse HEAD)
chown -R wpcg-preparer-test:wpcg-preparer-test "$PREP_REPO"
PREP_HELPER_ENTRY=$(runuser -u wpcg-preparer-test -- git --no-replace-objects -C "$PREP_REPO" ls-tree "$PREP_SHA" -- scripts/staging-diagnostics/provisioning/prepare-package.sh)
[[ "$PREP_HELPER_ENTRY" == *$'\t'* ]]
PREP_HELPER_META=${PREP_HELPER_ENTRY%%$'\t'*}
PREP_HELPER_PATH=${PREP_HELPER_ENTRY#*$'\t'}
read -r PREP_HELPER_MODE PREP_HELPER_KIND PREP_HELPER_OID <<< "$PREP_HELPER_META"
[[ "$PREP_HELPER_PATH" == scripts/staging-diagnostics/provisioning/prepare-package.sh ]]
[[ "$PREP_HELPER_MODE" == 100644 || "$PREP_HELPER_MODE" == 100755 ]]
[[ "$PREP_HELPER_KIND" == blob && "$PREP_HELPER_OID" =~ ^[0-9a-f]{40}$ ]]
runuser -u wpcg-preparer-test -- git --no-replace-objects -C "$PREP_REPO" cat-file blob "$PREP_HELPER_OID" |
  runuser -u wpcg-preparer-test -- /usr/bin/env -i \
    PATH=/usr/bin:/bin HOME=/var/lib/wpcg-preparer-test \
    /bin/bash -s -- --prepare-only "$PREP_REPO" "$PREP_SHA"
PREP_STAGE=/var/lib/wpcaregrid-staging-diagnostics-provisioning/source/$PREP_SHA
[[ "$(stat -c '%u:%g:%a:%F' "$PREP_STAGE")" == '0:0:700:directory' ]]
[[ "$(stat -c '%u:%g:%a:%F' "$PREP_STAGE/.wpcaregrid-provenance")" == '0:0:444:regular file' ]]
echo "CANONICAL_GIT_PACKAGE_STAGING=PASS"

rm -rf "$STAGE"
install -d -o root -g root -m 0700 "$STAGE"
while IFS= read -r relative; do
  [ -n "$relative" ] || continue
  destination=$STAGE/$relative
  install -d -o root -g root -m 0700 "$(dirname "$destination")"
  install -o root -g root -m 0444 "$REPO/$relative" "$destination"
done <<< "$PACKAGE_FILES"
rm -f /etc/ssh/ssh_host_ed25519_key /etc/ssh/ssh_host_ed25519_key.pub
ssh-keygen -q -t ed25519 -N '' -f /etc/ssh/ssh_host_ed25519_key
HOST_FINGERPRINT=$(ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256 | awk '{print $2}')
sed -i "s|SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ|$HOST_FINGERPRINT|g" "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" "$STAGE/scripts/staging-diagnostics/provisioning/verify.py"

{
  printf 'commit=%s\ntree=%s\n' "$APPROVED_SHA" "$(printf '%040d' 0)"
  while IFS= read -r relative; do
    [ -n "$relative" ] || continue
    digest=$(sha256sum "$STAGE/$relative" | awk '{print $1}')
    blob_oid=$(python3 -c 'import hashlib,sys; data=open(sys.argv[1],"rb").read(); print(hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest())' "$STAGE/$relative")
    printf '%s %s  %s\n' "$blob_oid" "$digest" "$relative"
  done <<< "$PACKAGE_FILES"
} > "$STAGE/.wpcaregrid-provenance"
chown root:root "$STAGE/.wpcaregrid-provenance"
chmod 0444 "$STAGE/.wpcaregrid-provenance"

cat > /etc/ssh/sshd_config <<'SSH_CONFIG'
Port 2222
ListenAddress 127.0.0.1
HostKey /etc/ssh/ssh_host_ed25519_key
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
GSSAPIAuthentication no
HostbasedAuthentication no
AuthorizedKeysFile .ssh/authorized_keys
AuthorizedKeysCommand none
TrustedUserCAKeys none
ForceCommand none
AllowTcpForwarding no
AllowAgentForwarding no
X11Forwarding no
PermitTunnel no
PermitTTY no
PermitUserRC no
PermitUserEnvironment no
Subsystem sftp /usr/lib/openssh/sftp-server
UsePAM yes
SSH_CONFIG
chmod 0600 /etc/ssh/sshd_config
mkdir -p /run/sshd
sshd -t

ssh-keygen -q -t ed25519 -N '' -f /root/diagnostics-test
chmod 0600 /root/diagnostics-test
chmod 0600 /root/diagnostics-test.pub
install -m 0600 /root/diagnostics-test.pub /root/diagnostics-test-authorized.pub
mkdir -p /root/.ssh
printf '[127.0.0.1]:2222 %s
' "$(cat /etc/ssh/ssh_host_ed25519_key.pub)" > /root/.ssh/known_hosts
chmod 0600 /root/.ssh/known_hosts

python3 -I -S - "$STAGE" "$APPROVED_SHA" <<'PY'
import sys
from pathlib import Path
sys.path.insert(0, str(Path(sys.argv[1]) / "scripts/staging-diagnostics/provisioning"))
import provision
key = provision._validate_key_file(Path("/root/diagnostics-test-authorized.pub"))
try:
    def fail_after_key(phase):
        if phase == "after_authorized_keys_installation":
            raise provision.ProvisionError("INJECTED_AFTER_KEY")
    provision.install(
        layout=provision.Layout(),
        source_root=Path(sys.argv[1]),
        approved_sha=sys.argv[2],
        public_key=key,
        runner=provision._run_checked,
        checkpoint=fail_after_key,
    )
except provision.ProvisionError as error:
    if str(error) != "INJECTED_AFTER_KEY":
        raise
else:
    raise SystemExit("EXPECTED_INJECTED_FAILURE_NOT_SEEN")
PY

/usr/sbin/sshd -D -e -f /etc/ssh/sshd_config >/tmp/wpcg-sshd.log 2>&1 &
SSHD_PID=$!
cleanup() {
  kill "$SSHD_PID" 2>/dev/null || true
  wait "$SSHD_PID" 2>/dev/null || true
}
trap cleanup EXIT
sleep 1
SSH=(ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1)

if "${SSH[@]}" m12-config-readback >/dev/null 2>&1; then
  echo "PENDING_INSTALL_WAS_USABLE" >&2
  exit 1
fi
echo "REAL_OPENSSH_PENDING_STATE=DENIED"

/usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/rollback.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub
/usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub
/usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/verify.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub
/usr/local/libexec/wpcaregrid-staging-diagnostics-activation-check

if ! output=$("${SSH[@]}" m12-config-readback 2>/tmp/wpcg-client.err); then
  cat /tmp/wpcg-client.err >&2
  tail -n 30 /tmp/wpcg-sshd.log >&2
  exit 1
fi
printf '%s
' "$output" | grep -q '^M12|destructive_retention_enabled|FALSE$'
printf '%s
' "$output" | grep -q '^IDENTITY|deployment_environment|staging$'
echo "REAL_OPENSSH_FIXED_DIAGNOSTIC=PASS"
if ssh -F /dev/null -p 2222 -o BatchMode=yes -o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive -o NumberOfPasswordPrompts=0 -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1 m12-config-readback >/dev/null 2>&1; then
  echo "ALTERNATE_PASSWORD_AUTH_WAS_ACCEPTED" >&2
  exit 1
fi
echo "REAL_OPENSSH_ALTERNATE_AUTH=DENIED"

if "${SSH[@]}" id >/dev/null 2>&1; then
  echo "ARBITRARY_REMOTE_COMMAND_WAS_ACCEPTED" >&2
  exit 1
fi
if "${SSH[@]}" >/dev/null 2>&1; then
  echo "INTERACTIVE_SHELL_WAS_ACCEPTED" >&2
  exit 1
fi
if printf '' | sftp -b - -F /dev/null -P 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts caregrid-diagnostics@127.0.0.1 >/dev/null 2>&1; then
  echo "SFTP_WAS_ACCEPTED" >&2
  exit 1
fi
echo "REAL_OPENSSH_COMMAND_SHELL_SFTP=DENIED"

if ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -tt caregrid-diagnostics@127.0.0.1 m12-config-readback >/tmp/wpcg-pty.out 2>/tmp/wpcg-pty.err; then
  grep -q 'PTY allocation request failed' /tmp/wpcg-pty.err
else
  echo "PTY_REQUEST_REJECTED"
fi

set +e
timeout 3 ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ExitOnForwardFailure=yes -N -R 18081:127.0.0.1:2222 caregrid-diagnostics@127.0.0.1 >/tmp/wpcg-forward.out 2>&1
FORWARD_STATUS=$?
set -e
if [ "$FORWARD_STATUS" -ne 255 ]; then
  echo "REMOTE_FORWARD_STATUS_UNEXPECTED=$FORWARD_STATUS" >&2
  exit 1
fi
grep -qi 'remote port forwarding failed' /tmp/wpcg-forward.out
echo "REAL_OPENSSH_FORWARDING=DENIED"

if /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub >/tmp/wpcg-repeat.out 2>&1; then
  echo "IDEMPOTENT_INSTALL=PASS"
else
  cat /tmp/wpcg-repeat.out >&2
  exit 1
fi
printf '%s
' 'caregrid-diagnostics ALL=(root) NOPASSWD: ALL' > /etc/sudoers.d/zz-wpcg-test-expansion
chmod 0440 /etc/sudoers.d/zz-wpcg-test-expansion
visudo -c >/dev/null
if /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub >/tmp/wpcg-sudo-expansion.out 2>&1; then
  echo "SUDO_EXPANSION_WAS_ACCEPTED" >&2
  exit 1
fi
grep -q 'SUDOERS_EFFECTIVE_POLICY_UNSAFE' /tmp/wpcg-sudo-expansion.out
rm -f /etc/sudoers.d/zz-wpcg-test-expansion
visudo -c >/dev/null
echo "REAL_SUDO_PRIVILEGE_EXPANSION=DENIED"

ACTIVE=/var/lib/wpcaregrid-staging-diagnostics/provisioning.active
PENDING=/var/lib/wpcaregrid-staging-diagnostics/provisioning.pending
rm -f "$ACTIVE"
if ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1 m12-config-readback >/dev/null 2>&1; then
  echo "MISSING_ACTIVATION_WAS_ACCEPTED" >&2
  exit 1
fi
printf 'active=%s\n' "$APPROVED_SHA" > "$ACTIVE"
chmod 0444 "$ACTIVE"
printf 'invalid\n' > /tmp/wpcg-invalid-active
cp /tmp/wpcg-invalid-active "$ACTIVE"
chmod 0444 "$ACTIVE"
if ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1 m12-config-readback >/dev/null 2>&1; then
  echo "INVALID_ACTIVATION_WAS_ACCEPTED" >&2
  exit 1
fi
printf 'active=%s\n' "$APPROVED_SHA" > "$ACTIVE"
chmod 0444 "$ACTIVE"
touch "$PENDING"
if ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1 m12-config-readback >/dev/null 2>&1; then
  echo "PENDING_MARKER_WAS_ACCEPTED" >&2
  exit 1
fi
rm "$PENDING"
rm "$ACTIVE"
ln -s /tmp/wpcg-invalid-active "$ACTIVE"
if ssh -F /dev/null -p 2222 -i /root/diagnostics-test -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/root/.ssh/known_hosts -o ConnectTimeout=3 caregrid-diagnostics@127.0.0.1 m12-config-readback >/dev/null 2>&1; then
  echo "ACTIVATION_SYMLINK_WAS_ACCEPTED" >&2
  exit 1
fi
echo "REAL_OPENSSH_ACTIVATION_STATES=DENIED"

python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/rollback.py" --approved-source-sha "$APPROVED_SHA" --public-key-file /root/diagnostics-test-authorized.pub
if "${SSH[@]}" m12-config-readback >/dev/null 2>&1; then
  echo "REVOKED_KEY_STILL_WORKED" >&2
  exit 1
fi
echo "REAL_OPENSSH_ROLLBACK_REVOCATION=PASS"
