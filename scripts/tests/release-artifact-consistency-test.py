#!/usr/bin/env python3
"""Verify cross-artifact hashes for the staging recovery release chain."""

import ast
import hashlib
import re
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
STAGING_APPLICATION_SHA = "ee3d29818ff1647d82087446dfaf017ca688cb77"
STAGING_BASELINE_SHA = "761e0e5d37558da808ac961ca2d2a68b4ffab0db"
STAGING_HELPER_PATH = "/usr/local/sbin/wpcaregrid-deploy-priv"
STAGING_LAUNCHER_PATH = "/usr/local/bin/wpcaregrid-php"


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def assignments(path):
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    values = {}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            try:
                values[node.targets[0].id] = ast.literal_eval(node.value)
            except (ValueError, TypeError):
                if (isinstance(node.value, ast.Call) and isinstance(node.value.func, ast.Name)
                        and node.value.func.id == "Path" and len(node.value.args) == 1):
                    try:
                        values[node.targets[0].id] = Path(ast.literal_eval(node.value.args[0]))
                    except (ValueError, TypeError):
                        continue
    return values


def require(condition, message):
    if not condition:
        raise SystemExit(f"RELEASE_CONSISTENCY=FAIL;CHECK={message}")


helper_path = ROOT / "scripts/wpcaregrid-deploy-priv.py"
recovery_path = ROOT / "scripts/recover-staging-deployment.py"
launcher_path = ROOT / "scripts/run-php-runtime.sh"
scheduler_path = ROOT / "scripts/run-scheduler.sh"
verifier_path = ROOT / "scripts/verify-release-ci.py"
deploy_path = ROOT / "scripts/deploy-staging.sh"
docs_path = ROOT / "docs/DEPLOYMENT.md"
operator_path = ROOT / "scripts/staging-recovery-operator.py"
dispatcher_path = ROOT / "scripts/staging-recovery-operator-dispatch.sh"
rehearsal_path = ROOT / "scripts/tests/staging-recovery-mysql-rehearsal.py"
operator_docs_path = ROOT / "docs/STAGING_RECOVERY_OPERATOR.md"
workflow_path = ROOT / ".github/workflows/ci.yml"

helper = assignments(helper_path)
recovery = assignments(recovery_path)
deploy_text = deploy_path.read_text(encoding="utf-8")
docs_text = docs_path.read_text(encoding="utf-8")
operator = assignments(operator_path)
operator_text = operator_path.read_text(encoding="utf-8")
dispatcher_text = dispatcher_path.read_text(encoding="utf-8")
operator_docs_text = operator_docs_path.read_text(encoding="utf-8")
workflow_text = workflow_path.read_text(encoding="utf-8")

helper_sha = sha256(helper_path)
recovery_sha = sha256(recovery_path)
launcher_sha = sha256(launcher_path)
scheduler_sha = sha256(scheduler_path)
verifier_sha = sha256(verifier_path)
deploy_sha = sha256(deploy_path)
operator_sha = sha256(operator_path)
dispatcher_sha = sha256(dispatcher_path)
rehearsal_sha = sha256(rehearsal_path)

require(recovery.get("HELPER_SHA") == helper_sha, "recovery_helper_pin")
require(recovery.get("APP_SHA") == STAGING_APPLICATION_SHA, "staging_application_baseline")
require(recovery.get("BASELINE_SHA") == STAGING_BASELINE_SHA, "staging_code_baseline")
require(recovery.get("HELPER") == STAGING_HELPER_PATH, "staging_installed_helper_path")
require(recovery.get("RUNTIME_LAUNCHER").as_posix() == STAGING_LAUNCHER_PATH,
        "staging_installed_launcher_path")
require(helper.get("PHP_RUNTIME_LAUNCHER_SHA256") == launcher_sha, "helper_launcher_pin")
require(recovery.get("RUNTIME_LAUNCHER_SHA") == launcher_sha, "recovery_launcher_pin")
require(helper.get("SCHEDULER_RUNTIME_SOURCE_SHA256") == scheduler_sha, "helper_scheduler_pin")

baseline_scheduler_source = subprocess.run(
    ["git", "show", f"{STAGING_BASELINE_SHA}:scripts/run-scheduler.sh"],
    cwd=ROOT,
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
).stdout
baseline_scheduler_sha = hashlib.sha256(baseline_scheduler_source).hexdigest()
require(helper.get("SCHEDULER_SOURCE_SHA256") == baseline_scheduler_sha,
        "helper_baseline_scheduler_pin")
previous_launcher_source = subprocess.run(
    ["git", "show", "eaf992b69a69e182095f00004cbe2d808a4aa011^:scripts/run-php-runtime.sh"],
    cwd=ROOT,
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
).stdout
previous_launcher_sha = hashlib.sha256(previous_launcher_source).hexdigest()
require(helper.get("PHP_RUNTIME_LAUNCHER_PREVIOUS_SHA256") == previous_launcher_sha,
        "helper_previous_launcher_pin")

composer_match = re.search(r"readonly COMPOSER_SHA256='([0-9a-f]{64})'", deploy_text)
require(composer_match is not None, "deploy_composer_pin_present")
require(recovery.get("COMPOSER_SHA") == composer_match.group(1), "recovery_deploy_composer_pin")
require(operator.get("APP_SHA") == recovery.get("APP_SHA"), "recovery_operator_application_pin")
require({"pre_recovery_backup_sha256", "restore_receipt_sha256", "helper_sha256",
         "controller_sha256", "verifier_sha256"}.issubset(operator.get("CONFIG_KEYS", set())),
        "recovery_operator_artifact_manifest")
require("RESTORE_RECEIPT_SHA256" in operator_text and "restore_receipt_sha256" in operator_text,
        "recovery_operator_restore_receipt_pin")
require("/usr/local/sbin/wpcaregrid-staging-recovery-runner" in dispatcher_text,
        "recovery_operator_dispatcher_target")
require(all(token in dispatcher_text for token in ("preflight)", "continue-migrations)", "finalize)")),
        "recovery_operator_dispatcher_allowlist")
require("restore_receipt_sha256" in operator_docs_text and "sudoers" in operator_docs_text,
        "recovery_operator_documented_manifest_and_boundary")
require("scripts/tests/staging-recovery-operator-test.py" in workflow_text
        and "scripts/tests/staging-recovery-mysql-rehearsal.py" in workflow_text
        and "mysql:8.4.11" in workflow_text,
        "recovery_operator_and_mysql_rehearsal_ci")

doc_hashes = re.findall(
    r"scripts/verify-release-ci\.py`: `([0-9a-f]{64})`", docs_text
)
require(bool(doc_hashes) and all(value == verifier_sha for value in doc_hashes),
        "deployment_docs_verifier_pin")

print("RELEASE_CONSISTENCY=PASS")
print(f"STAGING_APPLICATION_SHA={recovery['APP_SHA']}")
print(f"STAGING_CODE_BASELINE_SHA={recovery['BASELINE_SHA']}")
print(f"STAGING_INSTALLED_HELPER_SHA256={helper_sha}")
print(f"STAGING_INSTALLED_LAUNCHER_SHA256={launcher_sha}")
for name, value in (
    ("recovery_controller", recovery_sha),
    ("deploy_controller", deploy_sha),
    ("privileged_helper", helper_sha),
    ("php_runtime_launcher", launcher_sha),
    ("scheduler_wrapper", scheduler_sha),
    ("baseline_scheduler_wrapper", baseline_scheduler_sha),
    ("ci_verifier", verifier_sha),
    ("previous_launcher_artifact", previous_launcher_sha),
):
    print(f"ARTIFACT_{name.upper()}_SHA256={value}")
print(f"PINNED_EXTERNAL_COMPOSER_SHA256={recovery['COMPOSER_SHA']}")
print(f"ARTIFACT_STAGING_RECOVERY_OPERATOR_SHA256={operator_sha}")
print(f"ARTIFACT_STAGING_RECOVERY_DISPATCHER_SHA256={dispatcher_sha}")
print(f"ARTIFACT_STAGING_RECOVERY_MYSQL_REHEARSAL_SHA256={rehearsal_sha}")
