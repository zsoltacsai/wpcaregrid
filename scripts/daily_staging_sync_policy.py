#!/usr/bin/env python3
"""Pure fail-closed decision policy for staging-next release synchronization."""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Mapping


APP_DIR = "/var/www/wpcaregrid-staging-new"
DATABASE = "wpcg_staging_next"
HOST = "staging-next.wpcaregrid.com"
HELPER_CONTRACT = "wpcaregrid-deploy-profile-v1"
HELPER_VERSION = "1.0.2"
REQUIRED_EVIDENCE = frozenset({
    "environment_identity", "artifact_integrity", "exact_head_ci", "merge_sha_ci",
    "integration_qa", "m17_appsec",
})
DATABASE_EVIDENCE = frozenset({"fresh_backup", "isolated_restore", "migration_rehearsal"})


@dataclass(frozen=True)
class SyncDecision:
    status: str
    reasons: tuple[str, ...]


def _sha(value: object, length: int) -> bool:
    return isinstance(value, str) and re.fullmatch(rf"[0-9a-f]{{{length}}}", value) is not None


def _bound_pass(record: object, required: set[str], **expected: object) -> bool:
    """Require a PASS record whose identity fields bind to this release."""
    return (
        isinstance(record, Mapping)
        and set(record) == required
        and record.get("status") == "PASS"
        and all(record.get(key) == value for key, value in expected.items())
    )


def evaluate_sync(manifest: object, deployed: object) -> SyncDecision:
    """Evaluate a synthetic release manifest; never executes deployment work."""
    if not isinstance(manifest, Mapping) or not isinstance(deployed, Mapping):
        return SyncDecision("HOLD", ("evidence_shape_invalid",))

    expected_manifest_keys = {
        "schema", "profile", "app_dir", "database", "host", "application_sha",
        "exact_head_sha", "merge_sha", "artifact_sha256", "helper_version", "helper_sha256", "helper_contract", "required_evidence",
        "database_changes", "database_evidence",
    }
    expected_deployed_keys = {"profile", "app_dir", "database", "host", "application_sha", "artifact_sha256", "helper_version", "helper_sha256", "helper_contract"}
    if set(manifest) != expected_manifest_keys or set(deployed) != expected_deployed_keys:
        return SyncDecision("HOLD", ("evidence_fields_invalid",))

    if (type(manifest["schema"]) is not int or manifest["schema"] != 1
            or manifest["profile"] != "staging-next"
            or manifest["app_dir"] != APP_DIR or manifest["database"] != DATABASE or manifest["host"] != HOST):
        return SyncDecision("HOLD", ("release_target_mismatch",))
    if (deployed["profile"] != "staging-next" or deployed["app_dir"] != APP_DIR
            or deployed["database"] != DATABASE or deployed["host"] != HOST):
        return SyncDecision("HOLD", ("deployed_target_mismatch",))

    if not _sha(manifest["application_sha"], 40) or not _sha(deployed["application_sha"], 40):
        return SyncDecision("HOLD", ("application_identity_invalid",))
    if (not _sha(manifest["exact_head_sha"], 40)
            or not _sha(manifest["merge_sha"], 40)
            or manifest["merge_sha"] != manifest["application_sha"]):
        return SyncDecision("HOLD", ("release_commit_identity_invalid",))
    if (not _sha(manifest["artifact_sha256"], 64)
            or not _sha(deployed["artifact_sha256"], 64)):
        return SyncDecision("HOLD", ("artifact_identity_invalid",))
    if (manifest["helper_version"] != HELPER_VERSION or deployed["helper_version"] != HELPER_VERSION
            or not _sha(manifest["helper_sha256"], 64) or not _sha(deployed["helper_sha256"], 64)
            or manifest["helper_sha256"] != deployed["helper_sha256"]
            or manifest["helper_contract"] != HELPER_CONTRACT
            or deployed["helper_contract"] != HELPER_CONTRACT):
        return SyncDecision("HOLD", ("helper_identity_mismatch",))

    evidence = manifest["required_evidence"]
    if not isinstance(evidence, Mapping) or set(evidence) != REQUIRED_EVIDENCE:
        return SyncDecision("HOLD", ("release_acceptance_incomplete",))

    # Validate independent evidence blocks together. This gives an operator a
    # complete, deterministic blocker list without treating any partial PASS as
    # overall readiness.
    blockers: list[str] = []
    environment = evidence["environment_identity"]
    if not _bound_pass(
        environment, {"status", "profile", "app_dir", "database", "host"},
        profile="staging-next", app_dir=APP_DIR, database=DATABASE, host=HOST,
    ):
        blockers.append("environment_identity_unverified")
    if not _bound_pass(
        evidence["artifact_integrity"], {"status", "application_sha", "artifact_sha256"},
        application_sha=manifest["application_sha"], artifact_sha256=manifest["artifact_sha256"],
    ):
        blockers.append("artifact_integrity_unverified")
    exact_head_ci = evidence["exact_head_ci"]
    if (not _bound_pass(exact_head_ci, {"status", "commit_sha", "run_id"},
                        commit_sha=manifest["exact_head_sha"])
            or type(exact_head_ci.get("run_id")) is not int or exact_head_ci["run_id"] <= 0):
        blockers.append("exact_head_ci_unverified")
    merge_ci = evidence["merge_sha_ci"]
    if (not _bound_pass(merge_ci, {"status", "commit_sha", "run_id"},
                        commit_sha=manifest["merge_sha"])
            or type(merge_ci.get("run_id")) is not int or merge_ci["run_id"] <= 0):
        blockers.append("merge_sha_ci_unverified")
    if not _bound_pass(evidence["integration_qa"], {"status", "commit_sha"},
                       commit_sha=manifest["exact_head_sha"]):
        blockers.append("integration_qa_unverified")
    if not _bound_pass(evidence["m17_appsec"], {"status", "commit_sha"},
                       commit_sha=manifest["exact_head_sha"]):
        blockers.append("m17_appsec_unverified")
    if blockers:
        return SyncDecision("HOLD", tuple(blockers))

    if not isinstance(manifest["database_changes"], bool):
        return SyncDecision("HOLD", ("database_change_scope_unknown",))

    if (manifest["application_sha"] == deployed["application_sha"]
            and manifest["artifact_sha256"] == deployed["artifact_sha256"]):
        return SyncDecision("NO_OP", ())

    # `database_changes` is a caller-declared manifest field, not independently
    # authenticated migration-scope evidence. Until the release artifact has a
    # trusted migration-scope attestation, require the recovery evidence for
    # every changed release. A false declaration must not bypass these gates.
    db_evidence = manifest["database_evidence"]
    if (not isinstance(db_evidence, Mapping) or set(db_evidence) != DATABASE_EVIDENCE
            or any(value != "PASS" for value in db_evidence.values())):
        return SyncDecision("HOLD", ("database_safety_evidence_incomplete",))

    # This policy package intentionally has no deployment operation. Even a
    # fully eligible new release remains held until a separately reviewed,
    # installed helper contract implements the approved operation.
    return SyncDecision("HOLD", ("deployment_operation_not_in_approved_helper_contract",))
