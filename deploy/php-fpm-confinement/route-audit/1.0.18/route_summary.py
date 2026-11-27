#!/usr/bin/env python3
"""Bounded, redacted Nginx summary. Review aid only; never grants PASS."""
import fnmatch
import hashlib
import json
import os
import posixpath
import re
import stat
import types

EXPECTED_PINNED_SHA256 = "c61cebec06152fdbf54599fa4296c68d5d5db7c2220dacce38c7104da7b6aba2"
LOCAL_REVIEW_SOURCE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "pinned_deploy_priv.py")
PINNED_SOURCE = None if os.geteuid() == 0 else os.environ.get("WPCG_NGINX_PINNED_SOURCE", LOCAL_REVIEW_SOURCE)

ROOT = "/etc/nginx/nginx.conf"
DOMAINS = {"wpcaregrid.com", "www.wpcaregrid.com"}
PUBLIC_ROOT = re.compile(r"^/var/www/wpcaregrid-production/(?:current|releases/[A-Za-z0-9._-]+)/public$")
CERT = re.compile(r"^/etc/letsencrypt/(?:live/wpcaregrid\.com/fullchain\.pem|archive/wpcaregrid\.com/fullchain[0-9]+\.pem)$")
KEY = re.compile(r"^/etc/letsencrypt/(?:live/wpcaregrid\.com/privkey\.pem|archive/wpcaregrid\.com/privkey[0-9]+\.pem)$")
FPM_SOCKET = "unix:/run/php/wpcaregrid-production-php8.5-fpm.sock"
VARIABLE = re.compile(r"\$\{([A-Za-z0-9_]+)\}|\$([A-Za-z_][A-Za-z0-9_]*|[0-9]+)")
LISTEN_PORT = re.compile(r"(?:\[[0-9A-Fa-f:]+\]:|[0-9.]+:)?([0-9]{1,5})$")

# These allowlists suppress ordinary non-routing settings without displaying
# their values. Anything outside them is surfaced as HOLD.
ROOT_SAFE = {"user", "worker_processes", "worker_cpu_affinity", "worker_rlimit_nofile", "worker_shutdown_timeout", "pid", "error_log", "daemon", "master_process", "lock_file", "working_directory", "env"}
EVENTS_SAFE = {"worker_connections", "use", "multi_accept", "accept_mutex", "accept_mutex_delay"}
HTTP_SAFE = {
    "include", "default_type", "sendfile", "tcp_nopush", "tcp_nodelay", "keepalive_timeout", "keepalive_requests",
    "types_hash_max_size", "server_tokens", "client_max_body_size", "client_body_timeout", "client_header_timeout",
    "client_header_buffer_size", "large_client_header_buffers", "client_body_temp_path", "proxy_temp_path",
    "fastcgi_temp_path", "uwsgi_temp_path", "scgi_temp_path", "access_log", "error_log", "gzip", "gzip_types",
    "gzip_vary", "gzip_proxied", "gzip_comp_level", "map_hash_bucket_size", "map_hash_max_size",
    "server_names_hash_bucket_size", "server_names_hash_max_size", "underscores_in_headers", "ignore_invalid_headers",
    "ssl_protocols", "ssl_prefer_server_ciphers", "ssl_ciphers",
    "log_format", "reset_timedout_connection", "charset", "open_file_cache", "open_file_cache_valid",
    "open_file_cache_min_uses", "types_hash_bucket_size", "variables_hash_bucket_size", "variables_hash_max_size",
    "client_body_in_file_only", "client_body_in_single_buffer", "client_body_buffer_size", "client_body_in_file_only",
    "resolver", "resolver_timeout", "proxy_headers_hash_max_size", "proxy_headers_hash_bucket_size",
    "fastcgi_buffers", "fastcgi_buffer_size", "fastcgi_busy_buffers_size", "fastcgi_buffering", "fastcgi_cache_path",
    "limit_req_zone", "limit_conn_zone", "limit_req_status", "limit_conn_status", "map", "server_name_in_redirect",
}
SERVER_SAFE = HTTP_SAFE | {
    "ssl_protocols", "ssl_prefer_server_ciphers", "ssl_session_cache", "ssl_session_timeout", "ssl_session_tickets",
    "ssl_stapling", "ssl_stapling_verify", "add_header", "client_body_buffer_size", "large_client_header_buffers",
    "server_name_in_redirect", "root", "alias", "index", "listen", "server_name", "location", "error_page",
}
LOCATION_SAFE = SERVER_SAFE | {
    "fastcgi_hide_header", "fastcgi_pass_header", "fastcgi_read_timeout", "fastcgi_send_timeout",
    "proxy_read_timeout", "proxy_send_timeout", "try_files", "return", "rewrite", "set", "if",
}
ROUTE_DIRECTIVES = {
    "listen", "server_name", "root", "alias", "index", "try_files", "return", "rewrite", "set", "error_page",
    "proxy_pass", "proxy_redirect", "proxy_intercept_errors", "proxy_set_header", "auth_request", "auth_request_set",
    "auth_basic", "auth_basic_user_file", "fastcgi_pass", "fastcgi_index", "fastcgi_param", "fastcgi_split_path_info",
    "fastcgi_intercept_errors", "fastcgi_request_buffering", "fastcgi_cache", "fastcgi_cache_bypass", "fastcgi_no_cache",
    "ssl_certificate", "ssl_certificate_key", "uwsgi_pass", "grpc_pass", "scgi_pass", "mirror", "recursive_error_pages",
    "js_content", "content_by_lua", "access_by_lua", "deny", "allow", "satisfy", "limit_except", "index",
}
TYPE_DIRECTIVES = {"text/html", "text/css", "application/json", "application/javascript", "image/svg+xml", "application/xml", "font/woff2", "image/png", "image/jpeg"}
MIME_ENTRY = re.compile(r"^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$")
BUILTIN_VARS = {
    "host", "http_host", "server_name", "server_port", "server_addr", "scheme", "https", "request_method", "request_uri",
    "uri", "document_uri", "args", "query_string", "is_args", "request_filename", "request_length", "request_time",
    "remote_addr", "remote_port", "remote_user", "binary_remote_addr", "proxy_protocol_addr", "realpath_root", "document_root",
    "content_type", "content_length", "status", "body_bytes_sent", "bytes_sent", "connection", "connection_requests",
    "pid", "msec", "time_iso8601", "time_local", "http_user_agent", "http_referer", "http_x_forwarded_for",
    "sent_http_location", "sent_http_content_type", "upstream_addr", "upstream_status", "upstream_response_time",
}
SAFE_STATUS = {"200", "301", "302", "303", "307", "308", "400", "401", "403", "404", "410", "444", "500", "502", "503", "504"}
MAX_ROWS = 1500
REVIEWABLE_NON_ROUTE_SETTINGS = {"worker_cpu_affinity", "ssl_protocols", "ssl_prefer_server_ciphers", "ssl_ciphers"}


class Hold(ValueError):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def _read_source_bytes(path, expected_sha256, *, privileged):
    """Pin bytes before executing them; privileged mode requires a trusted tree."""
    if not os.path.isabs(path) or os.path.normpath(path) != path:
        raise Hold("pinned-parser-path-noncanonical")
    try:
        before = os.lstat(path)
    except OSError:
        raise Hold("pinned-parser-unavailable") from None
    if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_mode & 0o022:
        raise Hold("pinned-parser-file-metadata")
    if privileged:
        if before.st_uid != 0 or before.st_gid != 0:
            raise Hold("pinned-parser-not-immutable-root-owned")
        root_info = os.lstat("/")
        if (not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0 or root_info.st_gid != 0
                or root_info.st_mode & 0o022):
            raise Hold("pinned-parser-root-trust-failed")
        current = "/"
        parent_components = path.strip("/").split("/")[:-1]
        for index, component in enumerate(parent_components):
            current = posixpath.join(current, component)
            try:
                info = os.lstat(current)
            except OSError:
                raise Hold("pinned-parser-parent-unavailable") from None
            # Root-owned files and ancestors may be owner-writable because root
            # is the trusted administrator in this model. Group/other write is
            # rejected, and the opened bytes must still match the exact digest.
            if (not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_gid != 0
                    or info.st_mode & 0o022):
                raise Hold("pinned-parser-parent-trust-failed")
    nofollow = getattr(os, "O_NOFOLLOW", None)
    if nofollow is None:
        raise Hold("nofollow-unavailable")
    flags = os.O_RDONLY | nofollow
    try:
        fd = os.open(path, flags)
        try:
            opened = os.fstat(fd)
            if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                    or (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)
                    or (opened.st_uid, opened.st_gid, stat.S_IMODE(opened.st_mode))
                    != (before.st_uid, before.st_gid, stat.S_IMODE(before.st_mode))
                    or opened.st_size > PINNED_SOURCE_MAX_BYTES):
                raise Hold("pinned-parser-file-changed")
            chunks, total = [], 0
            while True:
                chunk = os.read(fd, min(65536, PINNED_SOURCE_MAX_BYTES + 1 - total))
                if not chunk:
                    break
                chunks.append(chunk); total += len(chunk)
                if total > PINNED_SOURCE_MAX_BYTES:
                    raise Hold("pinned-parser-oversized")
            data = b"".join(chunks)
            after = os.fstat(fd)
            if (len(data) != before.st_size
                    or (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns,
                        after.st_uid, after.st_gid, stat.S_IMODE(after.st_mode))
                    != (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns,
                        opened.st_uid, opened.st_gid, stat.S_IMODE(opened.st_mode))):
                raise Hold("pinned-parser-file-changed")
        finally:
            os.close(fd)
    except OSError:
        raise Hold("pinned-parser-read-failed") from None
    if hashlib.sha256(data).hexdigest() != expected_sha256:
        raise Hold("pinned-parser-digest-mismatch")
    return data


def _secure_source_bytes(path, *, privileged):
    return _read_source_bytes(path, EXPECTED_PINNED_SHA256, privileged=privileged)


PINNED_SOURCE_MAX_BYTES = 2_000_000


def load_pinned_parser(path=PINNED_SOURCE, *, privileged=None):
    if not path:
        raise Hold("pinned-parser-path-required")
    if privileged is None:
        privileged = os.geteuid() == 0
    data = _secure_source_bytes(path, privileged=privileged)
    module = types.ModuleType("wpcg_pinned_deploy_priv")
    module.__file__ = path
    exec(compile(data, path, "exec"), module.__dict__)
    return module


PINNED = load_pinned_parser(PINNED_SOURCE, privileged=False) if PINNED_SOURCE else None


def variables(args):
    found = {match.group(1) or match.group(2) for value in args for match in VARIABLE.finditer(value)}
    return sorted(value.removeprefix("WPCG_BRACED__") for value in found)


def captures(args):
    names, numbered = set(), 0
    for value in args:
        escaped = in_class = False
        class_first = False
        i = 0
        while i < len(value):
            char = value[i]
            if escaped:
                escaped = False
                if in_class:
                    class_first = False
                i += 1; continue
            if char == "\\":
                escaped = True; i += 1; continue
            if in_class:
                if class_first and char == "^":
                    i += 1; continue
                if class_first and char == "]":
                    class_first = False; i += 1; continue
                # Bracket subexpressions have their own closing bracket.
                if char == "[" and i + 1 < len(value) and value[i + 1] in {":", ".", "="}:
                    marker = value[i + 1]
                    end = value.find(marker + "]", i + 2)
                    if end < 0:
                        raise Hold("unsupported-regex-character-class")
                    i = end + 2
                    class_first = False
                    continue
                if char == "]":
                    in_class = False; i += 1; continue
                class_first = False; i += 1; continue
            if char == "[": in_class = True; class_first = True; i += 1; continue
            if char == "(" and not in_class:
                if value.startswith("(?<", i) and not value.startswith(("(?<=", "(?<!"), i):
                    named = re.match(r"\(\?<([A-Za-z_][A-Za-z0-9_]*)>", value[i:])
                    if not named:
                        raise Hold("unsupported-regex-capture-syntax")
                    names.add(named.group(1)); numbered += 1
                    i += len(named.group(0)) - 1
                elif value.startswith(("(?:", "(?=", "(?!"), i):
                    pass
                elif value.startswith("(?", i):
                    raise Hold("unsupported-regex-capture-syntax")
                else:
                    numbered += 1
            i += 1
        if escaped or in_class:
            raise Hold("unsupported-regex-character-class")
    names.update(str(i) for i in range(1, numbered + 1))
    return sorted(names)


def location_regex_captures(args):
    if not args:
        raise Hold("malformed-location-selector")
    modifier = args[0] if args[0] in {"=", "~", "~*", "^~"} else "default"
    if (modifier == "default" and len(args) != 1) or (modifier != "default" and len(args) != 2):
        raise Hold("malformed-location-selector")
    if modifier not in {"~", "~*"}:
        return []
    return captures(args[1:2])


def rewrite_regex_captures(args):
    if len(args) not in {2, 3}:
        raise Hold("malformed-rewrite")
    return captures(args[:1])


def map_entry_regex_captures(name, args):
    if name in {"~", "~*"}:
        if not args:
            raise Hold("malformed-map-regex-entry")
        return captures(args[:1])
    if name.startswith("~*"):
        return captures([name[2:]])
    if name.startswith("~"):
        return captures([name[1:]])
    return []


def if_regex_captures(args):
    for index, value in enumerate(args):
        if value in {"~", "~*", "!~", "!~*"}:
            if index + 1 >= len(args) or not args[index + 1].endswith(")"):
                raise Hold("unsupported-if-regex-syntax")
            return captures([args[index + 1][:-1]])
    return []


def expanded_leaves(nodes):
    for node in nodes:
        if node["kind"] == "include":
            yield from expanded_leaves(node["children"])
        else:
            yield node


def parse_source(text, source):
    # Shield ${name} from the pinned parser's brace tokenization.
    if "$WPCG_BRACED__" in text:
        raise Hold("reserved-variable-shield-collision")
    protected = re.sub(r"\$\{([A-Za-z0-9_]+)\}", r"$WPCG_BRACED__\1", text)
    return PINNED._parse_nginx_nodes(PINNED._tokenize_nginx_config(protected), source)


def resolve_include(pattern, source_paths):
    if not isinstance(pattern, str) or not pattern or "$" in pattern or "\\" in pattern or "\0" in pattern or "{" in pattern or "}" in pattern:
        raise Hold("dynamic-or-unsupported-include")
    target = pattern if pattern.startswith("/") else posixpath.join(posixpath.dirname(ROOT), pattern)
    if posixpath.normpath(target) != target or ".." in target.split("/"):
        raise Hold("non-canonical-include")
    components = target.split("/")
    if "**" in components:
        raise Hold("recursive-include-glob-unsupported")
    for component in components:
        if component.count("[") != component.count("]"):
            raise Hold("malformed-include-glob")

    def path_matches(path):
        path_components = path.split("/")
        if len(path_components) != len(components):
            return False
        for pat, value in zip(components, path_components):
            if value.startswith(".") and not pat.startswith("."):
                return False
            if not fnmatch.fnmatchcase(value, pat):
                return False
        return True

    dumped_matches = sorted(path for path in source_paths if path_matches(path))
    try:
        filesystem_matches = PINNED._resolve_nginx_include(pattern, source_paths)
    except Exception:
        raise Hold("include-resolution-unproven") from None
    if not isinstance(filesystem_matches, (list, tuple)):
        raise Hold("include-resolution-invalid-result")
    filesystem_matches = sorted(filesystem_matches)
    if any(path not in source_paths or posixpath.normpath(path) != path for path in filesystem_matches):
        raise Hold("include-resolution-invalid-target")
    if filesystem_matches != dumped_matches:
        raise Hold("include-resolution-mismatch")
    return filesystem_matches


def approved_path(name, args):
    if name in {"root", "alias"}:
        if len(args) != 1:
            return None
        path = args[0]
        # Nginx paths must be canonical before allowlist matching. normpath
        # rejects dot segments, duplicate separators, and trailing slashes.
        if not path.startswith("/") or posixpath.normpath(path) != path:
            return None
        return path if PUBLIC_ROOT.fullmatch(path) else None
    if name == "ssl_certificate":
        return args[0] if len(args) == 1 and CERT.fullmatch(args[0]) else None
    if name == "ssl_certificate_key":
        return args[0] if len(args) == 1 and KEY.fullmatch(args[0]) else None
    return None


def include_graph(raw):
    if PINNED is None:
        raise Hold("pinned-parser-not-loaded")
    sections = PINNED._nginx_dump_sections(raw)
    if ROOT not in sections:
        raise Hold("root-config-missing")
    total_source_bytes = 0
    final_source = next(reversed(sections))
    for path, dumped_text in sections.items():
        try:
            source_text = PINNED._read_trusted_nginx_source(path)
        except Exception:
            raise Hold("source-read-untrusted") from None
        if not isinstance(source_text, str):
            raise Hold("source-read-invalid-result")
        total_source_bytes += len(source_text.encode("utf-8"))
        if total_source_bytes > PINNED.NGINX_CONFIG_MAX_BYTES:
            raise Hold("source-files-oversized")
        normalized_source = source_text.replace("\r\n", "\n")
        normalized_dump = dumped_text.replace("\r\n", "\n")
        has_final_framing_lf = path == final_source and normalized_dump == normalized_source + "\n"
        if normalized_source != normalized_dump and not has_final_framing_lf:
            raise Hold("source-dump-mismatch")
    parsed = {path: parse_source(text, path) for path, text in sections.items()}
    reached = {ROOT}
    tree = PINNED._expand_nginx_includes(parsed[ROOT], sections, parsed, lambda _path: "", resolve_include, (ROOT,), reached, [0])
    if reached != set(sections):
        raise Hold("unreachable-source-sections")
    include_edges = []

    def record_edges(nodes):
        for node in nodes:
            if node["kind"] == "include":
                targets = sorted(node["targets"])
                include_edges.append({
                    "include_id": f"include-{len(include_edges) + 1}",
                    "source": node["source"],
                    "target_files": targets,
                    "target_count": len(targets),
                    "empty_glob_confirmed": not targets and any(c in node["path"] for c in "*?[")
                })
                record_edges(node["children"])
            elif node["kind"] == "block":
                record_edges(node["children"])

    record_edges(tree)
    if len(include_edges) > MAX_ROWS:
        raise Hold("summary-bound-exceeded")
    return sections, tree, include_edges


def summarize(raw):
    if PINNED is None:
        raise Hold("pinned-parser-not-loaded")
    if len(raw.encode("utf-8")) > PINNED.NGINX_CONFIG_MAX_BYTES:
        raise Hold("dump-oversized")
    sections, tree, include_edges = include_graph(raw)
    records, unclassified, policy_settings = [], [], []
    defs, refs, upstreams = {}, [], {}
    counters = {"server": 0, "location": 0, "upstream": 0, "map": 0, "if": 0, "limit_except": 0}
    route_effect_seen = False

    def define(var, source_id, kind, contexts=()):
        context_ids = [ctx["id"] for ctx in contexts if ctx["kind"] in {"server", "location", "if", "limit_except"}]
        defs.setdefault(var, []).append({"id": source_id, "kind": kind, "context_ids": context_ids})

    def definition_matches(definition, context):
        if definition["kind"].startswith("map-"):
            return True
        ref_ids = {ctx["id"] for ctx in context}
        return set(definition["context_ids"]).issubset(ref_ids)

    def ctx_row(node, contexts, directive, vars_used=None):
        # The pinned parser does not retain token line numbers. Explicit null
        # prevents fabricated line references; owner must inspect source file.
        row = {"source": node["source"], "line": None, "line_number_available": False,
               "context": list(contexts), "directive": directive, "variables": vars_used or []}
        for context_kind, field in (("server", "server_id"), ("location", "location_id"), ("upstream", "upstream_id"), ("map", "map_id")):
            match = next((ctx["id"] for ctx in reversed(contexts) if ctx["kind"] == context_kind), None)
            if match is not None: row[field] = match
        return row

    def walk(nodes, contexts=(), scope="root", server_id=None, location_id=None, upstream_id=None, map_id=None):
        nonlocal route_effect_seen
        for node in nodes:
            kind = node["kind"]
            if kind == "include":
                walk(node["children"], contexts, scope, server_id, location_id, upstream_id, map_id)
                continue
            name = node["name"]
            args = node.get("args", [])
            if kind == "block":
                next_scope, next_server, next_location, next_upstream, next_map = scope, server_id, location_id, upstream_id, map_id
                child_contexts = list(contexts)
                if name == "http" and scope == "root" and not args:
                    next_scope = "http"; child_contexts.append({"kind": "http", "id": "http-1"})
                elif name == "events" and scope == "root" and not args:
                    next_scope = "events"; child_contexts.append({"kind": "events", "id": "events-1"})
                elif name == "server" and scope == "http" and not args:
                    counters["server"] += 1; next_server = f"server-{counters['server']}"; next_location = None
                    next_scope = "server"; child_contexts.append({"kind": "server", "id": next_server})
                    records.append({**ctx_row(node, contexts, "server"), "server_id": next_server, "block": True})
                elif name == "location" and scope in {"server", "location", "if"} and args:
                    counters["location"] += 1; next_location = f"location-{counters['location']}"
                    captures_found = location_regex_captures(args)
                    modifier = args[0] if args[0] in {"=", "~", "~*", "^~"} else "default"
                    child_contexts.append({"kind": "location", "id": next_location, "server_id": server_id,
                                           "modifier": modifier,
                                           "capture_variables": captures_found})
                    records.append({**ctx_row(node, contexts, "location"), "location_id": next_location,
                                    "modifier": modifier, "capture_variables": captures_found,
                                    "pattern": "<redacted>", "block": True})
                    for var in captures_found: define(var, next_location, "location-capture", child_contexts)
                    next_scope = "location"
                    route_effect_seen = True
                elif name == "upstream" and scope == "http" and len(args) == 1:
                    counters["upstream"] += 1; next_upstream = f"upstream-{counters['upstream']}"; next_scope = "upstream"
                    upstreams[args[0]] = next_upstream
                    child_contexts.append({"kind": "upstream", "id": next_upstream})
                    records.append({**ctx_row(node, contexts, "upstream"), "upstream_id": next_upstream,
                                    "name": "<redacted>", "block": True})
                elif name == "map" and scope == "http" and len(args) == 2:
                    counters["map"] += 1; next_map = f"map-{counters['map']}"; next_scope = "map"
                    src_vars, dst_vars = variables(args[:1]), variables(args[1:2])
                    map_leaves = list(expanded_leaves(node["children"]))
                    cap_vars = sorted(set(v for child in map_leaves
                                          for v in map_entry_regex_captures(child["name"], child.get("args", []))))
                    for var in dst_vars: define(var, next_map, "map-target", child_contexts)
                    for var in cap_vars: define(var, next_map, "map-capture", child_contexts)
                    refs.extend((var, next_map) for var in src_vars)
                    records.append({**ctx_row(node, contexts, "map", sorted(set(src_vars + dst_vars + cap_vars))),
                                    "definition_id": next_map, "source_variables": src_vars,
                                    "target_variables": dst_vars, "capture_variables": cap_vars,
                                    "entry_count": len(map_leaves), "entry_values": "<redacted>"})
                    child_contexts.append({"kind": "map", "id": next_map}); route_effect_seen = True
                elif name == "if" and scope in {"server", "location", "if"}:
                    counters["if"] += 1; if_id = f"if-{counters['if']}"; next_scope = "if"
                    cond_vars = variables(args)
                    if_row = ctx_row(node, contexts, "if", cond_vars)
                    refs.extend((var, if_row) for var in cond_vars)
                    if_caps = if_regex_captures(args)
                    records.append({**if_row, "definition_id": if_id,
                                    "condition_variables": cond_vars, "capture_variables": if_caps,
                                    "condition_values": "<redacted>"})
                    child_contexts.append({"kind": "if", "id": if_id, "condition_variables": cond_vars,
                                           "capture_variables": if_caps}); route_effect_seen = True
                    for var in if_caps: define(var, if_id, "if-regex-capture", child_contexts)
                elif name == "limit_except" and scope in {"location", "if"}:
                    counters["limit_except"] += 1; block_id = f"limit-except-{counters['limit_except']}"; next_scope = "limit_except"
                    child_contexts.append({"kind": name, "id": block_id}); route_effect_seen = True
                elif name == "types" and scope == "http":
                    next_scope = "types"; child_contexts.append({"kind": "types", "id": f"types-{len(records)+1}"})
                else:
                    unclassified.append({**ctx_row(node, contexts, name), "reason": "unsupported-or-unexpected-block"})
                    route_effect_seen = True
                    # Continue parsing descendants to retain their presence, but
                    # the unsupported enclosing block already forces HOLD.
                    next_scope = "unknown"
                walk(node["children"], child_contexts, next_scope, next_server, next_location, next_upstream, next_map)
                continue

            vars_used = variables(args)
            if scope == "types":
                if not MIME_ENTRY.fullmatch(name) or not args or not all(re.fullmatch(r"[A-Za-z0-9._+-]+", ext) for ext in args):
                    unclassified.append({**ctx_row(node, contexts, name), "reason": "unclassified-types-entry"})
                continue
            if scope == "events":
                if name not in EVENTS_SAFE:
                    unclassified.append({**ctx_row(node, contexts, name, vars_used), "reason": "unclassified-events-directive"})
                continue
            if scope == "root":
                if name not in ROOT_SAFE:
                    unclassified.append({**ctx_row(node, contexts, name, vars_used), "reason": "unclassified-main-directive"})
                elif name in REVIEWABLE_NON_ROUTE_SETTINGS:
                    policy_settings.append({**ctx_row(node, contexts, name), "argument_count": len(args), "values": "<redacted>"})
                continue
            if scope == "map":
                if name in {"hostnames", "volatile"} and not args:
                    continue
                if name == "include":
                    unclassified.append({**ctx_row(node, contexts, name), "reason": "unexpanded-map-include"})
                    route_effect_seen = True
                    continue
                if not args:
                    unclassified.append({**ctx_row(node, contexts, name), "reason": "malformed-map-entry"})
                    route_effect_seen = True
                    continue
                map_key_captures = map_entry_regex_captures(name, args)
                entry_vars = sorted(set(variables([name]) + variables(args)))
                entry_id = f"{map_id or 'map-unknown'}.entry-{sum(1 for row in records if row.get('parent_id') == map_id)+1}"
                for var in map_key_captures: define(var, entry_id, "map-entry-capture", contexts)
                refs.extend((var, entry_id) for var in entry_vars if var not in map_key_captures)
                records.append({**ctx_row(node, contexts, "map-entry", entry_vars), "parent_id": map_id,
                                "entry_id": entry_id, "capture_variables": map_key_captures,
                                "literal_key_present": not bool(variables([name])),
                                "literal_value_count": max(0, len(args)-len(variables(args))),
                                "key_and_values": "<redacted>"})
                route_effect_seen = True
                continue
            if scope == "upstream":
                if name == "server":
                    # Do not reveal endpoint, port, parameters, or hash thereof.
                    refs.extend((var, upstream_id or "upstream") for var in vars_used)
                    records.append({**ctx_row(node, contexts, name, vars_used), "target_class": "redacted-upstream-endpoint", "argument_count": len(args)})
                    route_effect_seen = True
                elif name in {"zone", "keepalive", "keepalive_requests", "keepalive_time", "hash", "ip_hash", "least_conn", "least_time", "random", "resolve"}:
                    # Selection/endpoint behavior is still represented as a
                    # redacted directive record and must receive owner review.
                    refs.extend((var, upstream_id or "upstream") for var in vars_used)
                    records.append({**ctx_row(node, contexts, name, vars_used), "argument_count": len(args), "values": "<redacted>"})
                    route_effect_seen = True
                else:
                    unclassified.append({**ctx_row(node, contexts, name, vars_used), "reason": "unclassified-upstream-directive"})
                continue

            scope_safe = HTTP_SAFE if scope == "http" else SERVER_SAFE if scope == "server" else LOCATION_SAFE if scope in {"location", "if", "limit_except"} else set()
            if name in ROUTE_DIRECTIVES:
                row = ctx_row(node, contexts, name, vars_used)
                refs.extend((var, row) for var in vars_used)
                if name == "server_name":
                    values = [value.lower() for value in args if value.lower() in DOMAINS or value == "_"]
                    row["values"] = values if len(values) == len(args) else "<redacted>"
                    if row["values"] == "<redacted>": route_effect_seen = True
                elif name == "listen":
                    ports, flags = [], []
                    for value in args:
                        match = LISTEN_PORT.fullmatch(value)
                        if match and int(match.group(1)) <= 65535: ports.append(match.group(1))
                        elif value in {"ssl", "http2", "default_server", "reuseport", "proxy_protocol"}: flags.append(value)
                        else: row["other_listen_argument_count"] = row.get("other_listen_argument_count", 0) + 1; route_effect_seen = True
                    row["values"] = {"ports": ports, "flags": sorted(set(flags))}
                elif name in {"root", "alias", "ssl_certificate", "ssl_certificate_key"}:
                    path = approved_path(name, args); row["values"] = path or "<redacted>"
                    if path is None: route_effect_seen = True
                elif name in {"fastcgi_pass", "proxy_pass", "uwsgi_pass", "grpc_pass", "scgi_pass"}:
                    target = args[0] if len(args) == 1 else ""
                    if name == "fastcgi_pass":
                        if target == FPM_SOCKET:
                            row["target_kind"] = "approved-production-fpm-socket"
                        elif target in upstreams:
                            row["target_kind"] = "upstream"; row["target_id"] = upstreams[target]
                            route_effect_seen = True
                        elif vars_used:
                            row["target_kind"] = "variable"; row["authority_variables"] = vars_used
                            row["target_variables"] = vars_used
                            row["uri_variables"] = []
                        else:
                            row["target_kind"] = "direct-or-unresolved"; route_effect_seen = True
                            row["authority_variables"] = []; row["uri_variables"] = []
                    else:
                        match = re.fullmatch(r"(?:https?|grpc|grpcs|uwsgi|scgi)://([^/?#]+)(/[^?#]*)?", target)
                        authority = match.group(1) if match else ""
                        uri = match.group(2) if match else ""
                        if authority in upstreams:
                            row["target_kind"] = "upstream"; row["target_id"] = upstreams[authority]
                            row["authority_variables"] = []
                            row["uri_variables"] = variables([uri or ""])
                        elif variables([authority]):
                            row["target_kind"] = "variable"
                            row["authority_variables"] = variables([authority])
                            row["uri_variables"] = variables([uri or ""])
                        elif vars_used:
                            row["target_kind"] = "variable"
                            row["authority_variables"] = variables([authority])
                            row["uri_variables"] = variables([uri or ""])
                            row["target_variables"] = vars_used
                        else:
                            row["target_kind"] = "direct-or-unresolved"; route_effect_seen = True
                            row["authority_variables"] = []
                            row["uri_variables"] = variables([uri or ""])
                    row["values"] = "<redacted>"
                    if row.get("target_kind") != "approved-production-fpm-socket": route_effect_seen = True
                elif name == "return":
                    status = args[0] if args else ""
                    row["values"] = {"status": status, "argument_count": len(args)} if status in SAFE_STATUS else "<redacted>"
                    if status not in SAFE_STATUS or len(args) > 1: route_effect_seen = True
                elif name == "set":
                    lhs, rhs = variables(args[:1]), variables(args[1:])
                    def_id = f"set-{sum(1 for rec in records if rec['directive'] == 'set')+1}"
                    for var in lhs: define(var, def_id, "set", contexts)
                    refs.extend((var, row) for var in rhs)
                    row.update({"definition_id": def_id, "defined_variables": lhs, "referenced_variables": rhs,
                                "literal_rhs_count": max(0, len(args)-1-len(rhs)), "rhs_values": "<redacted>"})
                    if len(lhs) != 1 or len(args) < 2: route_effect_seen = True
                elif name == "rewrite":
                    cap_vars = rewrite_regex_captures(args)
                    for var in cap_vars: define(var, f"rewrite-{len(records)+1}", "rewrite-capture", contexts)
                    row.update({"capture_variables": cap_vars, "argument_count": len(args), "values": "<redacted>"}); route_effect_seen = True
                elif name == "try_files":
                    row["candidate_count"] = len(args); row["values"] = "<redacted>"; route_effect_seen = True
                else:
                    row["argument_count"] = len(args); row["values"] = "<redacted>"; route_effect_seen = True
                records.append(row)
                continue
            if name in scope_safe:
                if name in REVIEWABLE_NON_ROUTE_SETTINGS:
                    policy_settings.append({**ctx_row(node, contexts, name), "argument_count": len(args), "values": "<redacted>"})
                continue
            unclassified.append({**ctx_row(node, contexts, name, vars_used), "reason": "unclassified-or-unsupported-directive"})
            route_effect_seen = True

    walk(tree)
    # Resolve variable references after all set/map/capture definitions exist.
    unresolved = {}
    for var, origin in refs:
        origin_context = origin.get("context", []) if isinstance(origin, dict) else []
        candidates = [definition for definition in defs.get(var, []) if definition_matches(definition, origin_context)]
        if not candidates and var not in BUILTIN_VARS and not var.startswith("http_"):
            unresolved.setdefault(var, []).append(origin if isinstance(origin, str) else origin.get("directive", "route"))
    for row in records:
        if "variables" in row:
            row_context = row.get("context", [])
            row["definition_links"] = {var: [d["id"] for d in defs.get(var, []) if definition_matches(d, row_context)] for var in row["variables"]}
    if len(records) > MAX_ROWS or len(unclassified) > MAX_ROWS:
        raise Hold("summary-bound-exceeded")
    if unresolved:
        route_effect_seen = True
    return {
        "schema": 4,
        "effective_config_sha256": hashlib.sha256(raw.encode("utf-8")).hexdigest(),
        "source_files": sorted(sections),
        "include_edges": include_edges,
        "route_records": records,
        "non_route_policy_settings": policy_settings,
        "unclassified": unclassified,
        "variable_definitions": {key: value for key, value in sorted(defs.items())},
        "unresolved_variable_names": sorted(unresolved),
        "manual_owner_review_required": True,
        "line_numbers_available": False,
        "route_verdict": "HOLD" if unclassified or unresolved or route_effect_seen else "REVIEW_REQUIRED",
        "summary_parse": "PASS",
        "note": "Redacted review aid only. Pinned parser omits source lines; verify source files manually. This output is never route PASS or attestation.",
    }


if __name__ == "__main__":
    import sys
    try:
        if os.geteuid() == 0:
            if len(sys.argv) != 2:
                raise Hold("trusted-parser-path-required")
            PINNED = load_pinned_parser(sys.argv[1], privileged=True)
        elif len(sys.argv) != 1:
            raise Hold("unexpected-command-line-arguments")
        data = sys.stdin.read(PINNED.NGINX_CONFIG_MAX_BYTES + 1)
        print(json.dumps(summarize(data), separators=(",", ":"), ensure_ascii=True))
    except Hold as exc:
        print(json.dumps({"summary_parse": "HOLD", "reason_code": exc.code}, separators=(",", ":")))
        raise SystemExit(2)
    except Exception as exc:
        cls = type(exc).__name__
        if not re.fullmatch(r"[A-Za-z][A-Za-z0-9]{0,63}", cls): cls = "Error"
        print(json.dumps({"summary_parse": "HOLD", "error_class": cls}, separators=(",", ":")))
        raise SystemExit(2)
