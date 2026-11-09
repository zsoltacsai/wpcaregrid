#!/usr/bin/env bash
# WP CareGrid staging-next helper 1.4.9 - Owner GO #1: stage the six pinned files on the staging host.
#
# Runs on the OWNER'S WORKSTATION inside a clone of the repository. It exports the six files from the
# pinned merge commit, verifies their sha256 pins locally, and streams them over SSH to a small python
# program that runs on the host as the `deploy` user (never root). The host program changes only
# /home/deploy/.private (create-only: no overwrite, no deletion of existing objects, atomic publish,
# rollback of its own objects on failure). It installs nothing, touches no sudoers/helper/service/DB,
# opens no network connection, and prints no file content.
#
#   MODE=check   verify every precondition and the payload on the host, write NOTHING
#   MODE=stage   the above, then create the staged files (default)
#
# Final line (exit 0 only for PASS / CHECK_PASS):
#   STAGING_149_STAGE=PASS | CHECK_PASS | STOP;CODE=<reason>
set -euo pipefail

SSH_TARGET="${SSH_TARGET:-caregrid-staging}"
MODE="${MODE:-stage}"
COMMIT="49234694f062168a27b471fc43774409e6c4219a"
PINS=(
  "wpcaregrid-staging-next-deploy-priv-1_4_9.py 7b4fda009e1d098b7886866b6b0cafc4166dccf480c5cead595c89beabadd798"
  "staging_next_deploy_backend_1_4_9.py 68bc80ad8a87b06e19a2f9be1a52dc50f80db25183f66a39b1936f8434b1975f"
  "staging_next_db_transaction_1_4_9.py 809faa581019a9349eaae09ed18198f164781354ba5a89de55a858258dd7d290"
  "deployment_target_binding.py 987220381d5ea5d02aea1571e7b150f3f9596b27ff56d4b764a4a9e1bbdbb910"
  "deployment_environment_contract.py f22bc2ca1d570d932413f91e7c9dbddf7e2e613d56540418e5ca47dafe72caa1"
  "install_staging_next_helper_1_4_9.py d26120cb4f8066aef8a754315c1cd5a5252e2f69fcc338578b44694fe09558e5"
)

stop() { echo "STAGING_149_STAGE=STOP;CODE=$1"; exit 2; }
case "$MODE" in check|stage) ;; *) stop invalid_mode ;; esac
case "$SSH_TARGET" in ""|-*|*[!A-Za-z0-9._@:-]*) stop invalid_ssh_target ;; esac

sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || stop not_in_a_git_clone
cd "$ROOT"
git cat-file -e "${COMMIT}^{commit}" 2>/dev/null || stop pinned_commit_missing_run_git_fetch

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
SRC = PRIV + "/wpcg-staging-next-helper-1.4.9"
INST = PRIV + "/wpcaregrid-staging-next-helper-installer-1.4.9.py"
INSTALLER_NAME = "install_staging_next_helper_1_4_9.py"
PINS = {
@@PINS@@
}
MAX_FILE = 2_000_000


def stop(code):
    print("STAGING_149_STAGE=STOP;CODE=" + code)
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


def write_new(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        view = memoryview(data)
        while view:
            view = view[os.write(fd, view):]
        os.fchmod(fd, 0o600)
        os.fsync(fd)
    finally:
        os.close(fd)


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def readback(uid):
    """Independent read-back of the final objects (inside the rollback scope)."""
    need_dir(SRC, owner=uid, exact_mode=0o700)
    if sorted(os.listdir(SRC)) != sorted(n for n in PINS if n != INSTALLER_NAME):
        stop("readback_contents")
    lines = []
    for path, name in [(SRC + "/" + n, n) for n in PINS if n != INSTALLER_NAME] + [(INST, INSTALLER_NAME)]:
        info = lst(path)
        if info is None or not stat.S_ISREG(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o600 \
                or info.st_nlink != 1:
            stop("readback_metadata:" + name)
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            digest = hashlib.sha256(os.read(fd, MAX_FILE + 1)).hexdigest()
        finally:
            os.close(fd)
        if digest != PINS[name]:
            stop("readback_hash:" + name)
        lines.append("FILE|" + path + "|" + digest + "|0600|uid=" + str(info.st_uid))
    return lines


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
    priv = lst(PRIV)
    if priv is not None:
        need_dir(PRIV, owner=uid, exact_mode=0o700)
    if lst(SRC) is not None or lst(INST) is not None:
        stop("destination_exists")
    files = read_payload()
    print("PRECONDITIONS=PASS;PAYLOAD=VERIFIED;MODE=" + MODE)
    if MODE == "check":
        print("STAGING_149_STAGE=CHECK_PASS")
        return

    made = []
    temp = None
    try:
        if priv is None:
            os.mkdir(PRIV, 0o700)
            made.append(("empty", PRIV))
            need_dir(PRIV, owner=uid, exact_mode=0o700)
        temp = PRIV + "/.stage149." + secrets.token_hex(8)
        os.mkdir(temp, 0o700)
        os.mkdir(temp + "/src", 0o700)
        for name in PINS:
            if name != INSTALLER_NAME:
                write_new(temp + "/src/" + name, files[name])
        write_new(temp + "/installer", files[INSTALLER_NAME])
        fsync_dir(temp + "/src")
        fsync_dir(temp)
        if lst(SRC) is not None or lst(INST) is not None:
            raise RuntimeError("destination_appeared")
        os.rename(temp + "/src", SRC)
        made.append(("tree", SRC))
        os.link(temp + "/installer", INST, follow_symlinks=False)
        made.append(("file", INST))
        os.unlink(temp + "/installer")
        os.rmdir(temp)
        temp = None
        fsync_dir(PRIV)
        report = readback(uid)
    except BaseException as error:
        # roll back only what this run created (temporary tree first, then published objects)
        if temp is not None:
            for sub in ("/src", ""):
                try:
                    for name in os.listdir(temp + sub):
                        child = temp + sub + "/" + name
                        if os.path.isfile(child) and not os.path.islink(child):
                            os.unlink(child)
                    os.rmdir(temp + sub)
                except OSError:
                    pass
        for kind, path in reversed(made):
            try:
                if kind == "file":
                    os.unlink(path)
                elif kind == "tree":
                    for name in os.listdir(path):
                        os.unlink(path + "/" + name)
                    os.rmdir(path)
                else:
                    os.rmdir(path)
            except OSError:
                pass
        if isinstance(error, SystemExit):
            raise
        stop("stage_failed:" + type(error).__name__)

    for line in report:
        print(line)
    print("STAGING_149_STAGE=PASS")


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
ssh -T -o BatchMode=yes -o ConnectTimeout=15 "$SSH_TARGET" \
  "python3 -I -c \"import base64;exec(base64.b64decode('${B64}'))\"" < "${WORK}/payload" > "${WORK}/out"
status=$?
set -e
# Show the host's report; its own result line is renamed so that exactly one STAGING_149_STAGE= line exists:
# the driver's final verdict below.
sed 's/^STAGING_149_STAGE=/REMOTE_RESULT=/' "${WORK}/out" || true

# In stage mode the host must have reported exactly one FILE line per pin, with our digest, mode 0600 and the
# expected path suffix; a bare "PASS" line is not trusted on its own.
verify_stage_lines() {
  local total=${#PINS[@]} entry name pin rel
  [ "$(grep -c '^FILE|' "${WORK}/out" || true)" -eq "$total" ] || return 1
  for entry in "${PINS[@]}"; do
    name="${entry%% *}"; pin="${entry##* }"
    case "$name" in
      install_*) rel="/.private/wpcaregrid-staging-next-helper-installer-1.4.9.py" ;;
      *) rel="/.private/wpcg-staging-next-helper-1.4.9/${name}" ;;
    esac
    awk -F'|' -v rel="$rel" -v pin="$pin" \
      '$1=="FILE" && length($2)>=length(rel) && substr($2, length($2)-length(rel)+1)==rel && $3==pin && $4=="0600" {f=1} END {exit f?0:1}' \
      "${WORK}/out" || return 1
  done
}

LAST="$(tail -n 1 "${WORK}/out" 2>/dev/null || true)"
case "$LAST" in
  "STAGING_149_STAGE=PASS")
    [ "$status" -eq 0 ] || stop "remote_exit_${status}"
    [ "$MODE" = stage ] || stop "unexpected_pass_in_check_mode"
    verify_stage_lines || stop "remote_report_does_not_match_pins"
    echo "LOCAL_CROSSCHECK=PASS;REPORTED_FILES=${#PINS[@]}"
    echo "STAGING_149_STAGE=PASS"
    exit 0 ;;
  "STAGING_149_STAGE=CHECK_PASS")
    [ "$status" -eq 0 ] || stop "remote_exit_${status}"
    [ "$MODE" = check ] || stop "unexpected_check_pass_in_stage_mode"
    echo "STAGING_149_STAGE=CHECK_PASS"
    exit 0 ;;
  STAGING_149_STAGE=STOP\;CODE=*)
    CODE="${LAST#STAGING_149_STAGE=STOP;CODE=}"
    case "$CODE" in ""|*[!A-Za-z0-9_:./-]*) CODE=remote_stop_unparseable ;; esac
    stop "$CODE" ;;
  *) stop "no_valid_result_ssh_exit_${status}" ;;
esac
