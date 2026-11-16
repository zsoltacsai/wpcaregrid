#!/usr/bin/env bash
# WP CareGrid staging-next helper 1.4.10 - Owner GO #1: stage the six pinned files on the staging host.
#
# Runs on the OWNER'S WORKSTATION inside a clone of the repository. It exports the six files from the
# pinned merge commit, verifies their sha256 pins locally, and streams them over SSH to a small python
# program that runs on the host as the `deploy` user (never root). The host program changes only
# /home/deploy/.private (create-only: no overwrite, atomic publish). On an interrupted or failed
# publication it leaves any staged objects in place and reports HOLD; it never deletes by pathname.
# It installs nothing, touches no sudoers/helper/service/DB,
# opens no network connection, and prints no file content.
#
#   MODE=check   verify every precondition and the payload on the host, write NOTHING
#   MODE=stage   the above, then create the staged files
#
# Final line (exit 0 only for PASS / CHECK_PASS):
#   STAGING_1410_STAGE=PASS | CHECK_PASS | STOP;CODE=<reason>
set -euo pipefail

REQUESTED_SSH_TARGET="${SSH_TARGET:-caregrid-staging}"
[ "$REQUESTED_SSH_TARGET" = "caregrid-staging" ] || {
  echo "STAGING_1410_STAGE=STOP;CODE=invalid_ssh_target"
  exit 2
}
SSH_TARGET="caregrid-staging"
MODE="${MODE:-check}"
EXPECTED_TOOL_COMMIT="${EXPECTED_TOOL_COMMIT:-}"
COMMIT="8ff594bd7ca4e8e6963923c49b605bfcdc24d7ab"
PINS=(
  "wpcaregrid-staging-next-deploy-priv-1_4_10.py 893b77919aa87c1103ee0ecc54bd433a376e31cc85b797638618ed13e9344d65"
  "staging_next_deploy_backend_1_4_10.py 0857a9d81c5d368da634bdab846accd40dd24f296057d4234706b5b4e52ca66f"
  "staging_next_db_transaction_1_4_10.py d1a382728180526ae6a0b27f70abc5e42b512fb389eb8339b9c2c544c7567848"
  "deployment_target_binding_1_4_10.py e985227ea1307b5d9ff5b737cf9b3586fe5ebbd7c7b194632078237a8c732e16"
  "deployment_environment_contract.py f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1"
  "install_staging_next_helper_1_4_10.py f58c9c556ae8b52a793cd818c023253c9c20a69dd8b2dd612181ad8c8fefbe3c"
)

stop() { echo "STAGING_1410_STAGE=STOP;CODE=$1"; exit 2; }
case "$MODE" in check|stage) ;; *) stop invalid_mode ;; esac
case "$SSH_TARGET" in ""|-*|*[!A-Za-z0-9._@:-]*) stop invalid_ssh_target ;; esac
[[ "$EXPECTED_TOOL_COMMIT" =~ ^[0-9a-f]{40}$ ]] || stop expected_tool_commit_invalid

sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || stop not_in_a_git_clone
cd "$ROOT"
# Authenticate the executable driver itself before it can send PR-controlled Python over SSH.
SCRIPT_PATH="$(realpath -- "${BASH_SOURCE[0]}")" || stop local_script_path_invalid
SCRIPT_REL="scripts/staging-next-helper-1-4-10-stage-sources.sh"
[ "$SCRIPT_PATH" = "$ROOT/$SCRIPT_REL" ] || stop local_script_path_invalid
git ls-files --error-unmatch "$SCRIPT_REL" >/dev/null 2>&1 || stop local_script_untracked
git diff --quiet HEAD -- "$SCRIPT_REL" || stop local_script_modified
HEAD_SCRIPT_BLOB="$(git rev-parse "HEAD:$SCRIPT_REL" 2>/dev/null)" || stop local_script_head_blob_missing
LOCAL_SCRIPT_BLOB="$(git hash-object "$SCRIPT_PATH" 2>/dev/null)" || stop local_script_hash_failed
[ "$HEAD_SCRIPT_BLOB" = "$LOCAL_SCRIPT_BLOB" ] || stop local_script_head_blob_mismatch
git cat-file -e "${COMMIT}^{commit}" 2>/dev/null || stop pinned_commit_missing_run_git_fetch
HEAD_COMMIT="$(git rev-parse HEAD 2>/dev/null)" || stop tool_head_unavailable
[ "$HEAD_COMMIT" = "$EXPECTED_TOOL_COMMIT" ] || stop tool_head_mismatch

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# 1. export and verify the pinned bytes locally (git blobs: LF, exactly what CI hashed)
for entry in "${PINS[@]}"; do
  name="${entry%% *}"; pin="${entry##* }"
  git cat-file blob "${COMMIT}:scripts/${name}" > "${WORK}/${name}" 2>/dev/null || stop "export_failed:${name}"
  [ "$(sha "${WORK}/${name}")" = "$pin" ] || stop "local_pin_mismatch:${name}"
done
echo "LOCAL_VERIFY=PASS;COMMIT=${COMMIT};FILES=${#PINS[@]}"

# 2. framed payload: "FILE <name> <size>\n<bytes>" per file, then "END\n"
{
  for entry in "${PINS[@]}"; do
    name="${entry%% *}"
    printf 'FILE %s %s\n' "$name" "$(wc -c < "${WORK}/${name}" | tr -d ' ')"
    cat "${WORK}/${name}"
  done
  printf 'END\n'
} > "${WORK}/payload"

# 3. the host-side program (runs as `deploy`; all constants are fixed here, nothing is read from the environment)
IFS= read -r -d '' REMOTE_PY <<'WPCG_STAGE_PY' || true
import ctypes
import hashlib
import os
import pwd
import secrets
import signal
import stat
import sys

MODE = "@@MODE@@"
USER = "deploy"
HOME = "/home/deploy"
PRIV = HOME + "/.private"
SRC = PRIV + "/wpcg-staging-next-helper-1.4.10"
INST = PRIV + "/wpcaregrid-staging-next-helper-installer-1.4.10.py"
INSTALLER_NAME = "install_staging_next_helper_1_4_10.py"
PINS = {
@@PINS@@
}
MAX_FILE = 2_000_000
APP_DIR = "/var/www/wpcaregrid-staging-new"
PROFILE_DIR = "/etc/wpcaregrid-deploy/profiles"
ROOT_UID = 0


def stop(code):
    print("STAGING_1410_STAGE=STOP;CODE=" + code)
    sys.exit(2)


def lst(path):
    try:
        return os.lstat(path)
    except FileNotFoundError:
        return None
    except OSError:
        stop("lstat_failed")


def need_dir(path, *, owner=None, no_gow=True, exact_mode=None):
    info = lst(path)
    if info is None or not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        stop("directory_invalid:" + path)
    if owner is not None and info.st_uid != owner:
        stop("directory_owner:" + path)
    if no_gow and stat.S_IMODE(info.st_mode) & 0o022:
        stop("directory_writable_by_group_or_other:" + path)
    if exact_mode is not None and stat.S_IMODE(info.st_mode) != exact_mode:
        stop("directory_mode:" + path)
    return info


def read_exact(stream, size):
    data = stream.read(size)
    if len(data) != size:
        stop("payload_truncated")
    return data


def read_payload():
    stream = sys.stdin.buffer
    files = {}
    while True:
        header = stream.readline(300)
        if header == b"END\n":
            break
        parts = header.decode("ascii", "replace").rstrip("\n").split(" ")
        if len(parts) != 3 or parts[0] != "FILE" or not header.endswith(b"\n"):
            stop("payload_header_invalid")
        name, size_text = parts[1], parts[2]
        if name not in PINS or name in files:
            stop("payload_name_invalid")
        if not size_text.isdigit() or int(size_text) > MAX_FILE or int(size_text) == 0:
            stop("payload_size_invalid")
        data = read_exact(stream, int(size_text))
        if hashlib.sha256(data).hexdigest() != PINS[name]:
            stop("payload_hash_mismatch:" + name)
        files[name] = data
    if stream.read(1) != b"":
        stop("payload_trailing_data")
    if set(files) != set(PINS):
        stop("payload_incomplete")
    return files


def object_record(path, kind, *, size=0, digest=None):
    info = os.lstat(path)
    return {"path": path, "kind": kind, "dev": info.st_dev, "ino": info.st_ino,
            "uid": info.st_uid, "mode": stat.S_IMODE(info.st_mode), "size": size,
            "digest": digest}


def write_new(path, data, records):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    info = os.fstat(fd)
    record = {"path": path, "kind": "file", "dev": info.st_dev, "ino": info.st_ino,
              "uid": info.st_uid, "mode": 0o600, "size": 0,
              "digest": hashlib.sha256(b"").hexdigest()}
    records.append(record)
    try:
        view = memoryview(data)
        written = 0
        while view:
            count = os.write(fd, view)
            if count <= 0:
                raise OSError("short write")
            written += count
            view = view[count:]
            record["size"] = written
            record["digest"] = hashlib.sha256(data[:written]).hexdigest()
        os.fchmod(fd, 0o600)
        os.fsync(fd)
    finally:
        os.close(fd)


def track_directory(path, records):
    info = os.lstat(path)
    records.append({"path": path, "kind": "dir", "dev": info.st_dev, "ino": info.st_ino,
                    "uid": info.st_uid, "mode": stat.S_IMODE(info.st_mode), "size": 0, "digest": None})


def read_fd_bounded(path, expected_name, uid):
    """Read and verify a regular file through one no-follow fd, bounded to MAX_FILE bytes."""
    before = os.lstat(path)
    if not stat.S_ISREG(before.st_mode) or before.st_uid != uid or stat.S_IMODE(before.st_mode) != 0o600 \
            or before.st_nlink != 1 or before.st_size < 0 or before.st_size > MAX_FILE:
        stop("readback_metadata:" + expected_name)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
    try:
        opened = os.fstat(fd)
        if (not stat.S_ISREG(opened.st_mode) or opened.st_dev != before.st_dev or opened.st_ino != before.st_ino
                or opened.st_uid != before.st_uid or opened.st_size != before.st_size
                or stat.S_IMODE(opened.st_mode) != 0o600 or opened.st_nlink != 1):
            stop("readback_identity:" + expected_name)
        chunks = []
        remaining = MAX_FILE + 1
        while remaining:
            chunk = os.read(fd, min(65536, remaining))
            if not chunk:
                break
            chunks.append(chunk)
            remaining -= len(chunk)
        after = os.fstat(fd)
        if (after.st_dev != opened.st_dev or after.st_ino != opened.st_ino or after.st_uid != opened.st_uid
                or after.st_size != opened.st_size or stat.S_IMODE(after.st_mode) != 0o600
                or after.st_nlink != 1):
            stop("readback_identity:" + expected_name)
        data = b"".join(chunks)
        if len(data) != opened.st_size or len(data) > MAX_FILE:
            stop("readback_size:" + expected_name)
        return data, after
    finally:
        os.close(fd)


def rename_directory_noreplace(source, destination):
    """Atomically publish a directory without replacing even an empty raced-in target."""
    libc = ctypes.CDLL(None, use_errno=True)
    renameat2 = getattr(libc, "renameat2", None)
    if renameat2 is None:
        stop("atomic_no_replace_unavailable")
    renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
    renameat2.restype = ctypes.c_int
    result = renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1)  # AT_FDCWD, RENAME_NOREPLACE
    if result != 0:
        error = ctypes.get_errno()
        if error == 17:  # EEXIST
            raise FileExistsError(error, os.strerror(error), destination)
        raise OSError(error, os.strerror(error), destination)


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def readback(uid):
    """Independently read back and verify each final object."""
    need_dir(SRC, owner=uid, exact_mode=0o700)
    if sorted(os.listdir(SRC)) != sorted(n for n in PINS if n != INSTALLER_NAME):
        stop("readback_contents")
    lines = []
    for path, name in [(SRC + "/" + n, n) for n in PINS if n != INSTALLER_NAME] + [(INST, INSTALLER_NAME)]:
        data, info = read_fd_bounded(path, name, uid)
        digest = hashlib.sha256(data).hexdigest()
        if digest != PINS[name]:
            stop("readback_hash:" + name)
        lines.append("FILE|" + path + "|" + digest + "|0600|uid=" + str(info.st_uid))
    return lines


def require_existing_dir(path, *, owner, allowed_parent_owners, missing_code, identity_code):
    """Read-only no-symlink check of a contract path and its parents; never creates it."""
    cursor = ""
    parts = path.strip("/").split("/")
    for index, part in enumerate(parts):
        cursor += "/" + part
        try:
            info = os.lstat(cursor)
        except FileNotFoundError:
            stop(missing_code)
        except OSError:
            stop(identity_code)
        if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
            stop(identity_code)
        mode = stat.S_IMODE(info.st_mode)
        if index == len(parts) - 1:
            if info.st_uid != owner or mode & 0o022:
                stop(identity_code)
        else:
            sticky_tmp = cursor == "/tmp" and info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
            if (not sticky_tmp and info.st_uid not in allowed_parent_owners) or (mode & 0o022 and not sticky_tmp):
                stop(identity_code)


def require_contract_roots(uid):
    # Source staging is isolated from deployment-state provisioning. Verify only
    # the existing app/profile identities; the root installer has a separate gate.
    require_existing_dir(PROFILE_DIR, owner=ROOT_UID, allowed_parent_owners={0, ROOT_UID, uid},
                         missing_code="profile_directory_missing",
                         identity_code="profile_directory_identity_invalid")
    require_existing_dir(APP_DIR, owner=uid, allowed_parent_owners={0, ROOT_UID, uid},
                         missing_code="application_directory_missing",
                         identity_code="application_directory_identity_invalid")


def terminated(signum, frame):
    stop("terminated_by_signal_" + str(signum))


def main():
    for name in ("SIGHUP", "SIGTERM", "SIGINT"):
        signal.signal(getattr(signal, name), terminated)
    uid = os.geteuid()
    if uid == 0:
        stop("must_not_run_as_root")
    try:
        if pwd.getpwuid(uid).pw_name != USER:
            stop("wrong_user")
    except KeyError:
        stop("wrong_user")
    need_dir("/", no_gow=False)
    need_dir("/home")
    need_dir(HOME, owner=uid)
    require_contract_roots(uid)
    priv = lst(PRIV)
    if priv is not None:
        need_dir(PRIV, owner=uid, exact_mode=0o700)
    if lst(SRC) is not None or lst(INST) is not None:
        stop("destination_exists")
    files = read_payload()
    print("PRECONDITIONS=PASS;PAYLOAD=VERIFIED;MODE=" + MODE)
    if MODE == "check":
        print("STAGING_1410_STAGE=CHECK_PASS")
        return

    records = []
    temp = None
    try:
        if priv is None:
            os.mkdir(PRIV, 0o700)
            track_directory(PRIV, records)
            need_dir(PRIV, owner=uid, exact_mode=0o700)
        temp = PRIV + "/.stage1410." + secrets.token_hex(8)
        os.mkdir(temp, 0o700)
        track_directory(temp, records)
        for name in PINS:
            if name != INSTALLER_NAME:
                write_new(temp + "/" + name, files[name], records)
        fsync_dir(temp)
        if lst(SRC) is not None or lst(INST) is not None:
            raise RuntimeError("destination_appeared")
        rename_directory_noreplace(temp, SRC)
        # The source directory is atomically published. Remove no path by name:
        # even a random same-UID staging path can be raced between lstat and unlink.
        source_record = next(record for record in records if record["path"] == temp)
        source_record["path"] = SRC
        for record in records:
            if record["path"].startswith(temp + "/"):
                record["path"] = SRC + record["path"][len(temp):]
        temp = None
        write_new(INST, files[INSTALLER_NAME], records)
        fsync_dir(PRIV)
        report = readback(uid)
    except BaseException as error:
        # Same-UID processes can race path-based unlink/rmdir even after inode checks.
        # Staged source bytes are inert, so retain uncertain objects for explicit review.
        print("ROLLBACK=HOLD;NO_AUTOMATIC_DELETE;TRACKED_OBJECTS=" + str(len(records)))
        if isinstance(error, SystemExit):
            raise
        stop("stage_failed:" + type(error).__name__)

    for line in report:
        print(line)
    print("STAGING_1410_STAGE=PASS")


main()
WPCG_STAGE_PY

PIN_LINES=""
for entry in "${PINS[@]}"; do
  PIN_LINES+="    \"${entry%% *}\": \"${entry##* }\","$'\n'
done
REMOTE_PY="${REMOTE_PY/@@MODE@@/$MODE}"
REMOTE_PY="${REMOTE_PY/@@PINS@@/${PIN_LINES%$'\n'}}"
B64="$(printf '%s' "$REMOTE_PY" | base64 | tr -d '\n')"

# 4. run it on the host as the SSH login user (the program refuses anything but `deploy`, and refuses root)
set +e
ssh -T -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=yes "$SSH_TARGET" \
  "python3 -I -c \"import base64;exec(base64.b64decode('${B64}'))\"" < "${WORK}/payload" > "${WORK}/out"
status=$?
set -e
# Show the host's report; its own result line is renamed so that exactly one STAGING_1410_STAGE= line exists:
# the driver's final verdict below.
sed 's/^STAGING_1410_STAGE=/REMOTE_RESULT=/' "${WORK}/out" || true

# In stage mode the host must have reported exactly one FILE line per pin, with our digest, mode 0600 and the
# expected path suffix; a bare "PASS" line is not trusted on its own.
verify_stage_lines() {
  local total=${#PINS[@]} entry name pin rel
  [ "$(grep -c '^FILE|' "${WORK}/out" || true)" -eq "$total" ] || return 1
  for entry in "${PINS[@]}"; do
    name="${entry%% *}"; pin="${entry##* }"
    case "$name" in
      install_*) rel="/.private/wpcaregrid-staging-next-helper-installer-1.4.10.py" ;;
      *) rel="/.private/wpcg-staging-next-helper-1.4.10/${name}" ;;
    esac
    awk -F'|' -v rel="$rel" -v pin="$pin" \
      '$1=="FILE" && length($2)>=length(rel) && substr($2, length($2)-length(rel)+1)==rel && $3==pin && $4=="0600" {f=1} END {exit f?0:1}' \
      "${WORK}/out" || return 1
  done
}

LAST="$(tail -n 1 "${WORK}/out" 2>/dev/null || true)"
case "$LAST" in
  "STAGING_1410_STAGE=PASS")
    [ "$status" -eq 0 ] || stop "remote_exit_${status}"
    [ "$MODE" = stage ] || stop "unexpected_pass_in_check_mode"
    verify_stage_lines || stop "remote_report_does_not_match_pins"
    echo "LOCAL_CROSSCHECK=PASS;REPORTED_FILES=${#PINS[@]}"
    echo "STAGING_1410_STAGE=PASS"
    exit 0 ;;
  "STAGING_1410_STAGE=CHECK_PASS")
    [ "$status" -eq 0 ] || stop "remote_exit_${status}"
    [ "$MODE" = check ] || stop "unexpected_check_pass_in_stage_mode"
    echo "STAGING_1410_STAGE=CHECK_PASS"
    exit 0 ;;
  STAGING_1410_STAGE=STOP\;CODE=*)
    CODE="${LAST#STAGING_1410_STAGE=STOP;CODE=}"
    case "$CODE" in ""|*[!A-Za-z0-9_:./-]*) CODE=remote_stop_unparseable ;; esac
    stop "$CODE" ;;
  *) stop "no_valid_result_ssh_exit_${status}" ;;
esac
