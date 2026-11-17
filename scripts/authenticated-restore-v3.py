#!/usr/bin/env python3
"""GO-14 Workstream B: authenticated recovery points and migration receipts.

This tool extends ``isolated-staging-restore-v2.py`` (it imports and reuses its
protected-file, SQL-audit, restore, inventory, DEFINER and cleanup machinery);
it adds no second restore engine. It contributes:

* an authenticated *capture record* that binds the source application SHA (or an
  explicit pre-bootstrap state), source schema, MySQL server UUID hash, the
  authenticated backup account, the capture interval and the archive hash;
* a secret-safe *config recovery reference* (fingerprints and an opaque custody
  locator only);
* a restore wrapper that refuses any v2 manifest not bound to a verified capture
  record, proves destination/source separation, and writes a durable,
  authenticated, machine-checkable restore receipt chained to the v2 receipt;
* a migration rehearsal receipt for a *clone* restored from the same recovery
  point: exact candidate migration inventory, checkpoints, trigger/DEFINER
  inventory, least-privilege account observations, baseline-unchanged proof.

Authentication is HMAC-SHA256 under a root-protected key (see docs). It proves
that a document was produced or re-issued by a holder of that key, not that an
operator-written JSON file is truthful; it is a symmetric, same-trust-domain
control and is not an independent third-party signature.

Nothing here connects to a production or staging database by itself: every
database action needs a root-protected destination/source policy installed by an
approved procedure. Source tests are synthetic. A PASS is evidence about the
recorded isolated assertions only and never authorizes a migration or release.
"""

import argparse
import gzip
import subprocess
import select
import time
import datetime
import hashlib
import hmac
import importlib.util
import json
import os
import re
import secrets
import stat
import sys
from pathlib import Path


def _load_v2():
    path = Path(__file__).resolve().with_name("isolated-staging-restore-v2.py")
    spec = importlib.util.spec_from_file_location("isolated_staging_restore_v2_for_v3", path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


V2 = _load_v2()
RestoreRefused = V2.RestoreRefused
refuse = V2.refuse

SCHEMA_VERSION = 1
MAX_RECORD_BYTES = 64 * 1024
MAX_RECEIPT_BYTES = 512 * 1024
MAX_KEY_BYTES = 4096
MAX_ARCHIVE_BYTES = V2.MAX_COMPRESSED_BACKUP_BYTES
MAX_CAPTURE_DURATION = datetime.timedelta(hours=6)
MAX_CAPTURE_AGE = datetime.timedelta(hours=72)
CAPTURE_AUDIT_TARGET = "wpcg_recovery_restore_20000101_00000000"
MAX_RECORD_LIFETIME = datetime.timedelta(days=7)
MAX_RESTORE_RECEIPT_AGE = datetime.timedelta(hours=24)
MAX_MIGRATION_RECEIPT_AGE = datetime.timedelta(hours=24)
CANDIDATE_GIT_STORE = Path("/var/lib/wpcaregrid/candidate.git")
REHEARSAL_HOLD_ROOT = Path("/var/lib/wpcaregrid/rehearsal-holds")
MAX_MIGRATION_FILES = 512
MAX_MIGRATION_FILE_BYTES = 256 * 1024
MIGRATION_FILE_RE = re.compile(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php\Z")
MIGRATION_NAME_RE = re.compile(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\Z")

# Pinned Phase 2B migration pair and its six guard triggers (see
# docs/PHASE2B_DBA_MIGRATION_RUNBOOK.md). A candidate that contains the pair must
# match these bytes, and the rehearsed schema must carry exactly these triggers.
PHASE2B_MIGRATION_PINS = {
    "2026_11_02_000002_create_transition_v2_requests_table":
        "f616d65af26cce1677582b48d3165b8e5811169f1a8735d221b8e5d82ae494da",
    "2026_11_02_000003_create_transition_history_events_table":
        "fec0ae70fcd373bce7f21ec5dc274c9f53d9961d5c0833b92ab437912741e5c1",
}
PHASE2B_TRIGGERS = (
    "transition_history_events_immutable_delete",
    "transition_history_events_immutable_insert",
    "transition_history_events_immutable_update",
    "transition_v2_requests_immutable_delete",
    "transition_v2_requests_immutable_insert",
    "transition_v2_requests_immutable_update",
)

# Exact SHA2(ACTION_STATEMENT,256) fingerprints of the reviewed pinned MySQL SQL.
PHASE2B_TRIGGER_DEFINITIONS = {
    "transition_history_events_immutable_delete": {
        "action_sha256": "09e48cf4bc95c947611e33d058d374186c1f7b9dd5d659c8f9cba4b4547b648c",
        "event": "DELETE",
        "orientation": "ROW",
        "table": "transition_history_events",
        "timing": "BEFORE"
    },
    "transition_history_events_immutable_insert": {
        "action_sha256": "373848dbeaca3dd3854c8bc2831a2f1beff3e8a19551a70bd5210bdc0eff99a0",
        "event": "INSERT",
        "orientation": "ROW",
        "table": "transition_history_events",
        "timing": "BEFORE"
    },
    "transition_history_events_immutable_update": {
        "action_sha256": "09e48cf4bc95c947611e33d058d374186c1f7b9dd5d659c8f9cba4b4547b648c",
        "event": "UPDATE",
        "orientation": "ROW",
        "table": "transition_history_events",
        "timing": "BEFORE"
    },
    "transition_v2_requests_immutable_delete": {
        "action_sha256": "041029af6c0f290168710f3e3a61bf1b06aa2c3ce021fef22cecdeacb65a7b6a",
        "event": "DELETE",
        "orientation": "ROW",
        "table": "transition_v2_requests",
        "timing": "BEFORE"
    },
    "transition_v2_requests_immutable_insert": {
        "action_sha256": "734aecfd63c6669102b8a84037406bb23eaf8787194f5f2186a3c8d2bd0548cf",
        "event": "INSERT",
        "orientation": "ROW",
        "table": "transition_v2_requests",
        "timing": "BEFORE"
    },
    "transition_v2_requests_immutable_update": {
        "action_sha256": "041029af6c0f290168710f3e3a61bf1b06aa2c3ce021fef22cecdeacb65a7b6a",
        "event": "UPDATE",
        "orientation": "ROW",
        "table": "transition_v2_requests",
        "timing": "BEFORE"
    }
}

PHASE2B_DEFINER_SELECT_TABLES = (
    "deployment_transition_fences", "transition_history_events", "transition_v2_requests",
)
PHASE2B_CONTRACT = {"pins": PHASE2B_MIGRATION_PINS, "triggers": PHASE2B_TRIGGERS,
                    "select_tables": PHASE2B_DEFINER_SELECT_TABLES, "definitions": PHASE2B_TRIGGER_DEFINITIONS}

RECORD_ID_RE = re.compile(r"[0-9a-f]{32}\Z")
IDENT_RE = re.compile(r"[A-Za-z0-9_]{1,64}\Z")
POLICY_ID_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,95}\Z")
TIME_RE = re.compile(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\Z")
REFERENCE_ID_RE = re.compile(r"[a-z0-9][a-z0-9_.-]{2,63}\Z")
LOCATOR_RE = re.compile(r"[a-z][a-z0-9-]{1,30}:[a-z0-9][a-z0-9_.-]{2,63}\Z")
SECRET_MARKERS = ("base64", "app_key", "password", "passwd", "secret", "token", "private",
                  "credential", "://", "@", "=", "/", "\\", " ")
HIGH_ENTROPY_RE = re.compile(r"[0-9a-fA-F]{32,}|[A-Za-z0-9+_-]{40,}")

PURPOSE_CAPTURE_BEGIN = "capture-begin"
PURPOSE_CAPTURE_RECORD = "capture-record"
PURPOSE_RESTORE_RECEIPT = "restore-receipt"
PURPOSE_RESTORE_CLEANUP = "restore-cleanup"
PURPOSE_MIGRATION_RECEIPT = "migration-receipt"
PURPOSE_MIGRATION_FAILURE = "migration-failure"

RECOVERY_POST_BOOTSTRAP = "post_bootstrap_application_backup"
RECOVERY_PRE_BOOTSTRAP = "pre_bootstrap_empty"

LIMITATIONS = [
    "isolated_rehearsal_evidence_only",
    "not_a_production_authorization",
    "hmac_symmetric_key_same_trust_domain",
    "migration_execution_and_artifact_tree_binding_external",
    "mysqldump_connection_uuid_not_independently_observed",
    "application_account_connection_identity_not_observed",
]


# --------------------------------------------------------------------------
# Small declarative validators (fixed refusal codes, never echo input).
# --------------------------------------------------------------------------

def _hex(length):
    pattern = re.compile(f"[0-9a-f]{{{length}}}\\Z")
    return lambda value: isinstance(value, str) and pattern.fullmatch(value) is not None


def _rx(pattern):
    return lambda value: isinstance(value, str) and pattern.fullmatch(value) is not None


def _text(maximum=256):
    return lambda value: (isinstance(value, str) and 0 < len(value) <= maximum
                          and all(33 <= ord(character) < 127 for character in value))


def _int(low, high):
    return lambda value: type(value) is int and low <= value <= high


def _time(value):
    return isinstance(value, str) and TIME_RE.fullmatch(value) is not None and _try_time(value) is not None


def _try_time(value):
    try:
        parsed = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return parsed if parsed.tzinfo is not None else None


def _exact(expected):
    return lambda value: type(value) is type(expected) and value == expected


def _one_of(*values):
    return lambda value: isinstance(value, str) and value in values


def _list(item, maximum):
    return [item, maximum]


def _check(value, spec, code):
    if isinstance(spec, dict):
        if not isinstance(value, dict) or set(value) != set(spec):
            refuse(code)
        for key, nested in spec.items():
            _check(value[key], nested, code)
    elif isinstance(spec, list):
        item, maximum = spec
        if not isinstance(value, list) or len(value) > maximum:
            refuse(code)
        for element in value:
            _check(element, item, code)
    elif callable(spec):
        if not spec(value):
            refuse(code)
    elif type(value) is not type(spec) or value != spec:
        refuse(code)


def _utc(value):
    return _try_time(value).astimezone(datetime.timezone.utc)


def _iso(moment):
    return moment.astimezone(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _now(now):
    moment = now or datetime.datetime.now(datetime.timezone.utc)
    if moment.tzinfo is None:
        refuse("CLOCK_INVALID")
    return moment.astimezone(datetime.timezone.utc)


def _canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii")


def _sha(data):
    return hashlib.sha256(data).hexdigest()


def _ledger_sha(names):
    """Hash of a ledger in the listed order (the order MySQL returned it)."""
    return _sha("".join(name + "\n" for name in names).encode("ascii"))


def _canonical_ledger_sha(names):
    """Collation-independent ledger hash used by checkpoints: sorted names, LF terminated."""
    return _ledger_sha(sorted(names))


def _assert_secret_free(value):
    """Final guard: no secret-looking *value* may enter a receipt or record."""
    if isinstance(value, dict):
        for nested in value.values():
            _assert_secret_free(nested)
    elif isinstance(value, list):
        for nested in value:
            _assert_secret_free(nested)
    elif isinstance(value, str):
        lowered = value.lower()
        if "base64:" in lowered or "app_key=" in lowered or "password=" in lowered or "-----begin" in lowered:
            refuse("SECRET_MATERIAL_IN_DOCUMENT")


# --------------------------------------------------------------------------
# Protected file and key handling.
# --------------------------------------------------------------------------

def _read_protected_bytes(path, *, owner_uid, maximum, code):
    descriptor = None
    try:
        path = Path(path)
        if not path.is_absolute() or path != Path(os.path.normpath(str(path))):
            refuse(code + "_PATH_INVALID")
        V2._check_protected_path_parents(path, owner_uid=owner_uid)
        descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
            refuse(code + "_METADATA_INVALID")
        if info.st_size > maximum:
            refuse(code + "_SIZE_LIMIT")
        chunks, total = [], 0
        while True:
            block = os.read(descriptor, min(1024 * 1024, maximum + 1 - total))
            if not block:
                break
            total += len(block)
            if total > maximum:
                refuse(code + "_SIZE_LIMIT")
            chunks.append(block)
        return b"".join(chunks), info
    except RestoreRefused:
        raise
    except OSError:
        refuse(code + "_UNAVAILABLE")
    finally:
        if descriptor is not None:
            os.close(descriptor)


def load_signing_key(path, *, owner_uid):
    raw, _info = _read_protected_bytes(path, owner_uid=owner_uid, maximum=MAX_KEY_BYTES, code="SIGNING_KEY")
    try:
        text = raw.decode("ascii", "strict").strip()
        key = bytes.fromhex(text)
    except (UnicodeError, ValueError):
        refuse("SIGNING_KEY_INVALID")
    if len(key) < 32 or len(set(key)) < 8:
        refuse("SIGNING_KEY_WEAK")
    return key


def _subkey(key, purpose):
    return hmac.new(key, b"wpcg-go14-v1\0" + purpose.encode("ascii"), hashlib.sha256).digest()


def _auth_spec(purpose):
    return {"algorithm": "HMAC-SHA256", "purpose": purpose, "key_id": _hex(16), "mac": _hex(64)}


def sign_document(body, key, purpose):
    if "authentication" in body:
        refuse("DOCUMENT_ALREADY_AUTHENTICATED")
    _assert_secret_free(body)
    mac = hmac.new(_subkey(key, purpose), _canonical(body), hashlib.sha256).hexdigest()
    signed = dict(body)
    signed["authentication"] = {"algorithm": "HMAC-SHA256", "purpose": purpose,
                                "key_id": _sha(key)[:16], "mac": mac}
    return signed


def verify_document(document, key, purpose):
    if not isinstance(document, dict) or "authentication" not in document:
        refuse("AUTHENTICATION_MISSING")
    _check(document["authentication"], _auth_spec(purpose), "AUTHENTICATION_INVALID")
    body = {name: value for name, value in document.items() if name != "authentication"}
    expected = hmac.new(_subkey(key, purpose), _canonical(body), hashlib.sha256).hexdigest()
    if (document["authentication"]["key_id"] != _sha(key)[:16]
            or not hmac.compare_digest(expected, document["authentication"]["mac"])):
        refuse("AUTHENTICATION_FAILED")
    return body


def _load_signed(path, key, purpose, spec, *, owner_uid, maximum=MAX_RECORD_BYTES, code="DOCUMENT_SCHEMA_INVALID"):
    document, file_sha = V2.read_protected_json(path, owner_uid=owner_uid, maximum=maximum)
    body = verify_document(document, key, purpose)
    _check(body, spec, code)
    return body, file_sha


def _write_signed(path, body, key, purpose, *, owner_uid):
    return V2._atomic_json(path, sign_document(body, key, purpose), owner_uid=owner_uid)


def _require_absent(*paths):
    for path in paths:
        if Path(path).exists() or Path(path).is_symlink():
            refuse("RECEIPT_ALREADY_EXISTS")


# --------------------------------------------------------------------------
# Config recovery reference (secret-safe).
# --------------------------------------------------------------------------

CONFIG_REFERENCE_SPEC = {
    "reference_id": _rx(REFERENCE_ID_RE), "custody": "protected_escrow", "locator_id": _rx(LOCATOR_RE),
    "app_key_fingerprint_sha256": _hex(64), "config_identity_sha256": _hex(64), "verified_at": _time,
}


def validate_config_recovery_reference(reference):
    """Validate an opaque recovery reference. It may carry only fingerprints and
    identifiers: never a path, URL, key, password, token or credential value."""
    _check(reference, CONFIG_REFERENCE_SPEC, "CONFIG_RECOVERY_REFERENCE_INVALID")
    for name in ("reference_id", "locator_id"):
        value = reference[name].lower()
        if any(marker in value for marker in SECRET_MARKERS):
            refuse("CONFIG_RECOVERY_REFERENCE_SECRET_LIKE")
        for part in re.split(r"[:]", reference[name]):
            if HIGH_ENTROPY_RE.search(part):
                refuse("CONFIG_RECOVERY_REFERENCE_SECRET_LIKE")
    if reference["app_key_fingerprint_sha256"] == reference["config_identity_sha256"]:
        refuse("CONFIG_RECOVERY_REFERENCE_INVALID")
    return reference


def verify_config_reference_against_config(reference, config_path, *, owner_uid):
    config_sha, app_key_sha = V2._config_identity(config_path, owner_uid=owner_uid)
    if (not hmac.compare_digest(config_sha, reference["config_identity_sha256"])
            or not hmac.compare_digest(app_key_sha, reference["app_key_fingerprint_sha256"])):
        refuse("CONFIG_RECOVERY_REFERENCE_MISMATCH")


# --------------------------------------------------------------------------
# Source policy and capture records.
# --------------------------------------------------------------------------

SOURCE_POLICY_SPEC = {
    "schema_version": 1, "policy_id": _rx(POLICY_ID_RE), "environment_id": _text(), "host_id": _text(),
    "schema": _rx(IDENT_RE), "backup_account": _rx(V2.DEFINER_RE), "approved_definer": _rx(V2.DEFINER_RE),
    "server_uuid_sha256": _hex(64),
    "mysql": {"host": _one_of("127.0.0.1", "localhost"), "port": _int(1, 65535),
              "defaults_file": lambda value: isinstance(value, str) and Path(value).is_absolute()},
    "recovery_point_kind": _one_of(RECOVERY_POST_BOOTSTRAP, RECOVERY_PRE_BOOTSTRAP),
    "application": {"state": _one_of("deployed", "pre_bootstrap"),
                    "sha": lambda value: value is None or _hex(40)(value)},
    "deployment_receipt": _text(4096), "active_release": _text(4096),
}


def _check_application_consistency(kind, application, code):
    deployed = application["state"] == "deployed"
    if (kind == RECOVERY_POST_BOOTSTRAP) != deployed or deployed != (application["sha"] is not None):
        refuse(code)


def source_policy_object(policy):
    _check(policy, SOURCE_POLICY_SPEC, "SOURCE_POLICY_INVALID")
    _check_application_consistency(policy["recovery_point_kind"], policy["application"], "SOURCE_POLICY_INVALID")
    if policy["backup_account"].split("@", 1)[0].lower() in {"root", "mysql.sys", "mysql.session", "mysql.infoschema"}:
        refuse("SOURCE_POLICY_INVALID")
    return policy


def _source_mysql_policy(policy):
    mysql = policy["mysql"]
    return {"mysql": {"host": mysql["host"], "port": mysql["port"], "defaults_file": mysql["defaults_file"],
                      "client": "/usr/bin/mysql", "dump_client": "/usr/bin/mysqldump"}}


def observe_source_identity(policy):
    """Authenticate the *current connection* against the source policy. Only the
    server UUID hash is retained; the raw UUID never leaves this function."""
    raw = V2.mysql_query(
        _source_mysql_policy(policy),
        "SELECT CURRENT_USER(),DATABASE(),@@server_uuid,VERSION(),@@GLOBAL.log_bin,"
        "@@GLOBAL.binlog_format,@@GLOBAL.log_bin_trust_function_creators,"
        "DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA "
        "WHERE SCHEMA_NAME=DATABASE()",
        database=policy["schema"], maximum=2048)
    try:
        fields = raw.decode("ascii", "strict").rstrip("\n").split("\t")
    except UnicodeError:
        refuse("SOURCE_IDENTITY_INVALID")
    if len(fields) != 9:
        refuse("SOURCE_IDENTITY_INVALID")
    observation = {"current_user": fields[0], "database": fields[1],
                   "server_uuid_sha256": _sha(fields[2].encode("ascii", "replace")),
                   "mysql_version": fields[3], "log_bin": fields[4], "binlog_format": fields[5],
                   "trust_function_creators": fields[6], "database_charset": fields[7],
                   "database_collation": fields[8]}
    return observation


def check_source_observation(policy, observation):
    if (observation["current_user"] != policy["backup_account"]
            or observation["database"] != policy["schema"]
            or not hmac.compare_digest(observation["server_uuid_sha256"], policy["server_uuid_sha256"])
            or not V2.MYSQL_VERSION_RE.fullmatch(observation["mysql_version"])
            or not IDENT_RE.fullmatch(observation["database_charset"])
            or not IDENT_RE.fullmatch(observation["database_collation"])
            or observation["log_bin"] != "1" or observation["binlog_format"] != "ROW"
            or observation["trust_function_creators"] != "0"):
        refuse("SOURCE_IDENTITY_MISMATCH")


OBSERVATION_SPEC = {
    "current_user": _rx(V2.DEFINER_RE), "database": _rx(IDENT_RE), "server_uuid_sha256": _hex(64),
    "mysql_version": _rx(V2.MYSQL_VERSION_RE), "log_bin": "1", "binlog_format": "ROW",
    "trust_function_creators": "0", "database_charset": _rx(IDENT_RE),
    "database_collation": _rx(IDENT_RE),
}
CAPTURE_BEGIN_SPEC = {
    "schema_version": 1, "kind": "wpcg_capture_begin", "record_id": _rx(RECORD_ID_RE),
    "started_at": _time, "expires_at": _time, "source_policy_sha256": _hex(64),
    "observation": OBSERVATION_SPEC,
}
CAPTURE_RECORD_SPEC = {
    "schema_version": 1, "kind": "wpcg_capture_record", "record_id": _rx(RECORD_ID_RE),
    "issued_at": _time, "expires_at": _time, "source_policy_sha256": _hex(64),
    "recovery_point_kind": _one_of(RECOVERY_POST_BOOTSTRAP, RECOVERY_PRE_BOOTSTRAP),
    "application": {"state": _one_of("deployed", "pre_bootstrap"),
                    "sha": lambda value: value is None or _hex(40)(value)},
    "source": {"environment_id": _text(), "host_id": _text(), "schema": _rx(IDENT_RE),
               "backup_account": _rx(V2.DEFINER_RE), "server_uuid_sha256": _hex(64),
               "mysql_version": _rx(V2.MYSQL_VERSION_RE)},
    "capture": {"started_at": _time, "finished_at": _time, "identity_observation_sha256": _hex(64),
                "deployment_receipt_sha256": _hex(64), "dump_exit_status": 0},
    "archive": {"id": _rx(V2.BACKUP_ID_RE), "size_bytes": _int(1, MAX_ARCHIVE_BYTES), "sha256": _hex(64)},
    "config_reference": CONFIG_REFERENCE_SPEC,
}


def capture_begin(*args, **kwargs):
    refuse("EXTERNAL_CAPTURE_DISABLED")


def capture_finish(*args, **kwargs):
    refuse("EXTERNAL_CAPTURE_DISABLED")


def _validate_defaults(policy, owner_uid):
    raw, _ = _read_protected_bytes(policy["mysql"]["defaults_file"], owner_uid=owner_uid,
                                    maximum=64 * 1024, code="MYSQL_DEFAULTS")
    # This file supplies credentials only. Option groups and option keys can
    # change dump semantics or load client plugins even when the argv is fixed.
    group = None
    seen = set()
    configured_user = None
    for line in raw.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith((b"#", b";")):
            continue
        if stripped.startswith(b"!"):
            refuse("MYSQL_DEFAULTS_INCLUDE_FORBIDDEN")
        if stripped.startswith(b"["):
            if not stripped.endswith(b"]"):
                refuse("MYSQL_DEFAULTS_GROUP_INVALID")
            group = stripped[1:-1].strip().lower()
            if group != b"client":
                refuse("MYSQL_TOOL_DEFAULTS_GROUP_FORBIDDEN")
            continue
        if group != b"client" or b"=" not in stripped:
            refuse("MYSQL_DEFAULTS_OPTION_FORBIDDEN")
        key, _value = stripped.split(b"=", 1)
        key = key.strip().lower()
        if key not in {b"user", b"password"} or not key:
            refuse("MYSQL_DEFAULTS_OPTION_FORBIDDEN")
        if key in seen:
            refuse("MYSQL_DEFAULTS_OPTION_DUPLICATE")
        seen.add(key)
        if key == b"user":
            configured_user = _value.strip()
    if "backup_account" in policy:
        expected_user = policy["backup_account"].split("@", 1)[0].encode("ascii", "strict")
        if configured_user != expected_user:
            refuse("MYSQL_DEFAULTS_ACCOUNT_MISMATCH")
    return _sha(raw)


def observe_deployment(policy, *, owner_uid):
    """Observe a protected successful-deployment receipt and the active release
    symlink. The SHA remains a deployment-system attestation, not a DB value."""
    receipt, digest = V2.read_protected_json(policy["deployment_receipt"], owner_uid=owner_uid,
                                             maximum=MAX_RECORD_BYTES)
    _check(receipt, {"schema_version": 1, "result": "PASS", "application_sha": _hex(40),
                     "environment_id": _text(), "host_id": _text(), "release_path": _text(4096)},
           "DEPLOYMENT_RECEIPT_INVALID")
    active = Path(policy["active_release"])
    release = Path(receipt["release_path"])
    if not active.is_absolute() or not release.is_absolute():
        refuse("ACTIVE_RELEASE_INVALID")
    V2._check_protected_path_parents(active, owner_uid=owner_uid)
    V2._check_protected_path_parents(release / "identity", owner_uid=owner_uid)
    try:
        info = active.lstat()
        # The activation link must point directly at the immutable release. A
        # chain through a second mutable symlink would weaken that identity.
        if (not stat.S_ISLNK(info.st_mode) or info.st_uid != owner_uid
                or os.readlink(active) != str(release)
                or active.resolve(strict=True) != release or release.name != receipt["application_sha"]):
            refuse("ACTIVE_RELEASE_MISMATCH")
    except OSError:
        refuse("ACTIVE_RELEASE_INVALID")
    if (policy["application"] != {"state": "deployed", "sha": receipt["application_sha"]}
            or receipt["environment_id"] != policy["environment_id"] or receipt["host_id"] != policy["host_id"]):
        refuse("DEPLOYMENT_RECEIPT_BINDING_MISMATCH")
    identity, _ = V2.read_protected_json(release / ".wpcg-release.json", owner_uid=owner_uid,
                                          maximum=MAX_RECORD_BYTES)
    if identity != {"application_sha": receipt["application_sha"], "deployment_receipt_sha256": digest}:
        refuse("ACTIVE_RELEASE_IDENTITY_MISMATCH")
    return digest


def _bounded_process(argv, consume, *, maximum, timeout=300):
    process = None
    total = diagnostic = 0
    try:
        process = subprocess.Popen(argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, env={**V2._mysql_child_env(),
                                   "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null",
                                   "GIT_NO_REPLACE_OBJECTS": "1", "GIT_NO_LAZY_FETCH": "1",
                                   "GIT_PROTOCOL_FROM_USER": "0", "GIT_ALLOW_PROTOCOL": "",
                                   "HOME": "/nonexistent"}, close_fds=True)
        deadline = time.monotonic() + timeout
        streams = {process.stdout.fileno(): True, process.stderr.fileno(): False}
        while streams:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                refuse("CAPTURE_PROCESS_TIMEOUT")
            ready, _, _ = select.select(list(streams), [], [], min(0.5, remaining))
            for fd in ready:
                block = os.read(fd, 65536)
                if not block:
                    del streams[fd]
                elif streams[fd]:
                    total += len(block)
                    if total > maximum:
                        refuse("CAPTURE_PROCESS_SIZE_LIMIT")
                    consume(block)
                else:
                    diagnostic += len(block)
                    if diagnostic > 16384:
                        refuse("CAPTURE_PROCESS_DIAGNOSTIC_LIMIT")
        if process.wait(timeout=max(.1, deadline - time.monotonic())) != 0:
            refuse("CAPTURE_PROCESS_FAILED")
    except (OSError, subprocess.TimeoutExpired):
        refuse("CAPTURE_PROCESS_FAILED")
    finally:
        if process is not None:
            if process.poll() is None:
                process.kill()
                process.wait()
            process.stdout.close()
            process.stderr.close()
    if total == 0:
        refuse("CAPTURE_PROCESS_EMPTY")


def capture(source_policy_path, archive_path, archive_id, config_reference_path, record_path,
            key_path, *, owner_uid, now=None, observe=None):
    started = _now(now)
    key = load_signing_key(key_path, owner_uid=owner_uid)
    policy, policy_sha = V2.read_protected_json(source_policy_path, owner_uid=owner_uid, maximum=32768)
    source_policy_object(policy)
    if policy["application"]["state"] != "deployed":
        refuse("PRE_BOOTSTRAP_CAPTURE_UNSUPPORTED")
    defaults_sha = _validate_defaults(policy, owner_uid)
    deployment_sha = observe_deployment(policy, owner_uid=owner_uid)
    _require_absent(archive_path, record_path)
    if not V2.BACKUP_ID_RE.fullmatch(archive_id):
        refuse("ARCHIVE_ID_INVALID")
    reference, _ = V2.read_protected_json(config_reference_path, owner_uid=owner_uid, maximum=MAX_RECORD_BYTES)
    validate_config_recovery_reference(reference)
    observation = (observe or observe_source_identity)(policy)
    _check(observation, OBSERVATION_SPEC, "SOURCE_IDENTITY_MISMATCH")
    check_source_observation(policy, observation)
    archive = Path(archive_path)
    V2._check_protected_path_parents(archive, owner_uid=owner_uid)
    argv = ["/usr/bin/mysqldump", "--defaults-file=" + policy["mysql"]["defaults_file"],
            "--protocol=tcp", "--host=" + policy["mysql"]["host"], "--port=" + str(policy["mysql"]["port"]),
            "--single-transaction", "--skip-lock-tables", "--quick", "--no-tablespaces",
            "--set-gtid-purged=OFF", "--hex-blob", "--default-character-set=utf8mb4",
            "--skip-set-charset", "--triggers", "--skip-routines", "--skip-events", "--", policy["schema"]]
    fd = os.open(archive, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as output:
        os.fchown(output.fileno(), owner_uid, -1)
        os.fchmod(output.fileno(), 0o600)
        with gzip.GzipFile(fileobj=output, mode="wb", mtime=0) as compressed:
            compressed.write(V2.wrap_single_schema_dump(
                b"", policy["schema"], observation["database_charset"], observation["database_collation"]))
            def consume(block):
                compressed.write(block)
                if output.tell() > MAX_ARCHIVE_BYTES:
                    refuse("ARCHIVE_SIZE_LIMIT")
            _bounded_process(argv, consume, maximum=V2.MAX_UNCOMPRESSED_SQL_BYTES)
        output.flush()
        os.fsync(output.fileno())
    _fsync_directory(archive.parent)
    raw, _ = _read_protected_bytes(archive, owner_uid=owner_uid, maximum=MAX_ARCHIVE_BYTES, code="ARCHIVE")
    captured_sql = V2.decode_verified_archive(raw, _sha(raw))
    V2.audit_sql(captured_sql, CAPTURE_AUDIT_TARGET, policy["approved_definer"])
    after = (observe or observe_source_identity)(policy)
    check_source_observation(policy, after)
    if observation != after:
        refuse("SOURCE_IDENTITY_CHANGED_DURING_CAPTURE")
    if (_validate_defaults(policy, owner_uid) != defaults_sha
            or observe_deployment(policy, owner_uid=owner_uid) != deployment_sha):
        refuse("SOURCE_DEPLOYMENT_CHANGED_DURING_CAPTURE")
    _, current_policy_sha = V2.read_protected_json(source_policy_path, owner_uid=owner_uid, maximum=32768)
    if current_policy_sha != policy_sha:
        refuse("CAPTURE_POLICY_CHANGED")
    finished = _now(None)
    if finished < started or finished - started > MAX_CAPTURE_DURATION:
        refuse("CAPTURE_INTERVAL_INVALID")
    body = {
        "schema_version": 1, "kind": "wpcg_capture_record", "record_id": secrets.token_hex(16),
        "issued_at": _iso(finished), "expires_at": _iso(finished + MAX_RECORD_LIFETIME),
        "source_policy_sha256": policy_sha, "recovery_point_kind": policy["recovery_point_kind"],
        "application": policy["application"],
        "source": {"environment_id": policy["environment_id"], "host_id": policy["host_id"],
                   "schema": policy["schema"], "backup_account": observation["current_user"],
                   "server_uuid_sha256": observation["server_uuid_sha256"], "mysql_version": observation["mysql_version"]},
        "capture": {"started_at": _iso(started), "finished_at": _iso(finished),
                    "identity_observation_sha256": _sha(_canonical(observation)),
                    "deployment_receipt_sha256": deployment_sha, "dump_exit_status": 0},
        "archive": {"id": archive_id, "size_bytes": len(raw), "sha256": _sha(raw)},
        "config_reference": reference,
    }
    _check(body, CAPTURE_RECORD_SPEC, "CAPTURE_RECORD_INVALID")
    return _write_signed(record_path, body, key, PURPOSE_CAPTURE_RECORD, owner_uid=owner_uid)


def verify_capture_record(path, key, *, owner_uid, now=None):
    now = _now(now)
    body, file_sha = _load_signed(path, key, PURPOSE_CAPTURE_RECORD, CAPTURE_RECORD_SPEC,
                                  owner_uid=owner_uid, code="CAPTURE_RECORD_INVALID")
    _check_application_consistency(body["recovery_point_kind"], body["application"], "CAPTURE_RECORD_INVALID")
    validate_config_recovery_reference(body["config_reference"])
    issued, expires = _utc(body["issued_at"]), _utc(body["expires_at"])
    started, finished = _utc(body["capture"]["started_at"]), _utc(body["capture"]["finished_at"])
    if not started <= finished <= issued or finished - started > MAX_CAPTURE_DURATION:
        refuse("CAPTURE_INTERVAL_INVALID")
    if issued > now + datetime.timedelta(seconds=5) or expires <= issued or expires - issued > MAX_RECORD_LIFETIME:
        refuse("CAPTURE_RECORD_TIME_INVALID")
    if expires <= now or now - finished > MAX_CAPTURE_AGE:
        refuse("CAPTURE_RECORD_STALE")
    return body, file_sha


def bind_capture_to_manifest(capture, manifest, policy):
    """Bind the v2 manifest/destination policy to an authenticated capture record."""
    if capture["recovery_point_kind"] != RECOVERY_POST_BOOTSTRAP or capture["application"]["state"] != "deployed":
        refuse("PRE_BOOTSTRAP_RESTORE_UNSUPPORTED")
    backup, source, manifest_source = manifest["backup"], capture["source"], manifest["source_identity"]
    archive = capture["archive"]
    if (backup["id"] != archive["id"] or backup["sha256"] != archive["sha256"]
            or backup["size_bytes"] != archive["size_bytes"]):
        refuse("CAPTURE_ARCHIVE_BINDING_MISMATCH")
    if (backup["source_application_sha"] != capture["application"]["sha"]
            or manifest_source["application_sha"] != capture["application"]["sha"]):
        refuse("CAPTURE_APPLICATION_SHA_MISMATCH")
    if (backup["source_schema"] != source["schema"] or manifest_source["database"] != source["schema"]
            or manifest["target_schema"] == source["schema"]):
        refuse("CAPTURE_SCHEMA_BINDING_MISMATCH")
    source_uuid_sha = _sha(manifest_source["server_uuid"].encode("ascii", "replace"))
    if (not hmac.compare_digest(source_uuid_sha, source["server_uuid_sha256"])
            or manifest_source["environment_id"] != source["environment_id"]
            or manifest_source["host_id"] != source["host_id"]):
        refuse("CAPTURE_SOURCE_IDENTITY_MISMATCH")
    destination = policy["destination_identity"]
    destination_uuid_sha = _sha(policy["mysql"]["server_uuid"].encode("ascii", "replace"))
    if (destination_uuid_sha == source["server_uuid_sha256"]
            or destination["environment_id"] == source["environment_id"]
            or destination["host_id"] == source["host_id"]):
        refuse("DESTINATION_NOT_SEPARATE_FROM_SOURCE")
    reference = capture["config_reference"]
    if (manifest["config"]["app_key_fingerprint_sha256"] != reference["app_key_fingerprint_sha256"]
            or manifest["config"]["identity_sha256"] != reference["config_identity_sha256"]):
        refuse("CAPTURE_CONFIG_BINDING_MISMATCH")
    if _utc(manifest["issued_at"]) < _utc(capture["capture"]["finished_at"]):
        refuse("MANIFEST_PREDATES_CAPTURE")
    return {"source_uuid_sha256": source["server_uuid_sha256"], "destination_uuid_sha256": destination_uuid_sha}


# --------------------------------------------------------------------------
# Authenticated restore (wraps the v2 restore; adds a chained receipt).
# --------------------------------------------------------------------------

def _auth_path(receipt_path, suffix=".authenticated.json"):
    receipt_path = Path(receipt_path)
    return receipt_path.with_name(receipt_path.stem + suffix)


TRIGGER_RECORD_SPEC = {"name": _rx(V2.TABLE_RE), "event": _one_of("INSERT", "UPDATE", "DELETE"),
                       "table": _rx(V2.TABLE_RE), "timing": _one_of("BEFORE", "AFTER"),
                       "orientation": "ROW", "definer": _rx(V2.DEFINER_RE), "action_sha256": _hex(64)}
DEFINER_LIFECYCLE_SPEC = {
    "account_exists": True, "account_locked": True, "global_privileges": _exact(["USAGE"]),
    "schema_privileges": _exact(["TRIGGER"]), "select_tables": _list(_rx(V2.TABLE_RE), 64), "grants_sha256": _hex(64),
}
RESTORE_RECEIPT_SPEC = {
    "schema_version": 1, "kind": "wpcg_authenticated_restore_receipt", "receipt_id": _rx(RECORD_ID_RE),
    "result": "PASS", "issued_at": _time, "expires_at": _time, "started_at": _time, "completed_at": _time,
    "capture_record_sha256": _hex(64), "manifest_sha256": _hex(64), "destination_policy_sha256": _hex(64),
    "v2_receipt_sha256": _hex(64),
    "release": {"application_sha": _hex(40), "base_sha": _hex(40), "artifact_sha256": _hex(64)},
    "backup": {"id": _rx(V2.BACKUP_ID_RE), "sha256": _hex(64), "size_bytes": _int(1, MAX_ARCHIVE_BYTES),
               "source_application_sha": _hex(40)},
    "source": {"environment_id": _text(), "host_id": _text(), "schema": _rx(IDENT_RE),
               "server_uuid_sha256": _hex(64)},
    "destination": {"environment_id": _text(), "host_id": _text(), "server_uuid_sha256": _hex(64),
                    "target_schema": _rx(V2.TARGET_RE)},
    "separation": {"server_uuid_distinct": True, "environment_distinct": True, "host_distinct": True,
                   "schema_distinct": True, "target_schema_absent_before_import": True},
    "observed": {"schema_sha256": _hex(64), "ledger_sha256": _hex(64), "data_sha256": _hex(64),
                 "table_names": _list(_rx(V2.TABLE_RE), 256),
                 "table_row_counts": lambda v: isinstance(v, dict) and 0 < len(v) <= 256 and all(
                     V2.TABLE_RE.fullmatch(k) and type(c) is int and c >= 0 for k, c in v.items()),
                 "migrations": _list(_rx(MIGRATION_NAME_RE), 2048),
                 "triggers": _list(TRIGGER_RECORD_SPEC, 64), "definer_lifecycle": DEFINER_LIFECYCLE_SPEC},
    "config_recovery": {"reference_id": _rx(REFERENCE_ID_RE), "app_key_fingerprint_sha256": _hex(64),
                        "config_identity_sha256": _hex(64), "verified": True},
    "cleanup": {"status": "retained_for_migration_rehearsal"},
    "limitations": _list(_text(96), 8),
}


def verify_restore_receipt(path, key, *, owner_uid, now=None, capture_sha256=None, allow_stale=False):
    now = _now(now)
    body, file_sha = _load_signed(path, key, PURPOSE_RESTORE_RECEIPT, RESTORE_RECEIPT_SPEC,
                                  owner_uid=owner_uid, maximum=MAX_RECEIPT_BYTES, code="RESTORE_RECEIPT_INVALID")
    started, completed = _utc(body["started_at"]), _utc(body["completed_at"])
    issued, expires = _utc(body["issued_at"]), _utc(body["expires_at"])
    if (not started <= completed <= issued or expires <= issued or expires - issued > MAX_RESTORE_RECEIPT_AGE
            or issued > now + datetime.timedelta(seconds=5)):
        refuse("RESTORE_RECEIPT_TIME_INVALID")
    if not allow_stale and expires <= now:
        refuse("RESTORE_RECEIPT_STALE")
    if (body["source"]["server_uuid_sha256"] == body["destination"]["server_uuid_sha256"]
            or body["source"]["environment_id"] == body["destination"]["environment_id"]
            or body["source"]["host_id"] == body["destination"]["host_id"]
            or body["source"]["schema"] == body["destination"]["target_schema"]):
        refuse("RESTORE_RECEIPT_SEPARATION_INVALID")
    if set(body["observed"]["table_names"]) != set(body["observed"]["table_row_counts"]) or len(
            set(body["observed"]["table_names"])) != len(body["observed"]["table_names"]):
        refuse("RESTORE_RECEIPT_INVENTORY_INVALID")
    if capture_sha256 is not None and body["capture_record_sha256"] != capture_sha256:
        refuse("RESTORE_RECEIPT_CAPTURE_MISMATCH")
    return body, file_sha


def _restore_receipt_body(capture, capture_sha, manifest, manifest_sha, policy, policy_sha, v2_receipt,
                          v2_receipt_sha, bound, now):
    observed = v2_receipt["observed"]
    destination_uuid_sha = _sha(v2_receipt["destination_identity"]["mysql_server_uuid"].encode("ascii", "replace"))
    if destination_uuid_sha != bound["destination_uuid_sha256"]:
        refuse("DESTINATION_UUID_OBSERVATION_MISMATCH")
    return {
        "schema_version": 1, "kind": "wpcg_authenticated_restore_receipt", "receipt_id": secrets.token_hex(16),
        "result": "PASS", "issued_at": _iso(now), "expires_at": _iso(now + MAX_RESTORE_RECEIPT_AGE),
        "started_at": _iso(_utc(v2_receipt["started_at"])), "completed_at": _iso(_utc(v2_receipt["completed_at"])),
        "capture_record_sha256": capture_sha, "manifest_sha256": manifest_sha,
        "destination_policy_sha256": policy_sha, "v2_receipt_sha256": v2_receipt_sha,
        "release": manifest["release"],
        "backup": {"id": manifest["backup"]["id"], "sha256": manifest["backup"]["sha256"],
                   "size_bytes": manifest["backup"]["size_bytes"],
                   "source_application_sha": capture["application"]["sha"]},
        "source": {"environment_id": capture["source"]["environment_id"], "host_id": capture["source"]["host_id"],
                   "schema": capture["source"]["schema"],
                   "server_uuid_sha256": capture["source"]["server_uuid_sha256"]},
        "destination": {"environment_id": policy["destination_identity"]["environment_id"],
                        "host_id": policy["destination_identity"]["host_id"],
                        "server_uuid_sha256": destination_uuid_sha, "target_schema": manifest["target_schema"]},
        "separation": {"server_uuid_distinct": True, "environment_distinct": True, "host_distinct": True,
                       "schema_distinct": True, "target_schema_absent_before_import": True},
        "observed": {"schema_sha256": observed["schema_sha256"], "ledger_sha256": observed["ledger_sha256"],
                     "data_sha256": observed["data_sha256"],
                     "table_names": manifest["expected"]["table_names"],
                     "table_row_counts": observed["table_row_counts"],
                     "migrations": observed["migrations"], "triggers": observed["triggers"],
                     "definer_lifecycle": v2_receipt["definer_lifecycle"]},
        "config_recovery": {"reference_id": capture["config_reference"]["reference_id"],
                            "app_key_fingerprint_sha256": capture["config_reference"]["app_key_fingerprint_sha256"],
                            "config_identity_sha256": capture["config_reference"]["config_identity_sha256"],
                            "verified": True},
        "cleanup": {"status": "retained_for_migration_rehearsal"},
        "limitations": list(LIMITATIONS),
    }


def restore_authenticated(manifest_path, policy_path, config_path, receipt_path, capture_path, key_path,
                          *, owner_uid, now=None):
    now = _now(now)
    key = load_signing_key(key_path, owner_uid=owner_uid)
    capture, capture_sha = verify_capture_record(capture_path, key, owner_uid=owner_uid, now=now)
    manifest, manifest_sha, policy, policy_sha = V2.verify_manifest_and_policy(
        manifest_path, policy_path, owner_uid=owner_uid)
    _validate_defaults(policy, owner_uid)
    verify_config_reference_against_config(capture["config_reference"], config_path, owner_uid=owner_uid)
    bound = bind_capture_to_manifest(capture, manifest, policy)
    authenticated_path = _auth_path(receipt_path)
    _require_absent(authenticated_path, _auth_path(receipt_path, ".authenticated-cleanup.json"))
    v2_receipt = V2._restore_impl(manifest_path, policy_path, config_path, receipt_path, owner_uid=owner_uid)
    try:
        v2_receipt_sha = _sha(Path(receipt_path).read_bytes())
        if v2_receipt["manifest_sha256"] != manifest_sha or v2_receipt["destination_policy_sha256"] != policy_sha:
            refuse("INPUTS_CHANGED_DURING_RESTORE")
        body = _restore_receipt_body(capture, capture_sha, manifest, manifest_sha, policy, policy_sha,
                                     v2_receipt, v2_receipt_sha, bound, _now(None))
        _check(body, RESTORE_RECEIPT_SPEC, "RESTORE_RECEIPT_INVALID")
        _write_signed(authenticated_path, body, key, PURPOSE_RESTORE_RECEIPT, owner_uid=owner_uid)
    except BaseException:
        # No PASS evidence may outlive a failed chain: remove the restored
        # schema through the v2 owner-bound cleanup, or leave an explicit HOLD.
        authenticated_path_obj = Path(authenticated_path)
        try:
            _validate_defaults(policy, owner_uid)
            V2._cleanup_restore_impl(manifest_path, policy_path, receipt_path, owner_uid=owner_uid)
            authenticated_path_obj.unlink(missing_ok=True)
        except BaseException:
            refuse("RESTORE_FAILED_CLEANUP_HOLD")
        raise
    return body


def _load_chained_restore_receipt(receipt_path, key, *, owner_uid, now, capture_sha256=None):
    """Load the authenticated receipt next to a v2 receipt and prove the v2 file is the one it chains to."""
    body, file_sha = verify_restore_receipt(_auth_path(receipt_path), key, owner_uid=owner_uid, now=now,
                                            capture_sha256=capture_sha256)
    v2_bytes, _info = _read_protected_bytes(receipt_path, owner_uid=owner_uid, maximum=MAX_RECEIPT_BYTES,
                                            code="RESTORE_RECEIPT")
    if not hmac.compare_digest(_sha(v2_bytes), body["v2_receipt_sha256"]):
        refuse("RESTORE_RECEIPT_CHAIN_BROKEN")
    return body, file_sha


def cleanup_authenticated(manifest_path, policy_path, receipt_path, key_path, *, owner_uid, now=None):
    now = _now(now)
    key = load_signing_key(key_path, owner_uid=owner_uid)
    authenticated_path = _auth_path(receipt_path)
    receipt, receipt_sha = verify_restore_receipt(authenticated_path, key, owner_uid=owner_uid, now=now, allow_stale=True)
    _manifest, manifest_sha, policy, policy_sha = V2.verify_manifest_and_policy(
        manifest_path, policy_path, owner_uid=owner_uid)
    if (receipt["manifest_sha256"] != manifest_sha or receipt["destination_policy_sha256"] != policy_sha
            or receipt["v2_receipt_sha256"] != _sha(Path(receipt_path).read_bytes())):
        refuse("CLEANUP_RECEIPT_BINDING_INVALID")
    cleanup_path = _auth_path(receipt_path, ".authenticated-cleanup.json")
    _require_absent(cleanup_path)
    _validate_defaults(policy, owner_uid)
    V2._cleanup_restore_impl(manifest_path, policy_path, receipt_path, owner_uid=owner_uid)
    body = {"schema_version": 1, "kind": "wpcg_authenticated_restore_cleanup", "result": "PASS",
            "completed_at": _iso(_now(None)), "restore_receipt_sha256": receipt_sha,
            "target_schema": receipt["destination"]["target_schema"],
            "destination_server_uuid_sha256": receipt["destination"]["server_uuid_sha256"],
            "absence_verified": True}
    _write_signed(cleanup_path, body, key, PURPOSE_RESTORE_CLEANUP, owner_uid=owner_uid)
    return body


# --------------------------------------------------------------------------
# Least-privilege account observation.
# --------------------------------------------------------------------------

APP_SCHEMA_PRIVILEGES = frozenset({"select", "insert", "update", "delete"})
DEFINER_SCHEMA_PRIVILEGES = frozenset({"select", "trigger"})

FORBIDDEN_PRIVILEGES = frozenset({
    "super", "file", "grant option", "create user", "reload", "shutdown", "process", "all", "all privileges",
    "replication slave", "replication client", "create role", "drop role", "role_admin", "system_user",
    "binlog_admin", "connection_admin", "set_user_id", "system_variables_admin", "allow_nonexistent_definer",
})


def _split_grant(line):
    normalized = V2._normalized_grant(line)
    match = re.fullmatch(r"grant (.+?) on (.+?) to (\S+?)( with grant option)?", normalized)
    if not match:
        refuse("ACCOUNT_GRANT_UNPARSEABLE")  # includes role grants and unknown shapes
    privileges = tuple(sorted(part.strip() for part in match.group(1).split(",")))
    return privileges, match.group(2), bool(match.group(4))


def account_privilege_report(policy, schema, account, *, allow_schema_privileges, allowed_privileges=APP_SCHEMA_PRIVILEGES):
    """Observe an account's grants. Global privileges must be exactly USAGE; any
    schema-level grant must stay inside ``schema`` and avoid forbidden privileges."""
    if not V2.DEFINER_RE.fullmatch(account) or not V2.TARGET_RE.fullmatch(schema):
        refuse("ACCOUNT_CONTRACT_INVALID")
    user, host = account.split("@", 1)
    raw = V2.mysql_query(policy, "SHOW GRANTS FOR '" + user + "'@'" + host + "'", database=schema, maximum=16_384)
    try:
        lines = [line for line in raw.decode("utf-8", "strict").splitlines() if line]
    except UnicodeError:
        refuse("ACCOUNT_GRANT_UNPARSEABLE")
    if not lines or len(lines) > 128:
        refuse("ACCOUNT_GRANT_INVENTORY_INVALID")
    scoped = V2._normalized_grant(V2._grant_pattern_identifier(schema))
    schema_privileges, globals_seen, normalized_lines = set(), False, []
    for line in lines:
        privileges, obj, grant_option = _split_grant(line)
        normalized_lines.append(V2._normalized_grant(line))
        if grant_option or any(p in FORBIDDEN_PRIVILEGES for p in privileges):
            refuse("ACCOUNT_PRIVILEGE_EXCESSIVE")
        if obj == "*.*":
            globals_seen = True
            if privileges != ("usage",):
                refuse("ACCOUNT_PRIVILEGE_EXCESSIVE")
        # Match one complete quoted schema identifier followed by .*; do not
        # accept a dotted identifier whose prefix happens to match the schema.
        elif obj == scoped + ".*":
            if not set(privileges) <= allowed_privileges:
                refuse("ACCOUNT_PRIVILEGE_EXCESSIVE")
            schema_privileges.update(privileges)
        else:
            refuse("ACCOUNT_SCOPE_EXCESSIVE")
    if not globals_seen or (schema_privileges and not allow_schema_privileges):
        refuse("ACCOUNT_PRIVILEGE_EXCESSIVE")
    return {"account": account, "global_privileges": ["usage"], "schema_privileges": sorted(schema_privileges),
            "grants_sha256": _sha("\n".join(sorted(normalized_lines)).encode("utf-8"))}


def foreground_sessions(policy, schema, account):
    user = account.split("@", 1)[0]
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", user):
        refuse("ACCOUNT_CONTRACT_INVALID")
    raw = V2.mysql_query(
        policy, "SELECT COUNT(*) FROM performance_schema.threads WHERE TYPE='FOREGROUND' AND PROCESSLIST_USER='"
        + user + "'", database=schema, maximum=32)
    if not re.fullmatch(rb"[0-9]{1,6}\n?", raw):
        refuse("ACCOUNT_SESSION_INVENTORY_INVALID")
    return int(raw)


# --------------------------------------------------------------------------
# Migration rehearsal receipt.
# --------------------------------------------------------------------------

ACCOUNT_REPORT_SPEC = {"account": _rx(V2.DEFINER_RE), "global_privileges": _exact(["usage"]),
                       "schema_privileges": _list(_text(64), 32), "grants_sha256": _hex(64)}
MIGRATION_RECEIPT_SPEC = {
    "schema_version": 1, "kind": "wpcg_migration_rehearsal_receipt", "receipt_id": _rx(RECORD_ID_RE),
    "result": "PASS", "issued_at": _time, "expires_at": _time,
    "capture_record_sha256": _hex(64), "baseline_receipt_sha256": _hex(64), "clone_receipt_sha256": _hex(64),
    "clone_manifest_sha256": _hex(64), "destination_policy_sha256": _hex(64), "rehearsal_inputs_sha256": _hex(64),
    "candidate": {"application_sha": _hex(40), "base_sha": _hex(40), "artifact_sha256": _hex(64),
                  "migration_inventory_sha256": _hex(64), "migration_count": _int(1, MAX_MIGRATION_FILES),
                  "migrations": _list({"name": _rx(MIGRATION_NAME_RE), "sha256": _hex(64)}, MAX_MIGRATION_FILES)},
    "restore_point": {"backup_sha256": _hex(64), "source_application_sha": _hex(40),
                      "source_server_uuid_sha256": _hex(64), "destination_server_uuid_sha256": _hex(64),
                      "baseline_schema": _rx(V2.TARGET_RE), "clone_schema": _rx(V2.TARGET_RE)},
    "before": {"schema_sha256": _hex(64), "ledger_sha256": _hex(64), "data_sha256": _hex(64),
               "migrations": _list(_rx(MIGRATION_NAME_RE), 2048)},
    "after": {"schema_sha256": _hex(64), "ledger_sha256": _hex(64), "data_sha256": _hex(64),
              "migrations": _list(_rx(MIGRATION_NAME_RE), 2048),
              "table_row_counts": lambda v: isinstance(v, dict) and 0 < len(v) <= 256 and all(
                  V2.TABLE_RE.fullmatch(k) and type(c) is int and c >= 0 for k, c in v.items())},
    "pending": _list(_rx(MIGRATION_NAME_RE), 2048),
    "checkpoints": _list({"migration": _rx(MIGRATION_NAME_RE), "schema_sha256": _hex(64),
                          "ledger_sha256": _hex(64)}, 2048),
    "mysql": {"server_uuid_sha256": _hex(64), "log_bin": True, "binlog_format": "ROW",
              "trust_function_creators": False},
    "triggers": _list(TRIGGER_RECORD_SPEC, 64),
    "phase2b": {"pair_in_candidate": lambda v: type(v) is bool, "trigger_inventory_complete": lambda v: type(v) is bool,
                "pair_hashes_pinned": lambda v: type(v) is bool},
    "observed": {
        "baseline_unchanged": True, "destination_uuid_distinct_from_source": True,
        "definer": ACCOUNT_REPORT_SPEC | {"locked": True, "select_tables": _list(_rx(V2.TABLE_RE), 64)},
        "app_account": ACCOUNT_REPORT_SPEC, "dba_executor": ACCOUNT_REPORT_SPEC,
        "elevated_sessions_open": 0,
    },
    # This utility proves a disposable-clone rehearsal only. Production
    # recovery readiness requires separate host-bound evidence and cannot be
    # inferred from a successful synthetic/clone receipt.
    "production_admission": {
        "state": _one_of("HOLD", "PASS"),
        "required_runtime_attestations": _list(_text(64), 3),
    },
    "attested": {
        "accounts": {"app": _rx(V2.DEFINER_RE), "dba_executor": _rx(V2.DEFINER_RE), "definer": _rx(V2.DEFINER_RE)},
        "dba_window": {"granted_at": _time, "revoked_at": _time, "sessions_terminated_at": _time},
        "guarded_writes": {"executed_as": "app", "allowed_write_passed": True, "rejected_write_passed": True},
    },
    "limitations": _list(_text(96), 8),
}
REHEARSAL_INPUTS_SPEC = {
    "schema_version": 1, "rehearsal_id": _rx(POLICY_ID_RE),
    "candidate": {"application_sha": _hex(40), "base_sha": _hex(40), "artifact_sha256": _hex(64)},
    "accounts": {"app": _rx(V2.DEFINER_RE), "dba_executor": _rx(V2.DEFINER_RE), "definer": _rx(V2.DEFINER_RE)},
    "dba_window": {"granted_at": _time, "revoked_at": _time, "sessions_terminated_at": _time},
    "guarded_writes": {"executed_as": "app", "allowed_write_passed": True, "rejected_write_passed": True},
    "definer_select_tables": _list(_rx(V2.TABLE_RE), 64),
    "checkpoints": _list({"migration": _rx(MIGRATION_NAME_RE), "schema_sha256": _hex(64),
                          "ledger_sha256": _hex(64)}, 2048),
}
MIGRATION_FAILURE_SPEC = {
    "schema_version": 1, "kind": "wpcg_migration_rehearsal_failure", "result": "HOLD", "code": _text(96),
    "failed_at": _time, "clone_receipt_sha256": _hex(64), "destination_policy_sha256": _hex(64),
    "clone_schema": _rx(V2.TARGET_RE),
}


def candidate_migration_inventory(application_sha, *, owner_uid):
    """Read exact blobs from a fixed, protected bare object store; never a worktree."""
    if not _hex(40)(application_sha):
        refuse("CANDIDATE_SHA_INVALID")
    store = CANDIDATE_GIT_STORE
    V2._check_protected_path_parents(store / "objects", owner_uid=owner_uid)
    for root, dirs, files in os.walk(store, followlinks=False):
        for name in dirs + files:
            path = Path(root) / name
            try:
                info = path.lstat()
            except OSError:
                refuse("CANDIDATE_STORE_UNSAFE")
            if (info.st_uid != owner_uid or info.st_mode & 0o022 or stat.S_ISLNK(info.st_mode)
                    or (stat.S_ISREG(info.st_mode) and info.st_nlink != 1)
                    or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode))):
                refuse("CANDIDATE_STORE_UNSAFE")
    # Git's repository config is executable policy: partial-clone promisor
    # settings may trigger fetches and core.sshCommand may run a program.
    # Accept only the minimal bare-repository config emitted by git init.
    config_path = store / "config"
    try:
        config_info = config_path.lstat()
        if (not stat.S_ISREG(config_info.st_mode) or config_info.st_uid != owner_uid
                or config_info.st_mode & 0o022 or config_info.st_nlink != 1
                or config_info.st_size > 16 * 1024):
            refuse("CANDIDATE_STORE_UNSAFE")
        config_fd = os.open(config_path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            opened = os.fstat(config_fd)
            if (opened.st_ino != config_info.st_ino or opened.st_dev != config_info.st_dev
                    or opened.st_nlink != 1 or opened.st_uid != owner_uid):
                refuse("CANDIDATE_STORE_UNSAFE")
            config_raw = os.read(config_fd, 16 * 1024 + 1)
        finally:
            os.close(config_fd)
    except RestoreRefused:
        raise
    except OSError:
        refuse("CANDIDATE_STORE_UNSAFE")
    if len(config_raw) > 16 * 1024:
        refuse("CANDIDATE_STORE_UNSAFE")
    try:
        config_text = config_raw.decode("utf-8", "strict")
    except UnicodeError:
        refuse("CANDIDATE_STORE_UNSAFE")
    config_keys = set()
    section = None
    for line in config_text.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith(("#", ";")):
            continue
        if stripped.startswith("[") and stripped.endswith("]"):
            section = stripped[1:-1].strip().lower()
            if section != "core":
                refuse("CANDIDATE_STORE_UNSAFE")
            continue
        key, sep, value = stripped.partition("=")
        if not sep:
            refuse("CANDIDATE_STORE_UNSAFE")
        key, value = key.strip().lower(), value.strip().lower()
        if section != "core" or key not in {"repositoryformatversion", "filemode", "bare"}:
            refuse("CANDIDATE_STORE_UNSAFE")
        if key in config_keys:
            refuse("CANDIDATE_STORE_UNSAFE")
        config_keys.add(key)
        expected = {"repositoryformatversion": "0", "filemode": "true", "bare": "true"}[key]
        if value != expected:
            refuse("CANDIDATE_STORE_UNSAFE")
    if config_keys != {"repositoryformatversion", "filemode", "bare"}:
        refuse("CANDIDATE_STORE_UNSAFE")
    if any((store / name).exists() for name in ("objects/info/alternates", "info/grafts", "shallow")):
        refuse("CANDIDATE_STORE_UNSAFE")
    def git(*args, maximum):
        chunks = []
        _bounded_process(["/usr/bin/git", "-c", "protocol.allow=never", "-c", "gc.auto=0",
                          "-c", "maintenance.auto=false", "--git-dir=" + str(store), *args],
                         chunks.append, maximum=maximum)
        return b"".join(chunks)
    def verify_object(oid, kind, data):
        if not re.fullmatch(r"[0-9a-f]{40}", oid):
            refuse("CANDIDATE_OBJECT_INVALID")
        digest = hashlib.sha1(kind.encode("ascii") + b" " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()
        if digest != oid:
            refuse("CANDIDATE_OBJECT_HASH_MISMATCH")
        return data
    def tree_entries(data):
        entries, names, offset = [], set(), 0
        while offset < len(data):
            space = data.find(b" ", offset)
            nul = data.find(b"\0", space + 1) if space >= 0 else -1
            if space <= offset or nul < 0 or nul + 21 > len(data):
                refuse("CANDIDATE_TREE_INVALID")
            mode, name = data[offset:space], data[space + 1:nul]
            raw_oid = data[nul + 1:nul + 21]
            if not name or b"/" in name or name in names or mode not in (b"40000", b"100644", b"100755", b"120000", b"160000"):
                refuse("CANDIDATE_TREE_INVALID")
            names.add(name)
            entries.append((mode, name, raw_oid.hex()))
            offset = nul + 21
        return entries
    if git("rev-parse", "--is-bare-repository", maximum=64) != b"true\n":
        refuse("CANDIDATE_STORE_UNSAFE")
    if git("cat-file", "-t", application_sha, maximum=64) != b"commit\n":
        refuse("CANDIDATE_SHA_INVALID")
    commit_raw = verify_object(application_sha, "commit", git("cat-file", "commit", application_sha,
                                                                 maximum=MAX_RECORD_BYTES))
    tree_match = re.match(rb"tree ([0-9a-f]{40})\n", commit_raw)
    if not tree_match:
        refuse("CANDIDATE_COMMIT_INVALID")
    tree_oid = tree_match.group(1).decode("ascii")
    root_tree = verify_object(tree_oid, "tree", git("cat-file", "tree", tree_oid,
                                                       maximum=16 * 1024 * 1024))
    root_entries = tree_entries(root_tree)
    database_matches = [(mode, oid) for mode, name, oid in root_entries if name == b"database"]
    if len(database_matches) != 1 or database_matches[0][0] != b"40000":
        refuse("CANDIDATE_TREE_INVALID")
    database_oid = database_matches[0][1]
    database_tree = verify_object(database_oid, "tree", git("cat-file", "tree", database_oid,
                                                               maximum=16 * 1024 * 1024))
    database_entries = tree_entries(database_tree)
    migration_matches = [(mode, oid) for mode, name, oid in database_entries if name == b"migrations"]
    if len(migration_matches) != 1 or migration_matches[0][0] != b"40000":
        refuse("CANDIDATE_TREE_INVALID")
    migrations_oid = migration_matches[0][1]
    migrations_tree = verify_object(migrations_oid, "tree", git("cat-file", "tree", migrations_oid,
                                                                   maximum=MAX_MIGRATION_FILES * 512))
    entries = tree_entries(migrations_tree)
    if not entries or len(entries) > MAX_MIGRATION_FILES:
        refuse("CANDIDATE_MIGRATIONS_COUNT_INVALID")
    inventory = []
    for mode_bytes, name_bytes, oid in entries:
        try:
            mode, name = mode_bytes.decode("ascii"), name_bytes.decode("ascii")
        except UnicodeError:
            refuse("CANDIDATE_TREE_INVALID")
        if mode != "100644" or not MIGRATION_FILE_RE.fullmatch(name):
            refuse("CANDIDATE_TREE_INVALID")
        data = verify_object(oid, "blob", git("cat-file", "blob", oid, maximum=MAX_MIGRATION_FILE_BYTES))
        inventory.append({"name": name[:-4], "sha256": _sha(data)})
    return sorted(inventory, key=lambda item: item["name"])


def _inventory_sha(inventory):
    return _sha(_canonical(inventory))


def _check_checkpoints(before, pending, checkpoints, final_schema_sha):
    if len(checkpoints) != len(pending):
        refuse("MIGRATION_CHECKPOINTS_INCOMPLETE")
    applied = set(before)
    for expected, checkpoint in zip(pending, checkpoints):
        applied.add(expected)
        if checkpoint["migration"] != expected or checkpoint["ledger_sha256"] != _canonical_ledger_sha(applied):
            refuse("MIGRATION_CHECKPOINT_MISMATCH")
    if checkpoints and checkpoints[-1]["schema_sha256"] != final_schema_sha:
        refuse("MIGRATION_CHECKPOINT_MISMATCH")


def _check_ledger_against_candidate(before, after, candidate_names, pins, inventory):
    candidate = set(candidate_names)
    if len(set(before)) != len(before) or len(set(after)) != len(after):
        refuse("MIGRATION_LEDGER_INVALID")
    if not set(before) <= set(after):
        refuse("MIGRATION_LEDGER_REGRESSED")
    if not set(after) <= candidate or not set(before) <= candidate:
        refuse("LEDGER_NOT_IN_CANDIDATE")
    if set(after) != candidate:
        refuse("MIGRATION_REHEARSAL_INCOMPLETE")
    pair_present = bool(pins) and all(name in candidate for name in pins)
    if any(name in candidate for name in pins) and not pair_present:
        refuse("PHASE2B_PAIR_INCOMPLETE")
    if pair_present:
        by_name = {item["name"]: item["sha256"] for item in inventory}
        if any(by_name[name] != pins[name] for name in pins):
            refuse("PHASE2B_PAIR_HASH_MISMATCH")
    return pair_present


def _check_phase2b_triggers(triggers, definer, pair_present, trigger_names, definitions=None):
    names = [trigger["name"] for trigger in triggers]
    complete = sorted(names) == sorted(trigger_names) and all(t["definer"] == definer for t in triggers)
    definitions = PHASE2B_TRIGGER_DEFINITIONS if definitions is None else definitions
    complete = complete and all(t == {"name": t["name"], "definer": definer, **definitions.get(t["name"], {})}
                                for t in triggers)
    if pair_present and not complete:
        refuse("PHASE2B_TRIGGER_INVENTORY_INCOMPLETE")
    return complete


def _fsync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _migration_failure_path(clone):
    identity = {"server": clone["destination"]["server_uuid_sha256"],
                "schema": clone["destination"]["target_schema"]}
    return REHEARSAL_HOLD_ROOT / (_sha(_canonical(identity)) + ".json")


def _assert_no_migration_hold(body, *, owner_uid):
    restore_point = body["restore_point"]
    identity = {"server": restore_point["destination_server_uuid_sha256"],
                "schema": restore_point["clone_schema"]}
    path = REHEARSAL_HOLD_ROOT / (_sha(_canonical(identity)) + ".json")
    try:
        V2._check_protected_path_parents(path, owner_uid=owner_uid)
        root_info = REHEARSAL_HOLD_ROOT.lstat()
        if (not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != owner_uid
                or root_info.st_mode & 0o022):
            refuse("FAILED_REHEARSAL_HOLD_UNREADABLE")
        info = path.lstat()
    except FileNotFoundError:
        return
    except OSError:
        refuse("FAILED_REHEARSAL_HOLD_UNREADABLE")
    if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or info.st_mode & 0o077 or info.st_nlink != 1:
        refuse("FAILED_REHEARSAL_HOLD_PRESENT")
    # Any marker, including a malformed or stale one, is a fail-closed hold.
    refuse("FAILED_REHEARSAL_HOLD_PRESENT")


def create_migration_receipt(baseline_receipt_path, clone_receipt_path, clone_manifest_path, policy_path,
                             rehearsal_inputs_path, out_path, key_path, *, owner_uid, now=None,
                             contract=None):
    now = _now(now)
    contract = PHASE2B_CONTRACT if contract is None else contract
    key = load_signing_key(key_path, owner_uid=owner_uid)
    _require_absent(out_path)
    baseline, baseline_sha = _load_chained_restore_receipt(baseline_receipt_path, key, owner_uid=owner_uid, now=now)
    clone, clone_sha = _load_chained_restore_receipt(clone_receipt_path, key, owner_uid=owner_uid, now=now,
                                                     capture_sha256=baseline["capture_record_sha256"])
    failure_path = _migration_failure_path(clone)
    if failure_path.exists() or failure_path.is_symlink():
        refuse("FAILED_REHEARSAL_HOLD_PRESENT")
    manifest_raw, manifest_sha = V2.read_protected_json(clone_manifest_path, owner_uid=owner_uid, maximum=256 * 1024)
    policy_raw, policy_sha = V2.read_protected_json(policy_path, owner_uid=owner_uid, maximum=32 * 1024)
    # Receipt times are second-truncated; evaluate the manifest as of restore completion (+1s).
    manifest = V2._manifest_object(manifest_raw, now=_utc(clone["completed_at"]) + datetime.timedelta(seconds=1))
    policy = V2._policy_object(policy_raw)
    inputs, inputs_sha = V2.read_protected_json(rehearsal_inputs_path, owner_uid=owner_uid, maximum=MAX_RECORD_BYTES)
    _check(inputs, REHEARSAL_INPUTS_SPEC, "REHEARSAL_INPUTS_INVALID")
    if (clone["manifest_sha256"] != manifest_sha or clone["destination_policy_sha256"] != policy_sha
            or baseline["destination_policy_sha256"] != policy_sha):
        refuse("REHEARSAL_BINDING_MISMATCH")
    if (baseline["backup"]["sha256"] != clone["backup"]["sha256"]
            or baseline["source"] != clone["source"]
            or baseline["destination"]["server_uuid_sha256"] != clone["destination"]["server_uuid_sha256"]
            or baseline["destination"]["target_schema"] == clone["destination"]["target_schema"]
            or baseline["observed"]["ledger_sha256"] != clone["observed"]["ledger_sha256"]
            or baseline["observed"]["schema_sha256"] != clone["observed"]["schema_sha256"]
            or baseline["observed"]["data_sha256"] != clone["observed"]["data_sha256"]):
        refuse("CLONE_NOT_FROM_SAME_RECOVERY_POINT")
    if inputs["candidate"] != manifest["release"]:
        refuse("CANDIDATE_BINDING_MISMATCH")
    accounts = inputs["accounts"]
    if (accounts["definer"] != manifest["approved_definer"] or len(set(accounts.values())) != 3):
        refuse("ACCOUNT_IDENTITY_SEPARATION_INVALID")
    window = {key_: _utc(value) for key_, value in inputs["dba_window"].items()}
    if not (_utc(clone["completed_at"]) <= window["granted_at"] <= window["revoked_at"]
            <= window["sessions_terminated_at"] <= now):
        refuse("DBA_WINDOW_ORDER_INVALID")
    inventory = candidate_migration_inventory(inputs["candidate"]["application_sha"], owner_uid=owner_uid)
    candidate_names = [item["name"] for item in inventory]
    before = baseline["observed"]["migrations"]
    selected = inputs["definer_select_tables"]
    if len(set(selected)) != len(selected) or selected != sorted(selected):
        refuse("DEFINER_SELECT_TABLES_INVALID")
    clone_schema, baseline_schema = clone["destination"]["target_schema"], baseline["destination"]["target_schema"]

    _validate_defaults(policy, owner_uid)
    # Start the durable hold only after all static inputs and candidate
    # provenance have passed, immediately before the first database query.
    _write_signed(failure_path, {"result": "IN_PROGRESS", "clone_receipt_sha256": clone_sha},
                  key, PURPOSE_MIGRATION_FAILURE, owner_uid=owner_uid)
    try:
        identity = V2._mysql_identity(policy)
        if (_sha(identity["server_uuid"].encode("ascii", "replace")) != clone["destination"]["server_uuid_sha256"]
                or identity["server_uuid"] != policy["mysql"]["server_uuid"]
                or identity["log_bin"] != "1" or identity["binlog_format"] != "ROW"
                or identity["trust_function_creators"] != "0"):
            refuse("DESTINATION_MYSQL_CONTRACT_MISMATCH")
        # The baseline restore must be exactly as restored: migrating the only
        # copy would destroy the unchanged comparison point.
        baseline_tables = baseline["observed"]["table_names"]
        base_schema = V2.observe_schema(policy, baseline_schema)
        base_ledger, base_ledger_sha = V2.observe_ledger(policy, baseline_schema)
        base_data_sha, _bytes = V2._safe_dump_hash(policy, baseline_schema, baseline_tables)
        if (base_schema["schema_sha256"] != baseline["observed"]["schema_sha256"]
                or base_ledger_sha != baseline["observed"]["ledger_sha256"] or base_ledger != before
                or base_data_sha != baseline["observed"]["data_sha256"]):
            refuse("BASELINE_RESTORE_CHANGED")
        schema_state = V2.observe_schema(policy, clone_schema)
        after, after_ledger_sha = V2.observe_ledger(policy, clone_schema)
        tables = _observe_tables(policy, clone_schema)
        if any(name not in tables for name in selected):
            refuse("DEFINER_SELECT_TABLE_MISSING")
        counts = V2.observe_row_counts(policy, clone_schema, tables)
        data_sha, _bytes = V2._safe_dump_hash(policy, clone_schema, tables)
        if schema_state["object_raw"] not in (b"events\t0\nroutines\t0\nviews\t0\n", b"events\t0\nroutines\t0\nviews\t0"):
            refuse("UNEXPECTED_SCHEMA_OBJECT_PRESENT")
        pair_present = _check_ledger_against_candidate(before, after, candidate_names, contract["pins"], inventory)
        pending = sorted(set(after) - set(before))
        if after_ledger_sha != _ledger_sha(after):
            refuse("MIGRATION_LEDGER_INVALID")  # listed order must hash to the observed ledger
        _check_checkpoints(before, pending, inputs["checkpoints"], schema_state["schema_sha256"])
        triggers = schema_state["triggers"]
        trigger_complete = _check_phase2b_triggers(triggers, accounts["definer"], pair_present, contract["triggers"], contract.get("definitions"))
        if pair_present and tuple(selected) != tuple(contract["select_tables"]):
            refuse("PHASE2B_DEFINER_SELECT_TABLES_UNEXPECTED")
        if any(trigger["definer"] != accounts["definer"] for trigger in triggers):
            refuse("TRIGGER_DEFINER_UNEXPECTED")
        definer_lifecycle = V2.verify_definer_lifecycle(policy, clone_schema, accounts["definer"], selected)
        definer_report = account_privilege_report(policy, clone_schema, accounts["definer"], allow_schema_privileges=True,
                                                   allowed_privileges=DEFINER_SCHEMA_PRIVILEGES)
        app_report = account_privilege_report(policy, clone_schema, accounts["app"], allow_schema_privileges=True)
        if not app_report["schema_privileges"]:
            refuse("APP_ACCOUNT_GRANTS_MISSING")
        executor_report = account_privilege_report(policy, clone_schema, accounts["dba_executor"],
                                                   allow_schema_privileges=False)
        sessions = sum(foreground_sessions(policy, clone_schema, account)
                       for account in sorted({accounts["definer"], accounts["dba_executor"]}))
        if sessions != 0:
            refuse("ELEVATED_SESSIONS_STILL_OPEN")
        if V2._mysql_identity(policy) != identity:
            refuse("DESTINATION_MYSQL_SETTINGS_CHANGED_DURING_REHEARSAL")
    except BaseException:
        raise  # Durable IN_PROGRESS marker deliberately survives every failure.
    body = {
        "schema_version": 1, "kind": "wpcg_migration_rehearsal_receipt", "receipt_id": secrets.token_hex(16),
        "result": "PASS", "issued_at": _iso(now), "expires_at": _iso(now + MAX_MIGRATION_RECEIPT_AGE),
        "capture_record_sha256": clone["capture_record_sha256"], "baseline_receipt_sha256": baseline_sha,
        "clone_receipt_sha256": clone_sha, "clone_manifest_sha256": manifest_sha,
        "destination_policy_sha256": policy_sha, "rehearsal_inputs_sha256": inputs_sha,
        "candidate": {**inputs["candidate"], "migration_inventory_sha256": _inventory_sha(inventory),
                      "migration_count": len(inventory), "migrations": inventory},
        "restore_point": {"backup_sha256": clone["backup"]["sha256"],
                          "source_application_sha": clone["backup"]["source_application_sha"],
                          "source_server_uuid_sha256": clone["source"]["server_uuid_sha256"],
                          "destination_server_uuid_sha256": clone["destination"]["server_uuid_sha256"],
                          "baseline_schema": baseline_schema, "clone_schema": clone_schema},
        "before": {"schema_sha256": baseline["observed"]["schema_sha256"],
                   "ledger_sha256": baseline["observed"]["ledger_sha256"],
                   "data_sha256": baseline["observed"]["data_sha256"], "migrations": before},
        "after": {"schema_sha256": schema_state["schema_sha256"], "ledger_sha256": after_ledger_sha,
                  "data_sha256": data_sha, "migrations": after, "table_row_counts": counts},
        "pending": pending, "checkpoints": inputs["checkpoints"],
        "mysql": {"server_uuid_sha256": _sha(identity["server_uuid"].encode("ascii", "replace")),
                  "log_bin": True, "binlog_format": "ROW", "trust_function_creators": False},
        "triggers": triggers,
        "phase2b": {"pair_in_candidate": pair_present, "trigger_inventory_complete": trigger_complete,
                    "pair_hashes_pinned": pair_present},
        "observed": {"baseline_unchanged": True, "destination_uuid_distinct_from_source": True,
                     "definer": {**definer_report, "locked": definer_lifecycle["account_locked"],
                                 "select_tables": definer_lifecycle["select_tables"]},
                     "app_account": app_report, "dba_executor": executor_report, "elevated_sessions_open": 0},
        "production_admission": {
            "state": "HOLD",
            "required_runtime_attestations": [
                "mysqldump_endpoint_instance_identity",
                "application_connection_identity",
                "candidate_migration_bytes_execution",
            ],
        },
        "attested": {"accounts": accounts, "dba_window": inputs["dba_window"],
                     "guarded_writes": inputs["guarded_writes"]},
        "limitations": list(LIMITATIONS),
    }
    _check(body, MIGRATION_RECEIPT_SPEC, "MIGRATION_RECEIPT_INVALID")
    verify_migration_consistency(body, contract=contract)
    _write_signed(out_path, body, key, PURPOSE_MIGRATION_RECEIPT, owner_uid=owner_uid)
    _verify_migration_receipt_impl(out_path, key, owner_uid=owner_uid, now=now, contract=contract,
                                   expect_clone_receipt_sha256=clone_sha, allow_matching_hold=True)
    failure_path.unlink()
    _fsync_directory(failure_path.parent)
    return body


def _observe_tables(policy, schema):
    raw = V2.mysql_query(
        policy, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
        "AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME", database=schema, maximum=64_000)
    try:
        tables = [line for line in raw.decode("ascii", "strict").splitlines() if line]
    except UnicodeError:
        refuse("TABLE_INVENTORY_INVALID")
    if not tables or len(tables) > 256 or any(not V2.TABLE_RE.fullmatch(name) for name in tables):
        refuse("TABLE_INVENTORY_INVALID")
    return tables


def verify_migration_consistency(body, *, contract=None):
    """Offline internal-consistency check; a valid MAC alone is not trusted."""
    contract = PHASE2B_CONTRACT if contract is None else contract
    pins, trigger_names = contract["pins"], contract["triggers"]
    candidate = body["candidate"]
    inventory = candidate["migrations"]
    names = [item["name"] for item in inventory]
    if (names != sorted(names) or len(set(names)) != len(names) or candidate["migration_count"] != len(names)
            or candidate["migration_inventory_sha256"] != _inventory_sha(inventory)):
        refuse("MIGRATION_RECEIPT_INVENTORY_INVALID")
    before, after = body["before"]["migrations"], body["after"]["migrations"]
    pair_present = _check_ledger_against_candidate(before, after, names, pins, inventory)
    if body["pending"] != sorted(set(after) - set(before)):
        refuse("MIGRATION_RECEIPT_PENDING_INVALID")
    if (body["before"]["ledger_sha256"] != _ledger_sha(before) or body["after"]["ledger_sha256"] != _ledger_sha(after)):
        refuse("MIGRATION_RECEIPT_LEDGER_INVALID")
    _check_checkpoints(before, body["pending"], body["checkpoints"], body["after"]["schema_sha256"])
    attested = body["attested"]["accounts"]
    if (len(set(attested.values())) != 3
            or body["observed"]["app_account"]["account"] != attested["app"]
            or body["observed"]["definer"]["account"] != attested["definer"]
            or body["observed"]["dba_executor"]["account"] != attested["dba_executor"]):
        refuse("ACCOUNT_IDENTITY_SEPARATION_INVALID")
    for role, allowed in (("app_account", APP_SCHEMA_PRIVILEGES), ("definer", DEFINER_SCHEMA_PRIVILEGES),
                          ("dba_executor", frozenset())):
        privileges = body["observed"][role]["schema_privileges"]
        if privileges != sorted(set(privileges)) or not set(privileges) <= allowed:
            refuse("ACCOUNT_PRIVILEGE_EXCESSIVE")
    if not body["observed"]["app_account"]["schema_privileges"]:
        refuse("APP_ACCOUNT_GRANTS_MISSING")
    if body["observed"]["definer"]["schema_privileges"] != sorted(set(body["observed"]["definer"]["schema_privileges"])):
        refuse("MIGRATION_RECEIPT_ACCOUNT_INVALID")
    if any(trigger["definer"] != attested["definer"] for trigger in body["triggers"]):
        refuse("TRIGGER_DEFINER_UNEXPECTED")
    complete = _check_phase2b_triggers(body["triggers"], attested["definer"], pair_present, trigger_names, contract.get("definitions"))
    if pair_present and tuple(body["observed"]["definer"]["select_tables"]) != tuple(contract["select_tables"]):
        refuse("PHASE2B_DEFINER_SELECT_TABLES_UNEXPECTED")
    if (body["phase2b"] != {"pair_in_candidate": pair_present, "trigger_inventory_complete": complete,
                            "pair_hashes_pinned": pair_present}):
        refuse("MIGRATION_RECEIPT_PHASE2B_INVALID")
    if (body["restore_point"]["source_server_uuid_sha256"] == body["restore_point"]["destination_server_uuid_sha256"]
            or body["restore_point"]["baseline_schema"] == body["restore_point"]["clone_schema"]
            or body["mysql"]["server_uuid_sha256"] != body["restore_point"]["destination_server_uuid_sha256"]):
        refuse("MIGRATION_RECEIPT_SEPARATION_INVALID")
    if body["production_admission"] != {
            "state": "HOLD",
            "required_runtime_attestations": [
                "mysqldump_endpoint_instance_identity",
                "application_connection_identity",
                "candidate_migration_bytes_execution",
            ],
    }:
        refuse("PRODUCTION_ADMISSION_MUST_REMAIN_HOLD")
    window = {name: _utc(value) for name, value in body["attested"]["dba_window"].items()}
    if not (window["granted_at"] <= window["revoked_at"] <= window["sessions_terminated_at"] <= _utc(body["issued_at"])):
        refuse("DBA_WINDOW_ORDER_INVALID")


def _verify_migration_receipt_impl(path, key, *, owner_uid, now=None, contract=None,
                                   expect_clone_receipt_sha256=None, allow_matching_hold):
    now = _now(now)
    body, file_sha = _load_signed(path, key, PURPOSE_MIGRATION_RECEIPT, MIGRATION_RECEIPT_SPEC,
                                  owner_uid=owner_uid, maximum=MAX_RECEIPT_BYTES, code="MIGRATION_RECEIPT_INVALID")
    issued, expires = _utc(body["issued_at"]), _utc(body["expires_at"])
    if expires <= issued or expires - issued > MAX_MIGRATION_RECEIPT_AGE or issued > now + datetime.timedelta(seconds=5):
        refuse("MIGRATION_RECEIPT_TIME_INVALID")
    if expires <= now:
        refuse("MIGRATION_RECEIPT_STALE")
    verify_migration_consistency(body, contract=contract)
    if expect_clone_receipt_sha256 is not None and body["clone_receipt_sha256"] != expect_clone_receipt_sha256:
        refuse("MIGRATION_RECEIPT_CLONE_MISMATCH")
    if not allow_matching_hold:
        _assert_no_migration_hold(body, owner_uid=owner_uid)
    return body, file_sha


def verify_migration_receipt(path, key, *, owner_uid, now=None, contract=None,
                             expect_clone_receipt_sha256=None):
    """Public offline verifier; a clone HOLD can never be overridden by caller."""
    return _verify_migration_receipt_impl(
        path, key, owner_uid=owner_uid, now=now, contract=contract,
        expect_clone_receipt_sha256=expect_clone_receipt_sha256, allow_matching_hold=False)


# --------------------------------------------------------------------------
# Command line.
# --------------------------------------------------------------------------

def main(argv=None):
    parser = argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument("action", choices=(
        "validate-config-reference", "capture", "verify-capture", "restore", "cleanup",
        "verify-restore-receipt", "migration-receipt", "verify-migration-receipt"))
    for name in ("source-policy", "archive", "archive-id", "config-reference", "capture-record",
                 "signing-key", "release-manifest", "destination-policy", "config-path", "receipt",
                 "baseline-receipt", "clone-receipt", "clone-manifest", "rehearsal-inputs",
                 "output", "migration-receipt"):
        parser.add_argument("--" + name)
    args = parser.parse_args(argv)
    try:
        if os.geteuid() != 0:
            refuse("ROOT_EXECUTION_REQUIRED")

        def need(*names):
            values = [getattr(args, name.replace("-", "_")) for name in names]
            if any(not value for value in values):
                refuse("REQUIRED_ARGUMENT_MISSING")
            return values

        if args.action == "validate-config-reference":
            (path,) = need("config-reference")
            reference, _sha_value = V2.read_protected_json(path, owner_uid=0, maximum=MAX_RECORD_BYTES)
            validate_config_recovery_reference(reference)
            if args.config_path:
                verify_config_reference_against_config(reference, args.config_path, owner_uid=0)
            result = {"config_recovery_reference": "VALID", "reference_id": reference["reference_id"]}
        elif args.action == "capture":
            result = {"capture_record_sha256": capture(*need("source-policy", "archive", "archive-id",
                      "config-reference", "capture-record", "signing-key"), owner_uid=0)}
        elif args.action == "verify-capture":
            record, key = need("capture-record", "signing-key")
            body, file_sha = verify_capture_record(record, load_signing_key(key, owner_uid=0), owner_uid=0)
            result = {"capture_record": "VALID", "sha256": file_sha, "record_id": body["record_id"]}
        elif args.action == "restore":
            result = restore_authenticated(*need("release-manifest", "destination-policy", "config-path", "receipt",
                                                 "capture-record", "signing-key"), owner_uid=0)
        elif args.action == "cleanup":
            result = cleanup_authenticated(*need("release-manifest", "destination-policy", "receipt",
                                                 "signing-key"), owner_uid=0)
        elif args.action == "verify-restore-receipt":
            receipt, key = need("receipt", "signing-key")
            body, file_sha = verify_restore_receipt(_auth_path(receipt), load_signing_key(key, owner_uid=0), owner_uid=0)
            result = {"restore_receipt": "VALID", "sha256": file_sha, "receipt_id": body["receipt_id"]}
        elif args.action == "migration-receipt":
            result = create_migration_receipt(*need(
                "baseline-receipt", "clone-receipt", "clone-manifest", "destination-policy", "rehearsal-inputs",
                "output", "signing-key"), owner_uid=0)
        else:
            receipt, key = need("migration-receipt", "signing-key")
            body, file_sha = verify_migration_receipt(receipt, load_signing_key(key, owner_uid=0), owner_uid=0)
            result = {"migration_receipt": "VALID_CLONE_REHEARSAL_ONLY",
                      "production_recovery": body["production_admission"]["state"],
                      "required_runtime_attestations": body["production_admission"]["required_runtime_attestations"],
                      "sha256": file_sha, "receipt_id": body["receipt_id"]}
        print(json.dumps(result, sort_keys=True, separators=(",", ":")))
        return 0
    except RestoreRefused as error:
        print(f"RESTORE=STOP;CODE={error}", file=sys.stderr)
        return 1
    except Exception:
        print("RESTORE=STOP;CODE=UNEXPECTED_FAILURE", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
