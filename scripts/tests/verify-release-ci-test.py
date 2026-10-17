#!/usr/bin/env python3
"""Deterministic, offline tests for exact merged-primary CI authorization."""

import importlib.util
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/verify-release-ci.py"
spec = importlib.util.spec_from_file_location("release_ci", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

SHA = "a" * 40
RUN = {
    "id": 123456,
    "head_sha": SHA,
    "head_branch": module.BRANCH,
    "event": "push",
    "path": module.WORKFLOW,
    "status": "completed",
    "conclusion": "success",
    "created_at": "2026-10-17T10:00:00Z",
}


def good_jobs():
    jobs = [
        {"name": name, "status": "completed", "conclusion": "success", "steps": []}
        for name in module.REQUIRED_JOBS
    ]
    mysql = next(job for job in jobs if job["name"] == "Server tests (PHP 8.4, mysql)")
    mysql["steps"] = [{"name": module.MYSQL_GATE, "status": "completed", "conclusion": "success"}]
    return jobs


def run_case(run, jobs):
    responses = iter(({"total_count": 1, "workflow_runs": [run]}, {"total_count": len(jobs), "jobs": jobs}))
    module.api_json = lambda _url: next(responses)
    return module.verify_release(SHA)


def rejected(run, jobs):
    try:
        run_case(run, jobs)
    except module.EvidenceError:
        return
    raise AssertionError("unsafe release evidence was accepted")


def main():
    result = run_case(RUN.copy(), good_jobs())
    assert result == {"target_sha": SHA, "workflow_run_id": 123456, "mandatory_checks": 7}

    rejected({**RUN, "status": "in_progress", "conclusion": None}, good_jobs())
    rejected({**RUN, "conclusion": "cancelled"}, good_jobs())
    rejected({**RUN, "event": "pull_request"}, good_jobs())
    rejected({**RUN, "head_sha": "b" * 40}, good_jobs())
    rejected({**RUN, "path": ".github/workflows/other.yml"}, good_jobs())
    rejected(RUN.copy(), good_jobs()[:-1])
    failed = good_jobs()
    failed[0]["conclusion"] = "failure"
    rejected(RUN.copy(), failed)
    no_gate = good_jobs()
    next(job for job in no_gate if job["name"] == "Server tests (PHP 8.4, mysql)")["steps"] = []
    rejected(RUN.copy(), no_gate)
    failed_gate = good_jobs()
    next(job for job in failed_gate if job["name"] == "Server tests (PHP 8.4, mysql)")["steps"][0]["conclusion"] = "failure"
    rejected(RUN.copy(), failed_gate)
    duplicate = good_jobs()
    duplicate.append(duplicate[0].copy())
    rejected(RUN.copy(), duplicate)

    responses = iter(({"total_count": 21, "workflow_runs": [RUN]}, {"total_count": 7, "jobs": good_jobs()}))
    module.api_json = lambda _url: next(responses)
    try:
        module.verify_release(SHA)
    except module.EvidenceError:
        pass
    else:
        raise AssertionError("incomplete workflow-run pagination was accepted")

    responses = iter(({"total_count": 1, "workflow_runs": [RUN]}, {"total_count": 101, "jobs": good_jobs()}))
    module.api_json = lambda _url: next(responses)
    try:
        module.verify_release(SHA)
    except module.EvidenceError:
        pass
    else:
        raise AssertionError("incomplete mandatory-job pagination was accepted")

    for invalid in ("", "a" * 39, "g" * 40, "-" + "a" * 39):
        try:
            module.verify_release(invalid)
        except module.EvidenceError:
            continue
        raise AssertionError("invalid SHA was accepted")

    controller = (ROOT / "scripts/deploy-staging.sh").read_text(encoding="utf-8")
    assert "/usr/bin/env -i PATH=" in controller
    assert "/usr/bin/python3 \"${CONTROLLER_DIR}/verify-release-ci.py\" \"$TARGET_SHA\"" in controller
    assert "GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL='/dev/null'" in controller
    assert "target_commit\" == \"$fetched_tip" in controller
    print("Exact-SHA release CI policy fixtures passed.")


if __name__ == "__main__":
    main()
