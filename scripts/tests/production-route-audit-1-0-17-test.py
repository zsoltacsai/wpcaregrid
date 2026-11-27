import importlib.util
import fnmatch
import hashlib
import json
import os
import stat
import tempfile
import unittest
from unittest import mock

PKG = os.path.join(os.path.dirname(__file__), "../../deploy/php-fpm-confinement/route-audit/1.0.17")
PKG = os.path.abspath(PKG)
PINNED_FIXTURE = os.environ.get("WPCG_NGINX_PINNED_SOURCE", os.path.join(PKG, "pinned_deploy_priv.py"))
os.environ["WPCG_NGINX_PINNED_SOURCE"] = PINNED_FIXTURE
P = os.path.join(PKG, "route_summary.py")
EXPECTED_ADAPTER_SHA256 = "1591d1632ae32fecbcfe624a7f8b369f040a35a9a15dec5b0c94afefa654d669"
EXPECTED_PARSER_SHA256 = "c61cebec06152fdbf54599fa4296c68d5d5db7c2220dacce38c7104da7b6aba2"
EXPECTED_MANIFEST_SHA256 = "debc9cbc4682af5cab8d744a564e22fea307137b499a0c27de18c82a0c5e8f06"
spec = importlib.util.spec_from_file_location("summary", P)
M = importlib.util.module_from_spec(spec)
spec.loader.exec_module(M)


def dump(files):
    return "".join(f"\n# configuration file {path}:\n{text.rstrip()}\n" for path, text in files.items())


def summarize(raw):
    sections = M.PINNED._nginx_dump_sections(raw)
    with mock.patch.object(M.PINNED, "_read_trusted_nginx_source", side_effect=lambda path: sections[path]):
        return M.summarize(raw)


def base(site):
    return {
        "/etc/nginx/nginx.conf": "events { worker_connections 64; use epoll; } http { include /etc/nginx/mime.types; include /etc/nginx/sites-enabled/*; sendfile on; }",
        "/etc/nginx/mime.types": "types { text/html html htm; application/octet-stream bin; }",
        "/etc/nginx/sites-enabled/site": site,
    }


class Tests(unittest.TestCase):
    def setUp(self):
        def fake_filesystem_resolver(pattern, source_paths, **_kwargs):
            target = pattern if pattern.startswith("/") else os.path.join("/etc/nginx", pattern)
            components = target.split("/")
            matches = []
            for path in source_paths:
                values = path.split("/")
                if len(values) != len(components):
                    continue
                if all(fnmatch.fnmatchcase(value, pat) for pat, value in zip(components, values)):
                    if all(not value.startswith(".") or pat.startswith(".")
                           for pat, value in zip(components, values)):
                        matches.append(path)
            if matches:
                return sorted(matches)
            if any(char in target for char in "*?["):
                return []
            raise ValueError("unresolved include")

        self.include_resolver_patch = mock.patch.object(M.PINNED, "_resolve_nginx_include", side_effect=fake_filesystem_resolver)
        self.include_resolver_patch.start()
        self.addCleanup(self.include_resolver_patch.stop)

    def test_versioned_artifact_manifest_and_binding_are_exact(self):
        with open(os.path.join(PKG, "SHA256SUMS"), "rb") as f:
            self.assertEqual(hashlib.sha256(f.read()).hexdigest(), EXPECTED_MANIFEST_SHA256)
        with open(os.path.join(PKG, "SHA256SUMS"), encoding="ascii") as f:
            entries = [line.rstrip("\n").split("  ", 1) for line in f]
        self.assertEqual({name for digest, name in entries}, {"README.md", "binding.json", "pinned_deploy_priv.py", "route_summary.py"})
        self.assertEqual(len(entries), 4)
        for expected, name in entries:
            path = os.path.join(PKG, name)
            info = os.lstat(path)
            self.assertTrue(stat.S_ISREG(info.st_mode), name)
            self.assertEqual(info.st_nlink, 1, name)
            self.assertEqual(stat.S_IMODE(info.st_mode), 0o644, name)
            with open(path, "rb") as f:
                self.assertEqual(hashlib.sha256(f.read()).hexdigest(), expected, name)
        by_name = {name: digest for digest, name in entries}
        self.assertEqual(by_name["route_summary.py"], EXPECTED_ADAPTER_SHA256)
        self.assertEqual(by_name["pinned_deploy_priv.py"], EXPECTED_PARSER_SHA256)
        with open(os.path.join(PKG, "binding.json"), encoding="utf-8") as f:
            binding = json.load(f)
        self.assertEqual(binding["version"], "1.0.17")
        self.assertEqual(binding["acceptance_package_version"], "1.0.16")
        self.assertIs(binding["acceptance_verdict"], False)
        self.assertIs(binding["route_attestation"], False)
        self.assertEqual(binding["parser_sha256"], EXPECTED_PARSER_SHA256)

    def test_readme_never_pipes_nginx_stderr_into_config_parser(self):
        with open(os.path.join(PKG, "README.md"), encoding="utf-8") as f:
            readme = f.read()
        self.assertIn("set -euo pipefail", readme)
        self.assertIn("nginx -T 2>/dev/null |", readme)
        self.assertNotIn("nginx -T 2>&1 |", readme)

    def test_pinned_source_is_digest_checked_and_root_mode_requires_trusted_path(self):
        self.assertEqual(len(M._secure_source_bytes(M.LOCAL_REVIEW_SOURCE, privileged=False)), os.stat(M.LOCAL_REVIEW_SOURCE).st_size)
        with self.assertRaises(M.Hold) as caught:
            M._secure_source_bytes(M.LOCAL_REVIEW_SOURCE, privileged=True)
        self.assertEqual(caught.exception.code, "pinned-parser-not-immutable-root-owned")
        with tempfile.NamedTemporaryFile() as altered:
            altered.write(b"# altered parser\n")
            altered.flush()
            with self.assertRaises(M.Hold) as caught:
                M._secure_source_bytes(altered.name, privileged=False)
            self.assertEqual(caught.exception.code, "pinned-parser-digest-mismatch")

    def test_privileged_parser_load_requires_nofollow_and_trusted_root_parent(self):
        with mock.patch.object(M.os, "O_NOFOLLOW", None):
            with self.assertRaises(M.Hold) as caught:
                M._secure_source_bytes(M.LOCAL_REVIEW_SOURCE, privileged=False)
            self.assertEqual(caught.exception.code, "nofollow-unavailable")

        with open(M.LOCAL_REVIEW_SOURCE, "rb") as source_file:
            data = source_file.read()
        base_fields = dict(st_dev=9, st_ino=10, st_nlink=1, st_uid=0, st_gid=0,
                           st_size=len(data), st_mtime_ns=100)
        file_stat = type("Stat", (), {**base_fields, "st_mode": stat.S_IFREG | 0o644})()
        root_stat = type("Stat", (), {**base_fields, "st_mode": stat.S_IFDIR | 0o755})()
        safe_parent = type("Stat", (), {**base_fields, "st_mode": stat.S_IFDIR | 0o555})()
        trusted_root_writable_parent = type("Stat", (), {**base_fields, "st_mode": stat.S_IFDIR | 0o755})()
        untrusted_writable_parent = type("Stat", (), {**base_fields, "st_mode": stat.S_IFDIR | 0o775})()

        def run_fake(parent_stat):
            entries = {"/": root_stat, "/trusted": parent_stat, "/trusted/pinned.py": file_stat}
            reads = [data, b""]
            with mock.patch.object(M.os, "lstat", side_effect=lambda path: entries[path]), \
                 mock.patch.object(M.os, "open", return_value=41) as open_mock, \
                 mock.patch.object(M.os, "fstat", return_value=file_stat), \
                 mock.patch.object(M.os, "read", side_effect=lambda _fd, _size: reads.pop(0)), \
                 mock.patch.object(M.os, "close", return_value=None):
                if parent_stat in (safe_parent, trusted_root_writable_parent):
                    self.assertEqual(M._secure_source_bytes("/trusted/pinned.py", privileged=True), data)
                    self.assertTrue(open_mock.call_args.args[1] & M.os.O_NOFOLLOW)
                else:
                    with self.assertRaises(M.Hold) as caught:
                        M._secure_source_bytes("/trusted/pinned.py", privileged=True)
                    self.assertEqual(caught.exception.code, "pinned-parser-parent-trust-failed")

        run_fake(safe_parent)
        run_fake(trusted_root_writable_parent)
        run_fake(untrusted_writable_parent)

    def test_posix_bracket_class_cannot_hide_fake_named_capture(self):
        self.assertEqual(M.captures([r"^[[:alpha:](?<ghost>]+$"]), [])
        self.assertEqual(M.captures([r"^[[:alpha:](?<ghost>]+(?<real>x)$"]), ["1", "real"])

    def test_posix_bracket_class_delimiter_does_not_end_outer_class(self):
        self.assertEqual(M.captures([r"^[[:alpha:]]+(?<real>x)$"]), ["1", "real"])

    def test_isolated_fake_pinned_parser_fixture_loader(self):
        fake = b"NGINX_CONFIG_MAX_BYTES = 1234\nFAKE_FIXTURE = True\n"
        with tempfile.NamedTemporaryFile() as fixture:
            fixture.write(fake)
            fixture.flush()
            loaded = M._read_source_bytes(fixture.name, __import__("hashlib").sha256(fake).hexdigest(), privileged=False)
            module = type("FakePinnedParserFixture", (), {})()
            exec(compile(loaded, fixture.name, "exec"), module.__dict__)
        self.assertTrue(module.FAKE_FIXTURE)
        self.assertEqual(module.NGINX_CONFIG_MAX_BYTES, 1234)

    def test_normal_events_http_server_and_mime_are_classified(self):
        config = base("server { listen 80; server_name wpcaregrid.com; client_max_body_size 8m; location / { return 404; } }")
        raw = dump(config)
        result = summarize(raw)
        self.assertEqual(result["effective_config_sha256"], hashlib.sha256(raw.encode("utf-8")).hexdigest())
        self.assertEqual(result["unclassified"], [])
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertTrue(result["manual_owner_review_required"])
        self.assertFalse(result["line_numbers_available"])

    def test_source_bytes_must_match_nginx_dump(self):
        raw = dump(base("server { listen 80; server_name wpcaregrid.com; location / { return 404; } }"))
        sections = M.PINNED._nginx_dump_sections(raw)
        with mock.patch.object(M.PINNED, "_read_trusted_nginx_source",
                               side_effect=lambda path: sections[path] + "\n# changed after nginx -T\n"):
            with self.assertRaises(M.Hold) as caught:
                M.summarize(raw)
        self.assertEqual(caught.exception.code, "source-dump-mismatch")

    def test_untrusted_source_read_fails_closed(self):
        raw = dump(base("server { listen 80; server_name wpcaregrid.com; location / { return 404; } }"))
        with mock.patch.object(M.PINNED, "_read_trusted_nginx_source", side_effect=OSError("private path detail")):
            with self.assertRaises(M.Hold) as caught:
                M.summarize(raw)
        self.assertEqual(caught.exception.code, "source-read-untrusted")

    def test_upstream_server_and_proxy_are_linked_without_endpoint_or_name(self):
        config = base("upstream private_backend { server 127.0.0.1:9000; } server { listen 80; server_name wpcaregrid.com; location / { proxy_pass http://private_backend; } }")
        result = summarize(dump(config))
        rows = result["route_records"]
        endpoint = next(row for row in rows if row["directive"] == "server" and row.get("target_class"))
        proxy = next(row for row in rows if row["directive"] == "proxy_pass")
        self.assertEqual(endpoint["target_class"], "redacted-upstream-endpoint")
        self.assertEqual(proxy["target_id"], endpoint["context"][-1]["id"])
        rendered = json.dumps(result)
        for secret in ("127.0.0.1", "9000", "private_backend"):
            self.assertNotIn(secret, rendered)
        self.assertEqual(result["route_verdict"], "HOLD")

    def test_static_upstream_link_survives_variable_uri(self):
        config = base("upstream internal_pool { server 127.0.0.1:9000; } server { listen 80; server_name wpcaregrid.com; location / { proxy_pass http://internal_pool/$request_uri; } }")
        result = summarize(dump(config))
        proxy = next(row for row in result["route_records"] if row["directive"] == "proxy_pass")
        self.assertEqual(proxy["target_kind"], "upstream")
        self.assertEqual(proxy["target_id"], "upstream-1")
        self.assertEqual(proxy["uri_variables"], ["request_uri"])
        self.assertEqual(proxy["authority_variables"], [])
        self.assertNotIn("internal_pool", json.dumps(result))
        self.assertNotIn("127.0.0.1", json.dumps(result))

    def test_variable_authority_and_uri_are_separated(self):
        config = base("map $host $dynamic_target { default private_backend; } server { listen 80; server_name wpcaregrid.com; location / { proxy_pass http://$dynamic_target/$request_uri; } }")
        result = summarize(dump(config))
        proxy = next(row for row in result["route_records"] if row["directive"] == "proxy_pass")
        self.assertEqual(proxy["target_kind"], "variable")
        self.assertEqual(proxy["authority_variables"], ["dynamic_target"])
        self.assertEqual(proxy["uri_variables"], ["request_uri"])
        self.assertIn("map-1", proxy["definition_links"]["dynamic_target"])
        self.assertNotIn("private_backend", json.dumps(result))
        self.assertEqual(result["route_verdict"], "HOLD")

    def test_map_source_target_capture_and_set_lhs_rhs_relationships(self):
        config = base("map $host $route { default /; wpcaregrid.com /private-value; } server { listen 80; server_name wpcaregrid.com; location /one { set $next $route; try_files $next /index.php; } location ~ ^/(?<slug>[a-z]+)/(item)(?:/|$) { rewrite ^ /private-target; } }")
        result = summarize(dump(config))
        map_row = next(row for row in result["route_records"] if row["directive"] == "map")
        set_row = next(row for row in result["route_records"] if row["directive"] == "set")
        locs = [ctx for row in result["route_records"] for ctx in row["context"] if ctx["kind"] == "location"]
        self.assertEqual(map_row["source_variables"], ["host"])
        self.assertEqual(map_row["target_variables"], ["route"])
        self.assertEqual(set_row["defined_variables"], ["next"])
        self.assertEqual(set_row["referenced_variables"], ["route"])
        self.assertIn("map-1", set_row["definition_links"]["route"])
        self.assertEqual(len({ctx["id"] for ctx in locs}), 2)
        self.assertIn("slug", result["variable_definitions"])
        self.assertIn("1", result["variable_definitions"])
        self.assertIn("2", result["variable_definitions"])
        self.assertEqual(result["route_verdict"], "HOLD")
        rendered = json.dumps(result)
        self.assertNotIn("/private-value", rendered)
        self.assertNotIn("/private-target", rendered)

    def test_map_regex_capture_relationships_are_named_and_redacted(self):
        config = base("map $uri $route { ~^/(?<slug>[a-z]+)/(?<item>[0-9]+)$ $1; default /private-value; } server { listen 80; server_name wpcaregrid.com; location / { return 404; } }")
        result = summarize(dump(config))
        entry = next(row for row in result["route_records"] if row["directive"] == "map-entry" and row["capture_variables"])
        self.assertEqual(entry["capture_variables"], ["1", "2", "item", "slug"])
        self.assertEqual(entry["parent_id"], "map-1")
        self.assertIn("route", result["variable_definitions"])
        for hidden in ("private-value", "[a-z]+", "[0-9]+"):
            self.assertNotIn(hidden, json.dumps(result))
        self.assertEqual(result["route_verdict"], "HOLD")

    def test_capture_scanner_ignores_escaped_and_character_class_text(self):
        self.assertEqual(M.captures([r"[(?<ghost>)]"]), [])
        self.assertEqual(M.captures([r"\(\?<ghost>"]), [])
        with self.assertRaises(M.Hold):
            M.captures([r"(?P<unsupported>value)"])

    def test_if_regex_capture_is_local_to_its_if_context(self):
        config = base("server { listen 80; server_name wpcaregrid.com; location / { if ($uri ~ ^/(?<slug>[^/]+)$) { set $inside $slug; } } location /other { set $outside $slug; } }")
        result = summarize(dump(config))
        set_rows = [row for row in result["route_records"] if row["directive"] == "set"]
        inside = next(row for row in set_rows if row["defined_variables"] == ["inside"])
        outside = next(row for row in set_rows if row["defined_variables"] == ["outside"])
        if_row = next(row for row in result["route_records"] if row["directive"] == "if")
        self.assertIn("slug", if_row["capture_variables"])
        self.assertIn(if_row["definition_id"], inside["definition_links"]["slug"])
        self.assertEqual(outside["definition_links"]["slug"], [])
        self.assertIn("slug", result["unresolved_variable_names"])

    def test_if_condition_resolves_variable_defined_in_same_location(self):
        config = base("server { listen 80; server_name wpcaregrid.com; location / { set $local_flag 1; if ($local_flag = 1) { return 404; } } }")
        result = summarize(dump(config))
        self.assertNotIn("local_flag", result["unresolved_variable_names"])
        self.assertEqual(result["route_verdict"], "HOLD")

    def test_unknown_unresolved_variable_holds_and_only_name_is_reported(self):
        result = summarize(dump(base("server { listen 80; server_name wpcaregrid.com; location / { proxy_pass http://$private_backend; } }")))
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertIn("private_backend", result["unresolved_variable_names"])
        self.assertNotIn("$private_backend", json.dumps(result))

    def test_unresolved_upstream_endpoint_variable_is_reported(self):
        result = summarize(dump(base("upstream internal_pool { server $private_host:9000; } server { listen 80; server_name wpcaregrid.com; location / { return 404; } }")))
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertIn("private_host", result["unresolved_variable_names"])
        rendered = json.dumps(result)
        self.assertNotIn("9000", rendered)
        self.assertNotIn("$private_host", rendered)

    def test_parentheses_in_prefix_and_rewrite_replacement_are_not_captures(self):
        result = summarize(dump(base("server { listen 80; server_name wpcaregrid.com; location /literal(foo) { rewrite ^ /replacement(bar)/$1; } }")))
        self.assertNotIn("1", result["variable_definitions"])
        self.assertIn("1", result["unresolved_variable_names"])
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertNotIn("replacement", json.dumps(result))

    def test_braced_numeric_and_builtin_variables_are_recognized(self):
        config = base("server { listen 443 ssl; server_name wpcaregrid.com; location / { set $route ${host}; if ($1) { return 404; } } }")
        result = summarize(dump(config))
        set_row = next(row for row in result["route_records"] if row["directive"] == "set")
        self.assertEqual(set_row["referenced_variables"], ["host"])
        if_row = next(row for row in result["route_records"] if row["directive"] == "if")
        self.assertEqual(if_row["condition_variables"], ["1"])
        self.assertIn("1", result["unresolved_variable_names"])
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertNotIn("${host}", json.dumps(result))

    def test_tls_certificate_and_key_rules_are_directive_specific(self):
        site = "server { listen 443 ssl; server_name wpcaregrid.com; ssl_certificate /etc/letsencrypt/live/wpcaregrid.com/fullchain.pem; ssl_certificate_key /etc/letsencrypt/live/wpcaregrid.com/privkey.pem; }"
        result = summarize(dump(base(site)))
        values = {row["directive"]: row["values"] for row in result["route_records"] if "values" in row}
        self.assertEqual(values["ssl_certificate"], "/etc/letsencrypt/live/wpcaregrid.com/fullchain.pem")
        self.assertEqual(values["ssl_certificate_key"], "/etc/letsencrypt/live/wpcaregrid.com/privkey.pem")
        bad = summarize(dump(base("server { listen 443 ssl; server_name wpcaregrid.com; ssl_certificate /etc/letsencrypt/live/wpcaregrid.com/privkey.pem; }")))
        self.assertEqual(next(row for row in bad["route_records"] if row["directive"] == "ssl_certificate")["values"], "<redacted>")
        self.assertEqual(bad["route_verdict"], "HOLD")

    def test_only_package_public_root_and_fpm_socket_are_exposed(self):
        good = summarize(dump(base(r"server { listen 80; server_name wpcaregrid.com; root /var/www/wpcaregrid-production/current/public; location ~ \.php$ { fastcgi_pass unix:/run/php/wpcaregrid-production-php8.5-fpm.sock; } }")))
        root = next(row for row in good["route_records"] if row["directive"] == "root")
        fpm = next(row for row in good["route_records"] if row["directive"] == "fastcgi_pass")
        self.assertEqual(root["values"], "/var/www/wpcaregrid-production/current/public")
        self.assertEqual(fpm["target_kind"], "approved-production-fpm-socket")
        bad = summarize(dump(base("server { listen 80; server_name wpcaregrid.com; root /srv/secret/public; }")))
        self.assertNotIn("/srv/secret", json.dumps(bad))
        self.assertEqual(bad["route_verdict"], "HOLD")

    def test_public_root_rejects_traversal_and_noncanonical_paths(self):
        for path in (
            "/var/www/wpcaregrid-production/releases/../public",
            "/var/www/wpcaregrid-production/releases/./release/public",
            "/var/www/wpcaregrid-production/releases//release/public",
            "/var/www/wpcaregrid-production/current/public/",
        ):
            with self.subTest(path=path):
                result = summarize(dump(base(f"server {{ listen 80; server_name wpcaregrid.com; root {path}; }}")))
                root = next(row for row in result["route_records"] if row["directive"] == "root")
                self.assertEqual(root["values"], "<redacted>")
                self.assertEqual(result["route_verdict"], "HOLD")
                self.assertNotIn(path, json.dumps(result))

    def test_confirmed_empty_include_glob_is_reported_without_revealing_pattern(self):
        config = base("server { listen 80; server_name wpcaregrid.com; location / { return 404; } }")
        config["/etc/nginx/nginx.conf"] = "events {} http { include /etc/nginx/mime.types; include /etc/nginx/optional.d/*.conf; include /etc/nginx/sites-enabled/*; }"
        result = summarize(dump(config))
        empty = [edge for edge in result["include_edges"] if edge["empty_glob_confirmed"]]
        self.assertEqual(len(empty), 1)
        self.assertEqual(empty[0]["source"], "/etc/nginx/nginx.conf")
        self.assertEqual(empty[0]["target_count"], 0)
        self.assertNotIn("optional.d", json.dumps(result))
        self.assertEqual(result["route_verdict"], "HOLD")

    def test_include_edges_show_source_target_and_explicit_empty_match(self):
        config = base("server { listen 80; server_name wpcaregrid.com; location / { return 404; } }")
        config["/etc/nginx/nginx.conf"] = (
            "events {} http { include /etc/nginx/mime.types; "
            "include /etc/nginx/optional.d/*.conf; include /etc/nginx/sites-enabled/*; }"
        )
        result = summarize(dump(config))
        self.assertEqual(len(result["include_edges"]), 3)
        mime = next(edge for edge in result["include_edges"] if edge["target_files"] == ["/etc/nginx/mime.types"])
        site = next(edge for edge in result["include_edges"] if edge["target_files"] == ["/etc/nginx/sites-enabled/site"])
        empty = next(edge for edge in result["include_edges"] if edge["empty_glob_confirmed"])
        self.assertEqual(mime["source"], "/etc/nginx/nginx.conf")
        self.assertEqual(site["source"], "/etc/nginx/nginx.conf")
        self.assertEqual(empty["target_files"], [])
        self.assertNotIn("optional.d", json.dumps(result))

    def test_include_resolution_mismatch_holds(self):
        pattern = "/etc/nginx/sites-enabled/*.conf"
        source_paths = {"/etc/nginx/sites-enabled/nested/site.conf"}
        with mock.patch.object(M.PINNED, "_resolve_nginx_include", return_value=["/etc/nginx/sites-enabled/nested/site.conf"]):
            with self.assertRaises(M.Hold) as caught:
                M.resolve_include(pattern, source_paths)
        self.assertEqual(caught.exception.code, "include-resolution-mismatch")

    def test_include_glob_star_does_not_cross_directory_components(self):
        paths = {"/etc/nginx/sites-enabled/site.conf", "/etc/nginx/sites-enabled/nested/site.conf"}
        self.assertEqual(M.resolve_include("/etc/nginx/sites-enabled/*.conf", paths), ["/etc/nginx/sites-enabled/site.conf"])
        self.assertEqual(M.resolve_include("/etc/nginx/sites-enabled/*.conf", {"/etc/nginx/sites-enabled/nested/site.conf"}), [])
        with self.assertRaises(M.Hold) as caught:
            M.resolve_include("/etc/nginx/sites-enabled/**/*.conf", paths)
        self.assertEqual(caught.exception.code, "recursive-include-glob-unsupported")

    def test_bad_syntax_unsupported_block_and_sensitive_values_fail_closed(self):
        config = base("server { listen 80; server_name wpcaregrid.com; auth_basic \"secret-realm\"; auth_basic_user_file /private/credentials; location / { return 302 https://secret.example/token; } }")
        result = summarize(dump(config))
        rendered = json.dumps(result)
        for secret in ("secret-realm", "/private/credentials", "secret.example", "/token"):
            self.assertNotIn(secret, rendered)
        self.assertEqual(result["route_verdict"], "HOLD")
        with self.assertRaises(ValueError):
            summarize(dump(base("server { listen 80;")))
        config["/etc/nginx/nginx.conf"] = "events {} http { include /etc/nginx/mime.types; include /etc/nginx/sites-enabled/*; } stream { server { listen 443; proxy_pass hidden; } }"
        result = summarize(dump(config))
        self.assertEqual(result["route_verdict"], "HOLD")
        self.assertTrue(any(row["directive"] == "stream" and row["reason"] == "unsupported-or-unexpected-block" for row in result["unclassified"]))


if __name__ == "__main__":
    unittest.main()
