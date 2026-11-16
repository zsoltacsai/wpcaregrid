#!/usr/bin/env python3
"""Source-level tests for the GO-13 PHP-FPM confinement artifacts (version 1.0.1).

WHAT THIS PROVES: the committed AppArmor profiles, systemd units and FPM config
are internally consistent, fail closed under mutation, and -- evaluated by a
small AppArmor path-rule model -- grant and deny the paths in
deploy/php-fpm-confinement/1.0.1/acceptance/matrix.json in both directions
between production and the existing staging pools, including symlink escapes.

WHAT THIS DOES NOT PROVE: kernel enforcement. The model is not the kernel. The
external abstractions (base, nameservice, ssl_certs), inherited-descriptor
handling at exec, /proc and ptrace mediation, signal and unix-socket mediation,
and systemd mount-namespace behavior can only be shown on a real host by the
live acceptance procedure in docs/PHP_FPM_CONFINEMENT_GO13.md. Nothing here loads
a profile, starts a service or touches the host.

If apparmor_parser is installed the real parser also compiles every profile
(no kernel load, no cache). Set PHP_FPM_CONFINEMENT_REQUIRE_PARSER=1 (CI does) to
make a missing parser a failure instead of a skip.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.dont_write_bytecode = True  # never leave bytecode inside the hashed artifact tree

ROOT = Path(__file__).resolve().parents[2]
ARTIFACTS = ROOT / "deploy" / "php-fpm-confinement" / "1.0.1"
DOC = ROOT / "docs" / "PHP_FPM_CONFINEMENT_GO13.md"
COMMON = "wpcaregrid-php-fpm-common"
EXTERNAL_INCLUDES = {"tunables/global", "abstractions/base", "abstractions/nameservice", "abstractions/ssl_certs"}

EXPECTED_FILES = {
    "SHA256SUMS",
    "acceptance/collect-peers.py",
    "acceptance/derive-probe-profile.py",
    "acceptance/fd-sender.py",
    "acceptance/fixtures.py",
    "acceptance/matrix.json",
    "acceptance/probe.py",
    "acceptance/verify-acceptance.py",
    "apparmor/abstractions/wpcaregrid-php-fpm-common",
    "apparmor/wpcaregrid-php-fpm-production",
    "apparmor/wpcaregrid-php-fpm-staging-legacy",
    "apparmor/wpcaregrid-php-fpm-staging-next",
    "php-fpm/production/php-fpm.conf",
    "php-fpm/production/pool.d/wpcaregrid-production.conf",
    "systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf",
    "systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf",
    "systemd/wpcaregrid-production-php8.4-fpm.service",
}
PROFILES = {
    "wpcaregrid-php-fpm-production": "apparmor/wpcaregrid-php-fpm-production",
    "wpcaregrid-php-fpm-staging-next": "apparmor/wpcaregrid-php-fpm-staging-next",
    "wpcaregrid-php-fpm-staging-legacy": "apparmor/wpcaregrid-php-fpm-staging-legacy",
}
# Exact per-profile grants beyond plain reads. Anything else is a finding.
EXEC_ALLOW = {
    "wpcaregrid-php-fpm-production": {"/usr/sbin/php-fpm8.4", "/usr/bin/kill"},
    "wpcaregrid-php-fpm-staging-next": {"/usr/sbin/php-fpm8.5", "/usr/bin/kill", "/usr/bin/dash",
                                       "/usr/lib/php/php-fpm-socket-helper"},
}
WRITE_ALLOW = {
    "wpcaregrid-php-fpm-production": {
        "/var/lib/wpcaregrid-deploy/production/shared/storage/{,**}",
        "/run/php/wpcaregrid-production-php8.4-fpm.sock", "/run/systemd/notify",
        "/var/log/wpcaregrid-production/php-fpm.log", "/tmp/{,**}"},
    "wpcaregrid-php-fpm-staging-next": {
        "/var/lib/wpcaregrid-deploy/staging-next/shared/storage/{,**}",
        "/run/php/php8.5-fpm.sock", "/run/php/php8.5-fpm.pid", "/run/systemd/notify",
        "/var/log/php8.5-fpm.log", "/tmp/{,**}"},
}
CAPABILITY_ALLOW = {
    "wpcaregrid-php-fpm-production": set(),
    "wpcaregrid-php-fpm-staging-next": {"setuid", "setgid", "chown", "kill"},
}
NETWORK_ALLOW = {"inet stream", "inet6 stream", "inet dgram", "inet6 dgram", "unix stream", "unix dgram",
                 "netlink raw"}
PATH_EXCEPTIONS = {"/tmp/{,**}", "/proc/[0-9]*/maps", "/run/php/", "/etc/localtime"}
FOREIGN_TOKENS = {
    "wpcaregrid-php-fpm-production": ("staging", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.5-fpm"),
    "wpcaregrid-php-fpm-staging-next": ("production", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.4-fpm.sock"),
}
SECRET_PATHS_NEVER_GRANTED = (
    "/etc/wpcaregrid-deploy/", "/home/", "/root/", "/var/lib/mysql", "/run/mysqld/", "/etc/shadow",
    "database-backups", "/incoming", "/inbox", "/state", "/receipts",
)


# --------------------------------------------------------------------------- model

class Rule:
    def __init__(self, kind: str, body: str, *, deny: bool, audit: bool, perms: frozenset[str] = frozenset(),
                 raw: str = "") -> None:
        self.kind, self.body, self.deny, self.audit, self.perms, self.raw = kind, body, deny, audit, perms, raw
        self.regex = glob_regex(body) if kind == "file" else None


def glob_regex(pattern: str) -> re.Pattern[str]:
    """AppArmor-style glob. `**` is modelled as one-or-more characters (strict for allows)."""
    out, i, depth = "", 0, 0
    while i < len(pattern):
        ch = pattern[i]
        if pattern.startswith("**", i):
            out += ".+"
            i += 2
            continue
        if ch == "*":
            out += "[^/]*"
        elif ch == "?":
            out += "[^/]"
        elif ch == "[":
            end = pattern.index("]", i)
            out += pattern[i:end + 1]
            i = end
        elif ch == "{":
            out += "(?:"
            depth += 1
        elif ch == "}":
            out += ")"
            depth -= 1
        elif ch == "," and depth:
            out += "|"
        else:
            out += re.escape(ch)
        i += 1
    if depth:
        raise ValueError(f"unbalanced alternation: {pattern}")
    return re.compile(out + r"\Z")


def parse_perms(text: str) -> frozenset[str]:
    perms, i = set(), 0
    while i < len(text):
        if text[i] in "pPcCuUiI" and text[i + 1:i + 2] == "x":
            perms.add("x")
            i += 2
        elif text[i] in "rwalkmx":
            perms.add(text[i])
            i += 1
        else:
            raise ValueError(f"unknown permission in {text!r}")
    return frozenset(perms)


RULE_LINE = re.compile(r"^(?P<audit>audit\s+)?(?P<deny>deny\s+)?(?P<body>.+?),$")


def parse_text(text: str, source: str, findings: list[str], root: Path, seen: set[str]) -> tuple[list[Rule], list[str]]:
    rules: list[Rule] = []
    includes: list[str] = []
    for number, line in enumerate(text.splitlines(), 1):
        stripped = line.strip()
        where = f"{source}:{number}"
        if not stripped or (stripped.startswith("#") and not stripped.startswith("#include")):
            continue
        if stripped.startswith("#include"):
            match = re.fullmatch(r"#include <([a-z0-9_/-]+)>", stripped)
            if not match:
                findings.append(f"{where}: malformed include")
                continue
            includes.append(match.group(1))
            continue
        if stripped.startswith("profile ") or stripped == "}":
            continue
        match = RULE_LINE.match(stripped)
        if not match:
            findings.append(f"{where}: unparseable rule {stripped!r}")
            continue
        deny, audit, body = bool(match["deny"]), bool(match["audit"]), match["body"].strip()
        try:
            if body.startswith("/"):
                path, _, perms = body.partition(" ")
                rules.append(Rule("file", path, deny=deny, audit=audit, perms=parse_perms(perms.strip()), raw=stripped))
            elif re.fullmatch(r"(capability|network|signal|ptrace|mount|umount|remount|pivot_root|dbus|unix)(\s.*)?", body):
                rules.append(Rule(body.split()[0], body, deny=deny, audit=audit, raw=stripped))
            else:
                findings.append(f"{where}: unknown rule {stripped!r}")
        except ValueError as error:
            findings.append(f"{where}: {error}")
    for include in includes:
        if include == f"abstractions/{COMMON}":
            if include in seen:
                continue
            seen.add(include)
            target = root / "apparmor" / "abstractions" / COMMON
            if not target.is_file():
                findings.append(f"{source}: missing {include}")
                continue
            sub_rules, sub_includes = parse_text(target.read_text(encoding="utf-8"), include, findings, root, seen)
            rules.extend(sub_rules)
            includes.extend(item for item in sub_includes if item not in includes)
        elif include not in EXTERNAL_INCLUDES:
            findings.append(f"{source}: include {include} is not reviewed")
    return rules, includes


def load_profile(root: Path, name: str, findings: list[str]) -> tuple[list[Rule], list[str], str]:
    path = root / PROFILES[name]
    text = path.read_text(encoding="utf-8")
    header = re.findall(r"^profile (\S+) flags=\(([^)]*)\) \{$", text, re.MULTILINE)
    if header != [(name, "attach_disconnected")] or not text.endswith("\n}\n"):
        findings.append(f"{name}: profile header/flags/closing not as reviewed")
    rules, includes = parse_text(text, name, findings, root, set())
    return rules, includes, text


def decide(rules: list[Rule], path: str, access: str) -> bool:
    """True when the access is allowed. A matching deny of any requested permission wins."""
    want = frozenset("x" if ch == "x" else ch for ch in access)
    granted: set[str] = set()
    for rule in rules:
        if rule.kind != "file" or not rule.regex.match(path):
            continue
        if rule.deny and rule.perms & want:
            return False
        if not rule.deny:
            granted |= rule.perms
    return want <= granted


def resolve_in_tree(tree: Path, virtual: str) -> str:
    """Resolve symlinks in a real, isolated directory tree; return the virtual resolved path."""
    real = os.path.realpath(str(tree) + virtual)
    prefix = os.path.realpath(str(tree))
    if not (real == prefix or real.startswith(prefix + os.sep)):
        return "ESCAPED-TREE:" + real
    resolved = real[len(prefix):] or "/"
    return resolved + ("/" if virtual.endswith("/") and not resolved.endswith("/") else "")


def build_tree(tree: Path, symlinks: dict[str, str], extra: dict[str, str] | None = None) -> None:
    links = dict(symlinks)
    links.update(extra or {})
    for source, target in links.items():
        source_path = Path(str(tree) + source)
        source_path.parent.mkdir(parents=True, exist_ok=True)
        if target.startswith("/"):
            target = str(tree) + target
            Path(target).parent.mkdir(parents=True, exist_ok=True)
        os.symlink(target, source_path)


# --------------------------------------------------------------------------- unit files

def parse_unit(path: Path) -> list[tuple[str, str, str]]:
    entries, section = [], ""
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith(("#", ";")):
            continue
        if stripped.startswith("["):
            section = stripped.strip("[]")
        elif "=" in stripped:
            key, _, value = stripped.partition("=")
            entries.append((section, key.strip(), value.strip()))
        else:
            entries.append((section, "?", stripped))
    return entries


def unit_values(entries, key: str) -> list[str]:
    return [value for _, name, value in entries if name == key]


PRODUCTION_UNIT_KEYS = {
    "Description", "After", "AssertSecurity", "Type", "User", "Group", "AppArmorProfile", "ExecStartPre",
    "ExecStart", "ExecReload", "KillSignal", "TimeoutStopSec", "Restart", "RestartSec", "StandardInput",
    "StandardOutput", "StandardError", "FileDescriptorStoreMax", "LimitNOFILE", "NoNewPrivileges",
    "CapabilityBoundingSet", "AmbientCapabilities", "PrivateTmp", "PrivateDevices", "ProtectSystem",
    "ProtectHome", "ProtectProc", "ProcSubset", "ProtectKernelTunables", "ProtectKernelModules",
    "ProtectKernelLogs", "ProtectControlGroups", "ProtectClock", "ProtectHostname", "RestrictNamespaces",
    "RestrictRealtime", "RestrictSUIDSGID", "LockPersonality", "SystemCallArchitectures",
    "RestrictAddressFamilies", "LogsDirectory", "LogsDirectoryMode", "ReadWritePaths", "InaccessiblePaths",
    "WantedBy",
}
DROPIN_KEYS = {"AppArmorProfile", "NoNewPrivileges", "PrivateTmp", "ProtectHome", "ProtectProc", "ProcSubset",
               "StandardInput", "FileDescriptorStoreMax", "InaccessiblePaths"}
REQUIRED_UNIT = {
    "AppArmorProfile": ["wpcaregrid-php-fpm-production"], "User": ["www-data"], "Group": ["www-data"],
    "AssertSecurity": ["apparmor"], "NoNewPrivileges": ["yes"], "PrivateTmp": ["yes"],
    "ProtectProc": ["invisible"], "ProcSubset": ["pid"], "ProtectSystem": ["strict"], "ProtectHome": ["yes"],
    "StandardInput": ["null"], "FileDescriptorStoreMax": ["0"], "CapabilityBoundingSet": [""],
    "AmbientCapabilities": [""], "PrivateDevices": ["yes"], "RestrictSUIDSGID": ["yes"],
    "RestrictNamespaces": ["yes"], "Type": ["notify"],
}
REQUIRED_DROPIN = {
    "php8.5-fpm.service.d": {"AppArmorProfile": ["wpcaregrid-php-fpm-staging-next"], "NoNewPrivileges": ["yes"],
                              "PrivateTmp": ["yes"], "ProtectProc": ["invisible"], "ProcSubset": ["pid"],
                              "StandardInput": ["null"], "FileDescriptorStoreMax": ["0"]},
    "php8.4-fpm.service.d": {"AppArmorProfile": ["wpcaregrid-php-fpm-staging-legacy"], "NoNewPrivileges": ["yes"],
                              "ProtectProc": ["invisible"], "StandardInput": ["null"],
                              "FileDescriptorStoreMax": ["0"]},
}
MASKS_PRODUCTION = {"/var/www/wpcaregrid-staging-new", "/var/www/wpcaregrid", "/var/lib/wpcaregrid-deploy/staging-next",
                    "/var/lib/wpcaregrid", "/etc/wpcaregrid-deploy"}
MASKS_STAGING_NEXT = {"/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production", "/var/www/wpcaregrid",
                      "/var/lib/wpcaregrid", "/etc/wpcaregrid-deploy"}
MASKS_LEGACY = {"/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production",
                "/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next",
                "/etc/wpcaregrid-deploy"}


def masks(entries) -> set[str]:
    result: set[str] = set()
    for value in unit_values(entries, "InaccessiblePaths"):
        result.update(item.lstrip("-") for item in value.split())
    return result


# --------------------------------------------------------------------------- validator

def validate(root: Path) -> list[str]:
    findings: list[str] = []
    actual = {str(p.relative_to(root)) for p in root.rglob("*") if p.is_file()}
    if actual != EXPECTED_FILES:
        findings.append(f"file set differs: extra={sorted(actual - EXPECTED_FILES)} missing={sorted(EXPECTED_FILES - actual)}")
        if EXPECTED_FILES - actual:
            return findings
    common_rules, _ = parse_text((root / "apparmor/abstractions" / COMMON).read_text(encoding="utf-8"),
                                 "common", findings, root, set())
    if any(r.kind == "file" and not r.deny for r in common_rules) or any(not r.deny for r in common_rules):
        findings.append("common abstraction must contain only deny rules")
    for needed in ("ptrace", "capability sys_ptrace", "mount", "dbus", "network inet raw", "network packet"):
        if not any(r.deny and r.body.startswith(needed) for r in common_rules):
            findings.append(f"common abstraction lacks deny {needed}")
    for needed in ("/etc/wpcaregrid-deploy/{,**}", "/home/{,**}", "/root/{,**}", "/proc/[0-9]*/environ",
                   "/proc/[0-9]*/cmdline", "/proc/[0-9]*/mem"):
        if not any(r.deny and r.body == needed for r in common_rules):
            findings.append(f"common abstraction lacks deny of {needed}")
    for name in PROFILES:
        rules, includes, text = load_profile(root, name, findings)
        if f"abstractions/{COMMON}" not in includes:
            findings.append(f"{name}: does not include the common deny abstraction")
        allows = [r for r in rules if not r.deny]
        if name == "wpcaregrid-php-fpm-staging-legacy":
            expected = {"capability", "network", "/** rwlkmix", "signal", "unix"}
            got = {r.raw.rstrip(",") for r in allows}
            if got != expected:
                findings.append(f"{name}: overlay allow set changed: {sorted(got ^ expected)}")
            if set(includes) - {"tunables/global", f"abstractions/{COMMON}"}:
                findings.append(f"{name}: overlay includes unexpected abstractions")
            continue
        if any(r.kind in {"ptrace", "mount", "umount", "remount", "pivot_root", "dbus", "unix"} for r in allows):
            findings.append(f"{name}: allows a forbidden rule class")
        for rule in allows:
            where = f"{name}: {rule.raw}"
            if rule.kind == "capability":
                if rule.body.split()[1:] != [] and rule.body.split()[1] not in CAPABILITY_ALLOW[name]:
                    findings.append(f"{where}: capability not reviewed")
                if len(rule.body.split()) == 1:
                    findings.append(f"{where}: bare capability allow")
            elif rule.kind == "network":
                if rule.body.removeprefix("network ").strip() not in NETWORK_ALLOW:
                    findings.append(f"{where}: network rule not reviewed")
            elif rule.kind == "signal":
                if not re.fullmatch(rf"signal \((send, receive|receive)\) peer=({name}|unconfined)", rule.body):
                    findings.append(f"{where}: signal rule not reviewed")
            elif rule.kind == "file":
                depth = 0
                for part in rule.body.strip("/").split("/"):
                    if re.search(r"[*?\[{]", part):
                        break
                    depth += 1
                if depth < 3 and rule.body not in PATH_EXCEPTIONS:
                    findings.append(f"{where}: path too broad")
                if rule.perms & {"l", "a"}:
                    findings.append(f"{where}: grants link/append")
                if "w" in rule.perms and rule.body not in WRITE_ALLOW[name]:
                    findings.append(f"{where}: unreviewed write grant")
                if "x" in rule.perms and rule.body not in EXEC_ALLOW[name]:
                    findings.append(f"{where}: unreviewed exec grant")
                if "m" in rule.perms and not (rule.body.startswith("/usr/lib/") or rule.body in EXEC_ALLOW[name]):
                    findings.append(f"{where}: unreviewed mmap grant")
                if any(token in rule.body for token in FOREIGN_TOKENS[name]):
                    findings.append(f"{where}: grants another environment's path")
                if any(token in rule.body for token in SECRET_PATHS_NEVER_GRANTED) and "storage" not in rule.body:
                    findings.append(f"{where}: grants a path class that must stay denied")
            else:
                findings.append(f"{where}: unreviewed allow rule")
        if not any(r.kind == "signal" and not r.deny for r in allows):
            findings.append(f"{name}: no explicit signal rules")
        if "tunables/global" not in includes:
            findings.append(f"{name}: tunables/global missing")
    matrix = json.loads((root / "acceptance/matrix.json").read_text(encoding="utf-8"))
    if matrix.get("schema") != 1 or set(matrix.get("profiles", {})) != set(PROFILES):
        findings.append("matrix profile set invalid")
        return findings
    for name, spec in matrix["profiles"].items():
        rules, _, _ = load_profile(root, name, findings)
        if spec["file"] != PROFILES[name]:
            findings.append(f"matrix {name}: wrong profile file")
        for check in spec["checks"]:
            allowed = decide(rules, resolve_virtual(spec["layout"]["symlinks"], check["path"]), check["access"])
            if allowed != (check["expect"] == "allow"):
                findings.append(f"matrix {name}/{check['id']}: expected {check['expect']}, model says "
                                f"{'allow' if allowed else 'deny'}")
        for sock in spec["sockets"]:
            allowed = decide(rules, sock["path"], "rw")
            if allowed != (sock["expect"] == "allow"):
                findings.append(f"matrix {name}/socket {sock['id']}: expected {sock['expect']}")
    findings.extend(validate_units(root))
    return findings


def resolve_virtual(symlinks: dict[str, str], path: str) -> str:
    for _ in range(20):
        for source, target in symlinks.items():
            if path == source or path.startswith(source + "/"):
                path = target + path[len(source):]
                break
        else:
            return path
    raise AssertionError("symlink loop")


def validate_units(root: Path) -> list[str]:
    findings: list[str] = []
    unit = parse_unit(root / "systemd/wpcaregrid-production-php8.4-fpm.service")
    for _, key, _ in unit:
        if key not in PRODUCTION_UNIT_KEYS:
            findings.append(f"production unit: unreviewed directive {key}")
    for key, expected in REQUIRED_UNIT.items():
        if unit_values(unit, key) != expected:
            findings.append(f"production unit: {key} is {unit_values(unit, key)}, expected {expected}")
    for key in ("ExecStartPre", "ExecStart", "ExecReload"):
        for value in unit_values(unit, key):
            if value[:1] in "+!-@:" or value.startswith("|"):
                findings.append(f"production unit: {key} prefix may bypass sandboxing or confinement")
    if not masks(unit) >= MASKS_PRODUCTION | {f"/var/lib/wpcaregrid-deploy/production/{d}" for d in
                                               ("incoming", "inbox", "state", "receipts", "database-backups")}:
        findings.append("production unit: InaccessiblePaths incomplete")
    if set(" ".join(unit_values(unit, "ReadWritePaths")).split()) != {
            "/run/php", "/var/lib/wpcaregrid-deploy/production/shared/storage"}:
        findings.append("production unit: ReadWritePaths changed")
    if unit_values(unit, "AppArmorProfile")[0].startswith("-"):
        findings.append("production unit: AppArmorProfile must not ignore failures")
    for directory, required in REQUIRED_DROPIN.items():
        entries = parse_unit(root / "systemd" / directory / "90-wpcaregrid-confinement.conf")
        for _, key, _ in entries:
            if key not in DROPIN_KEYS and key not in {"?"}:
                findings.append(f"{directory}: unreviewed directive {key}")
        if any(key == "?" for _, key, _ in entries):
            findings.append(f"{directory}: malformed line")
        for key, expected in required.items():
            if unit_values(entries, key) != expected:
                findings.append(f"{directory}: {key} is {unit_values(entries, key)}, expected {expected}")
        want = MASKS_STAGING_NEXT if "8.5" in directory else MASKS_LEGACY
        if not masks(entries) >= want:
            findings.append(f"{directory}: InaccessiblePaths incomplete")
    pool = (root / "php-fpm/production/pool.d/wpcaregrid-production.conf").read_text(encoding="utf-8")
    master = (root / "php-fpm/production/php-fpm.conf").read_text(encoding="utf-8")
    for needed in ("user = www-data", "group = www-data", "clear_env = yes", "listen.mode = 0660",
                   "listen = /run/php/wpcaregrid-production-php8.4-fpm.sock", "security.limit_extensions = .php"):
        if needed not in pool.splitlines():
            findings.append(f"pool: missing {needed}")
    if re.search(r"^\s*env\[", pool, re.MULTILINE) or re.search(r"^listen\s*=\s*(?!/)\S", pool, re.MULTILINE):
        findings.append("pool: passes environment values or listens on TCP")
    if any(path in pool for path in ("staging-next", "wpcaregrid-staging-new", "/var/www/wpcaregrid/")):
        findings.append("pool: references another environment")
    if "daemonize = no" not in master or "include = /etc/php/8.4/fpm-wpcaregrid-production/pool.d/*.conf" not in master:
        findings.append("master config changed")
    return findings


def load_script(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)  # type: ignore[union-attr]
    return module


def copy_artifacts(destination: Path) -> Path:
    target = destination / "1.0.1"
    shutil.copytree(ARTIFACTS, target)
    return target


# --------------------------------------------------------------------------- tests

class ArtifactIntegrity(unittest.TestCase):
    def test_exact_file_set_and_checksums(self) -> None:
        actual = {str(p.relative_to(ARTIFACTS)) for p in ARTIFACTS.rglob("*") if p.is_file()}
        self.assertEqual(actual, EXPECTED_FILES)
        lines = (ARTIFACTS / "SHA256SUMS").read_text(encoding="ascii").splitlines()
        listed = {}
        for line in lines:
            digest, _, name = line.partition("  ")
            self.assertRegex(digest, r"^[0-9a-f]{64}$")
            self.assertNotIn(name, listed)
            listed[name] = digest
        self.assertEqual(set(listed), EXPECTED_FILES - {"SHA256SUMS"})
        self.assertEqual(lines, sorted(lines, key=lambda l: l.partition("  ")[2]))
        for name, digest in listed.items():
            self.assertEqual(hashlib.sha256((ARTIFACTS / name).read_bytes()).hexdigest(), digest, name)

    def test_current_artifacts_have_no_findings(self) -> None:
        self.assertEqual(validate(ARTIFACTS), [])

    def test_no_executable_bits_or_install_scripts(self) -> None:
        for path in ARTIFACTS.rglob("*"):
            if path.is_file() and path.suffix not in {".py"}:
                self.assertFalse(os.access(path, os.X_OK), path)
        self.assertEqual([p.name for p in ARTIFACTS.rglob("*.sh")], [])


class BidirectionalAccess(unittest.TestCase):
    """The matrix is evaluated for all three profiles; validate() reports any disagreement."""

    def test_matrix_covers_both_directions(self) -> None:
        matrix = json.loads((ARTIFACTS / "acceptance/matrix.json").read_text())
        production = {c["id"]: c for c in matrix["profiles"]["wpcaregrid-php-fpm-production"]["checks"]}
        staging = {c["id"]: c for c in matrix["profiles"]["wpcaregrid-php-fpm-staging-next"]["checks"]}
        legacy = {c["id"]: c for c in matrix["profiles"]["wpcaregrid-php-fpm-staging-legacy"]["checks"]}
        # production cannot read staging secrets/config/storage ...
        for key in ("staging-env-read", "staging-storage-read", "staging-storage-write", "staging-code-read"):
            self.assertEqual(production[key]["expect"], "deny")
        # ... staging cannot read production's ...
        for key in ("production-env-read", "production-storage-read", "production-storage-write", "production-code-read"):
            self.assertEqual(staging[key]["expect"], "deny")
            self.assertEqual(legacy[key]["expect"], "deny")
        # ... and each can still use its own.
        for table in (production, staging):
            for key in ("own-code-read", "own-env-read", "own-storage-write", "own-env-via-release-link"):
                self.assertEqual(table[key]["expect"], "allow")

    def test_each_profile_denies_the_other_environment_secret_even_with_inverted_expectation(self) -> None:
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-production", [])[0]
        self.assertTrue(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/.env", "r"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "r"))
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-staging-next", [])[0]
        self.assertTrue(decide(rules, "/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "r"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/.env", "r"))

    def test_deny_beats_allow_and_unlisted_paths_default_deny(self) -> None:
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-production", [])[0]
        # `.env` is readable; it is never writable or executable.
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/.env", "w"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/storage/run.sh", "x"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/database-backups/a.sql.gz", "r"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/hidden/new-dir/file", "r"))
        self.assertFalse(decide(rules, "/opt/anything", "r"))

    def test_staging_legacy_overlay_keeps_sibling_access_only_outside_protected_trees(self) -> None:
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-staging-legacy", [])[0]
        self.assertTrue(decide(rules, "/var/www/unknown-sibling/index.php", "r"))
        self.assertFalse(decide(rules, "/var/www/wpcaregrid-production/releases/r/artisan", "r"))
        self.assertFalse(decide(rules, "/var/lib/wpcaregrid-deploy/production/shared/.env", "r"))
        self.assertFalse(decide(rules, "/etc/wpcaregrid-deploy/profiles/production-v2.1.2.json", "r"))


class SymlinkEscape(unittest.TestCase):
    """Real symlinks in an isolated temporary tree. AppArmor mediates the resolved path."""

    def _check(self, name: str) -> None:
        matrix = json.loads((ARTIFACTS / "acceptance/matrix.json").read_text())["profiles"][name]
        rules = load_profile(ARTIFACTS, name, [])[0]
        symlinks = matrix["layout"]["symlinks"]
        checks = {c["id"]: c["path"] for c in matrix["checks"]}
        if matrix["kind"] == "allowlist":
            via_link_dir = str(Path(checks["own-storage-via-release-link"]).parent)
            real_dir = resolve_virtual(symlinks, via_link_dir)
            entry_points = [via_link_dir, real_dir]
        else:
            real_dir = "/var/www/wpcaregrid/storage/framework/cache"
            entry_points = [real_dir]
        targets = list(matrix["symlink_escape_targets"])
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-symlink-") as temp:
            tree = Path(temp)
            extra = {f"{real_dir}/escape-{index}": target for index, target in enumerate(targets)}
            # A relative link that climbs exactly to the virtual root, then descends to the target.
            extra[f"{real_dir}/escape-relative"] = os.path.relpath(targets[0], real_dir)
            extra[f"{real_dir}/escape-chain"] = f"{real_dir}/escape-0"
            build_tree(tree, symlinks, extra)
            for suffix in [f"escape-{i}" for i in range(len(targets))] + ["escape-relative", "escape-chain"]:
                for directory in entry_points:
                    lexical = f"{directory}/{suffix}"
                    resolved = resolve_in_tree(tree, lexical)
                    self.assertFalse(resolved.startswith("ESCAPED-TREE:"), resolved)
                    self.assertFalse(decide(rules, resolved, "r"), f"{name}: {lexical} -> {resolved}")
                    if matrix["kind"] == "allowlist":
                        # The lexical path sits under an allowed prefix; only the resolved path may be judged.
                        self.assertTrue(decide(rules, lexical, "r"), lexical)

    def test_production(self) -> None:
        self._check("wpcaregrid-php-fpm-production")

    def test_staging_next(self) -> None:
        self._check("wpcaregrid-php-fpm-staging-next")

    def test_staging_legacy(self) -> None:
        self._check("wpcaregrid-php-fpm-staging-legacy")

    def test_no_hard_link_grant_anywhere(self) -> None:
        for name in PROFILES:
            rules = load_profile(ARTIFACTS, name, [])[0]
            self.assertTrue([r for r in rules if r.kind == "file" and not r.deny])
            if name != "wpcaregrid-php-fpm-staging-legacy":
                self.assertFalse([r.raw for r in rules if r.kind == "file" and not r.deny and r.perms & {"l", "a"}])
        legacy = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-staging-legacy", [])[0]
        # The overlay keeps existing behavior, including `l`, so it must deny `l` into protected trees.
        self.assertFalse(decide(legacy, "/var/lib/wpcaregrid-deploy/production/shared/storage/x", "l"))
        self.assertFalse(decide(legacy, "/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "l"))


class ProcPtraceSignalAndSockets(unittest.TestCase):
    def test_ptrace_and_proc_denials_are_in_every_profile(self) -> None:
        for name in PROFILES:
            rules = load_profile(ARTIFACTS, name, [])[0]
            self.assertTrue([r for r in rules if r.kind == "ptrace" and r.deny and r.body == "ptrace"], name)
            self.assertTrue([r for r in rules if r.kind == "capability" and r.deny and r.body.endswith("sys_ptrace")], name)
            for path in ("/proc/4242/environ", "/proc/4242/cmdline", "/proc/4242/mem", "/proc/4242/fd/3",
                         "/proc/4242/root", "/proc/4242/cwd", "/proc/4242/exe", "/proc/4242/ns/mnt"):
                self.assertFalse(decide(rules, path, "r"), f"{name} {path}")
            self.assertFalse(decide(rules, "/proc/sysrq-trigger", "w"))
            self.assertFalse(decide(rules, "/proc/sys/kernel/core_pattern", "w"))
            self.assertFalse([r for r in rules if r.kind == "ptrace" and not r.deny])

    def test_signals_cannot_cross_between_pools(self) -> None:
        text = {n: (ARTIFACTS / f).read_text() for n, f in PROFILES.items()}
        for own, other in (("wpcaregrid-php-fpm-production", ("staging-next", "staging-legacy")),
                           ("wpcaregrid-php-fpm-staging-next", ("production", "staging-legacy")),
                           ("wpcaregrid-php-fpm-staging-legacy", ("production", "staging-next"))):
            for suffix in other:
                self.assertIn(f"audit deny signal (send) peer=wpcaregrid-php-fpm-{suffix},", text[own])
        self.assertIn("signal (receive) peer=unconfined,", text["wpcaregrid-php-fpm-production"])
        self.assertNotIn("signal,", text["wpcaregrid-php-fpm-production"].replace("signal (", ""))

    def test_no_raw_or_packet_sockets_and_foreign_sockets_denied(self) -> None:
        for name in ("wpcaregrid-php-fpm-production", "wpcaregrid-php-fpm-staging-next"):
            rules = load_profile(ARTIFACTS, name, [])[0]
            self.assertEqual({r.body for r in rules if r.kind == "network" and not r.deny},
                             {f"network {item}" for item in NETWORK_ALLOW})
            self.assertTrue([r for r in rules if r.kind == "network" and r.deny and "raw" in r.body])
        production = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-production", [])[0]
        for sock in ("/run/php/php8.5-fpm.sock", "/run/php/php8.4-fpm.sock", "/run/php/php-fpm.sock",
                     "/run/mysqld/mysqld.sock"):
            self.assertFalse(decide(production, sock, "rw"), sock)
        self.assertTrue(decide(production, "/run/php/wpcaregrid-production-php8.4-fpm.sock", "rw"))
        staging = load_profile(ARTIFACTS, "wpcaregrid-php-fpm-staging-next", [])[0]
        self.assertFalse(decide(staging, "/run/php/wpcaregrid-production-php8.4-fpm.sock", "rw"))
        self.assertTrue(decide(staging, "/run/php/php8.5-fpm.sock", "rw"))

    def test_tcp_port_separation_is_not_claimed(self) -> None:
        doc = DOC.read_text(encoding="utf-8")
        self.assertIn("AppArmor 1.0.1 does not separate TCP ports", doc)


class InheritedDescriptors(unittest.TestCase):
    """Descriptors opened before a transition, or handed over by systemd, are not path-mediated again."""

    def test_units_hand_over_no_descriptors(self) -> None:
        for path in ARTIFACTS.glob("systemd/**/*"):
            if not path.is_file():
                continue
            entries = parse_unit(path)
            for forbidden in ("OpenFile", "Sockets", "ListenStream", "ListenSequentialPacket", "PassFileDescriptorsToExec",
                              "ImportCredential", "LoadCredential", "SetCredential", "BindPaths", "BindReadOnlyPaths",
                              "ConditionSecurity", "PermissionsStartOnly"):
                self.assertEqual(unit_values(entries, forbidden), [], f"{path.name}: {forbidden}")
            self.assertEqual(unit_values(entries, "FileDescriptorStoreMax"), ["0"], path.name)
            self.assertEqual(unit_values(entries, "StandardInput"), ["null"], path.name)
        production = parse_unit(ARTIFACTS / "systemd/wpcaregrid-production-php8.4-fpm.service")
        self.assertEqual(unit_values(production, "StandardOutput"), ["journal"])
        self.assertEqual(unit_values(production, "StandardError"), ["journal"])

    def test_pool_has_no_environment_or_descriptor_passthrough(self) -> None:
        pool = (ARTIFACTS / "php-fpm/production/pool.d/wpcaregrid-production.conf").read_text()
        self.assertIn("clear_env = yes", pool)
        self.assertNotRegex(pool, r"(?m)^\s*env\[")

    def test_documentation_states_the_assumption_and_the_live_check(self) -> None:
        doc = DOC.read_text(encoding="utf-8")
        for needed in ("Inherited descriptors", "never rely on exec-time revocation", "/proc/<pid>/fd",
                       "StandardInput=file:"):
            self.assertIn(needed, doc)


class FailClosedMutations(unittest.TestCase):
    """Each mutation must produce a finding; otherwise the validator would not protect the policy."""

    def mutate(self, relative: str, old: str, new: str) -> list[str]:
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-mutation-") as temp:
            root = copy_artifacts(Path(temp))
            path = root / relative
            text = path.read_text(encoding="utf-8")
            self.assertIn(old, text, f"mutation anchor missing: {old!r}")
            path.write_text(text.replace(old, new, 1), encoding="utf-8")
            return validate(root)

    def assertCaught(self, findings: list[str], needle: str = "") -> None:
        self.assertTrue(findings, "mutation was not detected")
        if needle:
            self.assertTrue(any(needle in item for item in findings), findings)

    def test_baseline_has_no_findings(self) -> None:
        self.assertEqual(self.mutate("apparmor/wpcaregrid-php-fpm-production", "network inet stream,", "network inet stream,"), [])

    def test_production_gains_staging_secret_read(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  /tmp/{,**} rwk,",
                                      "  /var/lib/wpcaregrid-deploy/staging-next/shared/.env r,\n  /tmp/{,**} rwk,"),
                          "another environment's path")

    def test_production_loses_explicit_staging_deny_and_gains_secret_read(self) -> None:
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-mutation-") as temp:
            root = copy_artifacts(Path(temp))
            path = root / "apparmor/wpcaregrid-php-fpm-production"
            lines = [line for line in path.read_text().splitlines()
                     if "/var/lib/wpcaregrid-deploy/staging-next/{,**}" not in line]
            lines.insert(lines.index("  /tmp/{,**} rwk,"), "  /var/lib/wpcaregrid-deploy/staging-next/shared/{,**} r,")
            path.write_text("\n".join(lines) + "\n")
            findings = validate(root)
            self.assertTrue(any("staging-env-read" in f for f in findings), findings)

    def test_production_gains_broad_www_tree(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production",
                                      "/var/www/wpcaregrid-production/{,**} r,", "/var/www/{,**} r,"), "path too broad")

    def test_staging_gains_production_storage(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-staging-next", "  /tmp/{,**} rwk,",
                                      "  /var/lib/wpcaregrid-deploy/production/shared/storage/{,**} rw,\n  /tmp/{,**} rwk,"),
                          "unreviewed write grant")

    def test_common_deny_ptrace_removed(self) -> None:
        self.assertCaught(self.mutate("apparmor/abstractions/wpcaregrid-php-fpm-common", "  audit deny ptrace,\n", ""), "ptrace")

    def test_include_of_common_removed(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production",
                                      "  #include <abstractions/wpcaregrid-php-fpm-common>\n", ""), "common deny")

    def test_unreviewed_include(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "#include <abstractions/ssl_certs>",
                                      "#include <abstractions/user-tmp>"), "not reviewed")

    def test_ptrace_allow_added(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  network inet stream,",
                                      "  ptrace (read),\n  network inet stream,"), "forbidden rule class")

    def test_bare_signal_and_raw_network_added(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  signal (receive) peer=unconfined,",
                                      "  signal,"), "signal rule not reviewed")
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  network netlink raw,",
                                      "  network inet raw,"), "network rule not reviewed")

    def test_exec_write_hardlink_grants_added(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  /usr/bin/kill ix,",
                                      "  /usr/bin/kill ix,\n  /usr/bin/dash ix,"), "unreviewed exec")
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production",
                                      "/var/lib/wpcaregrid-deploy/production/shared/.env r,",
                                      "/var/lib/wpcaregrid-deploy/production/shared/.env rw,"), "write")
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "storage/{,**} rwk,", "storage/{,**} rwkl,"),
                          "link")

    def test_capability_added(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  /usr/sbin/php-fpm8.4 mrix,",
                                      "  capability dac_override,\n  /usr/sbin/php-fpm8.4 mrix,"), "capability")

    def test_unparseable_and_unknown_rules_fail_closed(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  /tmp/{,**} rwk,", "  /tmp/{,**} rwk"),
                          "unparseable")
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-production", "  /tmp/{,**} rwk,", "  frobnicate /tmp,"),
                          "unknown rule")

    def test_overlay_cannot_be_widened_silently(self) -> None:
        self.assertCaught(self.mutate("apparmor/wpcaregrid-php-fpm-staging-legacy", "  signal,\n", "  signal,\n  ptrace,\n"),
                          "overlay")

    def test_unit_fails_open_mutations(self) -> None:
        unit = "systemd/wpcaregrid-production-php8.4-fpm.service"
        self.assertCaught(self.mutate(unit, "AppArmorProfile=wpcaregrid-php-fpm-production",
                                      "AppArmorProfile=-wpcaregrid-php-fpm-production"), "AppArmorProfile")
        self.assertCaught(self.mutate(unit, "AssertSecurity=apparmor", "ConditionSecurity=apparmor"), "unreviewed directive")
        self.assertCaught(self.mutate(unit, "ExecReload=/usr/bin/kill", "ExecReload=+/usr/bin/kill"), "prefix")
        self.assertCaught(self.mutate(unit, "User=www-data", "User=root"), "User")
        self.assertCaught(self.mutate(unit, "FileDescriptorStoreMax=0", "FileDescriptorStoreMax=16"), "FileDescriptorStoreMax")
        self.assertCaught(self.mutate(unit, "ProtectProc=invisible", "ProtectProc=default"), "ProtectProc")
        self.assertCaught(self.mutate(unit, "StandardInput=null", "StandardInput=file:/etc/shadow"), "StandardInput")
        self.assertCaught(self.mutate(unit, "ProtectSystem=strict", "ProtectSystem=full"), "ProtectSystem")
        self.assertCaught(self.mutate(unit, "CapabilityBoundingSet=\n", "CapabilityBoundingSet=CAP_SYS_PTRACE\n"),
                          "CapabilityBoundingSet")
        self.assertCaught(self.mutate(unit, "ReadWritePaths=/run/php ", "ReadWritePaths=/run/php /var/lib/wpcaregrid-deploy "),
                          "ReadWritePaths")
        self.assertCaught(self.mutate(unit, "[Install]", "OpenFile=/etc/shadow\n[Install]"), "unreviewed directive")
        self.assertCaught(self.mutate(unit, "-/var/www/wpcaregrid-staging-new ", ""), "InaccessiblePaths")

    def test_dropin_mutations(self) -> None:
        drop = "systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf"
        self.assertCaught(self.mutate(drop, "AppArmorProfile=wpcaregrid-php-fpm-staging-next", "AppArmorProfile="), "AppArmorProfile")
        self.assertCaught(self.mutate(drop, "[Service]\n", "[Service]\nUser=root\n"), "unreviewed directive")
        self.assertCaught(self.mutate(drop, "[Service]\n", "[Service]\nExecStartPre=/bin/true\n"), "unreviewed directive")
        self.assertCaught(self.mutate(drop, "-/var/www/wpcaregrid-production ", ""), "InaccessiblePaths")
        legacy = "systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf"
        self.assertCaught(self.mutate(legacy, "NoNewPrivileges=yes", "NoNewPrivileges=no"), "NoNewPrivileges")

    def test_pool_mutations(self) -> None:
        pool = "php-fpm/production/pool.d/wpcaregrid-production.conf"
        self.assertCaught(self.mutate(pool, "clear_env = yes", "clear_env = no"), "clear_env")
        self.assertCaught(self.mutate(pool, "user = www-data", "user = wpcg-production"), "user")
        self.assertCaught(self.mutate(pool, "listen = /run/php/wpcaregrid-production-php8.4-fpm.sock",
                                      "listen = 127.0.0.1:9000"), "listen")
        self.assertCaught(self.mutate(pool, "pm = dynamic", "pm = dynamic\nenv[DB_PASSWORD] = x"), "environment")

    def test_matrix_mutation_is_detected(self) -> None:
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-mutation-") as temp:
            root = copy_artifacts(Path(temp))
            path = root / "acceptance/matrix.json"
            matrix = json.loads(path.read_text())
            matrix["profiles"]["wpcaregrid-php-fpm-production"]["checks"][0]["expect"] = "deny"
            path.write_text(json.dumps(matrix))
            self.assertCaught(validate(root), "matrix")

    def test_extra_file_is_detected(self) -> None:
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-mutation-") as temp:
            root = copy_artifacts(Path(temp))
            (root / "apparmor/extra").write_text("x")
            self.assertCaught(validate(root), "file set")


ACCEPTANCE = ARTIFACTS / "acceptance"
MATRIX_TEXT = (ACCEPTANCE / "matrix.json").read_text(encoding="utf-8")
EACCES, EPERM = 13, 1


def rules_from_text(text: str, root: Path = ARTIFACTS) -> list[Rule]:
    return parse_text(text, "derived", [], root, set())[0]


class AcceptanceTooling(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.derive = load_script(ACCEPTANCE / "derive-probe-profile.py", "wpcg_derive_probe")
        cls.probe = load_script(ACCEPTANCE / "probe.py", "wpcg_probe")

    def test_probe_profile_only_adds_exec_and_library_reads(self) -> None:
        for name, relative in PROFILES.items():
            original = (ARTIFACTS / relative).read_text()
            derived = self.derive.derive(original)
            before, after = original.splitlines(), derived.splitlines()
            normalized = [line.replace(f"{name}-probe", name) for line in after]
            added = [line for line in normalized if line not in before]
            self.assertEqual([line.strip() for line in added if line.strip() and not line.strip().startswith("#")],
                             ["/usr/bin/python3* mrix,", "/usr/lib/python3*/{,**} mr,",
                              "/proc/[0-9]*/attr/current r,"])
            self.assertEqual([line for line in before if line not in normalized], [])
            self.assertIn(f"profile {name}-probe flags=(attach_disconnected) {{", derived)

    def test_derive_refuses_probe_and_unknown_structure(self) -> None:
        derived = self.derive.derive((ARTIFACTS / PROFILES["wpcaregrid-php-fpm-production"]).read_text())
        for bad in (derived, "profile other {\n}\n", ""):
            with self.assertRaises(ValueError):
                self.derive.derive(bad)

    def test_probe_scope_guard_original_vs_derived_profile(self) -> None:
        """A check the probe profile answers differently from the real profile must not run in the probe.

        The probe profile grants python exec, so `exec-python` would pass for the wrong reason; the real
        profile's denial is tested by running the unit's original profile directly (see the runbook).
        """
        matrix = json.loads(MATRIX_TEXT)
        for name, relative in PROFILES.items():
            original = rules_from_text((ARTIFACTS / relative).read_text())
            derived = rules_from_text(self.derive.derive((ARTIFACTS / relative).read_text()))
            differing = []
            for check in matrix["profiles"][name]["checks"]:
                path = resolve_virtual(matrix["profiles"][name]["layout"]["symlinks"], check["path"])
                if decide(original, path, check["access"]) != decide(derived, path, check["access"]):
                    differing.append(check["id"])
                    self.assertEqual(check.get("probe_scope"), "original_profile", f"{name}/{check['id']}")
            for check in matrix["profiles"][name]["checks"]:
                if check.get("probe_scope") == "original_profile":
                    self.assertIn(check["id"], differing, "scope exemption without a real difference")
        self.assertEqual([c["id"] for c in matrix["profiles"]["wpcaregrid-php-fpm-production"]["checks"]
                          if c.get("probe_scope")], ["exec-python"])

    def test_probe_refuses_when_not_in_the_expected_label(self) -> None:
        for mode in ("confined",):
            result = subprocess.run(
                [sys.executable, "-I", "-B", str(ACCEPTANCE / "probe.py"), mode, MATRIX_TEXT,
                 "wpcaregrid-php-fpm-production", json.dumps({"peers": {}, "scm_socket": "/nonexistent", "abstract": "x"})],
                capture_output=True, text=True, timeout=30, check=False)
            self.assertEqual(result.returncode, 2, result.stderr)
            self.assertEqual(result.stdout, "")
            self.assertIn("REFUSED", result.stderr)
        unknown = subprocess.run([sys.executable, "-I", "-B", str(ACCEPTANCE / "probe.py"), "confined", MATRIX_TEXT, "nope", "{}"],
                                 capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(unknown.returncode, 2)

    def test_baseline_mode_refuses_unless_truly_unconfined(self) -> None:
        driver = ("import importlib.util,sys,json\n"
                  f"s=importlib.util.spec_from_file_location('p',{str(ACCEPTANCE / 'probe.py')!r})\n"
                  "m=importlib.util.module_from_spec(s); s.loader.exec_module(m)\n"
                  "m.label=lambda: 'wpcaregrid-php-fpm-production (enforce)'\n"
                  f"sys.exit(m.main(['baseline',{MATRIX_TEXT!r},'wpcaregrid-php-fpm-production','{{}}']))\n")
        result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(result.returncode, 2)
        self.assertEqual(result.stdout, "")

    def test_probe_output_ids_match_the_verifier_contract(self) -> None:
        """Run the real probe in baseline mode against this machine (open/exec/connect only, no secrets) and
        require it to emit exactly the ids the verifier expects."""
        verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_ids")
        sender = subprocess.Popen([sys.executable, "-B", str(ACCEPTANCE / "fd-sender.py"), "contracttest",
                                   str(self._canary())], stdout=subprocess.PIPE, text=True)
        try:
            self.assertTrue(sender.stdout.readline().startswith("READY"))
            env = {"peers": {}, "scm_socket": "/tmp/wpcg-probe-contracttest/scm.sock", "abstract": "wpcg-probe-contracttest"}
            for profile in PROFILES:
                driver = ("import importlib.util,sys\n"
                          f"s=importlib.util.spec_from_file_location('p',{str(ACCEPTANCE / 'probe.py')!r})\n"
                          "m=importlib.util.module_from_spec(s); s.loader.exec_module(m)\n"
                          "m.label=lambda: 'unconfined'\n"
                          "m.exec_path=lambda p: 2\n"  # never execute real binaries from a unit test
                          "m.open_path=lambda p,a: 2\n"  # never touch real paths from a unit test
                          "m.connect=lambda a: 2\n"
                          f"sys.exit(m.main(['baseline',{MATRIX_TEXT!r},{profile!r},{json.dumps(env)!r}]))\n")
                result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], capture_output=True, text=True,
                                        timeout=60, check=False)
                self.assertEqual(result.returncode, 0, result.stderr)
                document = json.loads(result.stdout)
                expected, _ = verifier.expected_checks(json.loads(MATRIX_TEXT), profile, {})
                self.assertEqual({r["id"] for r in document["results"]}, set(expected), profile)
                self.assertEqual(document["matrix_sha256"], hashlib.sha256(MATRIX_TEXT.encode()).hexdigest())
        finally:
            sender.kill()
            sender.wait()
            shutil.rmtree("/tmp/wpcg-probe-contracttest", ignore_errors=True)

    def _canary(self) -> Path:
        path = Path(tempfile.mkdtemp(prefix="wpcg-canary-")) / "canary"
        path.write_text(json.loads(MATRIX_TEXT)["canary"])
        self.addCleanup(shutil.rmtree, path.parent, True)
        return path

    @unittest.skipIf(os.geteuid() == 0, "fd-sender refuses to run as root")
    def test_inherited_fd0_is_actually_read(self) -> None:
        canary = self._canary()
        driver = ("import importlib.util,sys,json\n"
                  f"s=importlib.util.spec_from_file_location('p',{str(ACCEPTANCE / 'probe.py')!r})\n"
                  "m=importlib.util.module_from_spec(s); s.loader.exec_module(m)\n"
                  "print(json.dumps(m.fd0()))\n")
        with open(canary, "rb") as handle:
            result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], stdin=handle, capture_output=True,
                                    text=True, timeout=30, check=False)
        self.assertEqual(json.loads(result.stdout), {"errno": 0, "marker_ok": True, "state": "read"})
        other = Path(tempfile.mkdtemp(prefix="wpcg-notcanary-")) / "f"
        other.write_text("x" * 40)
        self.addCleanup(shutil.rmtree, other.parent, True)
        with open(other, "rb") as handle:
            result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], stdin=handle, capture_output=True,
                                    text=True, timeout=30, check=False)
        self.assertEqual(json.loads(result.stdout)["state"], "not-canary-file")  # never reads other content

    @unittest.skipIf(os.geteuid() == 0, "fd-sender refuses to run as root")
    def test_scm_rights_transfer_and_abstract_socket_are_exercised(self) -> None:
        canary = self._canary()
        sender = subprocess.Popen([sys.executable, "-B", str(ACCEPTANCE / "fd-sender.py"), "scmtest", str(canary)],
                                  stdout=subprocess.PIPE, text=True)
        try:
            self.assertTrue(sender.stdout.readline().startswith("READY"))
            self.assertEqual(self.probe.connect("\0wpcg-probe-scmtest"), 0)  # baseline: abstract socket reachable
            outcome = self.probe.scm("/tmp/wpcg-probe-scmtest/scm.sock")
            self.assertEqual(outcome, {"state": "received", "errno": 0, "read_ok": True})
            self.assertEqual(sender.wait(timeout=30), 0)
        finally:
            sender.kill()
            shutil.rmtree("/tmp/wpcg-probe-scmtest", ignore_errors=True)
        self.assertEqual(self.probe.scm("/tmp/wpcg-probe-scmtest/scm.sock")["state"], "channel-failed")

    def test_sender_refuses_anything_but_the_canary_and_root(self) -> None:
        other = Path(tempfile.mkdtemp(prefix="wpcg-notcanary-")) / "f"
        other.write_text("x" * 23)
        self.addCleanup(shutil.rmtree, other.parent, True)
        result = subprocess.run([sys.executable, "-B", str(ACCEPTANCE / "fd-sender.py"), "refuse", str(other)],
                                capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(result.returncode, 2)
        self.assertNotIn("READY", result.stdout)

    def test_probe_calibration_child_is_a_descendant_and_is_cleaned_up(self) -> None:
        results: list = []
        self.probe.descendant_calibration(results)
        self.assertEqual({r["id"] for r in results}, {
            "control:self-maps", "calibration:child-signal", "calibration:child-maps", "calibration:child-environ",
            "calibration:child-process_vm_readv", "calibration:child-ptrace-seize"})
        children = {r["child"] for r in results[-6:]}
        self.assertEqual(len(children), 1)
        with self.assertRaises(ProcessLookupError):
            os.kill(children.pop(), 0)  # reaped: no stray process left behind


class FixturesTool(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.tool = load_script(ACCEPTANCE / "fixtures.py", "wpcg_fixtures")
        cls.matrix = json.loads(MATRIX_TEXT)

    def tree(self) -> Path:
        root = Path(tempfile.mkdtemp(prefix="wpcg-fixture-root-"))
        self.addCleanup(shutil.rmtree, root, True)
        for parent in ("var/www", "var/lib", "var/log", "etc", "home", "run"):
            (root / parent).mkdir(parents=True)
        return root

    def test_every_live_target_outside_real_staging_is_a_fixture_or_in_a_fixture_dir(self) -> None:
        fixtures = self.matrix["fixtures"]
        fixture_files = {e["path"] for e in fixtures["files"]}
        fixture_dirs = {e["path"] for e in fixtures["mkdirs"]}
        real = ("/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next", "/var/www/wpcaregrid/",
                "/var/lib/wpcaregrid/", "/usr/", "/run/php/")
        symlinks = {e["path"] for e in fixtures["symlinks"]}
        merged: dict = {}
        for spec in self.matrix["profiles"].values():
            merged.update(spec["layout"]["symlinks"])
        for name, spec in self.matrix["profiles"].items():
            for check in spec["checks"]:
                if not check.get("live", True) or check.get("probe_scope") or check["path"].startswith(real):
                    continue
                path = check["path"]
                resolved = resolve_virtual(merged, path)
                parent = str(Path(resolved).parent)
                self.assertTrue(path.rstrip("/") in fixture_dirs or resolved in fixture_files or parent in fixture_dirs
                                or path in symlinks or resolved.rstrip("/") in fixture_dirs,
                                f"{name}/{check['id']}: {path} has no fixture")
            for target in spec["live_symlink_escape_targets"]:
                if not target.startswith(real):
                    self.assertIn(resolve_virtual(merged, target), fixture_files, target)

    def test_create_remove_roundtrip_in_an_isolated_root(self) -> None:
        root, receipt = self.tree(), self.tree() / "receipt.json"
        made = self.tool.create(self.matrix["fixtures"], str(root), str(receipt), chown=False)
        env = root / "var/lib/wpcaregrid-deploy/production/shared/.env"
        self.assertEqual(env.read_text(), self.matrix["canary"])
        self.assertEqual(oct(env.stat().st_mode & 0o7777), "0o640")
        self.assertTrue((root / "var/www/wpcaregrid-production/current").is_symlink())
        again = self.tool.create(self.matrix["fixtures"], str(root), str(receipt), chown=False)  # idempotent re-run
        self.assertEqual(again, made)  # the receipt still names everything the first run created
        self.assertTrue(made["files"])
        self.assertTrue(self.tool.status(self.matrix["fixtures"], str(root)))
        gone = self.tool.remove(self.matrix["fixtures"], str(root), str(receipt))
        self.assertEqual(self.tool.status(self.matrix["fixtures"], str(root)), [])
        self.assertTrue(gone["removed"])
        self.assertFalse((root / "var/lib/wpcaregrid-deploy/production/shared/.env").exists())

    def test_refuses_real_secrets_before_changing_anything(self) -> None:
        root, receipt = self.tree(), self.tree() / "receipt.json"
        shared = root / "var/lib/wpcaregrid-deploy/production/shared"
        shared.mkdir(parents=True)
        (shared / ".env").write_text("APP_KEY=base64:not-a-real-key\n")
        with self.assertRaises(self.tool.Refused):
            self.tool.create(self.matrix["fixtures"], str(root), str(receipt), chown=False)
        self.assertFalse((root / "home/wpcg-fixture").exists())
        self.assertFalse(receipt.exists())
        self.assertEqual((shared / ".env").read_text(), "APP_KEY=base64:not-a-real-key\n")

    def test_remove_never_deletes_changed_files(self) -> None:
        root, receipt = self.tree(), self.tree() / "receipt.json"
        self.tool.create(self.matrix["fixtures"], str(root), str(receipt), chown=False)
        env = root / "var/lib/wpcaregrid-deploy/production/shared/.env"
        env.write_text("APP_KEY=base64:now-a-real-secret\n")
        gone = self.tool.remove(self.matrix["fixtures"], str(root), str(receipt))
        self.assertIn("/var/lib/wpcaregrid-deploy/production/shared/.env", gone["left"])
        self.assertTrue(env.exists())

    def test_cli_requires_root_or_explicit_test_mode(self) -> None:
        result = subprocess.run([sys.executable, "-B", str(ACCEPTANCE / "fixtures.py"), "status",
                                 str(ACCEPTANCE / "matrix.json")], capture_output=True, text=True, timeout=30, check=False,
                                env={"PATH": os.environ.get("PATH", "")})
        self.assertEqual(result.returncode, 2 if os.geteuid() != 0 else result.returncode)


def fake_proc(base: Path, processes: list[dict], units: dict[str, list[int]]) -> tuple[str, str]:
    proc, cgroup = base / "proc", base / "cgroup"
    for p in processes:
        d = proc / str(p["pid"])
        (d / "attr").mkdir(parents=True)
        (d / "fd").mkdir()
        (d / "status").write_text(f"Name:\t{p['comm']}\nUid:\t{p['uid']}\t{p['uid']}\t{p['uid']}\t{p['uid']}\n")
        (d / "comm").write_text(p["comm"] + "\n")
        (d / "stat").write_text(f"{p['pid']} ({p['comm']}) S " + " ".join(["0"] * 18) + f" {p['start']} 0\n")
        (d / "attr" / "current").write_text(p["label"] + "\n")
        (d / "cgroup").write_text(f"0::/system.slice/{p['unit']}\n" if p.get("unit") else "0::/user.slice\n")
        os.symlink("/usr/sbin/php-fpm8.4", d / "exe")
        for index, target in enumerate(p.get("fds", [])):
            os.symlink(target, d / "fd" / str(index))
    for unit, pids in units.items():
        d = cgroup / "system.slice" / unit
        d.mkdir(parents=True)
        (d / "cgroup.procs").write_text("".join(f"{x}\n" for x in pids))
    cgroup.mkdir(exist_ok=True)
    return str(proc), str(cgroup)


class CollectPeers(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.tool = load_script(ACCEPTANCE / "collect-peers.py", "wpcg_collect")
        cls.units = json.loads(MATRIX_TEXT)["role_units"]

    def run_collect(self, processes, units):
        base = Path(tempfile.mkdtemp(prefix="wpcg-fakeproc-"))
        self.addCleanup(shutil.rmtree, base, True)
        proc, cgroup = fake_proc(base, processes, units)
        return base, self.tool.collect(self.units, proc, cgroup)

    def test_inventory_lists_only_uid_33_with_label_unit_and_start_time(self) -> None:
        _, inv = self.run_collect([
            {"pid": 10, "uid": 33, "comm": "php-fpm8.4", "label": "wpcaregrid-php-fpm-production (enforce)", "start": 555,
             "unit": "wpcaregrid-production-php8.4-fpm.service", "fds": ["socket:[1]"]},
            {"pid": 11, "uid": 0, "comm": "root-thing", "label": "unconfined", "start": 1, "unit": "x.service"},
            {"pid": 12, "uid": 33, "comm": "nginx", "label": "unconfined", "start": 7, "unit": "nginx.service"}],
            {"wpcaregrid-production-php8.4-fpm.service": [10]})
        self.assertTrue(inv["complete"], inv["reasons"])
        self.assertEqual([p["pid"] for p in inv["processes"]], [10, 12])
        self.assertEqual(inv["processes"][0]["starttime"], 555)
        self.assertEqual(inv["processes"][0]["unit"], "wpcaregrid-production-php8.4-fpm.service")
        self.assertEqual(inv["processes"][1]["label"], "unconfined")
        self.assertFalse(inv["units"]["php8.5-fpm.service"]["present"])

    @unittest.skipIf(os.geteuid() == 0, "root can read mode-000 files")
    def test_unreadable_process_data_makes_the_inventory_incomplete(self) -> None:
        base, _ = self.run_collect([{"pid": 20, "uid": 33, "comm": "php-fpm", "label": "unconfined", "start": 1}], {})
        (base / "proc/20/attr/current").chmod(0)
        proc, cgroup = str(base / "proc"), str(base / "cgroup")
        self.assertFalse(self.tool.collect(self.units, proc, cgroup)["complete"])
        (base / "proc/20/attr/current").chmod(0o644)
        (base / "proc/20/status").chmod(0)
        inv = self.tool.collect(self.units, proc, cgroup)
        self.assertFalse(inv["complete"])
        self.assertTrue(any("unreadable status" in r for r in inv["reasons"]))

    def test_vanished_process_is_benign_but_missing_unit_member_is_not(self) -> None:
        base, _ = self.run_collect([{"pid": 30, "uid": 33, "comm": "php-fpm", "label": "x", "start": 1, "unit": "u.service"}], {})
        shutil.rmtree(base / "proc/30/fd")
        inv = self.tool.collect(self.units, str(base / "proc"), str(base / "cgroup"))
        self.assertFalse(inv["complete"])  # fd list unreadable for a live process
        shutil.rmtree(base / "proc/30")
        self.assertTrue(self.tool.collect(self.units, str(base / "proc"), str(base / "cgroup"))["complete"])

    def test_script_is_read_only(self) -> None:
        source = (ACCEPTANCE / "collect-peers.py").read_text()
        for needle in ("os.kill", "ptrace", "os.remove", "os.unlink", "os.rename", "open(path, \"w", "subprocess", "chmod"):
            self.assertNotIn(needle, source)


def synthetic_run(profile: str):
    """A coherent baseline/confined/inventory/audit set built from the verifier's own expectations."""
    verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_synth")
    matrix = json.loads(MATRIX_TEXT)
    spec = matrix["profiles"][profile]
    pids = {"production": 2101, "staging-next": 2201, "staging-legacy": 2301}
    peers = {role: [pids[role]] for role in spec["peer_roles"]}
    processes = []
    for role, pid in pids.items():
        processes.append({"pid": pid, "uids": [33, 33, 33, 33], "comm": "php-fpm", "starttime": 900 + pid,
                          "label": f"{matrix['role_profiles'][role]} (enforce)", "unit": matrix["role_units"][role],
                          "exe": "/usr/sbin/php-fpm", "fds": ["socket:[1]", "pipe:[2]", "/dev/null"]})
    inventory = {"schema": 1, "complete": True, "reasons": [], "processes": processes,
                 "units": {u: {"present": True, "pids": [pids[r]]} for r, u in matrix["role_units"].items()}}
    expected, _ = verifier.expected_checks(matrix, profile, peers)
    probe, child, digest = f"{profile}-probe", 777, hashlib.sha256(MATRIX_TEXT.encode()).hexdigest()
    base_results, conf_results, audit = [], [], []

    def line(**kw):
        audit.append('audit: type=1400 apparmor="DENIED" ' + " ".join(
            f'{k}="{v}"' if isinstance(v, str) else f"{k}={v}" for k, v in kw.items()))

    for ident, (kind, item) in expected.items():
        extra = {"child": child} if ident.startswith(("calibration:child", "control:self")) else {}
        allow = kind == "control" or (kind in ("file", "socket") and item["expect"] == "allow")
        base_results.append({"id": ident, "kind": kind, "target": "", "errno": 0, **extra})
        conf_results.append({"id": ident, "kind": kind, "target": "", "errno": 0 if allow else EACCES, **extra})
        if allow:
            continue
        common = {"profile": probe, "pid": 200, "comm": "python3"}
        if kind == "file":
            op = "exec" if item["access"] == "x" else "open"
            line(operation=op, class_="file", name=item["path"], **common)
        elif kind == "socket":
            line(operation="connect", name=item["path"], **common)
        elif kind == "symlink":
            line(operation="open", name=item["path"], **common)
        elif kind == "abstract":
            line(operation="connect", addr="@wpcg-probe-x", **common)
        elif kind == "peer":
            line(operation="ptrace", peer=matrix["role_profiles"][item["role"]], **common)
        elif kind == "peer-signal":
            line(operation="signal", peer=matrix["role_profiles"][item["role"]], **common)
        elif kind == "peer-vm":
            line(operation="ptrace", peer=matrix["role_profiles"][item["role"]], **common)
        elif ident == "calibration:child-environ":
            line(operation="open", name=f"/proc/{child}/environ", **common)
        else:
            line(operation="ptrace", peer=probe, **common)
    header = {"schema": 1, "profile": profile, "matrix_sha256": digest, "peers": peers}
    baseline = {**header, "mode": "baseline", "label": "unconfined", "pid": 100, "results": base_results,
                "fd0": {"state": "read", "errno": 0, "marker_ok": True},
                "scm": {"state": "received", "errno": 0, "read_ok": True}}
    confined = {**header, "mode": "confined", "label": f"{probe} (enforce)", "pid": 200, "results": conf_results,
                "fd0": {"state": "read", "errno": EACCES, "marker_ok": False},
                "scm": {"state": "received", "errno": EACCES, "read_ok": False}}
    return verifier, matrix, baseline, confined, inventory, "\n".join(audit).replace("class_=", "class=")


class VerifyAcceptance(unittest.TestCase):
    profile = "wpcaregrid-php-fpm-production"

    def run_verify(self, mutate=None, profile=None, ack=False):
        profile = profile or self.profile
        verifier, matrix, baseline, confined, inventory, audit = synthetic_run(profile)
        state = {"baseline": copy.deepcopy(baseline), "confined": copy.deepcopy(confined),
                 "before": copy.deepcopy(inventory), "after": copy.deepcopy(inventory), "audit": audit}
        if mutate:
            mutate(state)
        return verifier.verify(MATRIX_TEXT, profile, state["baseline"], state["confined"], state["before"],
                               state["after"], state["audit"], ack)

    def assertFails(self, verdict, needle: str) -> None:
        self.assertTrue(any(needle in f for f in verdict.failures), verdict.failures[:5])

    def result(self, state, which, ident):
        return next(r for r in state[which]["results"] if r["id"] == ident)

    def test_coherent_run_passes_for_every_profile(self) -> None:
        for profile in PROFILES:
            verdict = self.run_verify(profile=profile)
            self.assertEqual(verdict.failures, [], profile)
            self.assertTrue(verdict.passes)

    def test_errno_alone_is_never_a_pass(self) -> None:
        # Confined errno is EACCES everywhere, but with no audit text nothing may pass as a denial.
        verdict = self.run_verify(lambda s: s.update(audit=""))
        self.assertFails(verdict, "no matching AppArmor DENIED audit record")
        self.assertFalse([p for p in verdict.passes if "audit=DENIED" in p])

    def test_audit_must_match_profile_pid_operation_and_target(self) -> None:
        wrong = {"profile": lambda a: a.replace(f'profile="{self.profile}-probe"', 'profile="other"'),
                 "pid": lambda a: a.replace("pid=200", "pid=999"),
                 "operation": lambda a: a.replace('operation="open"', 'operation="unlink"'),
                 "name": lambda a: a.replace('name="/var/lib/wpcaregrid-deploy/staging-next/shared/.env"', 'name="/other"')}
        for what, edit in wrong.items():
            with self.subTest(what=what):
                verdict = self.run_verify(lambda s, edit=edit: s.update(audit=edit(s["audit"])))
                self.assertFails(verdict, "staging-env-read" if what == "name" else "no matching AppArmor DENIED")

    def test_baseline_must_reach_the_target(self) -> None:
        verdict = self.run_verify(lambda s: self.result(s, "baseline", "staging-env-read").update(errno=EACCES))
        self.assertFails(verdict, "baseline could not reach the target")
        verdict = self.run_verify(lambda s: self.result(s, "baseline", "symlink-escape:0").update(errno=2))
        self.assertFails(verdict, "baseline could not reach the target")

    def test_confined_success_on_a_denied_target_fails(self) -> None:
        verdict = self.run_verify(lambda s: self.result(s, "confined", "staging-env-read").update(errno=0))
        self.assertFails(verdict, "confined access not refused")

    def test_allowed_access_must_work_in_both_runs(self) -> None:
        verdict = self.run_verify(lambda s: self.result(s, "confined", "own-env-read").update(errno=EACCES))
        self.assertFails(verdict, "allowed access failed")

    def test_dac_masked_optional_fixture_is_unproven_not_pass_and_never_allowed(self) -> None:
        verdict = self.run_verify(lambda s: self.result(s, "baseline", "fixture-control-plane").update(errno=EACCES))
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("UNPROVEN" in n for n in verdict.notes))
        verdict = self.run_verify(lambda s: (self.result(s, "baseline", "fixture-control-plane").update(errno=EACCES),
                                             self.result(s, "confined", "fixture-control-plane").update(errno=0)))
        self.assertFails(verdict, "confined access succeeded")

    def test_wrong_label_mode_or_matrix_hash_rejects_the_whole_run(self) -> None:
        for edit in (lambda s: s["confined"].update(label="unconfined"), lambda s: s["baseline"].update(label="x (enforce)"),
                     lambda s: s["confined"].update(matrix_sha256="0" * 64), lambda s: s["confined"].update(mode="baseline"),
                     lambda s: s["confined"].update(label=f"{self.profile}-probe (complain)")):
            self.assertFails(self.run_verify(edit), "run:")

    def test_incomplete_peer_inventory_fails_closed(self) -> None:
        for edit in (lambda s: s["before"].update(complete=False), lambda s: s["after"].update(complete=False),
                     lambda s: s["before"].pop("complete")):
            self.assertFails(self.run_verify(edit), "incomplete")

    def test_unconfined_same_uid_peer_in_either_inventory_fails(self) -> None:
        stray = {"pid": 4000, "uids": [33, 33, 33, 33], "comm": "php", "starttime": 1, "label": "unconfined",
                 "unit": "supervisor.service", "exe": None, "fds": []}
        for phase in ("before", "after"):
            verdict = self.run_verify(lambda s, phase=phase: s[phase]["processes"].append(copy.deepcopy(stray)))
            self.assertFails(verdict, "is not enforce-attached")
        nginx = dict(stray, comm="nginx")
        self.assertFails(self.run_verify(lambda s: s["before"]["processes"].append(nginx)), "is not enforce-attached")

    def test_missing_peer_role_or_unit_fails_enumeration(self) -> None:
        def drop(role_unit):
            return lambda s: s["before"].update(processes=[p for p in s["before"]["processes"] if p["unit"] != role_unit])
        for unit in ("php8.5-fpm.service", "php8.4-fpm.service", "wpcaregrid-production-php8.4-fpm.service"):
            self.assertFails(self.run_verify(drop(unit)), "peer enumeration incomplete")
        self.assertFails(self.run_verify(lambda s: s["before"]["units"][ "php8.5-fpm.service"].update(present=False)),
                         "peer enumeration incomplete")

    def test_peer_pid_reuse_or_absence_fails(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["after"]["processes"][1].update(starttime=1)), "start time changed")
        self.assertFails(self.run_verify(lambda s: s["after"].update(processes=[p for p in s["after"]["processes"] if p["pid"] != 2201])),
                         "peer-identity")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(peers={"staging-next": [2201], "staging-legacy": []})),
                         "identical, non-empty")
        self.assertFails(self.run_verify(lambda s: s["before"]["processes"][1].update(label="unconfined")), "peer")

    def test_unit_fd_inventory_rejects_other_environment_descriptors(self) -> None:
        for target in ("/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "/etc/wpcaregrid-deploy/profiles/x",
                       "/home/deploy/x", "/var/www/wpcaregrid-staging-new/artisan (deleted)"):
            verdict = self.run_verify(lambda s, t=target: s["before"]["processes"][0]["fds"].append(t))
            self.assertFails(verdict, "fd-inventory")
        verdict = self.run_verify(lambda s: s["before"]["processes"][1]["fds"].append(
            "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log"), profile="wpcaregrid-php-fpm-staging-next")
        self.assertFails(verdict, "fd-inventory")
        verdict = self.run_verify(lambda s: s["before"]["processes"][0]["fds"].append(
            "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log"))
        self.assertEqual(verdict.failures, [])  # own storage is fine

    def test_inherited_fd0_is_read_and_judged(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["baseline"].update(fd0={"state": "not-canary-file"})), "fd0")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(fd0={"state": "not-canary-file"})), "fd0")
        verdict = self.run_verify(lambda s: s["confined"].update(fd0={"state": "read", "errno": 0, "marker_ok": True}))
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("INHERITED-FD-READABLE" in n for n in verdict.notes))

    def test_descriptor_transfer_bypass_fails_unless_explicitly_acknowledged(self) -> None:
        bypass = lambda s: s["confined"].update(scm={"state": "received", "errno": 0, "read_ok": True})  # noqa: E731
        self.assertFails(self.run_verify(bypass), "scm-rights")
        self.assertEqual(self.run_verify(bypass, ack=True).failures, [])
        self.assertFails(self.run_verify(lambda s: s["confined"].update(scm={"state": "channel-failed", "errno": 2})), "scm-rights")
        self.assertFails(self.run_verify(lambda s: s["baseline"].update(scm={"state": "channel-failed", "errno": 2})), "scm-rights")

    def test_proc_fd_root_cwd_exe_are_in_the_peer_matrix_and_enforced(self) -> None:
        entries = json.loads(MATRIX_TEXT)["peer_entries"]
        for needed in ("fd/", "fdinfo/", "root/", "cwd/", "exe", "environ", "mem", "maps", "cmdline"):
            self.assertIn(needed, entries)
        verdict = self.run_verify(lambda s: self.result(s, "confined", "peer:staging-next:2201:root/").update(errno=0))
        self.assertFails(verdict, "peer:staging-next:2201:root/")
        verdict = self.run_verify(lambda s: self.result(s, "confined", "peer:staging-legacy:2301:fd/").update(errno=0))
        self.assertFails(verdict, "peer:staging-legacy:2301:fd/")

    def test_ptrace_and_process_vm_readv_need_a_calibrated_baseline(self) -> None:
        # Yama refuses even unconfined: AppArmor attribution is only allowed via the descendant calibration.
        def yama(s):
            for who in ("baseline", "confined"):
                self.result(s, who, "peer:staging-next:2201:process_vm_readv").update(errno=EPERM)
        self.assertEqual(self.run_verify(yama).failures, [])
        def yama_without_calibration(s):
            yama(s)
            self.result(s, "confined", "calibration:child-process_vm_readv").update(errno=0)
        self.assertTrue(self.run_verify(yama_without_calibration).failures)
        def yama_and_uncorrelated_calibration(s):
            yama(s)
            s["audit"] = "\n".join(l for l in s["audit"].splitlines() if f'peer="{self.profile}-probe"' not in l)
        self.assertFails(self.run_verify(yama_and_uncorrelated_calibration), "calibration")
        self.assertFails(self.run_verify(lambda s: self.result(s, "baseline", "calibration:child-ptrace-seize").update(errno=1)),
                         "baseline could not reach")

    def test_unexpected_denials_and_missing_results_fail(self) -> None:
        extra = f'audit: apparmor="DENIED" operation="open" profile="{self.profile}-probe" name="/etc/hosts" pid=200 comm="python3"'
        self.assertFails(self.run_verify(lambda s: s.update(audit=s["audit"] + "\n" + extra)), "unexplained-denial")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(results=s["confined"]["results"][1:])), "missing")
        self.assertFails(self.run_verify(lambda s: s["confined"]["results"].append(dict(s["confined"]["results"][0]))), "duplicate")

    def test_kernel_enforcement_is_not_claimed_by_the_verifier(self) -> None:
        source = (ACCEPTANCE / "verify-acceptance.py").read_text()
        self.assertIn("kernel enforcement is shown by the PASS lines above, not by this tool", source)
        self.assertIn("record, not a standing guarantee", source)

    def test_audit_parser_handles_quoted_and_bare_values(self) -> None:
        verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_parse")
        records = verifier.parse_audit('type=1400 audit(1:2): apparmor="DENIED" operation="open" class="file" '
                                       'profile="p-probe" name="/a b" pid=12 comm="python3" requested_mask="r"\n'
                                       'apparmor="ALLOWED" operation="open" pid=3\nnoise\n')
        self.assertEqual(len(records), 1)
        self.assertEqual((records[0]["pid"], records[0]["name"], records[0]["profile"]), (12, "/a b", "p-probe"))


class OriginalProfileHarness(unittest.TestCase):
    def test_runbook_tests_the_original_profile_for_exec_denial(self) -> None:
        doc = DOC.read_text(encoding="utf-8")
        self.assertIn("-p AppArmorProfile=wpcaregrid-php-fpm-production /usr/bin/python3", doc)
        self.assertIn("exec-python", doc)


class AbstractSocketDenial(unittest.TestCase):
    def test_every_profile_denies_abstract_unix_sockets(self) -> None:
        common = (ARTIFACTS / "apparmor/abstractions" / COMMON).read_text()
        self.assertIn("audit deny unix (connect, send, receive) peer=(addr=@**),", common)
        self.assertIn("audit deny unix (bind, listen, accept) addr=@**,", common)
        for name in PROFILES:
            rules = load_profile(ARTIFACTS, name, [])[0]
            self.assertEqual(len([r for r in rules if r.kind == "unix" and r.deny and r.audit]), 2, name)
            self.assertFalse([r for r in rules if r.kind == "unix" and not r.deny and "addr" in r.body], name)


class DocumentationContract(unittest.TestCase):
    def doc(self) -> str:
        return DOC.read_text(encoding="utf-8")

    def section(self, start: str, end: str) -> str:
        text = self.doc()
        return text[text.index(start):text.index(end)]

    def test_required_statements(self) -> None:
        doc = self.doc()
        for needed in (
            "Source tests cannot prove kernel enforcement", "NOT PROVEN", "fail closed",
            "No new users, groups or FPM UID", "complain", "Rollback", "STOP", "Inherited descriptors",
            "/proc/<pid>/attr/current", "/proc/<pid>/fd", "AppArmor 1.0.1 does not separate TCP ports", "php_fpm_uid",
            "production_unix_identity_not_isolated", "derive-probe-profile.py", "sha256sum -c --strict SHA256SUMS",
            "systemd 255", "PrivatePIDs", "never rely on exec-time revocation", "StandardInput=file:",
            "is not sufficient on its own", "exec-python", "Invariant I",
            "-p AppArmorProfile=wpcaregrid-php-fpm-production /usr/bin/python3",
        ):
            self.assertIn(needed, doc, needed)
        self.assertNotRegex(doc, r"(?i)\bis (now )?installed\b|has been (applied|installed|loaded)")

    def test_stage_zero_installs_nothing_into_unit_or_profile_search_paths(self) -> None:
        stage0 = self.section("**Stage 0", "**Stage 1")
        self.assertIn("/usr/local/libexec/wpcaregrid-php-fpm-confinement/1.0.1/", stage0)
        self.assertIn("does not install any profile, unit, drop-in", stage0)
        self.assertNotIn("90-wpcaregrid-confinement.conf", stage0)
        self.assertNotIn("cp ", stage0)

    def test_each_dropin_is_installed_only_in_its_own_stage_and_reload_follows_it(self) -> None:
        stage1 = self.section("**Stage 1", "**Stage 2")
        stage2 = self.section("**Stage 2", "**Stage 3")
        stage4 = self.section("**Stage 4", "**Stage 5")
        self.assertIn("php8.4-fpm.service.d/90-wpcaregrid-confinement.conf", stage1)
        self.assertNotIn("php8.5-fpm.service.d/90-wpcaregrid-confinement.conf", stage1)
        self.assertIn("php8.5-fpm.service.d/90-wpcaregrid-confinement.conf", stage2)
        self.assertNotIn("php8.4-fpm.service.d/90-wpcaregrid-confinement.conf", stage2)
        self.assertIn("wpcaregrid-production-php8.4-fpm.service", stage4)
        self.assertLess(stage1.index("90-wpcaregrid-confinement.conf"), stage1.index("daemon-reload"))
        self.assertLess(stage2.index("drop-in"), stage2.index("`daemon-reload` (only now)"))
        later = self.section("**Stage 3", "## Live acceptance")
        self.assertNotIn("php8.5-fpm.service.d/90", self.section("**Stage 3", "**Stage 4"))
        self.assertTrue(later)

    def test_staging_peers_are_confined_and_gated_before_any_production_stage(self) -> None:
        doc = self.doc()
        order = [doc.index(f"**Stage {n}") for n in range(6)]
        self.assertEqual(order, sorted(order))
        self.assertIn("staging-legacy", self.section("**Stage 1", "**Stage 2"))
        self.assertIn("staging-next", self.section("**Stage 2", "**Stage 3"))
        gate = self.section("**Stage 3", "**Stage 4")
        self.assertIn("STOP", gate)
        self.assertIn("production secrets may not be provisioned", gate)
        stage4 = self.section("**Stage 4", "**Stage 5")
        self.assertIn("Only after Stage 3 passes", stage4)
        stage5 = doc[doc.index("**Stage 5"):doc.index("Never combine stages")]
        self.assertIn("production Nginx route is never changed before this stage", stage5)
        for early in ("**Stage 0", "**Stage 1", "**Stage 2", "**Stage 3", "**Stage 4"):
            text = self.section(early, "**Stage %d" % (int(early[-1]) + 1) if early != "**Stage 4" else "**Stage 5")
            self.assertNotIn("open the production Nginx route", text)
            self.assertNotIn("provision real production secrets", text)

    def test_rollback_fences_production_before_restoring_unconfined_peers(self) -> None:
        rollback = self.section("## Rollback", "## What the next engineer")
        r1, r2, r3, r4 = (rollback.index(f"**R{n} ") for n in (1, 2, 3, 4))
        self.assertTrue(r1 < r2 < r3 < r4)
        self.assertIn("fence production traffic", rollback[r1:r2])
        self.assertIn("fence production secrets", rollback[r2:r3])
        self.assertIn("restore staging peers", rollback[r3:r4])
        self.assertIn("production is fenced before any same-UID peer is returned to unconfined", rollback)
        self.assertIn("Partial rollback", rollback)
        self.assertNotIn("drop-in", rollback[:r3].replace("drop-ins", ""))

    def test_unconfined_non_fpm_same_uid_peers_are_surfaced_as_a_blocker(self) -> None:
        doc = self.doc()
        for needed in ("nginx workers", "Supervisor queue workers", "ACCEPTED_UNCONFINED_SAME_UID_COMM", "fails on a real host today"):
            self.assertIn(needed, doc)

    def test_no_secret_values_in_artifacts(self) -> None:
        pattern = re.compile(r"(?i)(password|secret|token|api[_-]?key)\s*[:=]\s*\S+")
        for path in list(ARTIFACTS.rglob("*")) + [DOC]:
            if path.is_file() and path.suffix != ".py" and "__pycache__" not in path.parts:
                for line in path.read_text(encoding="utf-8").splitlines():
                    self.assertIsNone(pattern.search(line), f"{path}: {line}")


class RealParser(unittest.TestCase):
    """Compile with the real apparmor_parser when present. No kernel load, no cache."""

    def test_profiles_and_probe_profiles_compile(self) -> None:
        parser = shutil.which("apparmor_parser")
        if parser is None:
            if os.environ.get("PHP_FPM_CONFINEMENT_REQUIRE_PARSER") == "1":
                self.fail("apparmor_parser is required but unavailable")
            self.skipTest("apparmor_parser not installed: syntax NOT verified by the real parser in this run")
        derive = load_script(ARTIFACTS / "acceptance/derive-probe-profile.py", "wpcg_derive_parser")
        with tempfile.TemporaryDirectory(prefix="wpcg-fpm-parser-") as temp:
            for name, relative in PROFILES.items():
                source = ARTIFACTS / relative
                probe = Path(temp, f"{name}-probe")
                probe.write_text(derive.derive(source.read_text()))
                for target in (source, probe):
                    result = subprocess.run(
                        [parser, "-Q", "-K", "-I", str(ARTIFACTS / "apparmor"), str(target)],
                        capture_output=True, text=True, timeout=120, check=False)
                    self.assertEqual(result.returncode, 0, f"{target.name}: {result.stderr}")


if __name__ == "__main__":
    print("NOTE: source-only tests. Kernel enforcement is NOT verified here; see docs/PHP_FPM_CONFINEMENT_GO13.md.",
          file=sys.stderr)
    unittest.main(verbosity=2)
