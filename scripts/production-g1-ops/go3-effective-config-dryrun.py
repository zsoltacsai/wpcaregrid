#!/usr/bin/env python3
"""GO-3 dry-run: the effective MySQL options of both G1 instances as they WILL be after install.

Simulates the post-install option-file tree in a private temporary directory (mode 0700,
removed on exit): the host's include directories are mirrored with symlinks to the
host's own files (no file content is copied or printed), any existing #325 `90-` G1 file
is left out (it is removed by the rollback before install), and the two `zz-` instance
files of the #328 package are added. The real `my_print_defaults` then resolves the
options exactly like the installer does and the unchanged contract validator judges
them. Nothing is installed or changed on the host; only the 13 fixed contract options
(ports, paths, ON/OFF) are printed.

Usage (root):  python3 -I -B go3-effective-config-dryrun.py <package-dir>

Last line / exit code:
  G1_GO3_DRYRUN=PASS                                  0
  G1_GO3_DRYRUN=MISMATCH;INSTANCE=<n>;OPTION=<o>      3
  G1_GO3_DRYRUN=STOP;CODE=<cause>                     4
"""
import argparse
import hashlib
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import types

CONTRACT_SHA256 = "270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f"  # GO-5.2c AppArmor hotfix
ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C", "LC_ALL": "C"}
LEGACY_G1_FILES = ("90-wpcaregrid-production.cnf", "90-wpcaregrid-production-restore.cnf")
CONFIG_DIR = "/etc/mysql/mysql.conf.d"


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


def load_contract(package_dir):
    path = package_dir + "/production_g1_mysql_contract.py"
    if os.path.islink(package_dir) or not os.path.isdir(package_dir):
        raise Stop("package_directory_invalid")
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


def build_tree(root, tmp, artifacts):
    """Mirror /etc/mysql's include structure under tmp/etc/mysql; return the rewritten my.cnf path."""
    def under_tmp(absolute):
        return tmp + absolute

    source_cnf = root + "/etc/mysql/my.cnf"
    if not os.path.isfile(source_cnf):
        raise Stop("base_config_missing")
    lines, included_dirs = [], []
    with open(source_cnf, encoding="utf-8", errors="replace") as stream:
        for raw in stream:
            text = raw.strip()
            if text.startswith("!includedir"):
                directory = text.split(None, 1)[1].strip().rstrip("/") + "/"
                if not directory.startswith("/etc/mysql/"):
                    raise Stop("includedir_outside_etc_mysql")
                included_dirs.append(directory.rstrip("/"))
                lines.append(f"!includedir {under_tmp(directory)}\n")
            elif text.startswith("!include"):
                raise Stop("single_file_include_unsupported")
            else:
                lines.append(raw if raw.endswith("\n") else raw + "\n")
    if CONFIG_DIR not in included_dirs:
        raise Stop("instance_config_directory_not_included")
    for directory in included_dirs:
        target = under_tmp(directory)
        os.makedirs(target, mode=0o700, exist_ok=True)
        source = root + directory
        if os.path.isdir(source):
            for name in sorted(os.listdir(source)):
                if not name.endswith(".cnf"):
                    continue
                if directory == CONFIG_DIR and name in LEGACY_G1_FILES:
                    continue  # removed by the rollback before the install
                if directory == CONFIG_DIR and name.startswith("zz-wpcaregrid-production"):
                    raise Stop("g1_option_file_already_present")
                os.symlink(source + "/" + name, target + "/" + name)
    for path, content in artifacts.items():
        if path.startswith(CONFIG_DIR + "/"):
            destination = under_tmp(path)
            with open(destination, "xb") as stream:
                stream.write(content)
            os.chmod(destination, 0o644)
    cnf = under_tmp("/etc/mysql/my.cnf")
    os.makedirs(os.path.dirname(cnf), exist_ok=True)
    with open(cnf, "w", encoding="utf-8") as stream:
        stream.writelines(lines)
    os.chmod(cnf, 0o644)
    return cnf


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("package_dir")
    parser.add_argument("--root", default="")  # tests only: prefix of the mirrored host tree
    parser.add_argument("--my-print-defaults", default="/usr/bin/my_print_defaults")
    parser.add_argument("--mysqld", default="/usr/sbin/mysqld")
    args = parser.parse_args()
    if os.geteuid() != 0 and not args.root:
        raise Stop("not_root")
    c = load_contract(args.package_dir)
    ver = re.search(r"Ver (\d+\.\d+)", run([args.mysqld, "--version"]))
    if not ver:
        raise Stop("mysqld_version_unparsable")
    groups = ["mysqld", "server", "mysqld-" + ver.group(1)]
    tmp = tempfile.mkdtemp(prefix="g1-dryrun-")
    try:
        os.chmod(tmp, 0o700)
        cnf = build_tree(args.root, tmp, c.artifact_files())
        mismatches = []
        for name, inst in c.INSTANCES.items():
            out = run([args.my_print_defaults, f"--defaults-file={cnf}",
                       f"--defaults-group-suffix=@{name}", *groups])
            opts = c.parse_defaults_output(out)
            print(f"== {name}")
            for key, want in inst.expected_options().items():
                values = [v for k, v in opts if k == key]
                if not values:
                    print(f"   {key}: MISSING")
                    mismatches.append((name, key))
                elif values[-1] == want:
                    print(f"   {key}: ok (occurrences={len(values)})")
                else:
                    present = [i for i, v in enumerate(values) if v == want]
                    print(f"   {key}: MISMATCH occurrences={len(values)} "
                          f"contract_value_present_at={present or 'never'} effective_last_value={values[-1]!r}")
                    mismatches.append((name, key))
            try:
                c.validate_effective_options(inst, out)
                print(f"   validator: ok")
            except c.Refused as exc:
                print(f"   validator: REFUSED {exc}")
                if not any(n == name for n, _ in mismatches):
                    mismatches.append((name, f"validator:{exc}"))
        if mismatches:
            name, key = mismatches[0]
            print(f"G1_GO3_DRYRUN=MISMATCH;INSTANCE={name};OPTION={key}")
            return 3
        print("G1_GO3_DRYRUN=PASS")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    try:
        code = main()
    except Stop as exc:
        print(f"G1_GO3_DRYRUN=STOP;CODE={exc}")
        code = 4
    except BaseException as exc:
        print(f"G1_GO3_DRYRUN=STOP;CODE=internal_error:{exc.__class__.__name__}")
        code = 4
    raise SystemExit(code)
