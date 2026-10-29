#!/usr/bin/env python3
import json
import os
import re
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from policy import ALLOWED_ENV, fpm_environment, has_background_consumer, validate_environment

ROOT = Path("/app")
TEST_DIR = Path("/opt/php-fpm-test")
FPM_CONFIG = TEST_DIR / "php-fpm.conf"
NGINX_CONFIG = TEST_DIR / "nginx.conf"
def fail(message):
    print(message, file=sys.stderr)
    raise SystemExit(1)

if os.geteuid() != 33:
    fail("FPM harness must run as the dedicated unprivileged www-data uid.")
if Path("/app/.env").exists():
    fail("An .env file is forbidden in the disposable FPM image.")
if Path("/var/run/docker.sock").exists() or Path("/run/docker.sock").exists():
    fail("Docker socket is visible inside the FPM sandbox.")
if validate_environment(os.environ):
    fail("Disposable FPM environment does not match the fixed safe profile.")

fpm_env = fpm_environment(os.environ)
if set(fpm_env) - ALLOWED_ENV:
    fail("A variable outside the explicit FPM environment allowlist was selected.")

for path in ("/tmp/nginx/client", "/tmp/nginx/fastcgi", "/tmp/nginx/proxy",
             "/tmp/nginx/uwsgi", "/tmp/nginx/scgi", "/app/storage/framework/cache/data",
             "/app/storage/framework/sessions", "/app/storage/framework/views", "/app/storage/logs"):
    Path(path).mkdir(parents=True, exist_ok=True)

def checked(stage, command, env=None, timeout=20, capture=True):
    try:
        return subprocess.run(command, env=env, check=True, timeout=timeout,
                              stdout=subprocess.PIPE if capture else None,
                              stderr=subprocess.PIPE if capture else None, text=True)
    except subprocess.CalledProcessError as error:
        fail(f"Disposable {stage} failed (exit code {error.returncode}).")
    except subprocess.TimeoutExpired:
        fail(f"Disposable {stage} timed out.")

checked("PHP-FPM configuration validation", ["php-fpm", "-tt", "-y", str(FPM_CONFIG)],
        env=fpm_env, timeout=10)
checked("Nginx configuration validation", ["nginx", "-t", "-c", str(NGINX_CONFIG)],
        env={"PATH": fpm_env["PATH"]}, timeout=10)
checked("Laravel SQLite migration", ["php", "artisan", "migrate", "--force", "--no-interaction"],
        env=fpm_env, timeout=60)

def assert_no_consumers():
    if has_background_consumer():
        fail("A forbidden queue, scheduler, Supervisor, or Connector consumer is running.")

fpm_log = Path("/tmp/php-fpm.log")
nginx_log = Path("/tmp/nginx.log")
fpm_log.touch()
nginx_log.touch()
children = []
clean_shutdown = True
try:
    fpm = subprocess.Popen(["php-fpm", "-F", "-y", str(FPM_CONFIG)], env=fpm_env,
                           stdout=fpm_log.open("ab"), stderr=subprocess.STDOUT)
    children.append(fpm)
    nginx = subprocess.Popen(["nginx", "-g", "daemon off;", "-c", str(NGINX_CONFIG)],
                             env={"PATH": fpm_env["PATH"]},
                             stdout=nginx_log.open("ab"), stderr=subprocess.STDOUT)
    children.append(nginx)

    ready = False
    deadline = time.monotonic() + 15
    while time.monotonic() < deadline:
        if any(child.poll() is not None for child in children):
            fail("FPM or the local HTTP gateway exited before readiness.")
        try:
            with urllib.request.urlopen("http://127.0.0.1:8080/up", timeout=2) as response:
                ready = response.status == 200
            if ready:
                break
        except (OSError, urllib.error.URLError):
            time.sleep(0.2)
    if not ready:
        fail("The loopback HTTP request did not complete through FastCGI/FPM.")

    with urllib.request.urlopen("http://127.0.0.1:8080/__ci_fpm_probe", timeout=10) as response:
        probe = json.loads(response.read().decode("utf-8"))
        if response.status != 200:
            fail("The FPM compatibility or isolation probe returned failure.")
    if probe.get("failures"):
        fail("FPM probe failed checks: " + ", ".join(probe["failures"]))

    listener_lines = checked(["ss", "-H", "-ltn"], env={"PATH": fpm_env["PATH"]}).stdout.splitlines()
    if not listener_lines or any(not re.search(r"127\.0\.0\.1:8080\s*$", line) for line in listener_lines):
        fail("A TCP listener other than the loopback-only HTTP gateway was detected.")
    assert_no_consumers()
finally:
    for child in reversed(children):
        if child.poll() is None:
            try:
                child.send_signal(signal.SIGQUIT)
                child.wait(timeout=8)
            except (subprocess.TimeoutExpired, ProcessLookupError):
                clean_shutdown = False
                child.terminate()
                try:
                    child.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    child.kill()
                    child.wait(timeout=2)
        if child.returncode != 0:
            clean_shutdown = False
    if not clean_shutdown:
        fail("FPM or the HTTP gateway required forced shutdown or exited unsuccessfully.")

for log_path in (fpm_log, nginx_log):
    output = log_path.read_text(errors="replace")
    if re.search(r"PHP (?:Warning|Fatal error|Deprecated)|\b(?:crit|alert)\b", output, re.IGNORECASE):
        fail("FPM or the HTTP gateway emitted a warning, fatal error, or critical startup error.")
assert_no_consumers()
print(json.dumps({
    "php_version": probe["php_version"],
    "sapi": probe["sapi"],
    "worker_uid": probe["worker_uid"],
    "extensions": sorted(probe["extensions"]),
    "opcache_enabled": probe["opcache_enabled"],
    "jobs_rows_after_smoke": probe["jobs_rows_after_smoke"],
    "fpm_fastcgi_http_200": True,
    "tcp_listener_scope": "127.0.0.1:8080 only",
    "background_consumers": "none",
    "egress_checks": {name: result for name, result in probe["checks"].items()
                      if "denied" in name or "witness" in name or "proxy" in name or "dns" in name},
}, sort_keys=True))
