#!/usr/bin/env python3
from pathlib import Path
import base64
import sys
import tempfile
import shutil
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
HARNESS = ROOT / "scripts/tests/php-fpm"
sys.path.insert(0, str(HARNESS))
from policy import ALLOWED_ENV, REQUIRED_ENV, fpm_environment, has_background_consumer, validate_environment

def require(condition, message):
    if not condition:
        raise SystemExit(message)

dockerfile = (HARNESS / "Dockerfile").read_text()
runner = (HARNESS / "run-ci.sh").read_text()
pool = (HARNESS / "pool.conf").read_text()
nginx = (HARNESS / "nginx.conf").read_text()
entrypoint = (HARNESS / "entrypoint.py").read_text()

require("php:8.5.12-fpm@sha256:8b4b4a764f7985137b13e4cb49dd59e967d9daa7946a731f9d06e31e748e39bb" in dockerfile,
        "FPM base must pin the reviewed PHP patch and immutable image digest.")
require("USER www-data:www-data" in dockerfile and "posix_geteuid() === 33" in (HARNESS / "probe.php").read_text(),
        "FPM must run as a dedicated unprivileged UID.")
for flag in ("--network none", "--pid private", "--cap-drop ALL", "--security-opt no-new-privileges:true",
             "--read-only", "--user 33:33", "--pids-limit 64"):
    require(flag in runner, f"Missing container isolation control: {flag}")
for forbidden in ("--privileged", "--network host", "--pid host", "--env-file", "--volume", "--mount",
                  "--publish", "--device"):
    require(forbidden not in runner, f"Forbidden container escape/control option present: {forbidden}")
require("clear_env = yes" in pool and "env[APP_KEY] = $APP_KEY" in pool,
        "FPM must clear inherited process environment and receive only explicit pool variables.")
require("listen 127.0.0.1:8080;" in nginx and "fastcgi_pass unix:/tmp/php-fpm.sock;" in nginx,
        "HTTP gateway must be loopback-only and use the real FPM FastCGI socket.")
require("QUEUE_CONNECTION=database" in dockerfile and "env[QUEUE_CONNECTION] = database" in pool,
        "The lane must use a durable test queue with no consumers, never sync/inline execution.")
require("MAIL_MAILER=array" in dockerfile and "FILESYSTEM_DISK=local" in dockerfile
        and "BACKUP_STORAGE=" in dockerfile
        and "STRIPE_ALLOW_LIVE_MODE=false" in dockerfile,
        "External sinks must be disabled in the fixed test profile.")
require("validate_environment(os.environ)" in entrypoint and "fpm_environment(os.environ)" in entrypoint,
        "The entrypoint must use the tested fail-closed environment contract.")
require("has_background_consumer()" in entrypoint,
        "The entrypoint must reject queue, scheduler, Supervisor, or Connector consumers.")
require("docker.sock" in (HARNESS / "probe.php").read_text() and "--env " in runner,
        "Credential/socket isolation assertions are missing.")

safe_environment = {**REQUIRED_ENV, "PATH": "/usr/bin:/bin", "HOME": "/tmp",
                    "APP_KEY": "base64:" + base64.b64encode(b"x" * 32).decode(),
                    "APP_DEBUG": "false", "APP_URL": "http://127.0.0.1:8080",
                    "FPM_WITNESS_PORT4": "43123", "FPM_WITNESS_PORT6": "43124"}
require(not validate_environment(safe_environment), "The fixed safe environment fixture was rejected.")
filtered = fpm_environment({**safe_environment, "GITHUB_TOKEN": "must-not-pass",
                            "HTTPS_PROXY": "must-not-pass", "STRIPE_SECRET": "must-not-pass",
                            "DB_URL": "must-not-pass"})
require(set(filtered) <= ALLOWED_ENV and "GITHUB_TOKEN" not in filtered
        and "HTTPS_PROXY" not in filtered and "STRIPE_SECRET" not in filtered and "DB_URL" not in filtered,
        "A credential or unreviewed variable entered the FPM environment allowlist.")

unsafe_profiles = (
    ("QUEUE_CONNECTION", "sync"), ("QUEUE_CONNECTION", "redis"),
    ("MAIL_MAILER", "smtp"), ("FILESYSTEM_DISK", "s3"),
    ("BACKUP_STORAGE", "s3://unapproved"), ("STRIPE_ALLOW_LIVE_MODE", "true"),
    ("MARKETING_RELEASE_ENFORCEMENT_MODE", "production"),
    ("DB_CONNECTION", "mysql"),
)
for key, value in unsafe_profiles:
    mutated = dict(safe_environment)
    mutated[key] = value
    require(bool(validate_environment(mutated)),
            f"Unsafe environment mutation was accepted: {key}={value}")

with tempfile.TemporaryDirectory() as temporary:
    proc_root = Path(temporary)
    (proc_root / "101").mkdir()
    (proc_root / "101" / "cmdline").write_bytes(b"python\0entrypoint.py\0")
    require(not has_background_consumer(proc_root), "A safe process fixture was rejected.")
    with patch("pathlib.Path.read_bytes", side_effect=PermissionError("fixture denial")):
        try:
            has_background_consumer(proc_root)
        except RuntimeError:
            pass
        else:
            raise SystemExit("An unreadable process entry was not treated as a hard failure.")
    for index, argv in enumerate((
        b"php\0artisan\0queue:work\0",
        b"php\0artisan\0schedule:run\0",
        b"supervisord\0",
        b"php\0wp-caregrid-connector\0",
        b"php\0JobRunner\0",
    ), start=200):
        (proc_root / str(index)).mkdir()
        (proc_root / str(index) / "cmdline").write_bytes(argv)
        require(has_background_consumer(proc_root),
                "A background-consumer process fixture was not rejected.")
        shutil.rmtree(proc_root / str(index))

print("PHP-FPM isolation policy, 8 unsafe profiles, and 5 consumer fixtures passed.")
