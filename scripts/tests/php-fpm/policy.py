"""Fixed PHP-FPM test profile validation and consumer detection."""
import base64
import re
from pathlib import Path

ALLOWED_ENV = {
    "PATH", "HOME", "APP_ENV", "APP_KEY", "APP_DEBUG", "APP_URL",
    "CAREGRID_DEPLOYMENT_ENV", "MARKETING_RELEASE_PROFILE",
    "MARKETING_RELEASE_ENFORCEMENT_MODE", "DB_CONNECTION", "DB_DATABASE",
    "CACHE_STORE", "SESSION_DRIVER", "QUEUE_CONNECTION", "DB_QUEUE",
    "MAIL_MAILER", "FILESYSTEM_DISK", "BACKUP_STORAGE", "RES_OPTIONS",
    "STRIPE_ALLOW_LIVE_MODE", "STRIPE_MODE", "LOG_CHANNEL",
    "FPM_WITNESS_PORT4", "FPM_WITNESS_PORT6", "FPM_WITNESS_IPV4",
}
REQUIRED_ENV = {
    "APP_ENV": "testing",
    "CAREGRID_DEPLOYMENT_ENV": "testing",
    "MARKETING_RELEASE_PROFILE": "marketing_only",
    "MARKETING_RELEASE_ENFORCEMENT_MODE": "testing",
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": "/tmp/caregrid.sqlite",
    "CACHE_STORE": "array",
    "SESSION_DRIVER": "array",
    "QUEUE_CONNECTION": "database",
    "DB_QUEUE": "default",
    "MAIL_MAILER": "array",
    "FILESYSTEM_DISK": "local",
    "BACKUP_STORAGE": "",
    "RES_OPTIONS": "attempts:1 timeout:1",
    "STRIPE_ALLOW_LIVE_MODE": "false",
    "STRIPE_MODE": "test",
    "LOG_CHANNEL": "stderr",
}
FORBIDDEN_PREFIXES = ("GITHUB_", "ACTIONS_", "RUNNER_", "SSH_", "AWS_", "AZURE_", "DOCKER_")
FORBIDDEN_NAMES = {
    "ACTIONS_ID_TOKEN_REQUEST_TOKEN", "ACTIONS_RUNTIME_TOKEN", "GITHUB_TOKEN",
    "SSH_AUTH_SOCK", "HTTP_PROXY", "HTTPS_PROXY", "ALL_PROXY",
    "http_proxy", "https_proxy", "all_proxy",
}
BACKGROUND_CONSUMER_MARKERS = (
    "artisan queue:work", "artisan queue:listen", "artisan horizon",
    "artisan schedule:run", "artisan schedule:work", "supervisord",
    "wp-caregrid-connector", "JobRunner",
)


def validate_environment(environment):
    """Return a generic list of failed fixed-profile checks."""
    failures = []
    for key, expected in REQUIRED_ENV.items():
        if environment.get(key) != expected:
            failures.append("fixed_profile")
            break
    if any(name.startswith(FORBIDDEN_PREFIXES) or name in FORBIDDEN_NAMES
           for name in environment):
        failures.append("credential_or_proxy")
    app_key = environment.get("APP_KEY", "")
    try:
        valid_key = app_key.startswith("base64:") and len(
            base64.b64decode(app_key[7:], validate=True)
        ) == 32
    except (ValueError, TypeError):
        valid_key = False
    if not valid_key:
        failures.append("synthetic_key")
    return failures


def fpm_environment(environment):
    """Pass only the explicit non-secret allowlist to PHP-FPM and app commands."""
    return {key: environment[key] for key in ALLOWED_ENV if key in environment}


def has_background_consumer(proc_root=Path("/proc")):
    """Detect known queue, scheduler, Supervisor, and Connector consumers."""
    for proc in Path(proc_root).iterdir():
        if not proc.name.isdigit():
            continue
        try:
            args = (proc / "cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", "replace")
        except FileNotFoundError:
            continue
        except OSError as error:
            raise RuntimeError("Cannot establish the isolated consumer process state.") from error
        if any(marker in args for marker in BACKGROUND_CONSUMER_MARKERS):
            return True
    return False


SAFE_FATAL_DETAILS = (
    re.compile(r"Call to undefined function ([A-Za-z_][A-Za-z0-9_\\]*)"),
    re.compile(r"Call to undefined method ([A-Za-z_][A-Za-z0-9_\\]*)::([A-Za-z_][A-Za-z0-9_]*)\(\)"),
    re.compile(r'Class "([A-Za-z_][A-Za-z0-9_\\]*)" not found'),
    re.compile(r'Class "([A-Za-z_][A-Za-z0-9_\\]*)" does not exist'),
    re.compile(r'Target class \[([A-Za-z_][A-Za-z0-9_\\]*)\] does not exist\.'),
    re.compile(r"Call to a member function ([A-Za-z_][A-Za-z0-9_]*)\(\) on null"),
    re.compile(r'Undefined constant "([A-Za-z_][A-Za-z0-9_\\]*)"'),
)


def safe_php_fatal_detail(message):
    """Return only allowlisted symbol-level details, never arbitrary exception text."""
    for pattern in SAFE_FATAL_DETAILS:
        match = pattern.search(message)
        if match:
            return match.group(0)
    return None
