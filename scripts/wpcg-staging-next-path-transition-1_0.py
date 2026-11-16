#!/usr/bin/env python3
"""Root-only, receipt-producing bootstrap path transition for staging-next.

This separate utility adopts the already-present application tree behind a
new sibling link and changes only the uniquely identified staging-next HTTPS
vhost root. It never changes application data, database state, TLS, workers,
PHP-FPM, or any other Nginx server. The helper controller still requires its
own authenticated baseline receipt before treating the adopted tree as a
release.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import pwd
import re
import secrets
import stat
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


VERSION = "1.0"
HOST = "staging-next.wpcaregrid.com"
APP_ROOT = Path("/var/www/wpcaregrid-staging-new")
ACTIVE_LINK = Path("/var/www/wpcaregrid-staging-next-current")
OLD_WEB_ROOT = f"{APP_ROOT}/public"
NEW_WEB_ROOT = f"{ACTIVE_LINK}/public"
FPM_SOCKET = "unix:/run/php/php8.5-fpm.sock"
NGINX_ROOT = Path("/etc/nginx")
NGINX_CONF = Path("/etc/nginx/nginx.conf")
STATE_ROOT = Path("/var/lib/wpcaregrid-deploy/staging-next/state")
CONFIG_HEADER = re.compile(r"^# configuration file (/[^:\n]+):\r?$", re.MULTILINE)


class TransitionStop(RuntimeError):
    pass


@dataclass(frozen=True)
class Token:
    value: str
    raw: str
    start: int
    end: int


@dataclass(frozen=True)
class Node:
    head: tuple[Token, ...]
    children: tuple["Node", ...] | None
    start: int
    end: int
    source: str

    @property
    def words(self) -> tuple[str, ...]:
        return tuple(token.value for token in self.head)


def _lex(source: str) -> list[Token]:
    result: list[Token] = []
    index = 0
    while index < len(source):
        ch = source[index]
        if ch.isspace():
            index += 1
            continue
        if ch == "#":
            newline = source.find("\n", index)
            index = len(source) if newline < 0 else newline + 1
            continue
        if ch in "{};":
            result.append(Token(ch, ch, index, index + 1))
            index += 1
            continue
        start = index
        if ch in "\"'":
            quote = ch
            index += 1
            chars: list[str] = []
            while index < len(source):
                if source[index] == "\\":
                    if index + 1 >= len(source):
                        raise TransitionStop("nginx_quoted_value_invalid")
                    chars.append(source[index + 1])
                    index += 2
                elif source[index] == quote:
                    index += 1
                    break
                else:
                    chars.append(source[index])
                    index += 1
            else:
                raise TransitionStop("nginx_quoted_value_unterminated")
            result.append(Token("".join(chars), source[start:index], start, index))
            continue
        while index < len(source) and not source[index].isspace() and source[index] not in "{};#\"'":
            if source[index] == "\\" and index + 1 < len(source):
                index += 2
            else:
                index += 1
        if index == start:
            raise TransitionStop("nginx_token_invalid")
        raw = source[start:index]
        result.append(Token(raw.replace("\\ ", " "), raw, start, index))
    return result


def _parse_nodes(tokens: list[Token], source: str, position: int = 0,
                 *, nested: bool = False) -> tuple[tuple[Node, ...], int]:
    nodes: list[Node] = []
    head: list[Token] = []
    while position < len(tokens):
        token = tokens[position]
        if token.value == "}":
            if not nested or head:
                raise TransitionStop("nginx_block_parse_invalid")
            return tuple(nodes), position + 1
        if token.value == ";":
            if not head:
                raise TransitionStop("nginx_empty_directive")
            nodes.append(Node(tuple(head), None, head[0].start, token.end, source))
            head = []
            position += 1
            continue
        if token.value == "{":
            if not head:
                raise TransitionStop("nginx_block_head_missing")
            children, after = _parse_nodes(tokens, source, position + 1, nested=True)
            close = tokens[after - 1]
            nodes.append(Node(tuple(head), children, head[0].start, close.end, source))
            head = []
            position = after
            continue
        head.append(token)
        position += 1
    if nested or head:
        raise TransitionStop("nginx_block_unterminated")
    return tuple(nodes), position


def parse_nginx_source(text: str, source_path: str) -> tuple[Node, ...]:
    return _parse_nodes(_lex(text), source_path)[0]


def _walk(nodes: Iterable[Node]) -> Iterable[Node]:
    for node in nodes:
        yield node
        if node.children is not None:
            yield from _walk(node.children)


def parse_nginx_dump(raw: bytes) -> dict[str, str]:
    try:
        text = raw.decode("utf-8")
    except UnicodeError:
        raise TransitionStop("nginx_config_dump_invalid") from None
    headers = list(CONFIG_HEADER.finditer(text))
    if not headers:
        raise TransitionStop("nginx_config_dump_invalid")
    sources: dict[str, str] = {}
    for index, header in enumerate(headers):
        path = header.group(1)
        if not path.startswith("/etc/nginx/"):
            raise TransitionStop("nginx_config_source_identity_invalid")
        start = header.end() + 1
        end = headers[index + 1].start() if index + 1 < len(headers) else len(text)
        content = text[start:end]
        if path in sources and sources[path] != content:
            raise TransitionStop("nginx_config_source_conflict")
        sources[path] = content
    return sources


def _server_root(node: Node) -> bool:
    if node.children is None or node.words != ("server",):
        return False
    nodes = list(_walk(node.children))
    names = [word for item in nodes if item.children is None and item.words[:1] == ("server_name",)
             for word in item.words[1:]]
    listens = [item.words[1:] for item in nodes if item.children is None and item.words[:1] == ("listen",)]
    return names == [HOST] and bool(listens) and all(
        any(value == "443" or value.endswith(":443") for value in listen) and "ssl" in listen
        for listen in listens)


def _validate_vhost(text: str, source_path: str, expected_root: str) -> tuple[Node, list[Node]]:
    nodes = parse_nginx_source(text, source_path)
    servers = [node for node in _walk(nodes) if _server_root(node)]
    if len(servers) != 1:
        raise TransitionStop("nginx_target_vhost_ambiguous")
    server = servers[0]
    directives = [node for node in _walk(server.children or ()) if node.children is None]
    if any(node.words[:1] == ("include",) for node in directives):
        raise TransitionStop("nginx_target_vhost_include_unsupported")
    roots = [node for node in directives if node.words[:1] == ("root",)]
    if not roots or any(node.words != ("root", expected_root) for node in roots):
        raise TransitionStop("nginx_document_root_mismatch")
    fastcgi = [node.words[1] for node in directives
               if node.words[:1] == ("fastcgi_pass",) and len(node.words) == 2]
    if not fastcgi or any(value != FPM_SOCKET for value in fastcgi):
        raise TransitionStop("nginx_php_fpm_route_mismatch")
    return server, roots


def _find_unique_vhost(sources: dict[str, str], config_path: str, expected_root: str):
    matches: list[tuple[str, str, Node, list[Node]]] = []
    for path, content in sources.items():
        try:
            nodes = parse_nginx_source(content, path)
        except TransitionStop:
            continue
        for node in _walk(nodes):
            if _server_root(node):
                server, roots = _validate_vhost(content, path, expected_root)
                matches.append((path, content, server, roots))
    if len(matches) != 1:
        raise TransitionStop("nginx_target_vhost_ambiguous")
    path, content, server, roots = matches[0]
    if path != config_path:
        raise TransitionStop("nginx_source_file_mismatch")
    return content, server, roots


def _discover_vhost_source(sources: dict[str, str], expected_root: str) -> str:
    candidates: list[str] = []
    for path, content in sources.items():
        try:
            nodes = parse_nginx_source(content, path)
        except TransitionStop:
            continue
        if any(_server_root(node) for node in _walk(nodes)):
            candidates.append(path)
    if len(candidates) != 1:
        raise TransitionStop("nginx_target_vhost_ambiguous")
    _validate_vhost(sources[candidates[0]], candidates[0], expected_root)
    return candidates[0]


def _prepare_config(dump: bytes, config_path: str, old_root: str, new_root: str) -> tuple[bytes, str]:
    sources = parse_nginx_dump(dump)
    if config_path not in sources:
        raise TransitionStop("nginx_source_file_missing_from_dump")
    text, server, roots = _find_unique_vhost(sources, config_path, old_root)
    # Roots must be authored directly in the identified source. Includes inside
    # the target vhost are refused above, so each replacement is local/exact.
    replacements = [(node.head[1].start, node.head[1].end) for node in roots]
    for start, end in sorted(replacements, reverse=True):
        text = text[:start] + new_root + text[end:]
    old_bytes = sources[config_path].encode("utf-8")
    if text.encode("utf-8") == old_bytes:
        raise TransitionStop("nginx_document_root_not_changed")
    # Re-parse the candidate and insist it has the same exact staging vhost.
    _validate_vhost(text, config_path, new_root)
    return text.encode("utf-8"), hashlib.sha256(old_bytes).hexdigest()


def _lstat(path: Path):
    try:
        return os.lstat(path)
    except OSError:
        raise TransitionStop("path_metadata_unavailable") from None


def _verify_dir(path: Path, uid: int, gid: int, *, exact_mode: int | None = None) -> os.stat_result:
    info = _lstat(path)
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        raise TransitionStop("directory_not_real")
    if info.st_uid != uid or info.st_gid != gid or stat.S_IMODE(info.st_mode) & 0o022:
        raise TransitionStop("directory_owner_or_mode_invalid")
    if exact_mode is not None and stat.S_IMODE(info.st_mode) != exact_mode:
        raise TransitionStop("directory_mode_invalid")
    return info


def _verify_nginx_file(path: Path, logical_path: str, root: Path,
                       root_uid: int, root_gid: int) -> tuple[os.stat_result, bytes]:
    if not logical_path.startswith(str(NGINX_ROOT) + "/"):
        raise TransitionStop("nginx_file_path_invalid")
    nginx_root_physical = root / NGINX_ROOT.relative_to("/")
    _verify_dir(root / "etc", root_uid, root_gid)
    _verify_dir(nginx_root_physical, root_uid, root_gid)
    parent = path.parent
    while parent != nginx_root_physical:
        _verify_dir(parent, root_uid, root_gid)
        parent = parent.parent
    info = _lstat(path)
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or info.st_uid != root_uid \
            or info.st_gid != root_gid or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o644:
        raise TransitionStop("nginx_file_metadata_invalid")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    fd = os.open(path, flags)
    try:
        opened = os.fstat(fd)
        if opened.st_dev != info.st_dev or opened.st_ino != info.st_ino or opened.st_size > 1_000_000:
            raise TransitionStop("nginx_file_changed_during_read")
        raw = os.read(fd, 1_000_001)
    finally:
        os.close(fd)
    if len(raw) != info.st_size:
        raise TransitionStop("nginx_file_read_incomplete")
    return info, raw


def _run(argv: list[str], *, timeout: int = 20) -> bytes:
    try:
        result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=timeout, check=False,
                                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "HOME": "/root", "LANG": "C"})
    except (OSError, subprocess.TimeoutExpired):
        raise TransitionStop("system_command_failed") from None
    if result.returncode != 0:
        raise TransitionStop("system_command_failed")
    return result.stdout


def _fsync_dir(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _write_atomic(path: Path, data: bytes, *, uid: int, gid: int, mode: int,
                  expected: tuple[int, int, str] | None = None,
                  after_replace=None) -> tuple[int, int]:
    before = os.lstat(path)
    if expected is not None:
        dev, ino, digest = expected
        if before.st_dev != dev or before.st_ino != ino:
            raise TransitionStop("nginx_file_identity_changed")
        current = path.read_bytes()
        if hashlib.sha256(current).hexdigest() != digest:
            raise TransitionStop("nginx_file_content_changed")
    temp = path.parent / f".{path.name}.wpcg-{secrets.token_hex(12)}.tmp"
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), mode)
        os.replace(temp, path)
        after = os.lstat(path)
        if after_replace is not None:
            after_replace((after.st_dev, after.st_ino, hashlib.sha256(data).hexdigest()))
        _fsync_dir(path.parent)
        return after.st_dev, after.st_ino
    finally:
        try:
            temp.unlink()
        except FileNotFoundError:
            pass


def _make_backup(config: Path, original: bytes, info: os.stat_result) -> Path:
    backup = config.parent / f".{config.name}.wpcg-go10d-{secrets.token_hex(8)}.bak"
    fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(original)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), stat.S_IMODE(info.st_mode))
        _fsync_dir(config.parent)
    except BaseException:
        try:
            backup.unlink()
        except OSError:
            pass
        raise
    return backup


def _nginx_ops(root: Path, *, test_mode: bool, nginx_file: str):
    if test_mode:
        def dump():
            if nginx_file == "auto":
                return (root / "nginx.dump").read_bytes()
            physical = root / Path(nginx_file).relative_to("/")
            body = physical.read_bytes()
            return f"# configuration file {nginx_file}:\n".encode() + body.rstrip(b"\n") + b"\n"
        def check():
            return True
        def reload():
            return True
        return dump, check, reload
    def check():
        _run(["/usr/sbin/nginx", "-t"], timeout=20)
        return True
    def reload():
        _run(["/bin/systemctl", "reload", "nginx"], timeout=30)
        return True
    return (lambda: _run(["/usr/sbin/nginx", "-T"], timeout=20), check, reload)


def _receipt(result: str, reason: str, details: dict[str, object]) -> dict[str, object]:
    body = {"schema": 1, "utility_version": VERSION, "result": result, "reason": reason,
            "timestamp_utc": dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z"),
            **details}
    body["receipt_sha256"] = hashlib.sha256(
        json.dumps(body, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    return body


def _canonical_receipt_sha(document: dict[str, object]) -> str:
    unsigned = {key: value for key, value in document.items() if key != "receipt_sha256"}
    return hashlib.sha256(json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def _persist_receipt(root: Path, logical_path: Path, document: dict[str, object], *, uid: int,
                     gid: int) -> str:
    path = root / logical_path.relative_to("/")
    state = root / STATE_ROOT.relative_to("/")
    _verify_dir(state, uid, gid, exact_mode=0o700)
    if path.parent != state:
        raise TransitionStop("receipt_path_invalid")
    data = (json.dumps(document, sort_keys=True, separators=(",", ":")) + "\n").encode()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o400)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchown(stream.fileno(), uid, gid)
            os.fchmod(stream.fileno(), 0o400)
        _fsync_dir(state)
    except BaseException:
        try:
            path.unlink()
        except OSError:
            pass
        raise
    return str(logical_path)


def _read_receipt(root: Path, logical_path: str, *, uid: int, gid: int) -> dict[str, object]:
    candidate = Path(logical_path)
    state_prefix = str(STATE_ROOT) + "/"
    if not candidate.is_absolute() or not logical_path.startswith(state_prefix) or candidate.parent != STATE_ROOT:
        raise TransitionStop("receipt_path_invalid")
    physical = root / candidate.relative_to("/")
    _verify_dir(physical.parent, uid, gid, exact_mode=0o700)
    info = _lstat(physical)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != uid \
            or info.st_gid != gid or stat.S_IMODE(info.st_mode) != 0o400 or info.st_nlink != 1 \
            or info.st_size > 32_000:
        raise TransitionStop("receipt_metadata_invalid")
    fd = os.open(physical, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
    try:
        opened = os.fstat(fd)
        if opened.st_dev != info.st_dev or opened.st_ino != info.st_ino:
            raise TransitionStop("receipt_changed_during_open")
        raw = os.read(fd, 32_001)
    finally:
        os.close(fd)
    if len(raw) != info.st_size:
        raise TransitionStop("receipt_read_incomplete")
    def no_duplicates(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate")
            result[key] = value
        return result
    try:
        document = json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates)
    except (UnicodeError, ValueError, json.JSONDecodeError):
        raise TransitionStop("receipt_invalid") from None
    if not isinstance(document, dict) or document.get("receipt_sha256") != _canonical_receipt_sha(document):
        raise TransitionStop("receipt_hash_invalid")
    return document


def _sha256_regular(path: Path, *, uid: int, gid: int, mode: int) -> str:
    info = _lstat(path)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != uid \
            or info.st_gid != gid or stat.S_IMODE(info.st_mode) != mode or info.st_nlink != 1:
        raise TransitionStop("protected_file_metadata_invalid")
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
    try:
        opened = os.fstat(fd)
        if opened.st_dev != info.st_dev or opened.st_ino != info.st_ino:
            raise TransitionStop("protected_file_changed_during_open")
        digest = hashlib.sha256()
        while True:
            chunk = os.read(fd, 64 * 1024)
            if not chunk:
                break
            digest.update(chunk)
        return digest.hexdigest()
    finally:
        os.close(fd)


def transition(*, mode: str, root: Path, nginx_file: str, root_uid: int, app_uid: int,
               app_gid: int, root_gid: int = 0, test_mode: bool = False) -> dict[str, object]:
    if mode not in {"check", "apply"}:
        raise TransitionStop("mode_invalid")
    var_www = root / "var/www"
    _verify_dir(var_www, root_uid, root_gid)
    app_path = root / APP_ROOT.relative_to("/")
    app_info = _lstat(app_path)
    if not stat.S_ISDIR(app_info.st_mode) or stat.S_ISLNK(app_info.st_mode) \
            or app_info.st_uid != app_uid or app_info.st_gid != app_gid:
        raise TransitionStop("application_root_identity_invalid")
    public = app_path / "public"
    public_info = _lstat(public)
    if not stat.S_ISDIR(public_info.st_mode) or stat.S_ISLNK(public_info.st_mode):
        raise TransitionStop("application_public_directory_invalid")
    link_path = root / ACTIVE_LINK.relative_to("/")
    dump_fn, test_fn, reload_fn = _nginx_ops(root, test_mode=test_mode, nginx_file=nginx_file)
    if not test_fn():
        raise TransitionStop("nginx_config_invalid_before_change")
    dump = dump_fn()
    source_map = parse_nginx_dump(dump)
    source_path = _discover_vhost_source(source_map, OLD_WEB_ROOT) if nginx_file == "auto" else nginx_file
    if source_path not in source_map:
        raise TransitionStop("nginx_source_file_not_active")
    config_path = Path(source_path)
    physical_config = root / config_path.relative_to("/")
    config_info, original = _verify_nginx_file(physical_config, source_path, root, root_uid, root_gid)
    if source_map[source_path].rstrip("\n") != original.decode("utf-8").rstrip("\n"):
        raise TransitionStop("nginx_source_bytes_mismatch")

    link_info = None
    try:
        link_info = os.lstat(link_path)
    except FileNotFoundError:
        pass
    if link_info is not None:
        if not stat.S_ISLNK(link_info.st_mode) or link_info.st_uid != root_uid or link_info.st_nlink != 1 \
                or os.readlink(link_path) != str(APP_ROOT):
            raise TransitionStop("active_link_conflict")
        _validate_vhost(source_map[source_path], source_path, NEW_WEB_ROOT)
        return _receipt("PASS", "already_applied", {"application_root": str(APP_ROOT),
                "active_link": str(ACTIVE_LINK), "nginx_source": source_path,
                "nginx_root": NEW_WEB_ROOT, "configuration_backup": None,
                "app_storage_migration": "not_performed"})

    config_new, config_sha = _prepare_config(dump, source_path, OLD_WEB_ROOT, NEW_WEB_ROOT)
    details = {"application_root": str(APP_ROOT), "active_link": str(ACTIVE_LINK),
               "link_target": str(APP_ROOT), "nginx_source": source_path,
               "nginx_root_before": OLD_WEB_ROOT, "nginx_root_after": NEW_WEB_ROOT,
               "nginx_file_sha256_before": config_sha,
               "nginx_file_mode": stat.S_IMODE(config_info.st_mode),
               "application_tree_modified": False, "app_storage_migration": "not_performed"}
    if mode == "check":
        return _receipt("PASS", "ready_for_root_transition", details)

    if not test_mode and os.geteuid() != 0:
        raise TransitionStop("root_required")
    backup = _make_backup(physical_config, original, config_info)
    details["configuration_backup"] = str(config_path.parent / backup.name)
    backup_hash = _sha256_regular(backup, uid=root_uid, gid=root_gid, mode=stat.S_IMODE(config_info.st_mode))
    nonce = secrets.token_hex(8)
    prepared_path = STATE_ROOT / f"path-transition-{nonce}.prepared.json"
    details["configuration_backup_sha256"] = backup_hash
    details["prepared_receipt_path"] = str(prepared_path)
    prepared = _receipt("PREPARED", "path_transition_prepared", details)
    _persist_receipt(root, prepared_path, prepared, uid=root_uid, gid=root_gid)
    created_link = None
    config_identity = (config_info.st_dev, config_info.st_ino, config_sha)
    config_written_identity: tuple[int, int, str] | None = None
    reload_attempted = False
    try:
        os.symlink(str(APP_ROOT), link_path)
        created_link = os.lstat(link_path)
        if not stat.S_ISLNK(created_link.st_mode) or created_link.st_uid != root_uid \
                or os.readlink(link_path) != str(APP_ROOT):
            raise TransitionStop("active_link_create_invalid")
        def mark_config_written(identity):
            nonlocal config_written_identity
            config_written_identity = identity
        config_written_identity = _write_atomic(physical_config, config_new, uid=config_info.st_uid,
                                                gid=config_info.st_gid,
                                                mode=stat.S_IMODE(config_info.st_mode),
                                                expected=config_identity,
                                                after_replace=mark_config_written) + (
                                                    hashlib.sha256(config_new).hexdigest(),)
        if not test_fn():
            raise TransitionStop("nginx_config_invalid_after_change")
        updated_dump = dump_fn()
        updated_sources = parse_nginx_dump(updated_dump)
        if source_path not in updated_sources:
            raise TransitionStop("nginx_source_missing_after_change")
        _validate_vhost(updated_sources[source_path], source_path, NEW_WEB_ROOT)
        reload_attempted = True
        if not reload_fn():
            raise TransitionStop("nginx_reload_failed")
        final_config = os.lstat(physical_config)
        details["nginx_file_sha256_after"] = hashlib.sha256(physical_config.read_bytes()).hexdigest()
        details["nginx_file_dev_after"] = final_config.st_dev
        details["nginx_file_inode_after"] = final_config.st_ino
        details["active_link_dev"] = created_link.st_dev
        details["active_link_inode"] = created_link.st_ino
        details["backup_sha256"] = backup_hash
        complete_path = STATE_ROOT / f"path-transition-{nonce}.complete.json"
        details["complete_receipt_path"] = str(complete_path)
        details["nginx_reload"] = "PASS"
        complete = _receipt("PASS", "path_transition_complete", details)
        _persist_receipt(root, complete_path, complete, uid=root_uid, gid=root_gid)
        return complete
    except BaseException as exc:
        rollback_ok = True
        may_remove_link = False
        try:
            current = _lstat(physical_config)
            current_hash = hashlib.sha256(physical_config.read_bytes()).hexdigest()
            if config_written_identity is None:
                # The atomic replace did not occur. Only clean up our link if
                # the original config inode and bytes are still untouched.
                if current.st_dev == config_info.st_dev and current.st_ino == config_info.st_ino \
                        and current_hash == config_sha:
                    may_remove_link = True
                else:
                    rollback_ok = False
            elif (current.st_dev, current.st_ino, current_hash) == config_written_identity:
                backup_bytes = backup.read_bytes()
                _write_atomic(physical_config, backup_bytes, uid=config_info.st_uid,
                              gid=config_info.st_gid, mode=stat.S_IMODE(config_info.st_mode),
                              expected=(current.st_dev, current.st_ino, current_hash))
                old_config_valid = test_fn()
                reload_valid = reload_fn() if reload_attempted and old_config_valid else not reload_attempted
                rollback_ok = old_config_valid and reload_valid
                may_remove_link = rollback_ok
            else:
                # Another actor changed the config after our write. Preserve
                # their bytes and leave the link in place for manual review.
                rollback_ok = False
        except BaseException:
            rollback_ok = False
        try:
            if created_link is not None and may_remove_link:
                now = os.lstat(link_path)
                if (stat.S_ISLNK(now.st_mode) and now.st_dev == created_link.st_dev
                        and now.st_ino == created_link.st_ino and now.st_uid == root_uid
                        and os.readlink(link_path) == str(APP_ROOT)):
                    os.unlink(link_path)
                    _fsync_dir(link_path.parent)
                else:
                    rollback_ok = False
            elif created_link is not None and not may_remove_link:
                rollback_ok = False
        except OSError:
            rollback_ok = False
        reason = str(exc) if isinstance(exc, TransitionStop) else "transition_internal_error"
        result = "STOP_ROLLED_BACK" if rollback_ok else "HOLD_ROLLBACK_INCOMPLETE"
        details["rollback"] = "PASS" if rollback_ok else "HOLD"
        details["rollback_reason"] = reason
        rollback_path = STATE_ROOT / f"path-transition-{nonce}.rollback.json"
        details["rollback_receipt_path"] = str(rollback_path)
        receipt = _receipt(result, reason, details)
        _persist_receipt(root, rollback_path, receipt, uid=root_uid, gid=root_gid)
        return receipt


def rollback(*, root: Path, receipt_path: str, root_uid: int, root_gid: int,
             test_mode: bool = False) -> dict[str, object]:
    """Reverse only a completed transition whose objects still match its receipt."""
    if not test_mode and os.geteuid() != 0:
        raise TransitionStop("root_required")
    receipt = _read_receipt(root, receipt_path, uid=root_uid, gid=root_gid)
    if receipt.get("result") != "PASS" or receipt.get("reason") != "path_transition_complete":
        raise TransitionStop("rollback_requires_completed_receipt")
    if receipt.get("application_root") != str(APP_ROOT) or receipt.get("active_link") != str(ACTIVE_LINK) \
            or receipt.get("link_target") != str(APP_ROOT) or receipt.get("nginx_root_after") != NEW_WEB_ROOT:
        raise TransitionStop("rollback_receipt_identity_invalid")
    logical_config = receipt.get("nginx_source")
    logical_backup = receipt.get("configuration_backup")
    if not isinstance(logical_config, str) or not isinstance(logical_backup, str):
        raise TransitionStop("rollback_receipt_paths_invalid")
    config_logical = Path(logical_config)
    backup_logical = Path(logical_backup)
    if not config_logical.is_absolute() or not backup_logical.is_absolute() \
            or not str(config_logical).startswith("/etc/nginx/") \
            or backup_logical.parent != config_logical.parent:
        raise TransitionStop("rollback_receipt_paths_invalid")
    if receipt.get("nginx_file_mode") != 0o644:
        raise TransitionStop("rollback_receipt_mode_invalid")
    link_path = root / ACTIVE_LINK.relative_to("/")
    link_info = _lstat(link_path)
    if not stat.S_ISLNK(link_info.st_mode) or link_info.st_uid != root_uid \
            or link_info.st_dev != receipt.get("active_link_dev") \
            or link_info.st_ino != receipt.get("active_link_inode") \
            or os.readlink(link_path) != str(APP_ROOT):
        raise TransitionStop("rollback_link_identity_mismatch")
    physical_config = root / config_logical.relative_to("/")
    config_info, _ = _verify_nginx_file(physical_config, logical_config, root, root_uid, root_gid)
    config_hash = _sha256_regular(physical_config, uid=root_uid, gid=root_gid, mode=0o644)
    if (config_info.st_dev, config_info.st_ino, config_hash) != (
            receipt.get("nginx_file_dev_after"), receipt.get("nginx_file_inode_after"),
            receipt.get("nginx_file_sha256_after")):
        raise TransitionStop("rollback_config_identity_mismatch")
    backup = root / backup_logical.relative_to("/")
    backup_hash = _sha256_regular(backup, uid=root_uid, gid=root_gid, mode=0o644)
    if backup_hash != receipt.get("backup_sha256") or backup_hash != receipt.get("configuration_backup_sha256"):
        raise TransitionStop("rollback_backup_hash_mismatch")
    backup_bytes = backup.read_bytes()
    try:
        _validate_vhost(backup_bytes.decode("utf-8"), logical_config, OLD_WEB_ROOT)
    except UnicodeError:
        raise TransitionStop("rollback_backup_vhost_invalid") from None
    dump_fn, test_fn, reload_fn = _nginx_ops(root, test_mode=test_mode, nginx_file=logical_config)
    source_map = parse_nginx_dump(dump_fn())
    if logical_config not in source_map:
        raise TransitionStop("rollback_nginx_source_missing")
    _validate_vhost(source_map[logical_config], logical_config, NEW_WEB_ROOT)
    if not test_fn():
        raise TransitionStop("rollback_nginx_test_failed_before_change")
    _write_atomic(physical_config, backup_bytes, uid=root_uid, gid=root_gid, mode=0o644,
                  expected=(config_info.st_dev, config_info.st_ino, config_hash))
    if not test_fn():
        raise TransitionStop("rollback_nginx_test_failed_after_change")
    updated = parse_nginx_dump(dump_fn())
    if logical_config not in updated:
        raise TransitionStop("rollback_nginx_source_missing_after_change")
    _validate_vhost(updated[logical_config], logical_config, OLD_WEB_ROOT)
    if not reload_fn():
        raise TransitionStop("rollback_nginx_reload_failed")
    now = _lstat(link_path)
    if (not stat.S_ISLNK(now.st_mode) or now.st_dev != link_info.st_dev
            or now.st_ino != link_info.st_ino or now.st_uid != root_uid
            or os.readlink(link_path) != str(APP_ROOT)):
        raise TransitionStop("rollback_link_changed_after_reload")
    os.unlink(link_path)
    _fsync_dir(link_path.parent)
    result = _receipt("PASS", "path_transition_rolled_back", {
        "complete_receipt": receipt_path, "nginx_source": logical_config,
        "configuration_backup": logical_backup, "configuration_backup_sha256": backup_hash,
        "active_link_removed_dev": link_info.st_dev, "active_link_removed_inode": link_info.st_ino,
        "application_tree_modified": False, "app_storage_migration": "not_performed"})
    nonce = secrets.token_hex(8)
    _persist_receipt(root, STATE_ROOT / f"path-transition-{nonce}.reversed.json", result,
                     uid=root_uid, gid=root_gid)
    return result


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--mode", choices=("check", "apply", "rollback"), required=True)
    parser.add_argument("--receipt", help="completed receipt path required for --mode rollback")
    parser.add_argument("--nginx-file", default="auto",
                        help="exact source path from nginx -T, or auto to require one unique exact vhost")
    parser.add_argument("--test-root", type=Path, help=argparse.SUPPRESS)
    args = parser.parse_args(argv)
    try:
        test_mode = args.test_root is not None
        if test_mode:
            root = args.test_root
            ids = (os.getuid(), os.getgid(), os.getuid(), os.getgid())
        else:
            if os.geteuid() != 0:
                raise TransitionStop("root_required")
            root = Path("/")
            ids = (0, 0, pwd.getpwuid(1000).pw_uid, pwd.getpwuid(1000).pw_gid)
        if args.mode == "rollback":
            if not args.receipt:
                raise TransitionStop("rollback_receipt_required")
            result = rollback(root=root, receipt_path=args.receipt, root_uid=ids[0], root_gid=ids[1],
                              test_mode=test_mode)
        else:
            if args.receipt:
                raise TransitionStop("receipt_only_valid_for_rollback")
            result = transition(mode=args.mode, root=root, nginx_file=args.nginx_file,
                                root_uid=ids[0], root_gid=ids[1], app_uid=ids[2], app_gid=ids[3],
                                test_mode=test_mode)
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0 if result["result"] == "PASS" else 1
    except TransitionStop as exc:
        print(json.dumps({"result": "STOP", "reason": str(exc), "utility_version": VERSION},
                         sort_keys=True), file=sys.stderr)
        return 1
    except (OSError, UnicodeError, ValueError):
        print(json.dumps({"result": "STOP", "reason": "filesystem_or_config_error",
                          "utility_version": VERSION}, sort_keys=True), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
