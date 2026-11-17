#!/usr/bin/env python3
"""Source-level tests for the GO-13 PHP-FPM confinement artifacts (version 1.0.5).

WHAT THIS PROVES: the committed AppArmor profiles, systemd units and FPM config
are internally consistent, fail closed under mutation, and -- evaluated by a
small AppArmor path-rule model -- grant and deny the paths in
deploy/php-fpm-confinement/1.0.4/acceptance/matrix.json in both directions
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

import ast
import copy
import glob
import hashlib
import importlib.util
import json
import os
import re
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.dont_write_bytecode = True  # never leave bytecode inside the hashed artifact tree

ROOT = Path(__file__).resolve().parents[2]
ARTIFACTS = ROOT / "deploy" / "php-fpm-confinement" / "1.0.5"
MYSQL_SOCKET = "/run/mysqld/wpcaregrid-production.sock"
DEPLOY_UNIX = "(create, connect, shutdown, send, receive, getattr, getopt, setopt) unix stream"
DOC = ROOT / "docs" / "PHP_FPM_CONFINEMENT_GO13.md"
COMMON = "wpcaregrid-php-fpm-common"
EXTERNAL_INCLUDES = {"tunables/global", "abstractions/base", "abstractions/nameservice", "abstractions/ssl_certs"}

EXPECTED_FILES = {
    "SHA256SUMS",
    "binding.json",
    "acceptance/collect-peers.py",
    "acceptance/derive-probe-profile.py",
    "acceptance/fd-sender.py",
    "acceptance/fence-production.py",
    "acceptance/fixtures.py",
    "acceptance/matrix.json",
    "acceptance/probe.py",
    "acceptance/rollback-order.py",
    "acceptance/sibling-listener.py",
    "acceptance/verify-acceptance.py",
    "apparmor/abstractions/wpcaregrid-php-fpm-common",
    "apparmor/wpcaregrid-nginx",
    "apparmor/wpcaregrid-php-cli-production-deploy",
    "apparmor/wpcaregrid-php-cli-production-build",
    "apparmor/wpcaregrid-php-cli-staging",
    "apparmor/wpcaregrid-php-fpm-production",
    "apparmor/wpcaregrid-php-fpm-staging-legacy",
    "apparmor/wpcaregrid-php-fpm-staging-next",
    "php-fpm/production/php-fpm.conf",
    "php-fpm/production/pool.d/wpcaregrid-production.conf",
    "systemd/nginx.service.d/90-wpcaregrid-confinement.conf",
    "systemd/php8.4-fpm.service.d/90-wpcaregrid-confinement.conf",
    "systemd/supervisor.service.d/90-wpcaregrid-confinement.conf",
    "systemd/wpcaregrid-staging-legacy-scheduler.service.d/90-wpcaregrid-confinement.conf",
    "systemd/wpcaregrid-staging-next-scheduler.service.d/90-wpcaregrid-confinement.conf",
    "systemd/php8.5-fpm.service.d/90-wpcaregrid-confinement.conf",
    "systemd/wpcaregrid-production-php8.4-fpm.service",
}
PROFILES = {
    "wpcaregrid-php-fpm-production": "apparmor/wpcaregrid-php-fpm-production",
    "wpcaregrid-php-fpm-staging-next": "apparmor/wpcaregrid-php-fpm-staging-next",
    "wpcaregrid-php-fpm-staging-legacy": "apparmor/wpcaregrid-php-fpm-staging-legacy",
    "wpcaregrid-nginx": "apparmor/wpcaregrid-nginx",
    "wpcaregrid-php-cli-staging": "apparmor/wpcaregrid-php-cli-staging",
    "wpcaregrid-php-cli-production-deploy": "apparmor/wpcaregrid-php-cli-production-deploy",
    "wpcaregrid-php-cli-production-build": "apparmor/wpcaregrid-php-cli-production-build",
}
ATTACHMENT: dict = {}  # no profile has an exec-path attachment: www-data cron is not permitted, so nothing attaches by path
NO_COMMON = {"wpcaregrid-nginx"}  # nginx needs TLS keys, so it carries its own complete deny set
# Exact per-profile grants beyond plain reads. Anything else is a finding.
EXEC_ALLOW = {
    "wpcaregrid-php-fpm-production": {"/usr/sbin/php-fpm8.4", "/usr/bin/kill"},
    "wpcaregrid-php-fpm-staging-next": {"/usr/sbin/php-fpm8.5", "/usr/bin/kill", "/usr/bin/dash",
                                       "/usr/lib/php/php-fpm-socket-helper"},
    "wpcaregrid-nginx": {"/usr/sbin/nginx", "/usr/sbin/start-stop-daemon"},
    "wpcaregrid-php-cli-production-deploy": {"/usr/bin/php8.4"},
    "wpcaregrid-php-cli-production-build": {"/usr/bin/php8.4"},
    "wpcaregrid-php-cli-staging": {"/usr/bin/supervisord", "/usr/bin/python3*", "/usr/bin/php8.4", "/usr/bin/php8.5",
                                   "/usr/local/bin/wpcaregrid-php", "/usr/local/bin/wpcaregrid-scheduler",
                                   "/usr/bin/dash", "/usr/bin/bash", "/usr/bin/flock", "/usr/bin/logger"},
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
    "wpcaregrid-nginx": {"/run/nginx.pid", "/var/log/nginx/{,**}", "/var/lib/nginx/{,**}",
                         "/run/php/wpcaregrid-production-php8.4-fpm.sock", "/run/php/php8.5-fpm.sock",
                         "/run/php/php8.4-fpm.sock"},
    "wpcaregrid-php-cli-production-deploy": {MYSQL_SOCKET},
    "wpcaregrid-php-cli-production-build": {
        "/var/www/wpcaregrid-production/releases/*/bootstrap/cache/{,**}",
        "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/{,**}",
        "/var/lib/wpcaregrid-deploy/production/shared/storage/framework/{cache,views}/{,**}"},
    "wpcaregrid-php-cli-staging": {
        "/run/supervisor.sock", "/run/supervisord.pid", "/var/log/supervisor/{,**}", "/tmp/{,**}",
        "/var/lib/wpcaregrid-deploy/staging-next/shared/storage/{,**}", "/var/www/wpcaregrid/storage/{,**}",
        "/var/www/wpcaregrid/bootstrap/cache/{,**}", "/var/lib/wpcaregrid/{,**}"},
}
CAPABILITY_ALLOW = {
    "wpcaregrid-nginx": {"net_bind_service", "setuid", "setgid", "chown", "kill", "sys_resource"},
    "wpcaregrid-php-cli-staging": {"setuid", "setgid", "chown", "kill", "dac_override"},
    "wpcaregrid-php-cli-production-deploy": set(),  # runs as www-data: no capability at all
    "wpcaregrid-php-cli-production-build": set(),
    "wpcaregrid-php-fpm-production": set(),
    "wpcaregrid-php-fpm-staging-next": {"setuid", "setgid", "chown", "kill"},
}
NETWORK_ALLOW = {"inet stream", "inet6 stream", "inet dgram", "inet6 dgram", "unix stream", "unix dgram",
                 "netlink raw"}
PATH_EXCEPTIONS = {"/etc/hosts", "/etc/host.conf", "/etc/nsswitch.conf", "/tmp/{,**}", "/proc/[0-9]*/maps", "/run/php/", "/etc/localtime", "/etc/mime.types", "/run/nginx.pid",
                   "/etc/nginx/{,**}", "/etc/supervisor/{,**}", "/var/www/*/public/{,**}", "/usr/bin/python3*",
                   "/usr/lib/python3*/{,**}", "/run/supervisor.sock", "/run/supervisord.pid"}
# Explicit audited denials every allowlist profile must keep, so a denial leaves a correlatable audit record
# even where default-deny would already refuse it.
REQUIRED_DENIES = {
    "wpcaregrid-php-fpm-production": ("/var/lib/wpcaregrid-deploy/staging-next/{,**}", "/var/www/wpcaregrid-staging-new/{,**}",
                                      "/var/www/wpcaregrid/{,**}", "/run/mysqld/{,**}"),
    "wpcaregrid-php-fpm-staging-next": ("/var/lib/wpcaregrid-deploy/production/{,**}", "/var/www/wpcaregrid-production/{,**}",
                                        "/var/www/wpcaregrid/{,**}", "/run/mysqld/{,**}"),
    "wpcaregrid-php-fpm-staging-legacy": ("/var/lib/wpcaregrid-deploy/production/{,**}", "/var/www/wpcaregrid-production/{,**}", "/run/mysqld/{,**}"),
    "wpcaregrid-php-cli-staging": ("/var/lib/wpcaregrid-deploy/production/{,**}", "/var/www/wpcaregrid-production/{,**}",
                                   "/var/log/wpcaregrid-production/{,**}", "/run/mysqld/{,**}"),
    "wpcaregrid-php-cli-production-deploy": ("/var/lib/wpcaregrid-deploy/staging-next/{,**}", "/var/www/wpcaregrid-staging-new/{,**}",
                                             "/var/www/wpcaregrid/{,**}", "/var/lib/wpcaregrid/{,**}", "/var/log/wpcaregrid-production/{,**}",
                                             "/var/lib/wpcaregrid-deploy/production/state/{,**}", "/run/php/{,**}",
                                             "/run/mysqld/wpcaregrid-production-restore.sock", "/run/mysqld/mysqld.sock",
                                             "/run/mysqld/wpcaregrid-production.sock.lock", "/run/mysqld/{,**}", "/run/systemd/journal/{,**}",
                                             "/dev/log"),
    "wpcaregrid-php-cli-production-build": ("/var/lib/wpcaregrid-deploy/staging-next/{,**}", "/var/www/wpcaregrid-staging-new/{,**}",
                                             "/var/www/wpcaregrid/{,**}", "/var/lib/wpcaregrid/{,**}", "/var/log/wpcaregrid-production/{,**}",
                                             "/var/lib/wpcaregrid-deploy/production/state/{,**}", "/run/php/{,**}",
                                             "/run/mysqld/{,**}", "/run/systemd/journal/{,**}", "/dev/log"),
}
# Exact gate/fence marker files that nginx and the job profiles may stat/read; the paths contain "/state".
MARKER_EXCEPTIONS = {MYSQL_SOCKET, "/var/lib/wpcaregrid/deploy-maintenance", "/var/lib/wpcaregrid-deploy/staging-next/state/maintenance",
                     "/var/lib/wpcaregrid-deploy/production/state/maintenance",
                     "/var/lib/wpcaregrid-deploy/staging-next/state/workers-disabled",
                     "/var/lib/wpcaregrid-deploy/staging-next/state/scheduler-paused"}
FOREIGN_TOKENS = {
    "wpcaregrid-php-fpm-production": ("staging", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.5-fpm"),
    "wpcaregrid-php-fpm-staging-next": ("production", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.4-fpm.sock"),
    "wpcaregrid-nginx": ("/shared/", "/storage/", "/bootstrap/", "/.env", "/etc/wpcaregrid-deploy"),
    "wpcaregrid-php-cli-staging": ("production",),
    "wpcaregrid-php-cli-production-deploy": ("staging", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.5", "/run/php", "/log/"),
    "wpcaregrid-php-cli-production-build": ("staging", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "php8.5", "/run/php", "/log/"),
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
            elif re.fullmatch(r"(capability|network|signal|ptrace|mount|umount|remount|pivot_root|dbus|unix|userns)(\s.*)?", body):
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
    header = re.findall(r"^profile (\S+)(?: (/\S+))? flags=\(([^)]*)\) \{$", text, re.MULTILINE)
    if header != [(name, ATTACHMENT.get(name, ""), "attach_disconnected")] or not text.endswith("\n}\n"):
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


NET_FAMILIES = {"inet", "inet6", "unix", "netlink", "packet", "bluetooth", "local"}
NET_TYPES = {"stream", "dgram", "raw", "seqpacket", "rdm", "packet"}


def net_rule_matches(body: str, family: str, sock_type: str, access: str) -> bool:
    tokens = body.removeprefix("network").replace(",", " ").replace("(", " ( ").replace(")", " ) ").split()
    accesses: set[str] = set()
    if "(" in tokens:
        end = tokens.index(")")
        accesses = set(tokens[tokens.index("(") + 1:end])
        tokens = tokens[end + 1:]
    fams = [t for t in tokens if t in NET_FAMILIES]
    types = [t for t in tokens if t in NET_TYPES]
    return (not accesses or access in accesses) and (not fams or family in fams) and (not types or sock_type in types)


def net_decide(rules: list, family: str, sock_type: str, access: str) -> bool:
    """AppArmor network mediation model: any matching deny wins; otherwise a matching allow is required."""
    matching = [r for r in rules if r.kind == "network" and net_rule_matches(r.body, family, sock_type, access)]
    if any(r.deny for r in matching):
        return False
    return bool(matching)


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
    "nginx.service.d": {"AppArmorProfile": ["wpcaregrid-nginx"], "NoNewPrivileges": ["yes"], "PrivateTmp": ["yes"],
                         "ProtectHome": ["yes"], "ProtectProc": ["invisible"], "StandardInput": ["null"],
                         "FileDescriptorStoreMax": ["0"]},
    "supervisor.service.d": {"AppArmorProfile": ["wpcaregrid-php-cli-staging"], "NoNewPrivileges": ["yes"],
                              "PrivateTmp": ["yes"], "ProtectHome": ["yes"], "ProtectProc": ["invisible"],
                              "StandardInput": ["null"], "FileDescriptorStoreMax": ["0"]},
    "wpcaregrid-staging-next-scheduler.service.d": {
        "AppArmorProfile": ["wpcaregrid-php-cli-staging"], "NoNewPrivileges": ["yes"], "PrivateTmp": ["yes"],
        "ProtectHome": ["yes"], "ProtectProc": ["invisible"], "StandardInput": ["null"], "FileDescriptorStoreMax": ["0"]},
    "wpcaregrid-staging-legacy-scheduler.service.d": {
        "AppArmorProfile": ["wpcaregrid-php-cli-staging"], "NoNewPrivileges": ["yes"], "PrivateTmp": ["yes"],
        "ProtectHome": ["yes"], "ProtectProc": ["invisible"], "StandardInput": ["null"], "FileDescriptorStoreMax": ["0"]},
}
MASKS_PRODUCTION = {"/var/www/wpcaregrid-staging-new", "/var/www/wpcaregrid", "/var/lib/wpcaregrid-deploy/staging-next",
                    "/var/lib/wpcaregrid", "/etc/wpcaregrid-deploy"}
MASKS_STAGING_NEXT = {"/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production", "/var/www/wpcaregrid",
                      "/var/lib/wpcaregrid", "/etc/wpcaregrid-deploy"}
MASKS_LEGACY = {"/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production",
                "/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next",
                "/etc/wpcaregrid-deploy"}


MASKS_NGINX = {"/etc/wpcaregrid-deploy", "/var/lib/wpcaregrid-deploy/production/shared",
               "/var/lib/wpcaregrid-deploy/staging-next/shared", "/var/lib/wpcaregrid-deploy/production/database-backups",
               "/var/lib/wpcaregrid-deploy/staging-next/database-backups"}
MASKS_JOBS = {"/var/www/wpcaregrid-production", "/var/lib/wpcaregrid-deploy/production", "/etc/wpcaregrid-deploy"}
DROPIN_MASKS = {"php8.5-fpm.service.d": MASKS_STAGING_NEXT, "php8.4-fpm.service.d": MASKS_LEGACY,
                "nginx.service.d": MASKS_NGINX, "supervisor.service.d": MASKS_JOBS,
                "wpcaregrid-staging-next-scheduler.service.d": MASKS_JOBS,
                "wpcaregrid-staging-legacy-scheduler.service.d": MASKS_JOBS}


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
        allows = [r for r in rules if not r.deny]
        if name in NO_COMMON:
            if f"abstractions/{COMMON}" in includes:
                findings.append(f"{name}: must not include the common abstraction (it needs TLS keys)")
            for needed in ("ptrace", "capability sys_ptrace", "mount", "dbus", "network inet raw", "network packet",
                           "unix (connect, send, receive) peer=(addr=@**)", "unix (bind, listen, accept) addr=@**",
                           "/var/lib/wpcaregrid-deploy/*/shared/{,**}", "/etc/wpcaregrid-deploy/{,**}", "/home/{,**}",
                           "/var/www/**/.env*", "/var/www/*/{storage,bootstrap,config,database,app,routes}/{,**}",
                           "/proc/[0-9]*/environ", "/proc/[0-9]*/mem"):
                if not any(r.deny and (r.body == needed or r.body.startswith(needed)) for r in rules):
                    findings.append(f"{name}: lacks deny {needed}")
        elif f"abstractions/{COMMON}" not in includes:
            findings.append(f"{name}: does not include the common deny abstraction")
        for needed in REQUIRED_DENIES.get(name, ()):
            if not any(r.deny and r.audit and r.body == needed for r in rules):
                findings.append(f"{name}: lacks audited deny of {needed}")
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
                allowed = ({DEPLOY_UNIX} if name == "wpcaregrid-php-cli-production-deploy" else set() if name == "wpcaregrid-php-cli-production-build" else NETWORK_ALLOW)  # isolated CLI network contracts
                if rule.body.removeprefix("network ").strip() not in allowed:
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
                if any(token in rule.body for token in SECRET_PATHS_NEVER_GRANTED) and "storage" not in rule.body \
                        and rule.body not in MARKER_EXCEPTIONS:
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
        if directory not in DROPIN_MASKS:
            findings.append(f"{directory}: no mask expectation")
        for _, key, _ in entries:
            if key not in DROPIN_KEYS and key not in {"?"}:
                findings.append(f"{directory}: unreviewed directive {key}")
        if any(key == "?" for _, key, _ in entries):
            findings.append(f"{directory}: malformed line")
        for key, expected in required.items():
            if unit_values(entries, key) != expected:
                findings.append(f"{directory}: {key} is {unit_values(entries, key)}, expected {expected}")
        want = DROPIN_MASKS[directory]
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
    target = destination / "1.0.5"
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
        real_dir = matrix["symlink_dirs"]["real"]
        entry_points = list(matrix["symlink_dirs"]["lexical"])
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

    def test_nginx(self) -> None:
        self._check("wpcaregrid-nginx")

    def test_staging_jobs(self) -> None:
        self._check("wpcaregrid-php-cli-staging")

    def test_production_deploy_cli(self) -> None:
        self._check("wpcaregrid-php-cli-production-build")

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
        self.assertIn("AppArmor 1.0.5 does not separate TCP ports", doc)


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

    def test_nginx_and_job_profile_widening_is_detected(self) -> None:
        nginx, jobs = "apparmor/wpcaregrid-nginx", "apparmor/wpcaregrid-php-cli-staging"
        self.assertCaught(self.mutate(nginx, "/var/www/*/public/{,**} r,", "/var/www/{,**} r,"), "path too broad")
        self.assertCaught(self.mutate(nginx, "  /var/www/*/public/{,**} r,\n",
                                      "  /var/www/*/public/{,**} r,\n  /var/lib/wpcaregrid-deploy/production/shared/.env r,\n"),
                          "grants")
        self.assertCaught(self.mutate(nginx, "  audit deny /var/www/**/.env* rwklm,\n", ""), "lacks deny /var/www/**/.env*")
        self.assertCaught(self.mutate(nginx, "  audit deny /var/lib/wpcaregrid-deploy/*/shared/{,**} rwklm,\n", ""),
                          "lacks deny /var/lib/wpcaregrid-deploy/*/shared/{,**}")
        self.assertCaught(self.mutate(nginx, "/run/php/php8.4-fpm.sock rw,", "/run/** rw,"), "path too broad")
        self.assertCaught(self.mutate(nginx, "/var/log/nginx/{,**} rw,", "/var/log/nginx/{,**} rwl,"), "link")
        self.assertCaught(self.mutate(nginx, "  #include <abstractions/ssl_certs>\n",
                                      "  #include <abstractions/ssl_certs>\n  #include <abstractions/wpcaregrid-php-fpm-common>\n"),
                          "must not include the common")
        self.assertCaught(self.mutate(nginx, "  capability kill,\n", "  capability kill,\n  capability sys_admin,\n"), "capability")
        self.assertCaught(self.mutate(jobs, "  /usr/bin/flock ix,\n", "  /usr/bin/flock ix,\n  /usr/bin/curl ix,\n"), "exec")
        self.assertCaught(self.mutate(jobs, "  /var/lib/wpcaregrid/{,**} rwk,\n",
                                      "  /var/lib/wpcaregrid/{,**} rwk,\n  /var/lib/wpcaregrid-deploy/production/shared/.env r,\n"),
                          "production")
        self.assertCaught(self.mutate(jobs, "  audit deny /var/www/wpcaregrid-production/{,**} rwklm,\n"
                                      "  audit deny /var/www/wpcaregrid-production/{,**} x,\n", ""),
                          "lacks audited deny of /var/www/wpcaregrid-production/{,**}")
        self.assertCaught(self.mutate(jobs, "profile wpcaregrid-php-cli-staging flags=",
                                      "profile wpcaregrid-php-cli-staging /usr/local/bin/wpcaregrid-scheduler flags="), "header")
        self.assertCaught(self.mutate("systemd/supervisor.service.d/90-wpcaregrid-confinement.conf",
                                      "AppArmorProfile=wpcaregrid-php-cli-staging", "AppArmorProfile=-wpcaregrid-php-cli-staging"),
                          "AppArmorProfile")
        self.assertCaught(self.mutate("systemd/nginx.service.d/90-wpcaregrid-confinement.conf",
                                      "-/var/lib/wpcaregrid-deploy/production/shared ", ""), "InaccessiblePaths")

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
            original = re.sub(r"^profile (\S+) /\S+ flags=", r"profile \1 flags=", original, flags=re.MULTILINE)  # no attachment in the probe
            before, after = original.splitlines(), derived.splitlines()
            normalized = [line.replace(f"{name}-probe", name) for line in after]
            added = [line for line in normalized if line not in before]
            added_rules = [line.strip() for line in added if line.strip() and not line.strip().startswith("#") and not line.lstrip().startswith("profile ") and "peer=" not in line]
            probe_additions = {"/usr/bin/python3* mrix,", "/usr/lib/python3*/{,**} mr,", "/proc/[0-9]*/attr/current r,"}
            self.assertLessEqual(set(added_rules), probe_additions, name)
            self.assertIn("/proc/[0-9]*/attr/current r,", added_rules)
            if name != "wpcaregrid-php-cli-staging":  # the job profile already grants python (supervisord is python)
                self.assertEqual(set(added_rules), probe_additions, name)
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
        doc = json.loads(result.stdout)
        self.assertEqual({k: doc[k] for k in ("errno", "marker_ok", "state")}, {"errno": 0, "marker_ok": True, "state": "read"})
        self.assertTrue(doc["t0"] <= doc["t1"])
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
            self.assertEqual({k: outcome[k] for k in ("state", "stage", "errno", "read_ok")},
                             {"state": "received", "stage": "read", "errno": 0, "read_ok": True})
            self.assertTrue(outcome["t0"] <= outcome["t1"])
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

    def test_every_live_fixture_check_is_dac_reachable_for_uid_gid_33(self) -> None:
        """Review: an unreachable fixture ancestor (bootstrap 0750 root:root) turns allow checks into false FAILs
        and deny checks into 'baseline could not reach' even though the profile is right."""
        fixtures = self.matrix["fixtures"]
        dirs = {e["path"]: e for e in fixtures["mkdirs"]}
        files = {e["path"]: e for e in fixtures["files"]}
        merged: dict = {}
        for spec in self.matrix["profiles"].values():
            merged.update(spec["layout"]["symlinks"])
        symlink_targets = {e["path"]: e["target"] for e in fixtures["symlinks"]}

        def grants(entry, bit):  # bit: 4 read, 2 write, 1 search/execute; uid/gid 33 is the actor
            mode = int(entry["mode"], 8) & 0o777
            klass = (mode >> 6) if entry["uid"] == 33 else ((mode >> 3) if entry["gid"] == 33 else mode)
            return bool(klass & bit)
        real = ("/var/www/wpcaregrid-staging-new", "/var/lib/wpcaregrid-deploy/staging-next", "/var/www/wpcaregrid/", "/var/lib/wpcaregrid/", "/usr/", "/run/php/")
        checked = 0
        for name, spec in self.matrix["profiles"].items():
            for check in spec["checks"]:
                if not check.get("live", True) or check.get("probe_scope") or check["path"].startswith(real):
                    continue
                path = resolve_virtual(merged, check["path"])
                if path.rstrip("/") not in dirs and path not in files and str(Path(path).parent) not in dirs:
                    continue  # not a fixture path (system path with its own host modes)
                node, parent = (path, str(Path(path).parent)) if path in files else (path.rstrip("/"), str(Path(path.rstrip("/")).parent))
                ancestors = []
                walker = Path(node).parent
                while str(walker) != "/":
                    ancestors.append(str(walker))
                    walker = walker.parent
                for ancestor in ancestors:
                    if ancestor in dirs:
                        self.assertTrue(grants(dirs[ancestor], 1), f"{name}/{check['id']}: ancestor {ancestor} is not searchable by 33:33 ({dirs[ancestor]})")
                want = {"r": 4, "rw": 6, "w": 2}.get(check["access"], 4)
                if path in files:
                    if check["access"] in ("r", "rw"):
                        self.assertTrue(grants(files[path], 4), f"{name}/{check['id']}: {path} not readable by 33:33")
                elif check["access"] in ("w", "rw"):  # creating a probe file needs write+search on the parent directory
                    self.assertTrue(grants(dirs[parent], 3) if parent in dirs else True, f"{name}/{check['id']}: cannot create in {parent}")
                elif path.rstrip("/") in dirs:
                    self.assertTrue(grants(dirs[path.rstrip("/")], 4), f"{name}/{check['id']}: {path} not readable by 33:33")
                checked += 1
        self.assertGreater(checked, 40)

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


def fake_proc(base: Path, processes: list[dict], units: dict[str, list[int]], boot: str = "0123abcd-0000-4000-8000-000000000001") -> tuple[str, str]:
    proc, cgroup = base / "proc", base / "cgroup"
    (proc / "sys/kernel/random").mkdir(parents=True, exist_ok=True)
    (proc / "sys/kernel/random/boot_id").write_text(boot + "\n")
    for p in processes:
        d = proc / str(p["pid"])
        (d / "attr").mkdir(parents=True)
        (d / "fd").mkdir()
        uids = p.get("uids") or [p["uid"]] * 4
        (d / "status").write_text(f"Name:\t{p['comm']}\nUid:\t" + "\t".join(map(str, uids)) + "\nGid:\t" + "\t".join(map(str, p.get("gids", uids)))
                                  + "\nGroups:\t" + " ".join(map(str, p.get("groups", []))) + "\n")
        (d / "comm").write_text(p["comm"] + "\n")
        (d / "cmdline").write_bytes(p.get("cmdline", f"/usr/sbin/{p['comm']}\0--flag\0").encode())
        (d / "stat").write_text(f"{p['pid']} ({p['comm']}) S {p.get('ppid', 1)} " + " ".join(["0"] * 17) + f" {p['start']} 0\n")
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

    PROD = "wpcaregrid-production-php8.4-fpm.service"

    def tree(self, processes, units):
        base = Path(tempfile.mkdtemp(prefix="wpcg-fakeproc-"))
        self.addCleanup(shutil.rmtree, base, True)
        proc, cgroup = fake_proc(base, processes, units)
        return base, proc, cgroup

    def run_collect(self, processes, units, **kw):
        base, proc, cgroup = self.tree(processes, units)
        return base, self.tool.collect(self.units, proc, cgroup, pause=0, matrix_sha256="m" * 64, **kw)

    def base_processes(self):
        return [
            {"pid": 10, "uid": 33, "comm": "php-fpm8.4", "label": "wpcaregrid-php-fpm-production (enforce)", "start": 555, "unit": self.PROD,
             "fds": ["socket:[1]"]},
            {"pid": 11, "uid": 0, "comm": "root-thing", "label": "unconfined", "start": 1, "unit": "x.service"},
            {"pid": 12, "uid": 33, "comm": "nginx", "label": "unconfined", "start": 7, "unit": "nginx.service"},
            {"pid": 20, "uid": 0, "comm": "php-fpm8.5", "label": "wpcaregrid-php-fpm-staging-next (enforce)", "start": 90, "unit": "php8.5-fpm.service"},
            {"pid": 21, "uids": [33, 33, 33, 33], "ppid": 20, "comm": "php-fpm8.5", "label": "wpcaregrid-php-fpm-staging-next (enforce)", "start": 91,
             "unit": "php8.5-fpm.service"},
        ]

    UNITS = {PROD: [10], "nginx.service": [12], "php8.5-fpm.service": [20, 21]}

    def test_inventory_includes_root_masters_every_uid33_worker_and_two_stable_scans(self) -> None:
        _, inv = self.run_collect(self.base_processes(), self.UNITS)
        self.assertTrue(inv["complete"], inv["reasons"])
        self.assertEqual([p["pid"] for p in inv["processes"]], [10, 12, 20, 21])  # pid 11 is unrelated root: not inventoried
        master = next(p for p in inv["processes"] if p["pid"] == 20)
        self.assertEqual((master["uids"], master["label"], master["unit"], master["starttime"], master["ppid"]),
                         ([0, 0, 0, 0], "wpcaregrid-php-fpm-staging-next (enforce)", "php8.5-fpm.service", 90, 1))
        self.assertEqual(inv["scans"], 2)
        self.assertEqual(inv["matrix_sha256"], "m" * 64)
        self.assertEqual(inv["boot_id"], "0123abcd-0000-4000-8000-000000000001")
        self.assertTrue(inv["collected_start_ns"] <= inv["collected_end_ns"])
        self.assertEqual(next(p for p in inv["processes"] if p["pid"] == 10)["fds"], ["socket:[1]"])
        self.assertEqual(sorted(inv["units"]), sorted(set(self.units.values())))
        self.assertFalse(inv["units"]["php8.4-fpm.service"]["present"])

    def test_inventory_carries_the_fields_the_canonical_contract_consumes(self) -> None:
        procs = self.base_processes()
        procs[0]["cmdline"] = "/usr/sbin/php-fpm8.4\0--nodaemonize\0"
        _, inv = self.run_collect(procs, self.UNITS)
        worker = next(p for p in inv["processes"] if p["pid"] == 10)
        self.assertEqual(worker["state"], "S")
        self.assertEqual(worker["argv0"], "/usr/sbin/php-fpm8.4")
        self.assertEqual(worker["cmdline_sha256"], hashlib.sha256(b"/usr/sbin/php-fpm8.4\0--nodaemonize\0").hexdigest())
        self.assertEqual(worker["cgroup"], "0::/system.slice/wpcaregrid-production-php8.4-fpm.service")
        self.assertEqual(inv["incomplete_reasons"], inv["reasons"])
        for p in inv["processes"]:
            self.assertTrue({"pid", "ppid", "state", "uids", "gids", "groups", "comm", "argv0", "cmdline_sha256", "starttime", "label", "unit",
                             "cgroup", "exe", "fds"} <= set(p))

    def test_incomplete_reasons_mirrors_reasons_when_a_scan_fails(self) -> None:
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
        Path(proc, "21", "cmdline").unlink()
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)
        self.assertFalse(inv["complete"])
        self.assertEqual(inv["incomplete_reasons"], inv["reasons"])
        self.assertTrue(inv["incomplete_reasons"])

    def test_a_process_holding_gid_33_or_supplementary_group_33_is_a_peer(self) -> None:
        """The secrets are root:33 0640: a deploy shell in the www-data group reads them without being uid 33."""
        procs = self.base_processes() + [
            {"pid": 40, "uids": [1001, 1001, 1001, 1001], "gids": [1001, 33, 1001, 33], "comm": "bash", "label": "unconfined", "start": 6, "unit": None},
            {"pid": 41, "uids": [1002, 1002, 1002, 1002], "gids": [1002] * 4, "groups": [4, 33], "comm": "make", "label": "unconfined", "start": 8, "unit": None},
            {"pid": 42, "uids": [1003] * 4, "gids": [1003] * 4, "groups": [4, 100], "comm": "vim", "label": "unconfined", "start": 9, "unit": None}]
        _, inv = self.run_collect(procs, self.UNITS)
        listed = {p["pid"]: p for p in inv["processes"]}
        self.assertIn(40, listed)
        self.assertIn(41, listed)
        self.assertEqual(listed[41]["groups"], [4, 33])
        self.assertNotIn(42, listed)

    def test_a_process_with_any_uid_field_33_is_a_peer(self) -> None:
        procs = self.base_processes() + [{"pid": 30, "uids": [0, 0, 33, 0], "comm": "sudo", "label": "unconfined", "start": 5, "unit": None}]
        _, inv = self.run_collect(procs, self.UNITS)
        self.assertIn(30, [p["pid"] for p in inv["processes"]])

    def test_churn_between_scans_is_incomplete_but_a_single_blip_is_retried(self) -> None:
        state = {"calls": 0}
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)

        def spawn(attempt):
            state["calls"] += 1
            extra = Path(proc) / "99"
            if not extra.exists():
                (extra / "attr").mkdir(parents=True)
                (extra / "fd").mkdir()
                (extra / "status").write_text("Name:\tphp\nUid:\t33\t33\t33\t33\nGid:\t33\t33\t33\t33\n")
                (extra / "comm").write_text("php\n")
                (extra / "cmdline").write_bytes(b"php\0")
                (extra / "stat").write_text("99 (php) S 1 " + " ".join(["0"] * 17) + " 4242 0\n")
                (extra / "attr/current").write_text("unconfined\n")
                (extra / "cgroup").write_text("0::/user.slice\n")
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, between=spawn)
        self.assertTrue(inv["complete"], inv["reasons"])  # attempt 1 saw churn, attempt 2 was stable (pid 99 now permanent)
        self.assertIn(99, [p["pid"] for p in inv["processes"]])
        self.assertEqual(state["calls"], 2)

    def test_persistent_churn_or_pid_reuse_makes_the_inventory_incomplete(self) -> None:
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
        counter = {"n": 0}

        def reuse(attempt):
            counter["n"] += 1
            Path(proc, "10", "stat").write_text(f"10 (php-fpm8.4) S 1 " + " ".join(["0"] * 17) + f" {9000 + counter['n']} 0\n")
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, between=reuse, attempts=3)
        self.assertFalse(inv["complete"])
        self.assertTrue(any("churn" in r for r in inv["reasons"]), inv["reasons"])
        self.assertEqual(counter["n"], 3)

    def test_boot_change_between_scans_is_incomplete(self) -> None:
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
        flip = lambda attempt: Path(proc, "sys/kernel/random/boot_id").write_text(f"flip-{attempt}\n")  # noqa: E731
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, between=flip, attempts=2)
        self.assertFalse(inv["complete"])
        self.assertTrue(any("boot id" in r for r in inv["reasons"]))

    @unittest.skipIf(os.geteuid() == 0, "root can read mode-000 files")
    def test_unreadable_process_data_makes_the_inventory_incomplete(self) -> None:
        for victim in ("attr/current", "status", "stat", "cgroup"):
            base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
            Path(proc, "21", victim).chmod(0)
            inv = self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)
            self.assertFalse(inv["complete"], victim)
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
        Path(proc, "20", "fd").chmod(0)  # an unreadable root MASTER's descriptors
        self.assertFalse(self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)["complete"])

    def test_cgroup_membership_must_agree_with_the_scan_in_both_directions(self) -> None:
        # cgroup.procs lists a pid whose own cgroup file names another unit
        base, proc, cgroup = self.tree(self.base_processes(), {**self.UNITS, "supervisor.service": [12]})
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)
        self.assertFalse(inv["complete"])
        # a process claims a known unit but is absent from that unit's cgroup.procs
        base, proc, cgroup = self.tree(self.base_processes(), {self.PROD: [10], "nginx.service": [12], "php8.5-fpm.service": [20]})
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)
        self.assertFalse(inv["complete"])
        self.assertTrue(any("not in its cgroup.procs" in r for r in inv["reasons"]))
        # a cgroup.procs member the scan never saw
        base, proc, cgroup = self.tree(self.base_processes(), {**self.UNITS, "php8.5-fpm.service": [20, 21, 22]})
        Path(proc, "22").mkdir()
        self.assertFalse(self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)["complete"])

    def test_vanished_process_is_benign_only_when_both_scans_agree(self) -> None:
        base, proc, cgroup = self.tree(self.base_processes(), self.UNITS)
        shutil.rmtree(Path(proc, "21", "fd"))
        self.assertFalse(self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2)["complete"])  # live process, fd dir gone
        shutil.rmtree(Path(proc, "21"))
        inv = self.tool.collect(self.units, proc, cgroup, pause=0, attempts=2, matrix_sha256="m" * 64)
        self.assertFalse(inv["complete"])  # but it is still named by cgroup.procs: not silently dropped

    def test_unit_list_comes_from_the_matrix_not_from_a_caller(self) -> None:
        source = (ACCEPTANCE / "collect-peers.py").read_text()
        self.assertIn('collect(matrix["role_units"]', source)
        self.assertIn("matrix_sha256=hashlib.sha256(text.encode()).hexdigest()", source)

    def test_production_surface_distinguishes_fixture_from_real_files(self) -> None:
        canary = json.loads(MATRIX_TEXT)["canary"]
        root = Path(tempfile.mkdtemp(prefix="wpcg-surface-"))
        self.addCleanup(shutil.rmtree, root, True)
        (root / "releases/r1/public").mkdir(parents=True)
        (root / "releases/r1/bootstrap/cache").mkdir(parents=True)
        (root / "releases/r1/public/index.php").write_text("<?php // public, exempt\n")
        (root / "releases/r1/bootstrap/cache/config.php").write_text(canary)
        glob = str(root / "releases/*/public")
        none = self.tool.production_surface([str(root)], canary, glob)
        self.assertEqual((none["real_files"], none["fixture_files"], none["roots_present"]), ([], 1, True))
        (root / "releases/r1/bootstrap/cache/config.php").write_text("APP_KEY=base64:real\n")
        real = self.tool.production_surface([str(root)], canary, glob)
        self.assertEqual(real["real_files"], [str(root / "releases/r1/bootstrap/cache/config.php")])
        absent = self.tool.production_surface([str(root / "nope")], canary, glob)
        self.assertFalse(absent["roots_present"])

    def test_script_is_read_only(self) -> None:
        source = (ACCEPTANCE / "collect-peers.py").read_text()
        for needle in ("os.kill", "ptrace", "os.remove", "os.unlink", "os.rename", "os.chmod", "os.chown", "subprocess", 'open(path, "w'):
            self.assertNotIn(needle, source)


T0 = 1_760_000_000 * 10**9
BOOT_UUID = "0123abcd-0000-4000-8000-0000000000aa"
PIDS = {"production": 2101, "staging-next": 2201, "staging-legacy": 2301, "nginx": 2401, "jobs-supervisor": 2501}
MASTERS = {"staging-next": 2200, "staging-legacy": 2300, "nginx": 2400, "jobs-supervisor": 2500}


def audit_json(ts_ns: int, serial: int, boot: str = BOOT_UUID, journal_skew_ns: int = 3_000_000, **fields) -> str:
    sec, nsec = divmod(ts_ns, 10**9)
    body = " ".join(f'{k}="{v}"' if isinstance(v, str) else f"{k}={v}" for k, v in fields.items())
    message = f'audit: type=1400 audit({sec}.{nsec // 1_000_000:03d}:{serial}): apparmor="DENIED" {body}'
    return json.dumps({"_BOOT_ID": boot.replace("-", ""), "__REALTIME_TIMESTAMP": str((ts_ns + journal_skew_ns) // 1000), "MESSAGE": message})


def synthetic_run(profile: str, phase: str = "post"):
    """A coherent baseline/confined/inventory/audit set built from the verifier's own expectations."""
    verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_synth")
    matrix = json.loads(MATRIX_TEXT)
    spec = matrix["profiles"][profile]
    excluded = set(matrix["phases"][phase]["excluded_roles"])
    peers = {role: [PIDS[role]] for role in spec["peer_roles"] if role not in excluded}
    processes = []
    for role, pid in PIDS.items():
        if role in excluded:
            continue
        label = f"{matrix['role_profiles'][role]} (enforce)"
        processes.append({"pid": pid, "ppid": MASTERS.get(role, 1), "uids": [33] * 4, "gids": [33] * 4, "comm": "php-fpm", "starttime": 900 + pid,
                          "label": label, "unit": matrix["role_units"][role], "exe": "/usr/sbin/php-fpm",
                          "fds": ["socket:[1]", "pipe:[2]", "/dev/null"]})
        if role in MASTERS:
            processes.append({"pid": MASTERS[role], "ppid": 1, "uids": [0] * 4, "gids": [0] * 4, "comm": "master", "starttime": 800 + pid,
                              "label": label, "unit": matrix["role_units"][role], "exe": "/usr/sbin/master", "fds": ["socket:[3]", "/dev/null"]})
    units = {u: {"present": False, "pids": []} for u in matrix["role_units"].values()}
    for p in processes:
        units[p["unit"]] = {"present": True, "pids": sorted(units[p["unit"]]["pids"] + [p["pid"]])}

    def inventory(start: int):
        return {"schema": 2, "complete": True, "reasons": [], "matrix_sha256": hashlib.sha256(MATRIX_TEXT.encode()).hexdigest(),
                "boot_id": BOOT_UUID, "collected_start_ns": start, "collected_end_ns": start + 500_000_000, "scans": 2,
                "processes": copy.deepcopy(processes), "units": copy.deepcopy(units),
                "production_surface": {"roots_present": True, "real_files": [], "fixture_files": 14}}
    inv_before, inv_after = inventory(T0), inventory(T0 + 7 * 10**9)
    expected, _ = verifier.expected_checks(matrix, profile, peers, phase)
    probe = f"{profile}-probe"
    audit: list = []
    serial = [1000]

    def event(ts: int, **fields) -> None:
        serial[0] += 1
        audit.append(audit_json(ts, serial[0], profile=probe, comm="python3", **fields))

    def doc(mode: str, pid: int, start: int, label: str) -> dict:
        return {"schema": 2, "mode": mode, "profile": profile, "label": label, "pid": pid, "starttime_ticks": 123456,
                "kernel_start_ns": start, "started_ns": start, "ended_ns": start + 10**9, "boot_id": BOOT_UUID,
                "matrix_sha256": hashlib.sha256(MATRIX_TEXT.encode()).hexdigest(), "peers": peers,
                "scm_socket": "/tmp/wpcg-probe-n/scm.sock", "results": []}
    baseline = doc("baseline", 100, T0 + 2 * 10**9, "unconfined")
    confined = doc("confined", 200, T0 + 4 * 10**9, f"{probe} (enforce)")
    child, exec_pid = 777, 3000
    for run, run_doc in (("baseline", baseline), ("confined", confined)):
        cursor = run_doc["started_ns"] + 10_000_000
        for ident, (kind, item) in expected.items():
            t0, t1 = cursor, cursor + 5_000_000
            cursor += 10_000_000
            allow = kind == "control" or (kind in ("file", "socket", "net") and item["expect"] == "allow")
            if run == "baseline":
                errno_value = EFAULT_ if kind == "peer-vm" else 0
            else:
                errno_value = 0 if allow else (EPERM if kind == "peer-vm" else EACCES)
            actor = 200 if run == "confined" else 100
            if kind == "file" and item["access"] == "x":
                exec_pid += 1
                actor = exec_pid
            entry = {"id": ident, "kind": kind, "target": "", "errno": errno_value, "pid": actor, "t0": t0, "t1": t1}
            if kind == "socket" and item.get("banner"):
                entry["banner_ok"] = run == "baseline"
            if ident.startswith(("calibration:child", "control:self")):
                entry["child"] = child
            run_doc["results"].append(entry)
            if run == "confined" and not allow:
                mid = (t0 + t1) // 2
                if kind == "file":
                    event(mid, operation="exec" if item["access"] == "x" else "open", name=item["path"], pid=actor)
                elif kind == "socket":
                    event(mid, operation="connect", name=item["path"], pid=actor)
                elif kind == "symlink":
                    event(mid, operation="open", name=item["path"], pid=actor)
                elif kind == "abstract":
                    event(mid, operation="connect", addr="@wpcg-probe-x", pid=actor)
                elif kind == "net":
                    event(mid, operation={"create":"create","bind":"bind","connect":"connect"}[item["action"]], class_="net",
                          family={"AF_INET": "inet", "AF_INET6": "inet6", "AF_UNIX": "unix", "AF_NETLINK": "netlink"}[item["family"]],
                          sock_type=item["type"].removeprefix("SOCK_").lower(), pid=actor)
                elif kind in ("peer", "peer-vm"):
                    event(mid, operation="ptrace", peer=matrix["role_profiles"][item["role"]], pid=actor)
                elif kind == "peer-signal":
                    event(mid, operation="signal", peer=matrix["role_profiles"][item["role"]], pid=actor)
                elif ident == "calibration:child-environ":
                    event(mid, operation="open", name=f"/proc/{child}/environ", pid=actor)
                else:
                    event(mid, operation="ptrace", peer=probe, pid=actor)
        scm_t0 = cursor + 10_000_000
        run_doc["fd0"] = {"state": "read", "errno": 0 if run == "baseline" else EACCES, "marker_ok": run == "baseline",
                          "t0": scm_t0 - 2_000_000, "t1": scm_t0 - 1_000_000}
        if run == "baseline":
            run_doc["scm"] = {"state": "received", "stage": "read", "errno": 0, "read_ok": True, "t0": scm_t0, "t1": scm_t0 + 5_000_000}
        else:
            run_doc["scm"] = {"state": "received", "stage": "read", "errno": EACCES, "read_ok": False, "t0": scm_t0, "t1": scm_t0 + 5_000_000}
            event(scm_t0 + 2_000_000, operation="file_receive", name=matrix["scm"]["canary"], pid=200)
        run_doc["ended_ns"] = scm_t0 + 6_000_000
    ctx = {"probe": probe, "serial": serial, "events": audit}
    return verifier, matrix, baseline, confined, inv_before, inv_after, "\n".join(audit), ctx


EACCES, EPERM, EFAULT_ = 13, 1, 14


class VerifyAcceptance(unittest.TestCase):
    profile = "wpcaregrid-php-fpm-production"

    def run_verify(self, mutate=None, profile=None, phase="post", ack=False, verify_phase=None):
        profile = profile or self.profile
        verifier, matrix, baseline, confined, before, after, audit, ctx = synthetic_run(profile, phase)
        phase = verify_phase or phase
        state = {"baseline": copy.deepcopy(baseline), "confined": copy.deepcopy(confined), "before": copy.deepcopy(before),
                 "after": copy.deepcopy(after), "audit": audit, "ctx": ctx, "matrix": MATRIX_TEXT}
        if mutate:
            mutate(state)
        return verifier.verify(state["matrix"], profile, phase, state["baseline"], state["confined"], state["before"], state["after"],
                               state["audit"], ack)

    def assertFails(self, verdict, needle: str) -> None:
        self.assertTrue(any(needle in f for f in verdict.failures), verdict.failures[:6])

    def result(self, state, which, ident):
        return next(r for r in state[which]["results"] if r["id"] == ident)

    def lines(self, state):
        return state["audit"].split("\n")

    # ---- coherent runs -------------------------------------------------------------------------------
    def test_coherent_run_passes_for_every_profile_post_production(self) -> None:
        for profile in PROFILES:
            verdict = self.run_verify(profile=profile)
            self.assertEqual(verdict.failures, [], profile)
            self.assertTrue(verdict.passes)

    def test_pre_production_phase_has_no_production_peer_and_still_passes(self) -> None:
        """QA (A): the acceptance gate must not need the production unit that is only allowed to start afterwards."""
        for profile in PROFILES:
            if profile == "wpcaregrid-php-fpm-production":
                continue
            verdict = self.run_verify(profile=profile, phase="pre")
            self.assertEqual(verdict.failures, [], profile)
        pre_ids = synthetic_run("wpcaregrid-nginx", "pre")[2]["results"]
        self.assertFalse([r for r in pre_ids if ":production:" in r["id"]])

    def test_pre_phase_blocks_if_production_already_exists(self) -> None:
        def production_running(s):
            for key in ("before", "after"):
                s[key]["processes"].append({"pid": 2101, "ppid": 1, "uids": [33] * 4, "gids": [33] * 4, "comm": "php-fpm", "starttime": 3001,
                                            "label": "wpcaregrid-php-fpm-production (enforce)", "unit": "wpcaregrid-production-php8.4-fpm.service",
                                            "exe": "x", "fds": []})
                s[key]["units"]["wpcaregrid-production-php8.4-fpm.service"] = {"present": True, "pids": [2101]}
        self.assertFails(self.run_verify(production_running, profile="wpcaregrid-nginx", phase="pre"), "production unit already has processes")

        def secrets(s):
            s["before"]["production_surface"]["real_files"] = ["/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php"]
        self.assertFails(self.run_verify(secrets, profile="wpcaregrid-nginx", phase="pre"), "real (non-fixture) production files exist")

        def no_scan(s):
            s["before"]["production_surface"] = None
        self.assertFails(self.run_verify(no_scan, profile="wpcaregrid-nginx", phase="pre"), "scan is missing")
        self.assertFails(self.run_verify(profile="wpcaregrid-php-fpm-production", phase="pre"), "phase post")
        self.assertFails(self.run_verify(profile="wpcaregrid-nginx", verify_phase="nope"), "unknown phase")

    def test_post_phase_requires_the_production_peer(self) -> None:
        def drop(s):
            s["confined"]["peers"].pop("production", None)
            s["baseline"]["peers"].pop("production", None)
        self.assertFails(self.run_verify(drop, profile="wpcaregrid-nginx"), "no worker pid")

    # ---- review (4)/(E): errno alone never passes -----------------------------------------------------
    def test_errno_alone_is_never_a_pass(self) -> None:
        verdict = self.run_verify(lambda s: s.update(audit=""))
        self.assertFails(verdict, "no AppArmor DENIED record binds to this call")
        self.assertFalse([p for p in verdict.passes if "audit=DENIED" in p])

    def test_audit_must_match_profile_pid_operation_and_target(self) -> None:
        for what, edit in {
                "profile": lambda a: a.replace(f'profile=\\"{self.profile}-probe\\"', 'profile=\\"other\\"'),
                "pid": lambda a: a.replace("pid=200", "pid=999"),
                "operation": lambda a: a.replace('operation=\\"open\\"', 'operation=\\"unlink\\"'),
                "name": lambda a: a.replace('name=\\"/var/lib/wpcaregrid-deploy/staging-next/shared/.env\\"', 'name=\\"/other\\"')}.items():
            with self.subTest(what=what):
                verdict = self.run_verify(lambda s, edit=edit: s.update(audit=edit(s["audit"])))
                self.assertFails(verdict, "staging-env-read" if what == "name" else "no AppArmor DENIED record binds")

    # ---- review (5)/(H): boot, interval, lifetime, serial, window -------------------------------------
    def test_stale_audit_event_outside_the_run_is_rejected(self) -> None:
        stale = audit_json(T0 - 3600 * 10**9, 5, profile=f"{self.profile}-probe", operation="open", name="/etc/hosts", pid=200, comm="python3")
        self.assertFails(self.run_verify(lambda s: s.update(audit=stale + "\n" + s["audit"])), "stale record")
        late = audit_json(T0 + 3600 * 10**9, 6, profile=f"{self.profile}-probe", operation="open", name="/etc/hosts", pid=200, comm="python3")
        self.assertFails(self.run_verify(lambda s: s.update(audit=s["audit"] + "\n" + late)), "stale record")

    def test_a_replayed_old_run_cannot_satisfy_this_run(self) -> None:
        """Every event moved an hour back (a captured earlier run) must not pass for this run."""
        def replay(s):
            moved = []
            for line in s["audit"].split("\n"):
                entry = json.loads(line)
                entry["__REALTIME_TIMESTAMP"] = str(int(entry["__REALTIME_TIMESTAMP"]) - 3600 * 10**6)
                entry["MESSAGE"] = re.sub(r"audit\((\d+)\.", lambda m: f"audit({int(m.group(1)) - 3600}.", entry["MESSAGE"])
                moved.append(json.dumps(entry))
            s["audit"] = "\n".join(moved)
        verdict = self.run_verify(replay)
        self.assertFails(verdict, "stale record")
        self.assertFalse([p for p in verdict.passes if "audit=DENIED" in p])

    def test_events_from_another_boot_are_rejected(self) -> None:
        def reboot(s):
            s["audit"] = s["audit"].replace(BOOT_UUID.replace("-", ""), "f" * 32)
        verdict = self.run_verify(reboot)
        self.assertFails(verdict, "another boot")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(boot_id="feedface-0000-4000-8000-000000000000")), "one boot")

    def test_repeated_audit_serial_or_stamp_skew_is_rejected(self) -> None:
        self.assertFails(self.run_verify(lambda s: s.update(audit=s["audit"] + "\n" + s["audit"].split("\n")[0])), "repeated audit serial")
        skew = lambda s: s.update(audit="\n".join(json.dumps({**json.loads(l), "__REALTIME_TIMESTAMP": str(int(json.loads(l)["__REALTIME_TIMESTAMP"]) + 120 * 10**6)}) for l in s["audit"].split("\n")))  # noqa: E731
        self.assertFails(self.run_verify(skew), "disagree")

    def test_plain_text_or_malformed_audit_is_not_accepted(self) -> None:
        plain = lambda s: s.update(audit="\n".join(json.loads(l)["MESSAGE"] for l in s["audit"].split("\n")))  # noqa: E731
        self.assertFails(self.run_verify(plain), "not a journalctl -o json record")
        self.assertFails(self.run_verify(lambda s: s.update(audit=s["audit"] + "\n{broken")), "not a journalctl -o json record")
        nostamp = lambda s: s.update(audit=s["audit"].replace("audit(", "au("))  # noqa: E731
        self.assertFails(self.run_verify(nostamp), "without pid or audit stamp")

    def test_an_event_in_another_checks_window_does_not_satisfy_this_check(self) -> None:
        """Reused pid/target in a different moment: one denied open of the same name cannot stand for two checks."""
        def shift(s):
            target = next(l for l in s["audit"].split("\n") if "staging-next/shared/.env" in l and "operation=\\\"open\\\"" in l)
            entry = json.loads(target)
            entry["MESSAGE"] = re.sub(r"audit\((\d+)\.\d+:", lambda m: f"audit({int(m.group(1)) + 2}.000:", entry["MESSAGE"])
            entry["__REALTIME_TIMESTAMP"] = str(int(entry["__REALTIME_TIMESTAMP"]) + 2 * 10**6 * 1000)
            s["audit"] = s["audit"].replace(target, json.dumps(entry))
        self.assertTrue(self.run_verify(shift).failures)

    def test_one_event_cannot_satisfy_two_checks(self) -> None:
        def collapse(s):
            for ident in ("staging-env-read", "symlink-escape:0"):  # both name the same target
                pass
            first = self.result(s, "confined", "staging-env-read")
            second = self.result(s, "confined", "symlink-escape:0")
            second["t0"], second["t1"] = first["t0"], first["t1"]
        verdict = self.run_verify(collapse)
        self.assertTrue(verdict.failures)

    def test_probe_lifetime_is_bound_to_its_kernel_start_time_and_pid(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["confined"].update(kernel_start_ns=s["confined"]["started_ns"] + 3600 * 10**9)), "kernel start time")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(started_ns=s["confined"]["ended_ns"] + 1)), "kernel start time")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(pid=100, starttime_ticks=s["baseline"]["starttime_ticks"])), "same process")

    def test_denials_by_a_pid_that_is_not_the_probe_or_its_child_are_rejected(self) -> None:
        extra = lambda s: s.update(audit=s["audit"] + "\n" + audit_json(T0 + 4 * 10**9 + 100_000_000, 9001, profile=f"{self.profile}-probe",  # noqa: E731
                                                                          operation="open", name="/x", pid=424242, comm="python3"))
        self.assertFails(self.run_verify(extra), "not the probe or one of its children")

    # ---- QA (C): exec child identity -------------------------------------------------------------------
    def test_exec_denial_is_attributed_to_the_child_pid(self) -> None:
        verdict = self.run_verify()
        self.assertEqual(verdict.failures, [])
        run = synthetic_run(self.profile)[3]
        exec_results = [r for r in run["results"] if r["id"].startswith("exec-")]
        self.assertTrue(exec_results)
        self.assertTrue(all(r["pid"] > 3000 for r in exec_results))  # the child, not the probe (200)

        def parent_pid(s):  # the false-PASS the review described: event pid = parent while the call was a child's
            s["audit"] = re.sub(r'(operation=\\"exec\\".*?)pid=\d+', r"\1pid=200", s["audit"])
        self.assertFails(self.run_verify(parent_pid), "exec-shell")

    # ---- baseline / confined / masking -----------------------------------------------------------------
    def test_baseline_must_reach_the_target(self) -> None:
        self.assertFails(self.run_verify(lambda s: self.result(s, "baseline", "staging-env-read").update(errno=EACCES)), "baseline could not reach")
        self.assertFails(self.run_verify(lambda s: self.result(s, "baseline", "symlink-escape:0").update(errno=2)), "baseline could not reach")

    def test_confined_success_on_a_denied_target_fails(self) -> None:
        self.assertFails(self.run_verify(lambda s: self.result(s, "confined", "staging-env-read").update(errno=0)), "confined access not refused")

    def test_allowed_access_must_work_in_both_runs(self) -> None:
        self.assertFails(self.run_verify(lambda s: self.result(s, "confined", "own-env-read").update(errno=EACCES)), "allowed access failed")

    def test_dac_masked_optional_fixture_is_unproven_not_pass_and_never_allowed(self) -> None:
        verdict = self.run_verify(lambda s: self.result(s, "baseline", "fixture-control-plane").update(errno=EACCES))
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("UNPROVEN" in n for n in verdict.notes))
        verdict = self.run_verify(lambda s: (self.result(s, "baseline", "fixture-control-plane").update(errno=EACCES),
                                             self.result(s, "confined", "fixture-control-plane").update(errno=0)))
        self.assertFails(verdict, "confined access succeeded")

    def test_wrong_label_mode_schema_or_matrix_hash_rejects_the_whole_run(self) -> None:
        for edit in (lambda s: s["confined"].update(label="unconfined"), lambda s: s["baseline"].update(label="x (enforce)"),
                     lambda s: s["confined"].update(matrix_sha256="0" * 64), lambda s: s["confined"].update(mode="baseline"),
                     lambda s: s["confined"].update(schema=1), lambda s: s["confined"].update(label=f"{self.profile}-probe (complain)")):
            self.assertFails(self.run_verify(edit), "run:")

    # ---- peers, inventories, masters (G / AppSec 3) ----------------------------------------------------
    def test_incomplete_unstable_or_foreign_inventories_fail_closed(self) -> None:
        for edit in (lambda s: s["before"].update(complete=False), lambda s: s["after"].update(complete=False),
                     lambda s: s["before"].pop("complete"), lambda s: s["before"].update(scans=1), lambda s: s["after"].update(schema=1),
                     lambda s: s["before"].update(matrix_sha256="0" * 64),
                     lambda s: s["after"].update(boot_id="feedface-0000-4000-8000-000000000000")):
            self.assertFails(self.run_verify(edit), "inventory")

    def test_inventory_must_cover_the_package_unit_list_not_a_caller_list(self) -> None:
        def subset(s):
            for key in ("before", "after"):
                s[key]["units"].pop("nginx.service")
        self.assertFails(self.run_verify(subset), "exactly the package's service cgroups")

    def test_an_unconfined_service_master_blocks_even_though_it_is_root(self) -> None:
        for phase_key in ("before", "after"):
            def edit(s, key=phase_key):
                next(p for p in s[key]["processes"] if p["pid"] == MASTERS["staging-next"]).update(label="unconfined")
            verdict = self.run_verify(edit)
            self.assertFails(verdict, "service master or worker is not enforce-attached")
        def wrong_unit(s):
            next(p for p in s["before"]["processes"] if p["pid"] == MASTERS["nginx"]).update(label="wpcaregrid-nginx (complain)")
        self.assertFails(self.run_verify(wrong_unit), "not enforce-attached")

    def test_unknown_uid33_process_blocks_including_cron_and_shells(self) -> None:
        for comm, unit in (("php", "supervisor.service"), ("CRON", "cron.service"), ("sh", "cron.service"), ("dash", "cron.service"),
                           ("nginx", "nginx.service"), ("bash", None), ("sleep", "user@1000.service")):
            stray = {"pid": 4000, "ppid": 1, "uids": [33, 33, 33, 33], "gids": [33] * 4, "comm": comm, "starttime": 1,
                     "label": "unconfined", "unit": unit, "exe": None, "fds": []}
            for phase_key in ("before", "after"):
                verdict = self.run_verify(lambda s, k=phase_key, st=stray: s[k]["processes"].append(copy.deepcopy(st)))
                known = unit in json.loads(MATRIX_TEXT)["role_units"].values()
                self.assertFails(verdict, "carries label" if known else "maps to no permitted class")
        # a pinned crontab is irrelevant: the label rule has no cron/sh exception to pin
        verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_nocron")
        self.assertEqual(verifier.ACCEPTED_UNCONFINED_SAME_UID_COMM, frozenset())
        self.assertNotIn("permitted_unconfined_classes", json.loads(MATRIX_TEXT))
        self.assertNotIn("cron", " ".join(json.loads(MATRIX_TEXT)["role_units"].values()))

    def test_gid_or_supplementary_group_33_holders_block_unless_explicitly_permitted(self) -> None:
        for gids, groups in (([1001, 33, 1001, 33], []), ([1001] * 4, [4, 33])):
            stray = {"pid": 4100, "ppid": 1, "uids": [1001] * 4, "gids": gids, "groups": groups, "comm": "bash", "starttime": 3,
                     "label": "unconfined", "unit": None, "exe": None, "fds": []}
            verdict = self.run_verify(lambda s, st=stray: [s[k]["processes"].append(copy.deepcopy(st)) for k in ("before", "after")])
            self.assertFails(verdict, "maps to no permitted class")
            self.assertFails(verdict, "holds")
        allow = json.loads(MATRIX_TEXT)
        allow["permitted_group33_holders"] = [1001]
        text = json.dumps(allow, indent=2, sort_keys=True) + "\n"
        verifier, matrix, baseline, confined, before, after, audit, ctx = synthetic_run(self.profile)
        for inv in (before, after):
            inv["processes"].append({"pid": 4100, "ppid": 1, "uids": [1001] * 4, "gids": [1001, 33, 1001, 33], "groups": [], "comm": "bash",
                                     "starttime": 3, "label": "unconfined", "unit": None, "exe": None, "fds": []})
        for doc in (baseline, confined):
            doc["matrix_sha256"] = hashlib.sha256(text.encode()).hexdigest()
        for inv in (before, after):
            inv["matrix_sha256"] = hashlib.sha256(text.encode()).hexdigest()
        verdict = verifier.verify(text, self.profile, "post", baseline, confined, before, after, audit)
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("trusted group-33 holder" in n for n in verdict.notes))
        self.assertEqual(json.loads(MATRIX_TEXT)["permitted_group33_holders"], [])

    def test_pid_reuse_or_churn_between_the_two_inventories_is_rejected(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["after"]["processes"][1].update(starttime=1)), "churn or pid reuse")
        self.assertFails(self.run_verify(lambda s: s["after"].update(processes=[p for p in s["after"]["processes"] if p["pid"] != 2201])),
                         "churn or pid reuse")
        self.assertFails(self.run_verify(lambda s: s["after"]["processes"].append(
            {**copy.deepcopy(s["after"]["processes"][0]), "pid": 9999, "starttime": 77})), "churn or pid reuse")
        self.assertFails(self.run_verify(lambda s: s["after"]["processes"].append(copy.deepcopy(s["after"]["processes"][0]))), "duplicate pid")

    def test_inventory_timing_is_bound_around_the_probe_runs(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["before"].update(collected_end_ns=s["confined"]["started_ns"] + 1)), "before inventory was not finished")
        self.assertFails(self.run_verify(lambda s: s["after"].update(collected_start_ns=s["confined"]["ended_ns"] - 1)), "after inventory started before")

    def test_missing_peer_role_or_unit_fails_enumeration(self) -> None:
        for unit in ("php8.5-fpm.service", "php8.4-fpm.service", "wpcaregrid-production-php8.4-fpm.service", "nginx.service"):
            self.assertFails(self.run_verify(lambda s, u=unit: s["before"].update(processes=[p for p in s["before"]["processes"] if p["unit"] != u])),
                             "enumeration incomplete")
        self.assertFails(self.run_verify(lambda s: s["before"]["units"]["php8.5-fpm.service"].update(present=False)), "enumeration incomplete")

    def test_peer_pids_must_be_identified_workers_with_stable_start_times(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["after"]["processes"][1].update(starttime=1)), "start time changed")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(peers={"staging-next": [2201], "staging-legacy": []})), "identical peer pids")
        master = MASTERS["staging-next"]
        self.assertFails(self.run_verify(lambda s: (s["confined"]["peers"].update({"staging-next": [master]}),
                                                    s["baseline"]["peers"].update({"staging-next": [master]}))), "not an enforced uid-33")

    def test_unit_fd_inventory_covers_masters_and_rejects_other_environment_descriptors(self) -> None:
        for target in ("/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "/etc/wpcaregrid-deploy/profiles/x", "/home/deploy/x",
                       "/var/www/wpcaregrid-staging-new/artisan (deleted)"):
            self.assertFails(self.run_verify(lambda s, t=target: s["before"]["processes"][0]["fds"].append(t)), "fd-inventory")
            master = lambda s, t=target: next(p for p in s["before"]["processes"] if p["pid"] == MASTERS["nginx"]).__setitem__("fds", [t])  # noqa: E731
            self.assertFails(self.run_verify(master), "fd-inventory")
        verdict = self.run_verify(lambda s: next(p for p in s["before"]["processes"] if p["pid"] == PIDS["staging-next"])["fds"].append(
            "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log"), profile="wpcaregrid-php-fpm-staging-next")
        self.assertFails(verdict, "fd-inventory")
        self.assertEqual(self.run_verify(lambda s: s["before"]["processes"][0]["fds"].append(
            "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log")).failures, [])

    # ---- fd0 / SCM_RIGHTS (4)/(E) ----------------------------------------------------------------------
    def test_inherited_fd0_is_read_and_judged(self) -> None:
        self.assertFails(self.run_verify(lambda s: s["baseline"].update(fd0={"state": "not-canary-file"})), "fd0")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(fd0={"state": "not-canary-file"})), "fd0")
        verdict = self.run_verify(lambda s: s["confined"].update(fd0={"state": "read", "errno": 0, "marker_ok": True, "t0": 1, "t1": 2}))
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("INHERITED-FD-READABLE" in n for n in verdict.notes))

    def scm(self, st, **fields):
        st["confined"]["scm"] = {**st["confined"]["scm"], **fields}

    def test_scm_enoent_eio_and_nothing_received_are_inconclusive_failures_not_passes(self) -> None:
        """The review's false-PASS: errno ENOENT / read_ok=false with no denial was treated as 'refused'."""
        for fields in ({"state": "received", "stage": "read", "errno": errno_.ENOENT, "read_ok": False},
                       {"state": "received", "stage": "read", "errno": errno_.EIO, "read_ok": False},
                       {"state": "received", "stage": "read", "errno": 0, "read_ok": False},
                       {"state": "no-descriptor"}, {"state": "not-canary-file", "size": 4},
                       {"state": "channel-failed", "stage": "connect", "errno": errno_.ENOENT},
                       {"state": "channel-failed", "stage": "recv", "errno": errno_.EIO},
                       {"state": "channel-failed", "stage": "recv", "errno": errno_.ECONNRESET}):
            verdict = self.run_verify(lambda s, f=fields: self.scm(s, **f))
            self.assertFails(verdict, "scm-rights")
            self.assertFalse([p for p in verdict.passes if p.startswith("PASS scm-rights")], fields)

    def test_scm_denial_needs_its_own_exact_event(self) -> None:
        canary = json.loads(MATRIX_TEXT)["scm"]["canary"]
        no_event = lambda s: s.update(audit="\n".join(l for l in s["audit"].split("\n") if "file_receive" not in l))  # noqa: E731
        self.assertFails(self.run_verify(no_event), "scm-rights")
        unrelated = lambda s: s.update(audit=s["audit"].replace(f'name=\\"{canary}\\"', 'name=\\"/etc/hostname\\"'))  # noqa: E731
        self.assertFails(self.run_verify(unrelated), "scm-rights")
        wrong_op = lambda s: s.update(audit=s["audit"].replace('operation=\\"file_receive\\"', 'operation=\\"ptrace\\"'))  # noqa: E731
        self.assertFails(self.run_verify(wrong_op), "scm-rights")
        wrong_pid = lambda s: s.update(audit=re.sub(r'(file_receive.*?)pid=200', r"\1pid=201", s["audit"]))  # noqa: E731
        self.assertFails(self.run_verify(wrong_pid), "scm-rights")

    def retarget(self, st, operation, name):
        """Rewrite only the descriptor-transfer event (the one with operation file_receive)."""
        st["audit"] = "\n".join(
            line.replace('operation=\\"file_receive\\"', f'operation=\\"{operation}\\"').replace(
                f'name=\\"{json.loads(MATRIX_TEXT)["scm"]["canary"]}\\"', f'name=\\"{name}\\"') if "file_receive" in line else line
            for line in st["audit"].split("\n"))

    def test_scm_channel_failure_requires_the_event_of_the_stage_that_failed(self) -> None:
        sock = "/tmp/wpcg-probe-n/scm.sock"
        # recv refused: needs file_receive on the canary; a connect event for the socket does not count
        recv = lambda s: self.scm(s, state="channel-failed", stage="recv", errno=EACCES)  # noqa: E731
        self.assertEqual(self.run_verify(recv).failures, [])
        self.assertFails(self.run_verify(lambda s: (recv(s), self.retarget(s, "connect", sock))), "scm-rights")
        # connect refused: needs connect on the exact socket path
        connect = lambda s: (self.scm(s, state="channel-failed", stage="connect", errno=EACCES), self.retarget(s, "connect", sock))  # noqa: E731
        self.assertEqual(self.run_verify(connect).failures, [])
        self.assertFails(self.run_verify(lambda s: (self.scm(s, state="channel-failed", stage="connect", errno=EACCES),
                                                    self.retarget(s, "connect", "/tmp/other.sock"))), "scm-rights")
        # a file_receive event for the canary does not explain a failed connect
        self.assertFails(self.run_verify(lambda s: (self.scm(s, state="channel-failed", stage="connect", errno=EACCES))), "scm-rights")

    def test_descriptor_transfer_bypass_fails_unless_explicitly_acknowledged(self) -> None:
        bypass = lambda s: self.scm(s, state="received", stage="read", errno=0, read_ok=True)  # noqa: E731
        self.assertFails(self.run_verify(bypass), "a confined process read a descriptor")
        self.assertTrue(any("acknowledged" in n for n in self.run_verify(bypass, ack=True).notes))
        self.assertFails(self.run_verify(lambda s: s["baseline"].update(scm={"state": "channel-failed", "stage": "connect", "errno": 2, "t0": 1, "t1": 2})), "scm-rights")

    # ---- /proc exactness (F) ----------------------------------------------------------------------------
    def test_each_proc_check_binds_to_its_exact_target_and_window(self) -> None:
        entries = json.loads(MATRIX_TEXT)["peer_entries"]
        for needed in ("fd/", "fdinfo/", "root/", "cwd/", "exe", "environ", "mem", "maps", "cmdline"):
            self.assertIn(needed, entries)
        pid = PIDS["staging-next"]

        def only_name_events(s):
            """Replace the ptrace events with open events for a DIFFERENT /proc entry of the same pid."""
            s["audit"] = s["audit"].replace('operation=\\"ptrace\\" peer=\\"wpcaregrid-php-fpm-staging-next\\"',
                                            f'operation=\\"open\\" name=\\"/proc/{pid}/status\\"')
        verdict = self.run_verify(only_name_events)
        self.assertFails(verdict, f"peer:staging-next:{pid}:root/")
        exact = lambda s: s.update(audit=s["audit"].replace('operation=\\"ptrace\\" peer=\\"wpcaregrid-php-fpm-staging-next\\"',  # noqa: E731
                                                            'operation=\\"open\\" name=\\"/proc/x\\"'))
        self.assertFails(self.run_verify(exact), "peer:")
        # right operation and peer, but a window that belongs to another call
        other_window = lambda s: self.result(s, "confined", f"peer:staging-next:{pid}:fd/").update(t0=T0, t1=T0 + 10)  # noqa: E731
        self.assertFails(self.run_verify(other_window), f"peer:staging-next:{pid}:fd/")
        self.assertFails(self.run_verify(lambda s: self.result(s, "confined", f"peer:staging-next:{pid}:root/").update(errno=0)),
                         f"peer:staging-next:{pid}:root/")

    # ---- ptrace / process_vm_readv calibration (D) -----------------------------------------------------
    def test_efault_baseline_means_permitted_and_the_calibration_target_must_be_mapped(self) -> None:
        pid = PIDS["staging-next"]
        self.assertEqual(self.result({"baseline": synthetic_run(self.profile)[2]}, "baseline", f"peer:staging-next:{pid}:process_vm_readv")["errno"], EFAULT_)
        self.assertEqual(self.run_verify().failures, [])
        # calibration read of an UNMAPPED address would also return EFAULT: that proves nothing and must fail
        unmapped = lambda s: self.result(s, "baseline", "calibration:child-process_vm_readv").update(errno=EFAULT_)  # noqa: E731
        self.assertFails(self.run_verify(unmapped), "calibration read of a mapped byte did not succeed")
        # a refusal in the baseline is never "reached"
        self.assertFails(self.run_verify(lambda s: self.result(s, "baseline", "calibration:child-ptrace-seize").update(errno=1)), "baseline could not reach")
        # a peer baseline that is neither success, EFAULT nor a refusal is unreached
        self.assertFails(self.run_verify(lambda s: self.result(s, "baseline", f"peer:staging-next:{pid}:process_vm_readv").update(errno=errno_.ESRCH)),
                         "baseline could not reach")

    def test_yama_masked_peer_results_are_void_without_an_apparmor_calibration(self) -> None:
        pid = PIDS["staging-next"]

        def yama(s):
            for who in ("baseline", "confined"):
                self.result(s, who, f"peer:staging-next:{pid}:process_vm_readv").update(errno=EPERM)
        self.assertEqual(self.run_verify(yama).failures, [])

        def yama_uncalibrated(s):
            yama(s)
            s["audit"] = "\n".join(l for l in s["audit"].split("\n") if f'peer=\\"{self.profile}-probe\\"' not in l)
        verdict = self.run_verify(yama_uncalibrated)
        self.assertFails(verdict, "calibration")

    def test_unexpected_denials_and_missing_results_fail(self) -> None:
        extra = audit_json(T0 + 4 * 10**9 + 50_000_000, 9002, profile=f"{self.profile}-probe", operation="open", name="/etc/hosts", pid=200, comm="python3")
        self.assertFails(self.run_verify(lambda s: s.update(audit=s["audit"] + "\n" + extra)), "unexplained-denial")
        self.assertFails(self.run_verify(lambda s: s["confined"].update(results=s["confined"]["results"][1:])), "missing")
        self.assertFails(self.run_verify(lambda s: s["confined"]["results"].append(dict(s["confined"]["results"][0]))), "duplicate")

    def test_kernel_enforcement_is_not_claimed_by_the_verifier(self) -> None:
        source = (ACCEPTANCE / "verify-acceptance.py").read_text()
        self.assertIn("kernel enforcement is shown by the PASS lines above, not by this tool", source)
        self.assertIn("record, not a standing guarantee", source)

    def test_audit_parser_reads_journal_json_and_rejects_everything_else(self) -> None:
        verifier = load_script(ACCEPTANCE / "verify-acceptance.py", "wpcg_verify_parse")
        good = audit_json(T0, 1, profile="p-probe", operation="open", name="/a b", pid=12, comm="python3", requested_mask="r")
        allowed = json.dumps({"_BOOT_ID": "a" * 32, "__REALTIME_TIMESTAMP": "1", "MESSAGE": 'audit: apparmor="ALLOWED" operation="open" pid=3'})
        records, problems = verifier.parse_audit(good + "\n" + allowed + "\n")
        self.assertEqual(problems, [])
        self.assertEqual(len(records), 1)
        self.assertEqual((records[0]["pid"], records[0]["name"], records[0]["profile"], records[0]["_boot"]), (12, "/a b", "p-probe", BOOT_UUID.replace("-", "")))
        self.assertEqual(records[0]["_ts_ns"], T0)
        self.assertTrue(verifier.parse_audit("noise\n")[1])


class ProbeContracts(unittest.TestCase):
    """The probe-side fixes: child output, child identity, mapped vm_readv calibration, stage-aware SCM, timing."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.probe = load_script(ACCEPTANCE / "probe.py", "wpcg_probe_contracts")

    def test_exec_child_output_never_reaches_the_json_stream_and_the_child_pid_is_reported(self) -> None:
        driver = ("import importlib.util,sys,json\n"
                  f"s=importlib.util.spec_from_file_location('p',{str(ACCEPTANCE / 'probe.py')!r})\n"
                  "m=importlib.util.module_from_spec(s); s.loader.exec_module(m)\n"
                  f"r=m.exec_path({sys.executable!r})\n"
                  "missing=m.exec_path('/nonexistent/wpcg')\n"
                  "print(json.dumps([r, missing]))\n")
        result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], capture_output=True, text=True, timeout=30, check=False)
        lines = result.stdout.strip().splitlines()
        self.assertEqual(len(lines), 1, f"the exec'd python must not write its --version output into the probe's stdout: {result.stdout!r}")
        (ok, missing) = json.loads(lines[0])
        self.assertEqual(ok[0], 0)
        self.assertGreater(ok[1], 0)
        self.assertEqual(missing[0], errno_.ENOENT)
        self.assertGreater(missing[1], 0)
        self.assertNotEqual(ok[1], os.getpid())

    def test_vm_readv_distinguishes_permitted_unmapped_from_permitted_mapped(self) -> None:
        self.assertEqual(self.probe.vm_readv(os.getpid(), 0x1000), EFAULT_)  # allowed, but nothing mapped there
        results: list = []
        self.probe.descendant_calibration(results)
        by_id = {r["id"]: r for r in results}
        self.assertEqual(by_id["calibration:child-process_vm_readv"]["errno"], 0, "a mapped byte of the child must really be read")
        for r in results:
            self.assertTrue(r["t0"] <= r["t1"])
            self.assertIsInstance(r["pid"], int)

    def test_network_probe_reports_the_errno_of_each_family_without_sending_anything(self) -> None:
        for family, kind in (("AF_INET", "SOCK_STREAM"), ("AF_INET", "SOCK_DGRAM"), ("AF_UNIX", "SOCK_STREAM"), ("AF_UNIX", "SOCK_DGRAM")):
            self.assertEqual(self.probe.network({"family": family, "type": kind, "action": "create"}), 0, (family, kind))
        self.assertEqual(self.probe.network({"family": "AF_INET", "type": "SOCK_STREAM", "action": "bind"}), 0)  # unconfined baseline
        # connect with no listener can never read as a pass: the baseline must fail closed
        self.assertEqual(self.probe.network({"family": "AF_INET", "type": "SOCK_STREAM", "action": "connect"}, {}), errno_.EDESTADDRREQ)
        source = (ACCEPTANCE / "probe.py").read_text().split("def network(")[1].split("def vm_readv")[0]
        for forbidden in (".send(", ".sendall(", ".recv(", ".sendto("):
            self.assertNotIn(forbidden, source)  # nothing is ever sent

    def test_a_probe_connect_really_reaches_the_listener_not_a_no_op(self) -> None:
        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.bind(("127.0.0.1", 0))
        listener.listen(1)
        listener.settimeout(3)
        self.addCleanup(listener.close)
        port = listener.getsockname()[1]
        self.assertEqual(self.probe.network({"family": "AF_INET", "type": "SOCK_STREAM", "action": "connect"}, {"AF_INET": ["127.0.0.1", port]}), 0)
        accepted, _ = listener.accept()  # an actual TCP connection arrived
        accepted.close()
        self.assertEqual(self.probe.network({"family": "AF_UNIX", "type": "SOCK_STREAM", "action": "bind"}), 0)  # unconfined baseline; path unlinked
        self.assertFalse(list(Path("/tmp").glob(f"wpcg-probe-bind-{os.getpid()}")))

    @unittest.skipIf(os.geteuid() == 0, "fd-sender refuses to run as root")
    def test_loopback_tcp_connect_succeeds_unconfined_against_the_harness_listener(self) -> None:
        """The baseline the negative checks rely on: IPv4 (and IPv6 when the host has ::1) really connect without a profile."""
        canary = Path(tempfile.mkdtemp(prefix="wpcg-canary-")) / "canary"
        canary.write_text(json.loads(MATRIX_TEXT)["canary"])
        self.addCleanup(shutil.rmtree, canary.parent, True)
        sender = subprocess.Popen([sys.executable, "-B", str(ACCEPTANCE / "fd-sender.py"), "tcptest", str(canary)], stdout=subprocess.PIPE, text=True)
        try:
            ready = sender.stdout.readline()
            self.assertRegex(ready, r"^READY \S+ wpcg-probe-tcptest v4=(\d+|none) v6=(\d+|none)$")
            v4, v6 = re.search(r"v4=(\w+) v6=(\w+)", ready).groups()
            self.assertNotEqual(v4, "none")
            self.assertEqual(self.probe.network({"family": "AF_INET", "type": "SOCK_STREAM", "action": "connect"},
                                                {"AF_INET": ["127.0.0.1", int(v4)]}), 0)
            if v6 != "none":
                self.assertEqual(self.probe.network({"family": "AF_INET6", "type": "SOCK_STREAM", "action": "connect"},
                                                    {"AF_INET6": ["::1", int(v6)]}), 0)
            self.assertEqual(self.probe.network({"family": "AF_INET6", "type": "SOCK_STREAM", "action": "connect"}, {"AF_INET": ["127.0.0.1", int(v4)]}),
                             errno_.EDESTADDRREQ)
        finally:
            sender.kill()
            sender.wait()
            shutil.rmtree("/tmp/wpcg-probe-tcptest", ignore_errors=True)

    def test_every_result_is_timed_and_attributed(self) -> None:
        results: list = []
        self.probe.timed(results, "x", "file", "/nonexistent", lambda: self.probe.open_path("/nonexistent/wpcg", "r"))
        (entry,) = results
        self.assertEqual((entry["errno"], entry["pid"]), (errno_.ENOENT, os.getpid()))
        self.assertTrue(0 < entry["t0"] <= entry["t1"])

    def test_scm_reports_the_stage_and_its_window(self) -> None:
        outcome = self.probe.scm("/tmp/wpcg-probe-absent/scm.sock")
        self.assertEqual((outcome["state"], outcome["stage"], outcome["errno"]), ("channel-failed", "connect", errno_.ENOENT))
        self.assertTrue(outcome["t0"] <= outcome["t1"])

    def test_probe_document_binds_boot_pid_start_time_and_lifetime(self) -> None:
        driver = ("import importlib.util,sys,json\n"
                  f"s=importlib.util.spec_from_file_location('p',{str(ACCEPTANCE / 'probe.py')!r})\n"
                  "m=importlib.util.module_from_spec(s); s.loader.exec_module(m)\n"
                  "m.label=lambda: 'unconfined'\n"
                  "m.exec_path=lambda p: (2, 4242)\n"
                  "m.open_path=lambda p,a: 2\n"
                  "m.connect=lambda a: 2\n"
                  f"sys.exit(m.main(['baseline',{MATRIX_TEXT!r},'wpcaregrid-nginx',{json.dumps({'peers': {}, 'scm_socket': '/tmp/none/s', 'abstract': 'a'})!r}]))\n")
        result = subprocess.run([sys.executable, "-I", "-B", "-c", driver], capture_output=True, text=True, timeout=60, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        doc = json.loads(result.stdout)
        self.assertEqual(doc["schema"], 2)
        self.assertEqual(doc["boot_id"], Path("/proc/sys/kernel/random/boot_id").read_text().strip())
        self.assertGreater(doc["starttime_ticks"], 0)
        self.assertLessEqual(doc["started_ns"], doc["ended_ns"])
        self.assertLess(abs(doc["kernel_start_ns"] - doc["started_ns"]), 10 * 10**9)
        self.assertEqual(doc["scm_socket"], "/tmp/none/s")


import errno as errno_  # noqa: E402


class EdgeAndJobPeers(unittest.TestCase):
    """nginx and the queue/scheduler CLI share the www-data UID: model proof that neither can reach production
    (or, for nginx, either environment's) secrets, config caches or storage, even if attempted."""

    PS = "/var/lib/wpcaregrid-deploy/production"
    SS = "/var/lib/wpcaregrid-deploy/staging-next"
    P = "/var/www/wpcaregrid-production"
    S = "/var/www/wpcaregrid-staging-new"
    L = "/var/www/wpcaregrid"

    def rules(self, name):
        return load_profile(ARTIFACTS, name, [])[0]

    def resolve(self, name, path):
        layout = json.loads(MATRIX_TEXT)["profiles"][name]["layout"]["symlinks"]
        return resolve_virtual(layout, path)

    def test_nginx_reads_only_public_web_roots(self) -> None:
        rules = self.rules("wpcaregrid-nginx")
        for path in (f"{self.P}/current/public/index.php", f"{self.P}/current/public/build/app.js", f"{self.S}/public/index.php",
                     f"{self.L}/public/index.php", "/var/www/some-sibling/public/index.php"):
            self.assertTrue(decide(rules, self.resolve("wpcaregrid-nginx", path), "r"), path)
        for path in (f"{self.P}/current/public/index.php", f"{self.S}/public/index.php", f"{self.L}/public/index.php"):
            self.assertFalse(decide(rules, self.resolve("wpcaregrid-nginx", path), "w"), "nginx must never write web roots")
            self.assertFalse(decide(rules, self.resolve("wpcaregrid-nginx", path), "x"))
        for outside in ("/var/www/html/index.html", "/srv/anything", "/opt/app/public/index.php", "/tmp/x", "/etc/passwd.bak"):
            self.assertFalse(decide(rules, outside, "r"), outside)

    def test_nginx_denies_both_environments_secrets_config_and_storage(self) -> None:
        rules = self.rules("wpcaregrid-nginx")
        secrets = [
            f"{self.PS}/shared/.env", f"{self.P}/current/.env", f"{self.PS}/shared/storage/logs/laravel.log",
            f"{self.P}/current/bootstrap/cache/config.php", f"{self.P}/current/config/app.php", f"{self.P}/current/artisan",
            f"{self.P}/current/public/storage/logs/laravel.log", f"{self.P}/current/public/.env", f"{self.PS}/shared/vendor/autoload.php",
            f"{self.PS}/database-backups/x.sql.gz", f"{self.PS}/state/workers-disabled", f"{self.PS}/receipts/x",
            f"{self.SS}/shared/.env", f"{self.S}/.env", f"{self.SS}/shared/storage/logs/laravel.log",
            f"{self.S}/bootstrap/cache/config.php", f"{self.S}/config/app.php", f"{self.S}/artisan",
            f"{self.S}/public/storage/x", f"{self.SS}/database-backups/x", f"{self.SS}/state/scheduler-paused",
            f"{self.L}/.env", f"{self.L}/storage/logs/laravel.log", f"{self.L}/bootstrap/cache/config.php", f"{self.L}/config/app.php",
            "/etc/wpcaregrid-deploy/profiles/x.json", "/home/deploy/.private/x", "/root/.ssh/id_ed25519",
        ]
        for path in secrets:
            resolved = self.resolve("wpcaregrid-nginx", path)
            for access in ("r", "w"):
                self.assertFalse(decide(rules, resolved, access), f"{path} -> {resolved} ({access})")
        # only the exact gate markers are readable under /state, and nothing else there
        self.assertTrue(decide(rules, f"{self.PS}/state/maintenance", "r"))
        self.assertFalse(decide(rules, f"{self.PS}/state/maintenance", "w"))
        self.assertFalse(decide(rules, f"{self.PS}/state/maintenance.bak", "r"))

    def test_nginx_sockets_are_the_three_fastcgi_upstreams_only(self) -> None:
        rules = self.rules("wpcaregrid-nginx")
        for sock in ("/run/php/wpcaregrid-production-php8.4-fpm.sock", "/run/php/php8.5-fpm.sock", "/run/php/php8.4-fpm.sock"):
            self.assertTrue(decide(rules, sock, "rw"), sock)
        for sock in ("/run/mysqld/mysqld.sock", "/run/supervisor.sock", "/run/php/php-fpm.sock", "/run/php/other.sock",
                     "/run/systemd/private", "/var/run/docker.sock"):
            self.assertFalse(decide(rules, sock, "rw"), sock)

    def test_nginx_keeps_what_it_needs_that_the_fpm_profiles_must_not_have(self) -> None:
        nginx = self.rules("wpcaregrid-nginx")
        fpm = self.rules("wpcaregrid-php-fpm-production")
        self.assertTrue(decide(nginx, "/etc/letsencrypt/live/example/privkey.pem", "r"))
        self.assertFalse(decide(fpm, "/etc/letsencrypt/live/example/privkey.pem", "r"))

    def test_queue_and_scheduler_cli_cannot_touch_production_even_if_started_against_it(self) -> None:
        for name in ("wpcaregrid-php-cli-staging",):
            rules = self.rules(name)
            attempts = [f"{self.P}/current/artisan", f"{self.P}/current/.env", f"{self.PS}/shared/.env",
                        f"{self.P}/current/bootstrap/cache/config.php", f"{self.P}/current/config/app.php",
                        f"{self.PS}/shared/storage/logs/laravel.log", f"{self.PS}/shared/storage/framework/cache/x",
                        f"{self.PS}/shared/vendor/autoload.php", f"{self.PS}/state/workers-disabled", f"{self.PS}/state/scheduler-paused",
                        f"{self.PS}/database-backups/x", f"{self.PS}/incoming/x", "/var/log/wpcaregrid-production/php-fpm.log",
                        "/run/php/wpcaregrid-production-php8.4-fpm.sock", "/etc/wpcaregrid-deploy/profiles/x.json", "/home/deploy/x"]
            for path in attempts:
                resolved = self.resolve(name, path)
                for access in ("r", "w", "x"):
                    self.assertFalse(decide(rules, resolved, access), f"{path} -> {resolved} ({access})")

    def test_queue_and_scheduler_cli_keep_staging_working(self) -> None:
        rules = self.rules("wpcaregrid-php-cli-staging")
        for path, access in ((f"{self.S}/artisan", "r"), (f"{self.SS}/shared/.env", "r"), (f"{self.SS}/shared/storage/logs/w.log", "w"),
                             (f"{self.SS}/state/scheduler-paused", "r"), (f"{self.SS}/state/workers-disabled", "r"),
                             (f"{self.L}/artisan", "r"), (f"{self.L}/storage/logs/worker-default.log", "w"),
                             (f"{self.L}/bootstrap/cache/config.php", "r"), ("/var/lib/wpcaregrid/scheduler.lock", "rw"),
                             ("/usr/bin/php8.4", "x"), ("/usr/bin/php8.5", "x"), ("/usr/local/bin/wpcaregrid-php", "x"),
                             ("/usr/local/bin/wpcaregrid-scheduler", "x")):
            self.assertTrue(decide(rules, self.resolve("wpcaregrid-php-cli-staging", path), access), f"{path} {access}")
        for path, access in ((f"{self.S}/artisan", "w"), (f"{self.SS}/shared/.env", "w"), (f"{self.SS}/state/scheduler-paused", "w"),
                             ("/usr/bin/curl", "x"), ("/usr/bin/id", "x"), (f"{self.SS}/shared/storage/x", "x"),
                             (f"{self.SS}/state/other", "r")):
            self.assertFalse(decide(rules, self.resolve("wpcaregrid-php-cli-staging", path), access), f"{path} {access}")

    def test_deploy_cli_profile_is_selected_only_by_aa_exec_never_by_a_unit(self) -> None:
        for tree in ("systemd", "php-fpm"):
            for path in (ARTIFACTS / tree).rglob("*"):
                if path.is_file():
                    text = path.read_text()
                    self.assertNotIn("wpcaregrid-php-cli-production-deploy", text, f"{path.name} must not attach the DB probe")
                    self.assertNotIn("wpcaregrid-php-cli-production-build", text, f"{path.name} must not attach the build CLI")
        for profile_name in ("wpcaregrid-php-cli-production-deploy", "wpcaregrid-php-cli-production-build"):
            profile = (ARTIFACTS / PROFILES[profile_name]).read_text()
            self.assertIn(f"aa-exec -p {profile_name}", profile)
            self.assertIn("never a service", profile)
            for other, relative in PROFILES.items():
                if other != profile_name:
                    mentions = [l.strip() for l in (ARTIFACTS / relative).read_text().splitlines()
                                if f"peer={profile_name}" in l and not l.lstrip().startswith("#")]
                    self.assertEqual(mentions, [f"audit deny signal (send) peer={profile_name},"], other)

    def test_job_profile_is_selected_by_unit_only_with_no_path_attachment_and_no_cron(self) -> None:
        for name in PROFILES:
            text = (ARTIFACTS / PROFILES[name]).read_text()
            self.assertNotRegex(text, r"(?m)^profile \S+ /\S+ flags=", name)  # an exec-path attachment would reach a cron child
            self.assertRegex(text, rf"(?m)^profile {name} flags=\(attach_disconnected\) \{{$")
        for drop in ("supervisor.service.d", "wpcaregrid-staging-next-scheduler.service.d", "wpcaregrid-staging-legacy-scheduler.service.d"):
            self.assertIn("AppArmorProfile=wpcaregrid-php-cli-staging",
                          (ARTIFACTS / "systemd" / drop / "90-wpcaregrid-confinement.conf").read_text())
        self.assertFalse(list((ARTIFACTS / "systemd").glob("cron*")))
        self.assertIn("www-data cron is not permitted", (ARTIFACTS / PROFILES["wpcaregrid-php-cli-staging"]).read_text())

    def test_every_peer_profile_denies_signalling_every_other_class(self) -> None:
        names = set(PROFILES)
        for name in names:
            text = (ARTIFACTS / PROFILES[name]).read_text()
            for other in names - {name}:
                self.assertIn(f"audit deny signal (send) peer={other},", text, f"{name} -> {other}")


def make_production_tree(root: Path) -> None:
    """A production tree whose secret-bearing paths are group/other readable, as the deploy tooling leaves them."""
    web = root / "var/www/wpcaregrid-production/releases/r1"
    shared = root / "var/lib/wpcaregrid-deploy/production"
    for directory, mode in ((web / "bootstrap/cache", 0o750), (web / "public/build", 0o755), (shared / "shared/storage/logs", 0o2770),
                            (shared / "shared/vendor", 0o755), (shared / "state", 0o750), (shared / "database-backups", 0o750),
                            (root / "var/log/wpcaregrid-production", 0o750)):
        directory.mkdir(parents=True, exist_ok=True)
    files = {web / "bootstrap/cache/config.php": 0o640, web / "artisan": 0o644, web / "public/index.php": 0o644,
             web / "public/build/app.js": 0o644, shared / "shared/.env": 0o640, shared / "shared/storage/logs/laravel.log": 0o660,
             shared / "shared/vendor/autoload.php": 0o644, shared / "state/maintenance": 0o640,
             shared / "database-backups/x.sql.gz": 0o640, root / "var/log/wpcaregrid-production/php-fpm.log": 0o640}
    for path, mode in files.items():
        path.write_text("x")
        path.chmod(mode)
    os.symlink(str(shared / "shared/.env"), web / ".env")
    for directory, mode in ((web / "bootstrap/cache", 0o750), (shared / "shared/storage/logs", 0o770), (shared / "state", 0o750),
                            (root / "var/log/wpcaregrid-production", 0o750), (web, 0o755), (web.parent, 0o755)):
        directory.chmod(mode)


class FenceProduction(unittest.TestCase):
    """Review (1)/(I): fence EVERY uid-33-readable production secret-bearing path, not just .env and storage."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.tool = load_script(ACCEPTANCE / "fence-production.py", "wpcg_fence")
        cls.matrix = json.loads(MATRIX_TEXT)

    def setUp(self) -> None:
        self.root = Path(tempfile.mkdtemp(prefix="wpcg-fence-"))
        self.addCleanup(shutil.rmtree, self.root, True)
        make_production_tree(self.root)
        self.receipt = str(self.root / "receipt.json")
        # The test user's group plays www-data's group; the owner is a different uid, so only group/other bits expose.
        self.kw = {"uid": 999999, "gid": os.getgid()}

    def status(self):
        return self.tool.status(self.matrix, str(self.root), require_root_owner=False, **self.kw)

    def test_before_fencing_the_review_listed_paths_are_exposed_and_public_is_not_in_scope(self) -> None:
        exposed = self.status()
        for needed in ("/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php",
                       "/var/www/wpcaregrid-production/releases/r1/bootstrap/cache",
                       "/var/log/wpcaregrid-production/php-fpm.log", "/var/log/wpcaregrid-production",
                       "/var/lib/wpcaregrid-deploy/production/shared/.env",
                       "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log",
                       "/var/lib/wpcaregrid-deploy/production/state/maintenance",
                       "/var/lib/wpcaregrid-deploy/production/database-backups/x.sql.gz",
                       "/var/www/wpcaregrid-production/releases/r1/artisan"):
            self.assertIn(needed, exposed)
        self.assertFalse([p for p in exposed if "/public/" in p or p.endswith("/public")], "public web roots are out of scope")

    def test_fence_closes_every_path_and_keeps_only_public_and_traversal(self) -> None:
        self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)
        self.assertEqual(self.status(), [])
        web = self.root / "var/www/wpcaregrid-production/releases/r1"
        self.assertEqual(oct((web / "public/index.php").stat().st_mode & 0o7777), "0o644")  # public untouched
        self.assertEqual(oct(web.stat().st_mode & 0o7777), "0o755")  # nginx can still reach releases/*/public
        self.assertEqual(oct((web / "bootstrap/cache/config.php").stat().st_mode & 0o7777), "0o600")
        self.assertEqual(oct((web / "bootstrap/cache").stat().st_mode & 0o7777), "0o700")
        self.assertEqual(oct((self.root / "var/log/wpcaregrid-production/php-fpm.log").stat().st_mode & 0o7777), "0o600")

    def test_fence_is_reversible_from_the_receipt(self) -> None:
        before = {p: (p.lstat().st_mode & 0o7777) for p in self.root.rglob("*") if not p.is_symlink() and p.name != "receipt.json"}
        self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)
        self.tool.restore(str(self.root), self.receipt, chown=False)
        after = {p: (p.lstat().st_mode & 0o7777) for p in before}
        self.assertEqual(after, before)

    def test_interrupted_fence_is_still_reversible_and_still_reported_exposed(self) -> None:
        calls = {"n": 0}
        real = os.chmod

        def flaky(path, mode, *a, **k):
            calls["n"] += 1
            if calls["n"] == 4:
                raise OSError("injected fault")
            return real(path, mode, *a, **k)
        original = {p: (p.lstat().st_mode & 0o7777) for p in self.root.rglob("*") if not p.is_symlink()}
        self.tool.os.chmod = flaky
        try:
            with self.assertRaises(OSError):
                self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)
        finally:
            self.tool.os.chmod = real
        self.assertTrue(self.status(), "a half-applied fence must not look fenced")
        self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)  # re-run completes it
        self.assertEqual(self.status(), [])
        self.tool.restore(str(self.root), self.receipt, chown=False)
        restored = {p: (p.lstat().st_mode & 0o7777) for p in original if p.name != "receipt.json" and p.exists()}
        self.assertEqual(restored, {p: m for p, m in original.items() if p.name != "receipt.json" and p.exists()})

    def test_symlinks_are_never_followed(self) -> None:
        outside = self.root / "outside-secret"
        outside.write_text("x")
        outside.chmod(0o644)
        os.symlink(str(outside), self.root / "var/www/wpcaregrid-production/releases/r1/leak")
        self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)
        self.assertEqual(oct(outside.stat().st_mode & 0o7777), "0o644")

    def test_a_reexposed_path_is_detected_after_a_fence(self) -> None:
        self.tool.fence(self.matrix, str(self.root), self.receipt, chown=False)
        config = self.root / "var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php"
        config.chmod(0o644)  # a deploy re-creating a world-readable config cache
        self.assertEqual(self.status(), ["/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php"])
        (self.root / "var/log/wpcaregrid-production").chmod(0o750)
        self.assertIn("/var/log/wpcaregrid-production", self.status())

    def test_every_named_secret_location_in_the_matrix_is_inside_the_fenced_walk(self) -> None:
        walked = {v for v, _, _, _ in self.tool.entries(self.matrix, str(self.root))}
        for pattern in self.matrix["production_fence"]["named_secret_paths"]:
            real_pattern = str(self.root) + pattern
            matches = [str(Path(m)) for m in glob.glob(real_pattern)]
            if "*" in pattern or pattern.startswith("/var/lib") or pattern.startswith("/var/log"):
                virtual = ["/" + os.path.relpath(m, self.root) for m in matches]
                for item in virtual:
                    self.assertIn(item, walked, pattern)
        self.assertTrue({"/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php",
                         "/var/log/wpcaregrid-production/php-fpm.log"} <= walked)

    def test_matrix_names_the_review_locations(self) -> None:
        fence = self.matrix["production_fence"]
        joined = " ".join(fence["named_secret_paths"] + fence["roots"])
        for needed in ("bootstrap/cache", "/var/log/wpcaregrid-production", "shared/.env", "shared/storage", "releases/*/.env"):
            self.assertIn(needed, joined)
        self.assertEqual(fence["public_exempt"], "/var/www/wpcaregrid-production/releases/*/public")

    def test_exposure_rules(self) -> None:
        mk = lambda mode, uid=1, gid=1, kind=stat.S_IFREG: os.stat_result((kind | mode, 0, 0, 1, uid, gid, 0, 0, 0, 0))  # noqa: E731
        self.assertTrue(self.tool.exposed(mk(0o640, gid=33)))
        self.assertTrue(self.tool.exposed(mk(0o600, uid=33)))
        self.assertTrue(self.tool.exposed(mk(0o604)))
        self.assertTrue(self.tool.exposed(mk(0o701, kind=stat.S_IFDIR)))
        self.assertFalse(self.tool.exposed(mk(0o600, uid=0, gid=0)))
        self.assertFalse(self.tool.exposed(mk(0o640, uid=0, gid=1000)))
        self.assertFalse(self.tool.exposed(mk(0o777, kind=stat.S_IFLNK)))

    @unittest.skipIf(os.geteuid() == 0, "root is allowed to fence")
    def test_cli_refuses_fence_and_restore_without_root_but_status_is_read_only(self) -> None:
        for command in ("fence", "restore"):
            result = subprocess.run([sys.executable, "-B", str(ACCEPTANCE / "fence-production.py"), command, str(ACCEPTANCE / "matrix.json"),
                                     "--root", str(self.root)], capture_output=True, text=True, timeout=30, check=False,
                                    env={"PATH": os.environ.get("PATH", "")})
            self.assertEqual(result.returncode, 2)
        before = (self.root / "var/log/wpcaregrid-production/php-fpm.log").stat().st_mode
        subprocess.run([sys.executable, "-B", str(ACCEPTANCE / "fence-production.py"), "status", str(ACCEPTANCE / "matrix.json"), "--root", str(self.root)],
                       capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual((self.root / "var/log/wpcaregrid-production/php-fpm.log").stat().st_mode, before)


class FakeRollbackHost:
    """Simulates the host state the rollback order protects; asserts Invariant I at the moment of every unconfine."""

    def __init__(self, peers, faults=None, reexpose_after=None, jobs_masked=True, service_stays_running=False, route_reopens=False,
                 deploy_cli=False, cli_on_call=None):
        self.deploy_cli, self.cli_on_call, self.cli_calls = deploy_cli, cli_on_call, 0
        self.route, self.service, self.masked = True, True, jobs_masked
        self.exposed = {"/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php", "/var/log/wpcaregrid-production/php-fpm.log",
                        "/var/lib/wpcaregrid-deploy/production/shared/.env", "/var/lib/wpcaregrid-deploy/production/shared/storage/logs/laravel.log"}
        self.confined, self.unconfined = set(peers), []
        self.faults, self.counts, self.violations, self.calls = dict(faults or {}), {}, [], []
        self.reexpose_after, self.service_stays_running, self.route_reopens = reexpose_after, service_stays_running, route_reopens

    def _fault(self, name):
        self.counts[name] = self.counts.get(name, 0) + 1
        self.calls.append(name)
        if self.faults.get(name) == self.counts[name]:
            raise RuntimeError(f"injected fault in {name} #{self.counts[name]}")

    def close_route(self):
        self._fault("close_route")
        self.route = False

    def stop_production_service(self):
        self._fault("stop_production_service")
        self.service = self.service_stays_running

    def jobs_masked(self):  # replaced by attribute below
        return self.masked

    def fence_secrets(self):
        self._fault("fence_secrets")
        if self.faults.get("fence_partial") == 1:
            self.exposed.discard(sorted(self.exposed)[0])
            self.faults.pop("fence_partial")
            raise RuntimeError("injected partial fence")
        self.exposed.clear()

    def exposed_paths(self):
        self._fault("exposed_paths")
        return sorted(self.exposed)

    def deploy_cli_running(self):
        self.cli_calls += 1
        return self.deploy_cli or self.cli_on_call == self.cli_calls

    def route_open(self):
        return self.route

    def production_service_running(self):
        return self.service

    def unconfine(self, peer):
        self._fault("unconfine")
        if self.exposed or self.route or self.service or not self.masked or self.deploy_cli:
            self.violations.append((peer, sorted(self.exposed), self.route, self.service, self.masked))
        self.confined.discard(peer)
        self.unconfined.append(peer)

    def restart(self, peer):
        self._fault("restart")
        if self.reexpose_after == peer:
            self.exposed.add("/var/www/wpcaregrid-production/releases/r1/bootstrap/cache/config.php")
        if self.route_reopens == peer:
            self.route = True

    def remove_artifacts(self):
        self._fault("remove_artifacts")


class RollbackOrder(unittest.TestCase):
    """Review (1)/(I): fault-order proof that production is fenced before ANY peer is returned to unconfined."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.mod = load_script(ACCEPTANCE / "rollback-order.py", "wpcg_rollback")
        cls.peers = cls.mod.PEERS_NEWEST_FIRST

    def host(self, **kw):
        host = FakeRollbackHost(self.peers, **kw)
        host.jobs_masked = lambda masked=host.masked: masked  # the protocol method
        host.masked = kw.get("jobs_masked", True)
        host.jobs_masked = lambda: host.masked
        return host

    def test_peer_list_is_every_non_production_role_newest_stage_first(self) -> None:
        matrix = json.loads(MATRIX_TEXT)
        self.assertEqual(set(self.peers), set(matrix["role_units"]) - {"production"})
        self.assertEqual(self.peers[-2:], ("staging-next", "staging-legacy"))
        self.assertLess(self.peers.index("nginx"), self.peers.index("staging-next"))

    def test_happy_path_orders_fence_before_every_unconfine_and_never_violates_the_invariant(self) -> None:
        host = self.host()
        log = self.mod.run_rollback(host)
        self.assertEqual(host.violations, [])
        self.assertEqual(host.unconfined, list(self.peers))
        order = [log.index(x) for x in ("R1:close_route", "R1:stop_production_service", "R1:assert_jobs_masked",
                                        "R1:assert_no_deploy_cli", "R2:fence_secrets")]
        self.assertLess(log.index("R4:assert_no_deploy_cli"), log.index("R4:remove_artifacts"))
        self.assertEqual(order, sorted(order))
        self.assertLess(log.index("R2:fence_secrets"), log.index(f"R3:unconfine:{self.peers[0]}"))
        for peer in self.peers:  # re-proven immediately before EVERY unconfine, and again after its restart
            self.assertEqual(log.index(f"R3:unconfine:{peer}") - 1, log.index(f"R3:require_fenced_before:{peer}"))
            self.assertEqual(log.index(f"R3:restart:{peer}") + 1, log.index(f"R3:require_fenced_after:{peer}"))

    def test_a_fault_at_any_point_aborts_before_any_unsafe_unconfine_and_resumes_safely(self) -> None:
        points = ["close_route", "stop_production_service", "fence_secrets", "fence_partial", "exposed_paths", "unconfine", "restart"]
        for name in points:
            for occurrence in (1, 2, 3, 5):
                faults = {name: occurrence}
                host = self.host(faults=faults)
                try:
                    self.mod.run_rollback(host)
                    completed = True
                except self.mod.RollbackAborted as aborted:
                    completed = False
                    self.assertTrue(aborted.log)
                if name in ("exposed_paths", "unconfine", "restart") and occurrence > 20:
                    continue
                self.assertEqual(host.violations, [], f"{name}#{occurrence}")
                if not completed:
                    # nothing past the failing step ran; peers not yet unconfined stay confined
                    self.assertTrue(set(self.peers) - set(host.unconfined) or set(host.unconfined) == set(self.peers))
                    host.faults.clear()
                    log = self.mod.run_rollback(host)  # resume: every precondition is re-proven from the start
                    self.assertEqual(host.violations, [], f"resume after {name}#{occurrence}")
                    self.assertEqual(set(host.unconfined), set(self.peers))
                    self.assertTrue(log)

    def test_a_fault_before_the_fence_is_verified_leaves_every_peer_confined(self) -> None:
        for name in ("close_route", "stop_production_service", "fence_secrets", "fence_partial"):
            host = self.host(faults={name: 1})
            with self.assertRaises(self.mod.RollbackAborted):
                self.mod.run_rollback(host)
            self.assertEqual(host.unconfined, [], name)
            self.assertEqual(host.confined, set(self.peers), name)

    def test_a_partial_fence_never_unconfines_anything(self) -> None:
        host = self.host(faults={"fence_partial": 1})
        with self.assertRaises(self.mod.RollbackAborted) as caught:
            self.mod.run_rollback(host)
        self.assertEqual(caught.exception.step, "R2:fence_secrets")
        self.assertTrue(host.exposed)
        self.assertEqual(host.unconfined, [])

    def test_reexposure_after_one_peers_restart_stops_the_remaining_peers(self) -> None:
        for victim in self.peers[:-1]:
            host = self.host(reexpose_after=victim)
            with self.assertRaises(self.mod.RollbackAborted) as caught:
                self.mod.run_rollback(host)
            self.assertTrue(caught.exception.step.startswith("R3:require_fenced_after:"), caught.exception.step)
            self.assertEqual(host.unconfined, list(self.peers[:self.peers.index(victim) + 1]))
            self.assertEqual(host.violations, [])  # the victim itself was unconfined while fenced; no later peer was

    def test_route_reopening_or_a_running_production_service_blocks_every_unconfine(self) -> None:
        for kw in ({"service_stays_running": True}, {"route_reopens": self.peers[0]}):
            host = self.host(**kw)
            with self.assertRaises(self.mod.RollbackAborted):
                self.mod.run_rollback(host)
            self.assertEqual(host.violations, [])
            self.assertLessEqual(len(host.unconfined), 1)

    def test_unmasked_production_jobs_abort_before_anything_and_are_never_unmasked_by_rollback(self) -> None:
        host = self.host(jobs_masked=False)
        with self.assertRaises(self.mod.RollbackAborted) as caught:
            self.mod.run_rollback(host)
        self.assertEqual(caught.exception.step, "R1:assert_jobs_masked")
        self.assertEqual(host.unconfined, [])
        source = (ACCEPTANCE / "rollback-order.py").read_text()
        self.assertNotIn("unmask(", source)
        self.assertNotIn("def unmask", source)

    def test_a_running_production_deploy_cli_aborts_in_r1_before_any_change(self) -> None:
        """Review: the CLI guard must run in R1 (before the fence), not first inside the R3 loop."""
        host = self.host(deploy_cli=True)
        with self.assertRaises(self.mod.RollbackAborted) as caught:
            self.mod.run_rollback(host)
        self.assertEqual(caught.exception.step, "R1:assert_no_deploy_cli")
        self.assertEqual(host.unconfined, [])
        self.assertTrue(host.exposed, "nothing was fenced yet: the operator investigates the running CLI first")
        self.assertNotIn("fence_secrets", host.calls)

    def test_a_cli_started_between_the_last_peer_and_profile_removal_blocks_r4(self) -> None:
        """Removing the deploy profile would turn a running CLI unconfined: it is re-checked immediately before R4."""
        peers = len(self.peers)
        host = self.host(cli_on_call=1 + 2 * peers + 1)  # call 1: R1; 2 per peer in R3; then the R4 guard
        with self.assertRaises(self.mod.RollbackAborted) as caught:
            self.mod.run_rollback(host)
        self.assertEqual(caught.exception.step, "R4:assert_no_deploy_cli")
        self.assertNotIn("remove_artifacts", host.calls)
        self.assertEqual(host.violations, [])

    def test_a_cli_appearing_mid_rollback_stops_the_remaining_peers(self) -> None:
        host = self.host(cli_on_call=1 + 2 * 2 + 1)  # appears at the check before the third peer
        with self.assertRaises(self.mod.RollbackAborted) as caught:
            self.mod.run_rollback(host)
        self.assertTrue(caught.exception.step.startswith("R3:require_fenced_before:"), caught.exception.step)
        self.assertEqual(len(host.unconfined), 2)

    def test_the_cli_guard_detects_by_label_not_by_name(self) -> None:
        source = (ACCEPTANCE / "rollback-order.py").read_text()
        self.assertIn("attr/current", source)
        self.assertNotIn("pgrep", source)

    def test_without_fencing_the_guard_itself_would_have_allowed_a_violation(self) -> None:
        """Control: prove the fake host can detect the review's bug (peers unconfined while paths are still readable)."""
        host = self.host()
        host.unconfine(self.peers[0])
        self.assertTrue(host.violations)


class DeployCliProfile(unittest.TestCase):
    """Owner-approved wpcaregrid-php-cli-production-deploy: the helper's PHP 8.4 CLI, separately confined, same www-data UID."""

    NAME = "wpcaregrid-php-cli-production-build"
    P = "/var/www/wpcaregrid-production"
    PS = "/var/lib/wpcaregrid-deploy/production"

    def rules(self):
        return load_profile(ARTIFACTS, self.NAME, [])[0]

    def resolve(self, path):
        return resolve_virtual(json.loads(MATRIX_TEXT)["profiles"][self.NAME]["layout"]["symlinks"], path)

    def test_positive_what_artisan_needs(self) -> None:
        rules = self.rules()
        for path, access in ((f"{self.P}/current/artisan", "r"), (f"{self.P}/current/vendor/autoload.php", "r"),
                             (f"{self.P}/current/.env", "r"), (f"{self.PS}/shared/.env", "r"),
                             (f"{self.P}/current/bootstrap/cache/config.php", "r"), (f"{self.P}/current/bootstrap/cache/packages.php", "w"),
                             (f"{self.P}/current/bootstrap/cache/services.php", "w"), (f"{self.P}/current/bootstrap/cache/config.php", "w"),
                             (f"{self.P}/current/storage/logs/laravel.log", "w"), (f"{self.P}/current/storage/framework/views/x.php", "w"),
                             (f"{self.PS}/shared/storage/framework/cache/data/x", "w"), ("/usr/bin/php8.4", "x"),
                             ("/etc/php/8.4/cli/php.ini", "r"), ("/etc/php/8.4/mods-available/pdo_mysql.ini", "r"),
                             ("/usr/lib/php/20240924/pdo_mysql.so", "r")):
            self.assertTrue(decide(rules, self.resolve(path), access), f"{path} {access}")

    def test_negative_writes_only_cache_and_storage(self) -> None:
        rules = self.rules()
        for path in (f"{self.P}/current/artisan", f"{self.P}/current/app/Models/User.php", f"{self.P}/current/config/app.php",
                     f"{self.P}/current/routes/web.php", f"{self.P}/current/.env", f"{self.PS}/shared/.env", f"{self.PS}/shared/vendor/autoload.php",
                     f"{self.P}/current/vendor/composer/installed.php", f"{self.P}/current/public/index.php", f"{self.P}/releases/x/new-file",
                     f"{self.P}/current/bootstrap/app.php"):
            self.assertFalse(decide(rules, self.resolve(path), "w"), f"{path} must not be writable")
        for path in (f"{self.PS}/shared/storage/x.php", f"{self.P}/current/bootstrap/cache/x.php"):
            self.assertFalse(decide(rules, self.resolve(path), "x"), "written files are never executable")
        # writes are limited to logs and framework cache/views, not the whole storage tree (review S3)
        for path in (f"{self.PS}/shared/storage/app/x", f"{self.PS}/shared/storage/app/public/x", f"{self.PS}/shared/storage/framework/sessions/x",
                     f"{self.PS}/shared/storage/framework/x", f"{self.PS}/shared/storage/x", f"{self.PS}/shared/storage/framework/testing/x"):
            self.assertFalse(decide(rules, path, "w"), f"{path} must not be writable by the deploy CLI")
        self.assertFalse(decide(rules, f"{self.P}/releases/r1/bootstrap/cache/x", "l"))  # no hard links either

    def test_negative_staging_control_plane_and_other_credentials(self) -> None:
        rules = self.rules()
        for path in ("/var/www/wpcaregrid-staging-new/artisan", "/var/www/wpcaregrid-staging-new/.env", "/var/lib/wpcaregrid-deploy/staging-next/shared/.env",
                     "/var/lib/wpcaregrid-deploy/staging-next/shared/storage/logs/laravel.log", "/var/www/wpcaregrid/.env",
                     "/var/www/wpcaregrid/storage/logs/laravel.log", "/var/lib/wpcaregrid/scheduler.lock",
                     "/etc/wpcaregrid-deploy/profiles/production-v2.1.2.json", "/etc/wpcaregrid-deploy/credentials/production-database-2.0.0.json",
                     "/home/deploy/.private/x", "/home/deploy/private-backups/x", "/root/.ssh/id_ed25519", "/etc/shadow",
                     f"{self.PS}/state/workers-disabled", f"{self.PS}/database-backups/x.sql.gz", f"{self.PS}/incoming/x", f"{self.PS}/inbox/x",
                     f"{self.PS}/receipts/x", "/var/log/wpcaregrid-production/php-fpm.log", "/etc/mysql/debian.cnf", "/var/lib/mysql/x",
                     "/run/mysqld/mysqld.sock", "/etc/ssl/private/x.key", "/etc/letsencrypt/live/x/privkey.pem", "/etc/ssh/ssh_host_rsa_key"):
            for access in ("r", "w"):
                self.assertFalse(decide(rules, path, access), f"{path} {access}")

    def test_negative_shell_and_every_other_binary(self) -> None:
        rules = self.rules()
        for path in ("/usr/bin/dash", "/usr/bin/bash", "/bin/sh", "/usr/bin/python3", "/usr/bin/perl", "/usr/bin/curl", "/usr/bin/wget",
                     "/usr/bin/nc", "/usr/bin/env", "/usr/bin/id", "/usr/bin/php8.5", "/usr/bin/php8.3", "/usr/sbin/php-fpm8.4", "/usr/sbin/sendmail",
                     "/usr/bin/aa-exec", "/usr/bin/sudo", "/usr/bin/mysql", "/usr/local/bin/php", "/tmp/x"):
            self.assertFalse(decide(rules, path, "x"), path)
        self.assertEqual([r.body for r in rules if r.kind == "file" and not r.deny and "x" in r.perms], ["/usr/bin/php8.4"])
        for path in ("/tmp/x", "/dev/shm/x", f"{self.P}/x"):
            self.assertFalse(decide(rules, path, "w"), path)

    def test_only_the_exact_mysql_unix_socket_and_no_tcp(self) -> None:
        """Owner decision (a): no TCP of any kind; one unix STREAM connect to exactly one pathname."""
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-cli-production-deploy", [])[0]
        self.assertEqual([r.body for r in rules if r.kind == "network" and not r.deny], [f"network {DEPLOY_UNIX}"])
        self.assertEqual([r.raw for r in rules if r.kind == "capability" and not r.deny], [])
        # TCP/IP: nothing, in any family, type or access
        for family in ("inet", "inet6"):
            for sock_type in ("stream", "dgram", "raw", "seqpacket"):
                for access in ("create", "connect", "bind", "listen", "accept", "send", "receive", "setopt", "getopt"):
                    self.assertFalse(net_decide(rules, family, sock_type, access), f"{family}/{sock_type}/{access}")
        for family in ("netlink", "packet"):
            for access in ("create", "connect", "bind"):
                self.assertFalse(net_decide(rules, family, "raw", access), family)
        # unix: client side of a stream socket only
        for access in ("create", "connect", "send", "receive", "shutdown", "getattr", "getopt", "setopt"):
            self.assertTrue(net_decide(rules, "unix", "stream", access), access)
        for access in ("bind", "listen", "accept"):
            self.assertFalse(net_decide(rules, "unix", "stream", access), f"the process must not serve a unix socket ({access})")
        for sock_type in ("dgram", "seqpacket", "raw"):
            self.assertFalse(net_decide(rules, "unix", sock_type, "create"), sock_type)
        denied = {r.body for r in rules if r.deny}
        for needed in ("network inet", "network inet6", "network netlink", "network (bind, listen, accept) unix stream", "network unix dgram",
                       "network inet raw", "network packet", "userns"):
            self.assertIn(needed, denied)
        text = (ARTIFACTS / PROFILES["wpcaregrid-php-cli-production-deploy"]).read_text()
        for gone in ("nameservice", "ssl_certs", "/etc/nsswitch.conf", "/etc/hosts", "/etc/host.conf"):
            self.assertNotIn(gone, text)  # no resolver and no TLS: there is nothing to resolve
        self.assertIn("NO TCP, NO OTHER NETWORK", text)
        self.assertIn("fail closed", text)

    def test_exact_socket_allowed_alternate_sockets_refused(self) -> None:
        rules = load_profile(ARTIFACTS, "wpcaregrid-php-cli-production-deploy", [])[0]
        self.assertTrue(decide(rules, MYSQL_SOCKET, "rw"))
        self.assertFalse(decide(rules, MYSQL_SOCKET, "x"))
        self.assertFalse(decide(rules, MYSQL_SOCKET, "l"))
        for alternate in ("/run/mysqld/wpcaregrid-production-restore.sock", "/run/mysqld/mysqld.sock", "/run/mysqld/wpcaregrid-production.sock.lock",
                          "/run/mysqld/wpcaregrid-production.pid", "/run/mysqld/wpcaregrid-production.sock.bak", "/run/mysqld/wpcaregrid-productionx.sock",
                          "/run/mysqld/other.sock", "/run/mysqld/", "/run/mysqld/sub/wpcaregrid-production.sock", "/var/lib/mysql/mysql.sock",
                          "/tmp/mysql.sock", "/run/php/php8.5-fpm.sock", "/run/php/php8.4-fpm.sock", "/run/php/wpcaregrid-production-php8.4-fpm.sock",
                          "/run/supervisor.sock", "/run/systemd/private", "/var/run/docker.sock", "/run/user/0/bus",
                          "/run/systemd/journal/stdout", "/run/systemd/journal/socket", "/dev/log", "/run/dbus/system_bus_socket"):
            for access in ("r", "w", "rw"):
                self.assertFalse(decide(rules, alternate, access), f"{alternate} {access}")
        denied = {r.body for r in rules if r.deny}
        for explicit in ("/run/mysqld/wpcaregrid-production-restore.sock", "/run/mysqld/mysqld.sock", "/run/mysqld/wpcaregrid-production.sock.lock"):
            self.assertIn(explicit, denied)
        # the allow is a single exact path, written in the profile, never a pattern and never from the environment
        file_allows = [r.body for r in rules if r.kind == "file" and not r.deny and "/run/" in r.body]
        self.assertEqual(file_allows, [MYSQL_SOCKET])
        rule_lines = "\n".join(l for l in (ARTIFACTS / PROFILES[self.NAME]).read_text().splitlines() if not l.strip().startswith("#"))
        self.assertNotRegex(rule_lines, r"@\{|\$\{|getenv|environ")  # no variable, so nothing environment-controlled can change the path

    def test_the_socket_path_is_the_production_g1_instance_and_bound_everywhere(self) -> None:
        spec = importlib.util.spec_from_file_location("wpcg_g1_contract", ROOT / "scripts" / "production_g1_mysql_contract.py")
        module = importlib.util.module_from_spec(spec)
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
        instances = module.INSTANCES
        self.assertEqual(instances["wpcaregrid-production"].socket, MYSQL_SOCKET)
        self.assertNotEqual(instances["wpcaregrid-production-restore"].socket, MYSQL_SOCKET)  # the isolated restore instance differs
        binding = json.loads((ARTIFACTS / "binding.json").read_text())["aa_exec_profiles"]["wpcaregrid-php-cli-production-deploy"]
        self.assertEqual(binding["mysql_unix_socket"]["path"], MYSQL_SOCKET)
        self.assertIn(instances["wpcaregrid-production-restore"].socket, binding["mysql_unix_socket"]["alternates_denied"])
        self.assertIn("has NOT been checked", binding["mysql_unix_socket"]["policy"])
        self.assertIn("server-UUID", binding["mysql_unix_socket"]["policy"])
        matrix = json.loads(MATRIX_TEXT)["profiles"]["wpcaregrid-php-cli-production-deploy"]
        self.assertEqual(matrix["mysql_unix_socket"], MYSQL_SOCKET)
        self.assertEqual([x["path"] for x in matrix["sockets"] if x["expect"] == "allow"], [MYSQL_SOCKET])
        self.assertEqual({x["path"] for x in matrix["sockets"] if x["id"].startswith("mysql-") and x["expect"] == "deny"},
                         {instances["wpcaregrid-production-restore"].socket, "/run/mysqld/mysqld.sock", "/run/mysqld/wpcaregrid-acceptance-canary.sock"})
        # no other profile may allow it: only the deploy CLI talks to MySQL over this socket
        for other in PROFILES:
            if other not in ("wpcaregrid-php-cli-production-deploy",):
                self.assertFalse(decide(load_profile(ARTIFACTS, other, [])[0], MYSQL_SOCKET, "rw"), other)


    def test_build_cache_profile_cannot_connect_to_any_socket_or_network(self) -> None:
        rules = self.rules()
        self.assertFalse(decide(rules, MYSQL_SOCKET, "rw"))
        for family in ("inet", "inet6", "unix", "netlink"):
            for typ in ("stream", "dgram", "raw", "seqpacket"):
                self.assertFalse(net_decide(rules, family, typ, "connect"), f"{family}/{typ}")
        matrix = json.loads(MATRIX_TEXT)["profiles"][self.NAME]
        self.assertTrue(all(x["expect"] == "deny" for x in matrix["sockets"]))
        self.assertTrue(all(x["expect"] == "deny" for x in matrix["network_checks"]))

    def test_1_0_2_had_a_tcp_rule_and_1_0_3_removed_it(self) -> None:
        """Regression for the review: the model sees the old exfiltration path in 1.0.2 and its absence in 1.0.3."""
        old = parse_text((ROOT / "deploy/php-fpm-confinement/1.0.2/apparmor/wpcaregrid-php-cli-production-deploy").read_text(), "old", [],
                         ROOT / "deploy/php-fpm-confinement/1.0.2", set())[0]
        new = self.rules()
        self.assertTrue(net_decide(old, "inet", "stream", "connect"))   # 1.0.2: any IPv4 TCP address was reachable
        self.assertFalse(net_decide(old, "inet6", "stream", "connect"))
        self.assertFalse(net_decide(new, "inet", "stream", "connect"))  # 1.0.3+: nothing
        self.assertFalse(net_decide(new, "inet6", "stream", "connect"))
        self.assertFalse(net_decide(new, "unix", "raw", "create"))
        self.assertFalse(net_decide(new, "netlink", "raw", "create"))

    def test_local_operations_each_supported_command_needs_are_allowed(self) -> None:
        """Positive tests for the three authorized helper commands, with no socket among their needs."""
        rules = self.rules()
        commands = {c["id"]: c for c in json.loads(MATRIX_TEXT)["profiles"][self.NAME]["commands_supported"]}
        self.assertEqual(set(commands), {"package-discover", "config-cache"})
        self.assertEqual({k: c["network"] for k, c in commands.items()}, {"package-discover": "none", "config-cache": "none"})
        db_commands = {c["id"]: c for c in json.loads(MATRIX_TEXT)["profiles"]["wpcaregrid-php-cli-production-deploy"]["commands_supported"]}
        self.assertEqual(set(db_commands), {"runtime-identity-probe"})
        self.assertEqual(db_commands["runtime-identity-probe"]["network"], f"unix stream connect to {MYSQL_SOCKET} only")
        self.assertIn("no host/port, no TCP fallback", db_commands["runtime-identity-probe"]["note"])
        self.assertIn("server-UUID", db_commands["runtime-identity-probe"]["note"])
        db_rules = load_profile(ARTIFACTS, "wpcaregrid-php-cli-production-deploy", [])[0]
        for path, access in (("/usr/bin/php8.4", "x"), (MYSQL_SOCKET, "rw"), (f"{self.P}/current/vendor/autoload.php", "r"),
                             (f"{self.P}/current/.env", "r"), (f"{self.P}/current/bootstrap/cache/config.php", "r")):
            self.assertTrue(decide(db_rules, self.resolve(path), access), f"runtime identity probe: {path} {access}")
        self.assertFalse(decide(db_rules, f"{self.P}/current/bootstrap/cache/config.php", "w"))
        self.assertFalse(decide(db_rules, f"{self.PS}/shared/storage/logs/laravel.log", "w"))
        needs = {
            "package-discover": [("/usr/bin/php8.4", "x"), (f"{self.P}/current/artisan", "r"), (f"{self.P}/current/vendor/composer/installed.json", "r"),
                                 (f"{self.P}/current/bootstrap/cache/packages.php", "w"), (f"{self.P}/current/bootstrap/cache/services.php", "w")],
            "config-cache": [("/usr/bin/php8.4", "x"), (f"{self.P}/current/artisan", "r"), (f"{self.P}/current/config/app.php", "r"),
                             (f"{self.P}/current/.env", "r"), (f"{self.P}/current/bootstrap/cache/config.php", "w")],
        }
        for command, paths in needs.items():
            for path, access in paths:
                self.assertTrue(decide(rules, self.resolve(path), access), f"{command}: {path} {access}")
        disabled = json.loads(MATRIX_TEXT)["profiles"][self.NAME]["commands_disabled"]
        self.assertEqual([d["id"] for d in disabled], ["phase-b-migration-runner"])
        self.assertIn("not authorized", disabled[0]["reason"])
        self.assertIn("never allows", disabled[0]["reason"])  # its TCP host/port runner cannot run under this profile

    def test_the_live_probe_proves_ipv4_and_ipv6_tcp_connects_and_every_family_are_refused(self) -> None:
        checks = {c["id"]: c for c in json.loads(MATRIX_TEXT)["profiles"][self.NAME]["network_checks"]}
        self.assertEqual({k: v["expect"] for k, v in checks.items()}, {i: "deny" for i in (
            "inet-stream-create", "inet-stream-bind", "inet-stream-connect-loopback", "inet6-stream-create", "inet6-stream-connect-loopback",
            "inet-dgram-create", "inet6-dgram-create", "unix-dgram-create", "unix-seqpacket-create", "netlink-raw-create", "unix-raw-create", "unix-stream-bind", "unix-stream-create")})
        self.assertEqual((checks["inet-stream-connect-loopback"]["action"], checks["inet6-stream-connect-loopback"]["action"]), ("connect", "connect"))
        self.assertTrue(checks["inet6-stream-connect-loopback"]["optional"])  # a host without ::1 reports UNPROVEN, never PASS
        self.assertNotIn("optional", checks["inet-stream-connect-loopback"])

    def test_fixture_cache_directory_is_writable_by_www_data_like_the_helper_leaves_it(self) -> None:
        matrix = json.loads(MATRIX_TEXT)
        cache = next(e for e in matrix["fixtures"]["mkdirs"] if e["path"].endswith("releases/wpcg-fixture/bootstrap/cache"))
        self.assertEqual((cache["uid"], cache["gid"], cache["mode"]), (33, 33, "2770"))

    def test_matrix_proves_the_same_decisions_for_the_live_probe(self) -> None:
        profiles = json.loads(MATRIX_TEXT)["profiles"]
        deploy = profiles["wpcaregrid-php-cli-production-deploy"]
        build = profiles["wpcaregrid-php-cli-production-build"]
        self.assertEqual(deploy["profile_role"], "production-deploy-cli")
        self.assertEqual(build["profile_role"], "production-build-cli")
        for profile in (deploy, build):
            self.assertNotIn(profile["profile_role"], json.loads(MATRIX_TEXT)["role_units"])  # transient, not a service role
        deploy_ids = {c["id"]: c["expect"] for c in deploy["checks"]}
        build_ids = {c["id"]: c["expect"] for c in build["checks"]}
        for needed in ("cache-write", "storage-write", "storage-via-release-link"):
            self.assertEqual(build_ids[needed], "allow")
            self.assertEqual(deploy_ids[needed], "deny")
        self.assertEqual(build_ids["shared-env-read"], "allow")
        self.assertEqual(deploy_ids["shared-env-read"], "allow")
        self.assertEqual(build_ids["staging-env-read"], "deny")
        self.assertEqual(deploy_ids["staging-env-read"], "deny")
        self.assertTrue(all(x["expect"] == "deny" for x in build["network_checks"]))
        self.assertEqual(deploy["network_checks"][0]["expect"], "allow")


class OtherProfilesUnchangedSince102(unittest.TestCase):
    """QA: 'other profiles unchanged except the /run/mysqld deny move' is enforced, not just true today."""

    OLD = ROOT / "deploy/php-fpm-confinement/1.0.2"

    @staticmethod
    def norm(text: str) -> list:
        return [re.sub(r"confinement 1\.0\.\d", "confinement X", line) for line in text.splitlines()]

    def test_every_non_deploy_profile_differs_only_by_the_header_and_the_mysqld_deny(self) -> None:
        deny = "  audit deny /run/mysqld/{,**} rwklm,"
        for name, relative in PROFILES.items():
            if name in ("wpcaregrid-php-cli-production-deploy", "wpcaregrid-php-cli-production-build"):
                continue
            old, new = self.norm((self.OLD / relative).read_text()), self.norm((ARTIFACTS / relative).read_text())
            extra = "  audit deny signal (send) peer=wpcaregrid-php-cli-production-build,"
            if name == "wpcaregrid-nginx":
                self.assertEqual([l for l in new if l not in old], [extra], name)
                self.assertEqual([l for l in old if l not in new], [], name)
            else:
                self.assertEqual([l for l in new if l not in old], [deny, extra], name)
                self.assertEqual([l for l in old if l not in new], [], name)
                self.assertEqual(new.index(deny), len(new) - 3, "mysqld deny remains before appended peer deny")

    def test_the_common_abstraction_only_loses_the_mysqld_deny(self) -> None:
        relative = "apparmor/abstractions/wpcaregrid-php-fpm-common"
        old, new = self.norm((self.OLD / relative).read_text()), self.norm((ARTIFACTS / relative).read_text())
        self.assertEqual([l for l in old if l not in new], ["  audit deny /run/mysqld/{,**} rwklm,"])
        self.assertTrue(all(l.lstrip().startswith("#") for l in new if l not in old))

    def test_non_deploy_network_rules_are_exactly_those_of_1_0_2(self) -> None:
        for name, relative in PROFILES.items():
            if name in ("wpcaregrid-php-cli-production-deploy", "wpcaregrid-php-cli-production-build"):
                continue
            old = {r.raw for r in parse_text((self.OLD / relative).read_text(), "old", [], self.OLD, set())[0] if r.kind in ("network", "unix", "capability")}
            new = {r.raw for r in load_profile(ARTIFACTS, name, [])[0] if r.kind in ("network", "unix", "capability")}
            self.assertEqual(new, old, name)


class DeployNetworkVerdict(unittest.TestCase):
    """The live net:* checks are what proves the no-network profile on the host; the verifier must fail if it regains any."""

    PROFILE = "wpcaregrid-php-cli-production-deploy"

    def run_with(self, mutate=None):
        return VerifyAcceptance().run_verify(mutate, profile=self.PROFILE, phase="post")

    def result(self, state, ident):
        return next(r for r in state["confined"]["results"] if r["id"] == ident)

    def test_coherent_run_has_every_net_check_denied_with_its_own_event(self) -> None:
        verdict = self.run_with()
        self.assertEqual(verdict.failures, [])
        net = [p for p in verdict.passes if p.startswith("PASS net:")]
        self.assertEqual(len(net), 13)
        self.assertTrue([p for p in verdict.passes if p.startswith("PASS socket:mysql-production")])
        for needed in ("net:inet-stream-connect-loopback", "net:inet6-stream-connect-loopback"):
            self.assertTrue([p for p in net if needed in p], needed)

    def test_a_profile_that_regained_tcp_connect_is_caught_for_ipv4_and_ipv6(self) -> None:
        for ident in ("net:inet-stream-connect-loopback", "net:inet-stream-create", "net:inet6-stream-create", "net:inet6-stream-connect-loopback",
                      "net:inet-dgram-create", "net:unix-dgram-create", "net:netlink-raw-create", "net:unix-raw-create",
                      "net:inet-stream-bind", "net:inet6-dgram-create"):
            verdict = self.run_with(lambda s, i=ident: self.result(s, i).update(errno=0))
            self.assertTrue(any(ident in f and "confined access not refused" in f for f in verdict.failures), ident)

    def test_the_exact_socket_must_work_and_a_wrong_or_missing_path_fails_closed(self) -> None:
        """No TCP fallback: if the host socket differs, the allowed connect fails and acceptance fails (never passes)."""
        refused = self.run_with(lambda s: self.result(s, "socket:mysql-production").update(errno=13))
        self.assertTrue(any("socket:mysql-production" in f and "allowed access failed" in f for f in refused.failures), refused.failures[:3])
        missing = self.run_with(lambda s: [r.update(errno=errno_.ENOENT) for st in ("baseline", "confined") for r in s[st]["results"]
                                            if r["id"] == "socket:mysql-production"])
        self.assertTrue(any("socket:mysql-production" in f for f in missing.failures))
        self.assertFalse(any("socket:mysql-production" in p for p in missing.passes))
        # an alternate MySQL socket that the profile failed to refuse
        for alt in ("socket:mysql-restore", "socket:mysql-vendor-main", "socket:fpm-staging-next", "socket:fpm-legacy"):
            verdict = self.run_with(lambda s, a=alt: self.result(s, a).update(errno=0))
            self.assertTrue(any(alt in f and "confined access not refused" in f for f in verdict.failures), alt)
        # unix stream create refused would also break the probe: the allow check catches it
        broken = self.run_with(lambda s: self.result(s, "net:unix-stream-create").update(errno=13))
        self.assertTrue(any("net:unix-stream-create" in f and "allowed access failed" in f for f in broken.failures))

    def test_sibling_socket_requires_the_exact_live_canary_banner(self) -> None:
        ident = "socket:mysql-sibling-canary"
        good = self.run_with()
        self.assertEqual(good.failures, [])
        self.assertTrue([p for p in good.passes if p.startswith(f"PASS {ident}")])

        # Listener absent/stale/wrong cannot count as baseline reachability, even if a stale socket pathname exists.
        for errno_value, banner_ok in ((errno_.ECONNREFUSED, False), (0, False), (errno_.ENOENT, False)):
            def bad_baseline(state, e=errno_value, banner=banner_ok):
                result = next(r for r in state["baseline"]["results"] if r["id"] == ident)
                result.update(errno=e, banner_ok=banner)
            verdict = self.run_with(bad_baseline)
            self.assertTrue(any(ident in f and "exact sibling canary banner" in f for f in verdict.failures), verdict.failures[:5])

        # A confined read of the canary is never accepted, even if a crafted result also claims denial.
        leaked = self.run_with(lambda s: next(r for r in s["confined"]["results"] if r["id"] == ident).update(errno=0, banner_ok=True))
        self.assertTrue(any(ident in f and ("exact sibling canary banner" in f or "denied-canary outcome" in f or "confined access not refused" in f)
                            for f in leaked.failures), leaked.failures[:5])

    def test_a_refusal_without_the_matching_family_event_does_not_pass(self) -> None:
        def wrong_family(state):
            state["audit"] = state["audit"].replace('family=\\"inet6\\"', 'family=\\"inet\\"')
        verdict = self.run_with(wrong_family)
        self.assertTrue(any("net:inet6" in f and "no AppArmor DENIED record" in f for f in verdict.failures), verdict.failures[:3])
        self.assertTrue(self.run_with(lambda s: s.update(audit="")).failures)

    def test_a_baseline_that_never_connected_is_inconclusive_for_required_checks_and_unproven_for_optional_ipv6(self) -> None:
        required = self.run_with(lambda s: next(r for r in s["baseline"]["results"] if r["id"] == "net:inet-stream-connect-loopback").update(errno=errno_.EDESTADDRREQ))
        self.assertTrue(any("net:inet-stream-connect-loopback" in f and "baseline could not reach" in f for f in required.failures))
        optional = self.run_with(lambda s: next(r for r in s["baseline"]["results"] if r["id"] == "net:inet6-stream-connect-loopback").update(errno=errno_.EDESTADDRREQ))
        self.assertEqual(optional.failures, [])
        self.assertTrue(any("net:inet6-stream-connect-loopback" in n and "UNPROVEN" in n for n in optional.notes))
        masked = self.run_with(lambda s: (next(r for r in s["baseline"]["results"] if r["id"] == "net:inet6-stream-connect-loopback").update(errno=errno_.EDESTADDRREQ),
                                          self.result(s, "net:inet6-stream-connect-loopback").update(errno=0)))
        self.assertTrue(any("confined access succeeded" in f for f in masked.failures))


class TransientDeployCliClass(unittest.TestCase):
    """The deploy CLI is provable in complete peer inventories as a transient, non-service class."""

    LABEL = "wpcaregrid-php-cli-production-build (enforce)"

    def proc(self, **kw):
        base = {"pid": 7001, "ppid": 7000, "uids": [33] * 4, "gids": [33] * 4, "groups": [], "comm": "php8.4", "starttime": 12, "label": self.LABEL,
                "unit": None, "exe": "/usr/bin/php8.4", "fds": ["/dev/null", "socket:[9]", "/var/www/wpcaregrid-production/releases/r1/artisan"],
                "state": "S", "argv0": "/usr/bin/php8.4", "cmdline_sha256": "a" * 64, "cgroup": "0::/user.slice/user-0.slice/session-7.scope", "age_s": 3.2}
        base.update(kw)
        return base

    def run_with(self, mutate_proc=None, phase="post", profile="wpcaregrid-nginx", **kw):
        def mutate(state):
            for key in ("before", "after"):
                state[key]["processes"].append(self.proc(**kw))
        return VerifyAcceptance().run_verify(mutate, profile=profile, phase=phase)

    def test_a_confined_transient_deploy_cli_is_permitted_after_production_starts(self) -> None:
        verdict = self.run_with()
        self.assertEqual(verdict.failures, [])
        self.assertTrue(any("transient production-build-cli" in n for n in verdict.notes))

    def test_it_is_not_permitted_before_production_exists(self) -> None:
        verdict = self.run_with(phase="pre")
        self.assertTrue(any("production is not allowed to run PHP yet" in f for f in verdict.failures), verdict.failures[:3])

    def test_wrong_exe_uid_or_a_service_unit_is_refused(self) -> None:
        for change in ({"exe": "/usr/bin/php8.5"}, {"exe": "/usr/bin/dash"}, {"uids": [0, 0, 0, 0]}, {"uids": [33, 33, 33, 0]},
                       {"unit": "supervisor.service"}, {"unit": "wpcaregrid-production-php8.4-fpm.service"}, {"exe": None},
                       {"gids": [33, 33, 33, 0]}, {"gids": [1000] * 4}, {"groups": [33, 4]}, {"groups": [1000]},
                       {"argv0": "/usr/bin/php8.4 -S 0.0.0.0:80"}, {"argv0": "php8.4"},
                       {"cgroup": "0::/system.slice/run-u12.service"}, {"cgroup": "0::/user.slice/user-0.slice/user@0.service/app.slice/x.scope"},
                       {"cgroup": "0::/user.slice/user-1000.slice/session-3.scope"}, {"cgroup": ""},
                       {"cmdline_sha256": None}, {"cmdline_sha256": "xyz"}, {"age_s": 5000.0}, {"age_s": None}):
            verdict = self.run_with(**change)
            self.assertTrue(any("not the permitted class" in f for f in verdict.failures), (change, verdict.failures[:3]))

    def test_a_daemonised_or_long_lived_php_in_the_session_scope_is_refused(self) -> None:
        """Review: `php -S` or `queue:work` left in a session scope must not pass indefinitely as 'transient'."""
        verdict = self.run_with(age_s=86400.0, argv0="/usr/bin/php8.4")
        self.assertTrue(any("not short-lived" in f for f in verdict.failures), verdict.failures[:2])
        self.assertEqual(self.run_with(age_s=959.0).failures, [])

    def test_a_probe_profile_process_in_an_inventory_is_refused(self) -> None:
        """Review: probe labels used to skip every inventory check although the probe profile grants python and secrets."""
        for label in ("wpcaregrid-php-cli-production-deploy-probe (enforce)", "wpcaregrid-nginx-probe (enforce)"):
            verdict = self.run_with(label=label, comm="python3", exe="/usr/bin/python3")
            self.assertTrue(any("probe-profile label" in f for f in verdict.failures), label)

    def test_a_deploy_cli_that_holds_staging_or_control_descriptors_is_refused(self) -> None:
        for target in ("/var/lib/wpcaregrid-deploy/staging-next/shared/.env", "/var/www/wpcaregrid-staging-new/artisan", "/etc/wpcaregrid-deploy/x", "/home/deploy/x"):
            verdict = self.run_with(fds=[target])
            self.assertTrue(any("not the permitted class" in f and "descriptor" in f for f in verdict.failures), target)

    def test_the_label_without_the_class_attributes_and_other_labels_still_block(self) -> None:
        verdict = self.run_with(label="wpcaregrid-php-cli-production-deploy (complain)")
        self.assertTrue(any("maps to no permitted class" in f for f in verdict.failures))
        verdict = self.run_with(label="unconfined", comm="php8.4")
        self.assertTrue(any("maps to no permitted class" in f for f in verdict.failures))

    def test_a_churning_transient_process_makes_the_two_inventories_disagree(self) -> None:
        def only_before(state):
            state["before"]["processes"].append(self.proc())
        verdict = VerifyAcceptance().run_verify(only_before, profile="wpcaregrid-nginx", phase="post")
        self.assertTrue(any("churn or pid reuse" in f for f in verdict.failures))

    def test_matrix_and_binding_describe_the_class_for_the_canonical_contract(self) -> None:
        cls = next(x for x in json.loads(MATRIX_TEXT)["transient_classes"] if x["label"] == self.LABEL)
        self.assertEqual((cls["exe"], cls["uids"], cls["unit"], cls["phases"]), ("/usr/bin/php8.4", [33] * 4, None, ["post"]))
        binding = json.loads((ARTIFACTS / "binding.json").read_text())["aa_exec_profiles"]["wpcaregrid-php-cli-production-build"]
        self.assertEqual((binding["exe"], binding["unit"], binding["unit_attachment"], binding["exec_path_attachment"], binding["peer_label"]),
                         ("/usr/bin/php8.4", None, None, None, self.LABEL))
        self.assertIn("never a *.service unit", binding["cgroup"])


def git_out(*args: str) -> str | None:
    try:
        result = subprocess.run(["git", "-C", str(ROOT), *args], capture_output=True, text=True, timeout=60, check=False)
    except (OSError, subprocess.SubprocessError):
        return None
    return result.stdout.strip() if result.returncode == 0 else None


class PackageImmutability(unittest.TestCase):
    """Published packages never change: a change ships as a new version directory.

    Independent immutable reference: the Git TREE OBJECT id of each frozen package directory (it commits to every
    file name, mode and blob id, so it covers bytes, executable bits and the file set, not just the listed checksums),
    pinned here AND re-derived from the historical commit when that history is available, so editing a package and
    its checksums (or a constant) in one commit is caught. Set PHP_FPM_CONFINEMENT_REQUIRE_GIT_IDENTITY=1 (CI does) to
    make a missing git checkout a failure instead of a skip.
    """

    # version -> (git tree id of deploy/php-fpm-confinement/<version> at its reviewed commit, that commit)
    FROZEN = {
        "1.0.4": ("71dc6c8150a0a8f0658dd0a3a6fb62e0baf41912", "9a54ca6581ea0fe173667236dcd6555f10ccdc12"),
        "1.0.1": ("202e548782e0071d55fd9efc369b790e556ef43c", "547212372efb80e8acf7ff2cd55951950cf94ea8"),
        "1.0.2": ("90502b788a660347c52a83ccbe2c99c965fba2ef", "f478c8a70f91078e828ed89403cb9f890457c195"),
        "1.0.3": ("dcf18aa07cbdb8bcddde8f2a0527a13701ab0316", "96dc862ea00ebbcaa0dfe87e37ee44b984a74955"),
    }
    SUMS_1_0_1 = "1a44d13aa63c71e2228e2ab52cfce76f7139724f4025d13c665d9064a2222883"

    def require_git(self) -> None:
        if git_out("rev-parse", "--git-dir") is None:
            if os.environ.get("PHP_FPM_CONFINEMENT_REQUIRE_GIT_IDENTITY") == "1":
                self.fail("a git checkout is required to verify the frozen packages' object identity")
            self.skipTest("not a git checkout: frozen-package object identity NOT verified in this run")

    def test_frozen_packages_match_their_pinned_git_tree_object(self) -> None:
        self.require_git()
        for version, (tree, _) in self.FROZEN.items():
            self.assertEqual(git_out("rev-parse", f"HEAD:deploy/php-fpm-confinement/{version}"), tree, version)

    def test_frozen_packages_have_no_uncommitted_or_untracked_change(self) -> None:
        self.require_git()
        for version in self.FROZEN:
            path = f"deploy/php-fpm-confinement/{version}"
            self.assertEqual(git_out("status", "--porcelain", "--untracked-files=all", "--", path), "", f"{version} was modified in the working tree")

    def test_the_pin_agrees_with_the_historical_commit_when_history_is_available(self) -> None:
        self.require_git()
        checked = 0
        for version, (tree, commit) in self.FROZEN.items():
            if git_out("cat-file", "-t", commit) != "commit":
                if os.environ.get("PHP_FPM_CONFINEMENT_REQUIRE_GIT_HISTORY") == "1":
                    self.fail(f"history required but the pinned commit {commit[:7]} for {version} is missing")
                continue  # shallow clone: the pinned tree id above is the reference
            ancestor = subprocess.run(["git", "-C", str(ROOT), "merge-base", "--is-ancestor", commit, "HEAD"], capture_output=True)
            self.assertEqual(ancestor.returncode, 0, f"pinned commit {commit[:7]} for {version} is not an ancestor of HEAD")
            self.assertEqual(git_out("rev-parse", f"{commit}:deploy/php-fpm-confinement/{version}"), tree, version)
            self.assertEqual(git_out("rev-parse", f"HEAD:deploy/php-fpm-confinement/{version}"),
                             git_out("rev-parse", f"{commit}:deploy/php-fpm-confinement/{version}"), f"{version} changed after {commit[:7]}")
            checked += 1
        if checked == 0 and os.environ.get("PHP_FPM_CONFINEMENT_REQUIRE_GIT_HISTORY") == "1":
            self.fail("history required but unavailable")

    def test_tree_identity_is_reproducible_from_bytes_and_tamper_evident(self) -> None:
        """Re-derive the tree id from the files alone in a scratch repository: any byte or mode change moves it."""
        self.require_git()
        with tempfile.TemporaryDirectory(prefix="wpcg-tree-id-") as temp:
            repo = Path(temp)
            def run(*args):
                return subprocess.run(["git", "-C", str(repo), "-c", "core.autocrlf=false", "-c", "core.filemode=true", *args],
                                      capture_output=True, text=True, check=True).stdout.strip()
            run("init", "-q")
            target = repo / "pkg"
            shutil.copytree(ROOT / "deploy/php-fpm-confinement/1.0.1", target)
            run("add", "-A")
            self.assertEqual(run("write-tree", "--prefix=pkg/"), self.FROZEN["1.0.1"][0])
            victim = target / "apparmor/wpcaregrid-php-fpm-production"
            original = victim.read_bytes()
            victim.write_bytes(original + b"#")
            run("add", "-A")
            self.assertNotEqual(run("write-tree", "--prefix=pkg/"), self.FROZEN["1.0.1"][0])
            victim.write_bytes(original)
            victim.chmod(0o755)
            run("add", "-A")
            self.assertNotEqual(run("write-tree", "--prefix=pkg/"), self.FROZEN["1.0.1"][0], "an executable-bit change is also caught")

    def test_1_0_1_checksums_still_match(self) -> None:
        old = ROOT / "deploy" / "php-fpm-confinement" / "1.0.1"
        self.assertEqual(hashlib.sha256((old / "SHA256SUMS").read_bytes()).hexdigest(), self.SUMS_1_0_1)
        for line in (old / "SHA256SUMS").read_text().splitlines():
            digest, _, name = line.partition("  ")
            self.assertEqual(hashlib.sha256((old / name).read_bytes()).hexdigest(), digest, name)

    def test_1_0_5_is_a_distinct_package_with_split_cli_profiles_and_pinned_sibling_canary(self) -> None:
        new = ARTIFACTS
        for version in self.FROZEN:
            old = ROOT / "deploy" / "php-fpm-confinement" / version
            old_files = {str(p.relative_to(old)) for p in old.rglob("*") if p.is_file()}
            new_files = {str(p.relative_to(new)) for p in new.rglob("*") if p.is_file()}
            if version == "1.0.4":
                self.assertEqual(new_files - old_files, {"apparmor/wpcaregrid-php-cli-production-build"})
            elif version == "1.0.1":
                self.assertEqual(new_files - old_files, {"apparmor/wpcaregrid-php-cli-production-deploy", "apparmor/wpcaregrid-php-cli-production-build", "acceptance/sibling-listener.py"})
            elif version == "1.0.3":
                self.assertEqual(new_files - old_files, {"apparmor/wpcaregrid-php-cli-production-build", "acceptance/sibling-listener.py"})
            elif version == "1.0.2":
                self.assertEqual(new_files - old_files, {"apparmor/wpcaregrid-php-cli-production-build", "acceptance/sibling-listener.py"})
            else:
                self.assertEqual(new_files, old_files)
            self.assertEqual(old_files - new_files, set())
            self.assertNotEqual((old / "SHA256SUMS").read_bytes(), (new / "SHA256SUMS").read_bytes())
            self.assertEqual(json.loads((old / "binding.json").read_text())["package_version"], version)
        self.assertEqual(json.loads((new / "binding.json").read_text())["package_version"], "1.0.5")
        self.assertEqual(len(json.loads((new / "binding.json").read_text())["installed_files"]), 17)
        profile_matrix=json.loads((new / "acceptance/matrix.json").read_text())["profiles"]
        self.assertEqual(set(profile_matrix["wpcaregrid-php-cli-production-deploy"]["commands_supported"] and [x["id"] for x in profile_matrix["wpcaregrid-php-cli-production-deploy"]["commands_supported"]]), {"runtime-identity-probe"})
        self.assertEqual(set(x["id"] for x in profile_matrix["wpcaregrid-php-cli-production-build"]["commands_supported"]), {"package-discover", "config-cache"})
        self.assertTrue(all(x["expect"] == "deny" for x in profile_matrix["wpcaregrid-php-cli-production-build"]["sockets"]))
        # the one security-relevant change since 1.0.2: the deploy CLI profile lost its network rule
        old_profile = (ROOT / "deploy/php-fpm-confinement/1.0.2/apparmor/wpcaregrid-php-cli-production-deploy").read_text()
        new_profile = (new / "apparmor/wpcaregrid-php-cli-production-deploy").read_text()
        self.assertIn("network (create, connect, shutdown, send, receive, getattr, getopt, setopt) inet stream", old_profile)
        self.assertNotIn("(getattr, getopt, setopt) inet stream", new_profile.replace("audit deny", ""))
        self.assertIn("network (create, connect, shutdown, send, receive, getattr, getopt, setopt) unix stream", new_profile)
        matrix = json.loads((new / "acceptance/matrix.json").read_text())
        canary = matrix["sibling_canary"]
        self.assertEqual(canary["path"], "/run/mysqld/wpcaregrid-acceptance-canary.sock")
        self.assertEqual(canary["banner"], "WPCG-SIBLING-CANARY-v1\n")
        deploy_cli = matrix["profiles"]["wpcaregrid-php-cli-production-deploy"]
        sibling = next(item for item in deploy_cli["sockets"] if item["id"] == "mysql-sibling-canary")
        self.assertEqual(sibling["path"], canary["path"])
        self.assertEqual(sibling["banner"], canary["banner"])
        self.assertEqual(sibling["expect"], "deny")
        self.assertEqual({item["id"] for item in deploy_cli["network_checks"] if item["type"] == "SOCK_RAW"},
                         {"unix-raw-create", "netlink-raw-create"})
        self.assertEqual(hashlib.sha256((new / "acceptance/sibling-listener.py").read_bytes()).hexdigest(),
                         next(line.split()[0] for line in (new / "SHA256SUMS").read_text().splitlines()
                              if line.endswith("  acceptance/sibling-listener.py")))



class ReviewRegression105(unittest.TestCase):
    def test_raw_socket_audit_cannot_satisfy_stream_or_other_type(self) -> None:
        for replacement in ("stream", "dgram"):
            def corrupt(state, replacement=replacement):
                lines=[]
                for line in state["audit"].splitlines():
                    record=json.loads(line)
                    record["MESSAGE"]=record["MESSAGE"].replace('sock_type="raw"', f'sock_type="{replacement}"')
                    lines.append(json.dumps(record))
                state["audit"]="\n".join(lines)
            verdict = self.run_verdict(corrupt)
            self.assertTrue(verdict.failures, replacement)

    def run_verdict(self, mutate):
        test=VerifyAcceptance()
        test.setUp()
        try: return test.run_verify(mutate, profile="wpcaregrid-php-cli-production-deploy")
        finally: test.tearDown()

    def test_verifier_cli_accepts_eight_positional_arguments_and_not_usage(self) -> None:
        import contextlib, io
        mod=load_script(ACCEPTANCE / "verify-acceptance.py", "verify_acceptance_cli_105")
        with tempfile.TemporaryDirectory() as tmp:
            paths=[Path(tmp)/name for name in ("matrix.json","baseline.json","confined.json","before.json","after.json","audit.jsonl")]
            paths[0].write_text(MATRIX_TEXT)
            empty={}; [x.write_text(json.dumps(empty)) for x in paths[1:5]]
            paths[5].write_text("")
            out=io.StringIO(); err=io.StringIO()
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                result=mod.main([str(paths[0]),"wpcaregrid-nginx","post",str(paths[1]),str(paths[2]),str(paths[3]),str(paths[4]),str(paths[5])])
            self.assertEqual(result,1)
            self.assertNotIn("usage",err.getvalue().lower())

    def test_collector_identity_changes_when_gid_or_supplementary_groups_change(self) -> None:
        mod=load_script(ACCEPTANCE / "collect-peers.py", "collect_peers_identity_105")
        first={"processes":[{"pid":7,"starttime":10,"label":"x","unit":None,"uids":[1]*4,"gids":[33]*4,"groups":[]}]}
        second=json.loads(json.dumps(first)); second["processes"][0]["gids"]=[1]*4
        self.assertNotEqual(mod.identity(first),mod.identity(second))
        second=json.loads(json.dumps(first)); second["processes"][0]["groups"]=[33]
        self.assertNotEqual(mod.identity(first),mod.identity(second))

    def test_listener_shell_line_has_one_continuation_backslash(self) -> None:
        doc=DOC.read_text()
        line=next(line for line in doc.splitlines() if "systemd-run --collect --unit=wpcg-sibling-canary" in line)
        self.assertTrue(line.endswith("\\"), repr(line))
        self.assertFalse(line.endswith("\\\\"), repr(line))


class OriginalProfileHarness(unittest.TestCase):
    def test_runbook_tests_the_original_profile_for_exec_denial(self) -> None:
        doc = DOC.read_text(encoding="utf-8")
        self.assertIn("-p AppArmorProfile=wpcaregrid-php-fpm-production /usr/bin/python3", doc)
        self.assertIn("exec-python", doc)


class BindingManifest(unittest.TestCase):
    """binding.json is what workstream A's canonical contract binds; it must agree with the matrix and the files."""

    @classmethod
    def setUpClass(cls) -> None:
        cls.binding = json.loads((ARTIFACTS / "binding.json").read_text())
        cls.matrix = json.loads(MATRIX_TEXT)

    def test_installed_files_map_exactly_to_the_non_acceptance_artifacts(self) -> None:
        artifacts = {rel for rel in EXPECTED_FILES if not rel.startswith("acceptance/") and rel not in ("SHA256SUMS", "binding.json")}
        self.assertEqual(set(self.binding["installed_files"].values()), artifacts)
        self.assertEqual(len(set(self.binding["installed_files"])), len(artifacts))
        for installed in self.binding["installed_files"]:
            self.assertTrue(installed.startswith(("/etc/apparmor.d/", "/etc/systemd/system/", "/etc/php/8.4/fpm-wpcaregrid-production/")), installed)
            self.assertEqual(installed, os.path.normpath(installed))

    def test_services_agree_with_the_matrix_units_and_profiles(self) -> None:
        self.assertEqual(set(self.binding["services"]), set(self.matrix["role_units"]))
        for role, entry in self.binding["services"].items():
            self.assertEqual(entry["unit"], self.matrix["role_units"][role])
            self.assertEqual(entry["apparmor_profile"], self.matrix["role_profiles"][role])
            self.assertTrue(set(entry["files"]) <= set(self.binding["installed_files"]), role)
            self.assertIn(entry["profile_path"], entry["files"])
            self.assertIn(entry["attachment_path"], entry["files"])
            profile_file = ARTIFACTS / self.binding["installed_files"][entry["profile_path"]]
            self.assertRegex(profile_file.read_text(), rf"(?m)^profile {entry['apparmor_profile']} flags=")
            attachment = (ARTIFACTS / self.binding["installed_files"][entry["attachment_path"]]).read_text()
            self.assertIn(f"AppArmorProfile={entry['apparmor_profile']}", attachment)
            self.assertEqual(entry["profile_path"], "/etc/apparmor.d/" + entry["apparmor_profile"] if "jobs" not in role else "/etc/apparmor.d/wpcaregrid-php-cli-staging")
            self.assertEqual(("/etc/apparmor.d/abstractions/wpcaregrid-php-fpm-common" in entry["files"]), role != "nginx")

    def test_rules_state_the_review_decisions(self) -> None:
        rules = self.binding["rules"]
        self.assertEqual(rules["exec_path_attachments"], [])
        self.assertIs(rules["www_data_cron_permitted"], False)
        self.assertEqual(rules["unconfined_same_uid_classes"], [])
        self.assertEqual(rules["permitted_group33_holders"], self.matrix["permitted_group33_holders"])
        self.assertEqual(rules["package_owner_mode"], {"uid": 0, "gid": 0, "mode": "0644", "type": "regular", "nlink": 1})
        for name in PROFILES:  # the statement is true of the artifacts: nothing attaches by exec path
            self.assertNotRegex((ARTIFACTS / PROFILES[name]).read_text(), r"(?m)^profile \S+ /\S+ flags=")
        self.assertEqual(self.binding["inventory_schema"]["units"], sorted(set(self.matrix["role_units"].values())))
        self.assertEqual(self.binding["package_version"], ARTIFACTS.name)
        deploy = self.binding["aa_exec_profiles"]["wpcaregrid-php-cli-production-deploy"]
        self.assertEqual((deploy["gids"], deploy["argv0"], deploy["max_age_seconds"]), ([33] * 4, "/usr/bin/php8.4", 960))
        self.assertIn("no TCP", deploy["network"])
        self.assertEqual({c["id"] for c in deploy["commands_supported"]}, {"runtime-identity-probe"})
        build = self.binding["aa_exec_profiles"]["wpcaregrid-php-cli-production-build"]
        self.assertEqual({c["id"] for c in build["commands_supported"]}, {"package-discover", "config-cache"})
        self.assertTrue(build["network"].startswith("none"))
        self.assertEqual([c["id"] for c in deploy["commands_disabled"]], ["phase-b-migration-runner"])
        self.assertIn("unix socket only", deploy["runtime_probe_constraint"])
        self.assertIn("no TCP fallback", deploy["runtime_probe_constraint"])
        self.assertEqual(self.binding["rules"]["deploy_cli_network"], "unix-stream connect to the exact MySQL socket only; no TCP")
        self.assertTrue(re.search(deploy["cgroup_regex"], "0::/user.slice/user-0.slice/session-12.scope"))
        self.assertFalse(re.search(deploy["cgroup_regex"], "0::/system.slice/run-u1.service"))
        self.assertIn("Phase B stays disabled", deploy["phase_b_interpreter"])
        self.assertEqual(self.binding["schema"], 1)

    def test_contract_module_is_not_carried_in_this_branch(self) -> None:
        self.assertFalse((ROOT / "scripts/deployment_environment_contract_2_1_3.py").exists())
        self.assertFalse((ROOT / "scripts/tests/deployment-environment-contract-2-1-3-test.py").exists())
        ci = (ROOT / ".github/workflows/ci.yml").read_text()
        self.assertNotIn("deployment-environment-contract-2-1-3-test", ci)
        self.assertIn("php-fpm-confinement-test.py", ci)


class AbstractSocketDenial(unittest.TestCase):
    def test_every_profile_denies_abstract_unix_sockets(self) -> None:
        common = (ARTIFACTS / "apparmor/abstractions" / COMMON).read_text()
        self.assertIn("audit deny unix (connect, send, receive) peer=(addr=@**),", common)
        self.assertIn("audit deny unix (bind, listen, accept) addr=@**,", common)
        for name in PROFILES:
            rules = load_profile(ARTIFACTS, name, [])[0]
            self.assertEqual(len([r for r in rules if r.kind == "unix" and r.deny and r.audit and "@" in r.body]), 2, name)
            explicit = {r.raw for r in rules if r.kind == "unix" and r.deny and r.audit and "@" not in r.body}
            # The deploy CLI also states its unix denies as unix rules, in case the parser does not turn the
            # `network unix <type>` form into them.
            expected = {"audit deny unix type=dgram,", "audit deny unix type=seqpacket,", "audit deny unix type=raw,",
                        "audit deny unix (bind, listen, accept) type=stream,"} if name in ("wpcaregrid-php-cli-production-deploy", "wpcaregrid-php-cli-production-build") else set()
            self.assertEqual(explicit, expected, name)
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
            "/proc/<pid>/attr/current", "/proc/<pid>/fd", "AppArmor 1.0.5 does not separate TCP ports", "wpcaregrid-php-cli-production-deploy",
            "aa-exec", "never a `*.service` unit", "Packages are immutable",
            "derive-probe-profile.py", "sha256sum -c --strict SHA256SUMS", "binding.json", "fence-production.py",
            "pre-production peer gate", "supplementary group 33", "workstream A", "wpcaregrid-php-fpm-harness",
            "systemd 255", "PrivatePIDs", "never rely on exec-time revocation", "StandardInput=file:",
            "is not sufficient on its own", "exec-python", "Invariant I",
            "-p AppArmorProfile=wpcaregrid-php-fpm-production /usr/bin/python3",
            "sibling-listener.py", "banner_ok: true", "banner_ok: false", "RuntimeMaxSec=600",
            "ECONNREFUSED", "SOCK_RAW",
        ):
            self.assertIn(needed, doc, needed)
        self.assertNotRegex(doc, r"(?i)\bis (now )?installed\b|has been (applied|installed|loaded)")

    def test_stage_zero_installs_nothing_into_unit_or_profile_search_paths(self) -> None:
        stage0 = self.section("**Stage 0", "**Stage 1")
        self.assertIn("/usr/local/libexec/wpcaregrid-php-fpm-confinement/1.0.5/", stage0)
        self.assertIn("does not install any profile, unit, drop-in", stage0)
        self.assertNotIn("90-wpcaregrid-confinement.conf", stage0)
        self.assertNotIn("cp ", stage0)

    def test_each_dropin_is_installed_only_in_its_own_stage_and_reload_follows_it(self) -> None:
        stage1 = self.section("**Stage 1", "**Stage 2")
        stage2 = self.section("**Stage 2", "**Stage 3")
        stage3 = self.section("**Stage 3", "**Stage 4")
        stage5 = self.section("**Stage 5", "**Stage 6")
        dropins = {"php8.4-fpm.service.d": stage1, "php8.5-fpm.service.d": stage2, "nginx.service.d": stage3,
                   "supervisor.service.d": stage3, "wpcaregrid-staging-next-scheduler.service.d": stage3}
        stages = [stage1, stage2, stage3, self.section("**Stage 4", "**Stage 5"), stage5, self.section("**Stage 6", "Never combine")]
        for dropin, home in dropins.items():
            for stage in stages:
                if dropin in home and stage is not home:
                    continue
                if stage is not home and not (dropin == "nginx.service.d" and stage is stage3):
                    self.assertNotIn(f"{dropin}/90-wpcaregrid", stage, dropin)
        self.assertIn("php8.4-fpm.service.d/90-wpcaregrid-confinement.conf", stage1)
        self.assertIn("php8.5-fpm.service.d/90-wpcaregrid-confinement.conf", stage2)
        for needed in ("nginx.service.d/90-wpcaregrid-confinement.conf", "supervisor.service.d/", "wpcaregrid-staging-next-scheduler.service.d/"):
            self.assertIn(needed, stage3)
        self.assertIn("wpcaregrid-production-php8.4-fpm.service", stage5)
        self.assertLess(stage1.index("90-wpcaregrid-confinement.conf"), stage1.index("daemon-reload"))
        self.assertLess(stage2.index("drop-in"), stage2.index("`daemon-reload` (only now)"))
        self.assertLess(stage3.index("copy that unit's drop-in only now"), stage3.index("`daemon-reload`"))

    def test_runbook_lists_the_residuals_and_the_grant_gate(self) -> None:
        doc = self.doc()
        for needed in ("no inherited sockets", "close_fds", "no FILE, REPLICATION or SUPER", "secure_file_priv", "local_infile", "tcp",
                       "journald", "not group-writable by gid 33", "stdout and stderr", "every release's `bootstrap/cache`"):
            self.assertIn(needed, doc, needed)
        binding = json.loads((ARTIFACTS / "binding.json").read_text())["aa_exec_profiles"]["wpcaregrid-php-cli-production-deploy"]
        self.assertEqual(len(binding["residuals"]), 3)

    def test_runbook_states_the_socket_decision_and_its_limits(self) -> None:
        doc = self.doc()
        for needed in ("/run/mysqld/wpcaregrid-production.sock", "no environment-controlled path", "no TCP fallback", "Source has not checked the live host",
                       "fails closed", "exact server-UUID check", "Phase B stays disabled and is not authorized here",
                       "AppArmor cannot tell a query from DDL", "is a new Owner decision and a new package", "socket:mysql-production"):
            self.assertIn(needed, doc, needed)

    def test_deploy_cli_profile_arrives_with_the_production_unit_never_before(self) -> None:
        name = "wpcaregrid-php-cli-production-deploy"
        for earlier in ("**Stage 0", "**Stage 1", "**Stage 2", "**Stage 3", "**Stage 4"):
            self.assertNotIn(name, self.section(earlier, "**Stage %d" % (int(earlier[-1]) + 1)), earlier)
        stage5 = self.section("**Stage 5", "**Stage 6")
        self.assertIn(name, stage5)
        self.assertIn("never attached to a unit", stage5)
        self.assertIn("without widening the FPM profile", stage5)
        doc = self.doc()
        for needed in ("7a. **Production CLI, real profiles.**", "permitted transient class", "/usr/bin/php8.5", "never a service"):
            self.assertIn(needed, doc)

    def test_staging_peers_are_confined_and_gated_before_any_production_stage(self) -> None:
        doc = self.doc()
        order = [doc.index(f"**Stage {n}") for n in range(7)]
        self.assertEqual(order, sorted(order))
        self.assertIn("staging-legacy", self.section("**Stage 1", "**Stage 2"))
        self.assertIn("staging-next", self.section("**Stage 2", "**Stage 3"))
        edge = self.section("**Stage 3", "**Stage 4")
        for needed in ("nginx", "queue workers and schedulers", "no www-data cron", "fence production jobs", "systemctl mask"):
            self.assertIn(needed, edge)
        gate = self.section("**Stage 4", "**Stage 5")
        self.assertIn("STOP", gate)
        self.assertIn("production secrets may not be provisioned", gate)
        self.assertIn("map to a permitted class", gate)
        self.assertIn("Only after Stage 4 passes", self.section("**Stage 5", "**Stage 6"))
        stage6 = doc[doc.index("**Stage 6"):doc.index("Never combine stages")]
        self.assertIn("production Nginx route is never changed before this stage", stage6)
        self.assertIn("fenced production jobs", stage6)
        for number in range(6):
            end = f"**Stage {number + 1}"
            text = self.section(f"**Stage {number}", end)
            self.assertNotIn("open the production Nginx route", text)
            self.assertNotIn("provision real production secrets", text)

    def test_rollback_fences_production_before_restoring_unconfined_peers(self) -> None:
        rollback = self.section("## Rollback", "## What the next engineer")
        r1, r2, r3, r4 = (rollback.index(f"**R{n} ") for n in (1, 2, 3, 4))
        self.assertTrue(r1 < r2 < r3 < r4)
        self.assertIn("fence production traffic", rollback[r1:r2])
        self.assertIn("stay masked", rollback[r1:r2])
        self.assertIn("never unmask", rollback[r1:r2])
        self.assertIn("fence production secrets", rollback[r2:r3])
        self.assertIn("restore peers", rollback[r3:r4])
        for peer in ("nginx", "jobs-supervisor", "jobs-scheduler", "jobs-legacy-scheduler", "staging-next", "staging-legacy"):
            self.assertIn(peer, rollback[r3:r4])
        for secret_location in ("bootstrap/cache/config.php", "production logs", "every path", "release `.env` files", "ACL xattrs"):
            self.assertIn(secret_location, rollback[r2:r3])
        self.assertIn("immediately before removing that peer's drop-in", rollback[r3:r4])
        self.assertIn("stops the remaining peers", rollback[r3:r4])
        self.assertLess(rollback.index("`jobs-legacy-scheduler`"), rollback.index("`staging-next`"))
        self.assertIn("production is fenced before any same-UID peer is returned to unconfined", rollback)
        self.assertIn("Partial rollback", rollback)
        self.assertIn("a production job unit that is no longer masked", rollback)
        self.assertNotIn("drop-in", rollback[:r3].replace("drop-ins", ""))

    def test_every_uid33_class_is_documented_and_production_jobs_are_fenced(self) -> None:
        doc = self.doc()
        for needed in ("exactly one explicitly permitted class; an unknown process blocks", "`nginx`", "`jobs-supervisor`",
                       "`jobs-scheduler`", "`jobs-legacy-scheduler`", "masked", "fail-closed start policy",
                       "ACCEPTED_UNCONFINED_SAME_UID_COMM", "systemctl mask wpcaregrid-production-scheduler.timer",
                       "no active or autostarting `wpcaregrid-production-*` Supervisor program", "There is no `cron`/`sh` class",
                       "unconfined same-UID shell", "permitted_group33_holders", "any GID 33, or supplementary group 33"):
            self.assertIn(needed, doc, needed)
        for gone in ("cron-shim", "`jobs-cron`", "pinned crontab hash", "exact-path attachment on", "fails on a real host today"):
            self.assertNotIn(gone, doc, gone)

    def test_the_acceptance_gate_is_split_so_production_is_not_required_before_it_may_start(self) -> None:
        """QA (A): a gate that needs the production unit, which may only start after the gate, is circular."""
        doc = self.doc()
        pre = self.section("**Stage 4", "**Stage 5")
        post = self.section("**Stage 5", "**Stage 6")
        self.assertIn("pre-production peer gate", pre)
        self.assertIn("verify-acceptance.py ... pre", pre)
        self.assertIn("production role is excluded", pre)
        self.assertIn("**no** process", pre)
        self.assertIn("no non-fixture file may exist in the production tree", pre)
        self.assertIn("post", post)
        self.assertIn("can only be accepted in this phase", post)
        self.assertLess(doc.index("**Stage 4"), doc.index("start `wpcaregrid-production-php8.4-fpm.service`"))
        matrix = json.loads(MATRIX_TEXT)
        self.assertEqual(matrix["phases"]["pre"]["excluded_roles"], ["production"])
        self.assertEqual(matrix["phases"]["post"]["excluded_roles"], [])

    def test_harness_is_published_readably_and_hash_verified_instead_of_run_from_the_root_only_package(self) -> None:
        """QA (B): a root:root 0700 package directory cannot be executed by www-data."""
        stage0 = self.section("**Stage 0", "**Stage 1")
        for needed in ("install -d -o root -g root -m 0755 /usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.5",
                       "install -o root -g root -m 0644", "fd-sender.py", "sha256sum -c --strict -", "stdlib-only"):
            self.assertIn(needed, stage0)
        doc = self.doc()
        self.assertIn("/usr/bin/python3 -I -B /usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.5/fd-sender.py", doc)
        self.assertNotIn('"$PKG/acceptance/fd-sender.py" "$N"', doc)  # never executed from the 0700 package directory
        tree = ast.parse((ACCEPTANCE / "fd-sender.py").read_text())
        imports = {n.names[0].name for n in ast.walk(tree) if isinstance(n, ast.Import)} | {n.module for n in ast.walk(tree) if isinstance(n, ast.ImportFrom)}
        self.assertLessEqual(imports, {"os", "socket", "sys", "time", "__future__"})
        source = (ACCEPTANCE / "fd-sender.py").read_text()
        self.assertIn("os.geteuid() == 0", source)
        self.assertNotRegex(source, r"(?i)(password|token|api[_-]?key)")

    def test_sibling_canary_listener_is_exact_path_root_only_and_inode_safe(self) -> None:
        source = (ACCEPTANCE / "sibling-listener.py").read_text(encoding="utf-8")
        self.assertIn('os.geteuid() != 0', source)
        self.assertIn('"/run/mysqld/" + os.path.basename(path)', source)
        self.assertIn('info.st_uid != 0 or info.st_mode & 0o022', source)
        self.assertIn('if os.path.lexists(path):', source)
        self.assertIn('"mode": "0666"', (ACCEPTANCE / "matrix.json").read_text())
        self.assertIn('(now.st_dev, now.st_ino) == (identity.st_dev, identity.st_ino)', source)
        self.assertIn('client.sendall(BANNER.encode())', source)

    def test_no_secret_values_in_artifacts(self) -> None:
        pattern = re.compile(r"(?i)(password|secret|token|api[_-]?key)\s*[:=]\s*\S+")
        for path in list(ARTIFACTS.rglob("*")) + [DOC]:
            if path.is_file() and path.suffix != ".py" and "__pycache__" not in path.parts:
                for line in path.read_text(encoding="utf-8").splitlines():
                    self.assertIsNone(pattern.search(line), f"{path}: {line}")


class RealParser(unittest.TestCase):
    """Compile with the real apparmor_parser when present. No kernel load, no cache."""

    def test_profiles_and_probe_profiles_compile(self) -> None:
        parser = os.environ.get("APPARMOR_PARSER_BIN") or shutil.which("apparmor_parser")  # APPARMOR_PARSER_BASE: -b dir of abstractions
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
                        [parser, "-Q", "-K", *(["-b", os.environ["APPARMOR_PARSER_BASE"]] if os.environ.get("APPARMOR_PARSER_BASE") else []),
                         "-I", str(ARTIFACTS / "apparmor"), str(target)],
                        capture_output=True, text=True, timeout=120, check=False)
                    self.assertEqual(result.returncode, 0, f"{target.name}: {result.stderr}")


if __name__ == "__main__":
    print("NOTE: source-only tests. Kernel enforcement is NOT verified here; see docs/PHP_FPM_CONFINEMENT_GO13.md.",
          file=sys.stderr)
    unittest.main(verbosity=2)
