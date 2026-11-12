#!/usr/bin/env python3
"""Read-only effective-option check of the INSTALLED Production G1 MySQL instances (GO-4 acceptance and diagnosis).

Checks the contract options of both G1 instances against the effective MySQL option
files, exactly as the #327 installer does (same my_print_defaults arguments), and
names the instance, the option and the option-file lines involved.

Read-only: writes no file, starts/stops nothing, changes no G1/AppArmor/systemd/MySQL
state. Only the 13 fixed contract options (ports, paths, ON/OFF) are ever printed;
no other option and no value of any other option is shown.

Usage (root):  python3 -I -B g1-effective-config-check.py [/usr/local/libexec/production_g1_mysql_contract.py]

Result line (last line) and exit code:
  G1_EFFECTIVE_CONFIG=PASS                                   exit 0
  G1_EFFECTIVE_CONFIG=MISMATCH;INSTANCE=<n>;OPTION=<o>       exit 3
  G1_EFFECTIVE_CONFIG=STOP;CODE=<cause>                      exit 4
"""
import hashlib
import os
import re
import stat
import subprocess
import sys
import types

CONTRACT_PATH = "/usr/local/libexec/production_g1_mysql_contract.py"
CONTRACT_SHA256 = "0023be227381e617f482c1e905cd0019c1fba604a7aa7050345a95f208c89cf8"  # G1 socket-lock AppArmor hotfix
MY_PRINT_DEFAULTS = "/usr/bin/my_print_defaults"
MYSQLD = "/usr/sbin/mysqld"
BASE_CONFIG = "/etc/mysql/my.cnf"
GROUPS = ("mysqld", "server")
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"}


class Stop(Exception):
    pass


def norm(key):
    return key.strip().lstrip("-").replace("_", "-").lower()


def run(args):
    try:
        done = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True,
                              timeout=60, check=False, env=ENV, stdin=subprocess.DEVNULL)
    except (OSError, subprocess.TimeoutExpired):
        raise Stop("command_failed:" + os.path.basename(args[0]))
    if done.returncode != 0:
        raise Stop("command_failed:" + os.path.basename(args[0]))
    return done.stdout


def load_contract():
    path = sys.argv[1] if len(sys.argv) > 1 else CONTRACT_PATH
    if len(sys.argv) > 2 or os.path.islink(path):
        raise Stop("contract_path_invalid")
    st = os.lstat(path)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0:
        raise Stop("contract_file_invalid")
    with open(path, "rb") as stream:
        data = stream.read()
    if hashlib.sha256(data).hexdigest() != CONTRACT_SHA256:
        raise Stop("contract_file_hash_mismatch")
    module = types.ModuleType("g1c")
    sys.modules["g1c"] = module
    exec(compile(data, path, "exec"), module.__dict__)  # exactly the bytes that were hashed
    return module


def config_files(path, depth=0, seen=None):
    """Option files in mysqld read order (includedir: sorted by file name)."""
    seen = set() if seen is None else seen
    real = os.path.realpath(path)
    if depth > 5 or real in seen or not os.path.isfile(path):
        return []
    seen.add(real)
    found = [path]
    with open(path, encoding="utf-8", errors="replace") as stream:
        for line in stream:
            line = line.strip()
            if line.startswith("!includedir"):
                directory = line.split(None, 1)[1].strip()
                if os.path.isdir(directory):
                    for name in sorted(os.listdir(directory)):
                        if name.endswith(".cnf"):
                            found += config_files(os.path.join(directory, name), depth + 1, seen)
            elif line.startswith("!include "):
                found += config_files(line.split(None, 1)[1].strip(), depth + 1, seen)
    return found


def main():
    if os.geteuid() != 0:
        raise Stop("not_root")
    c = load_contract()
    ver = re.search(r"Ver (\d+\.\d+)", run([MYSQLD, "--version"]))
    if not ver:
        raise Stop("mysqld_version_unparsable")
    groups = [*GROUPS, "mysqld-" + ver.group(1)]
    mismatches = []
    for name, inst in c.INSTANCES.items():
        out = run([MY_PRINT_DEFAULTS, f"--defaults-file={BASE_CONFIG}",
                   f"--defaults-group-suffix=@{name}", *groups])
        opts = c.parse_defaults_output(out)
        print(f"== {name}")
        for key, want in inst.expected_options().items():
            vals = [v for k, v in opts if k == key]
            if not vals:
                print(f"   {key}: MISSING")
                mismatches.append((name, key))
            elif vals[-1] == want:
                print(f"   {key}: ok (occurrences={len(vals)})")
            else:
                present = [i for i, v in enumerate(vals) if v == want]
                print(f"   {key}: MISMATCH occurrences={len(vals)} "
                      f"contract_value_present_at={present or 'never'} effective_last_value={vals[-1]!r}")
                mismatches.append((name, key))
    print("== option files in read order (only lines setting a contract option or an mysqlx-* option)")
    contract_keys = {norm(k) for k in c.INSTANCES["wpcaregrid-production"].expected_options()}
    wanted = set()
    for group in groups:
        wanted.add(group)
        wanted.update(group + "@" + n for n in c.INSTANCES)
    for path in config_files(BASE_CONFIG):
        st = os.stat(path)
        print(f"   FILE {path} owner={st.st_uid}:{st.st_gid} mode={oct(st.st_mode & 0o7777)}")
        group = None
        with open(path, encoding="utf-8", errors="replace") as stream:
            for number, line in enumerate(stream, 1):
                text = line.strip()
                header = re.match(r"\[([^\]]+)\]", text)
                if header:
                    group = header.group(1)
                    continue
                if group in wanted and text and text[0] not in "#;!":
                    key = norm(re.split(r"[=\s]", text, 1)[0])
                    if key in contract_keys or key.startswith("mysqlx"):
                        print(f"        line {number} [{group}] {key}")
    if mismatches:
        name, key = mismatches[0]
        print(f"G1_EFFECTIVE_CONFIG=MISMATCH;INSTANCE={name};OPTION={key}")
        return 3
    print("G1_EFFECTIVE_CONFIG=PASS")
    return 0


if __name__ == "__main__":
    try:
        code = main()
    except Stop as exc:
        print(f"G1_EFFECTIVE_CONFIG=STOP;CODE={exc}")
        code = 4
    except BaseException as exc:
        print(f"G1_EFFECTIVE_CONFIG=STOP;CODE=internal_error:{exc.__class__.__name__}")
        code = 4
    raise SystemExit(code)
