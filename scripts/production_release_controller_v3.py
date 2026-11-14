#!/usr/bin/env python3
"""Read-only V3 release evidence gate for the production controller successor.

This candidate intentionally stops after admission verification. It never
stages an artifact, invokes sudo, or calls the currently installed 2.0.0
helper. A future, separately reviewed privileged-helper version must consume
this gate before it can authorize any production mutation.
"""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

# ``-I`` excludes the checkout from Python's import path.  Keep the executable
# entry point self-contained by adding only its own source directory, which is
# the same directory whose immutable files are verified by the caller.
if __package__ in (None, ""):
    sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
    from release_admission_v3 import AdmissionRefused, PRIMARY_BRANCH, verify_release_admission
else:
    from .release_admission_v3 import AdmissionRefused, PRIMARY_BRANCH, verify_release_admission

API = "https://api.github.com"
REPOSITORY = "zsoltacsai/wpcaregrid"
LEDGER_ISSUE = 174
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
MAX_RESPONSE_BYTES = 4_000_000
MAX_PAGES = 20


class EvidenceUnavailable(RuntimeError):
    pass


def _request_json(path: str):
    if path != f"/repos/{REPOSITORY}" and not path.startswith(f"/repos/{REPOSITORY}/"):
        raise EvidenceUnavailable("api_path_invalid")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, response, code, message, headers, new_url):
            return None

    request = urllib.request.Request(
        API + path,
        headers={"Accept": "application/vnd.github+json", "User-Agent": "WPCG-release-controller-v3",
                 "X-GitHub-Api-Version": "2022-11-28"},
        method="GET")
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=8) as response:
            if response.status != 200:
                raise EvidenceUnavailable("api_response_unavailable")
            raw = response.read(MAX_RESPONSE_BYTES + 1)
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, OSError):
        raise EvidenceUnavailable("api_unavailable") from None
    if len(raw) > MAX_RESPONSE_BYTES:
        raise EvidenceUnavailable("api_response_too_large")
    try:
        value = json.loads(raw.decode("utf-8"))
    except (UnicodeError, ValueError):
        raise EvidenceUnavailable("api_response_invalid") from None
    if not isinstance(value, (dict, list)):
        raise EvidenceUnavailable("api_response_invalid")
    return value


def _list(path: str):
    output = []
    for page in range(1, MAX_PAGES + 1):
        separator = "&" if "?" in path else "?"
        batch = _request_json(f"{path}{separator}per_page=100&page={page}")
        if not isinstance(batch, list) or len(batch) > 100:
            raise EvidenceUnavailable("api_list_invalid")
        output.extend(batch)
        if len(batch) < 100:
            return output
    raise EvidenceUnavailable("api_list_incomplete")


def _workflow_jobs(run_id: int):
    """Read the GitHub jobs envelope with bounded, completeness-checked pages."""
    output = []
    expected_total = None
    endpoint = f"/repos/{REPOSITORY}/actions/runs/{run_id}/jobs"
    for page in range(1, MAX_PAGES + 1):
        batch_data = _request_json(f"{endpoint}?per_page=100&page={page}")
        batch = batch_data.get("jobs") if isinstance(batch_data, dict) else None
        total = batch_data.get("total_count") if isinstance(batch_data, dict) else None
        if (not isinstance(batch, list) or len(batch) > 100 or type(total) is not int
                or total < 0 or total > MAX_PAGES * 100):
            raise EvidenceUnavailable("ci_jobs_invalid")
        if expected_total is None:
            expected_total = total
        if total != expected_total:
            raise EvidenceUnavailable("ci_jobs_changed_during_read")
        output.extend(batch)
        if len(batch) < 100:
            break
    else:
        raise EvidenceUnavailable("ci_jobs_incomplete")
    if expected_total != len(output):
        raise EvidenceUnavailable("ci_jobs_incomplete")
    ids = [job.get("id") for job in output if isinstance(job, dict)]
    if (len(ids) != len(output) or any(type(job_id) is not int or job_id <= 0 for job_id in ids)
            or len(set(ids)) != len(ids)):
        raise EvidenceUnavailable("ci_jobs_invalid")
    return output


def _workflow_run(commit_sha: str, *, event: str, pr_number: int | None = None):
    query = urllib.parse.urlencode({"head_sha": commit_sha, "event": event, "per_page": 100})
    runs = []
    expected_total = None
    for page in range(1, MAX_PAGES + 1):
        data = _request_json(f"/repos/{REPOSITORY}/actions/runs?{query}&page={page}")
        batch = data.get("workflow_runs") if isinstance(data, dict) else None
        total = data.get("total_count") if isinstance(data, dict) else None
        if not isinstance(batch, list) or len(batch) > 100 or type(total) is not int:
            raise EvidenceUnavailable("ci_runs_invalid")
        if expected_total is None:
            expected_total = total
        if total != expected_total:
            raise EvidenceUnavailable("ci_runs_changed_during_read")
        runs.extend(batch)
        if len(batch) < 100:
            break
    else:
        raise EvidenceUnavailable("ci_runs_incomplete")
    if expected_total != len(runs):
        raise EvidenceUnavailable("ci_runs_incomplete")
    candidates = []
    for run in runs:
        if not isinstance(run, dict) or run.get("head_sha") != commit_sha or run.get("event") != event:
            continue
        if (run.get("path") != ".github/workflows/ci.yml"
                or not isinstance(run.get("created_at"), str)
                or not run.get("created_at") or type(run.get("id")) is not int
                or run["id"] <= 0):
            continue
        if pr_number is not None:
            prs = run.get("pull_requests")
            if not isinstance(prs, list) or not any(
                isinstance(pr, dict) and pr.get("number") == pr_number
                and isinstance(pr.get("base"), dict)
                and pr["base"].get("ref") == PRIMARY_BRANCH for pr in prs):
                continue
        elif run.get("head_branch") != PRIMARY_BRANCH:
            continue
        candidates.append(run)
    if not candidates:
        raise EvidenceUnavailable("ci_run_missing")
    selected = max(candidates, key=lambda run: (run["created_at"], run["id"]))
    run_id = selected.get("id")
    if type(run_id) is not int or run_id <= 0:
        raise EvidenceUnavailable("ci_run_identity_invalid")
    jobs = _workflow_jobs(run_id)
    return selected, jobs


def verify(candidate_sha: str, pr_number: int, expected_pr_head_sha: str,
           expected_base_sha: str) -> dict[str, object]:
    if (SHA_RE.fullmatch(candidate_sha) is None or type(pr_number) is not int
            or pr_number <= 0 or SHA_RE.fullmatch(expected_pr_head_sha) is None
            or SHA_RE.fullmatch(expected_base_sha) is None):
        raise AdmissionRefused("release_identity_invalid")
    branch = urllib.parse.quote(PRIMARY_BRANCH, safe="")
    branch_data = _request_json(f"/repos/{REPOSITORY}/branches/{branch}")
    commit = branch_data.get("commit") if isinstance(branch_data, dict) else None
    primary_sha = commit.get("sha") if isinstance(commit, dict) else None
    if not isinstance(branch_data, dict) or branch_data.get("protected") is not True:
        raise AdmissionRefused("primary_branch_not_protected")
    if primary_sha != candidate_sha:
        raise AdmissionRefused("candidate_not_current_primary")

    pr = _request_json(f"/repos/{REPOSITORY}/pulls/{pr_number}")
    merge_commit = _request_json(f"/repos/{REPOSITORY}/commits/{candidate_sha}")
    pr_head = pr.get("head") if isinstance(pr, dict) else None
    pr_head_sha = pr_head.get("sha") if isinstance(pr_head, dict) else None
    if pr_head_sha != expected_pr_head_sha:
        raise AdmissionRefused("merged_pr_identity_invalid")
    pr_run, pr_jobs = _workflow_run(pr_head_sha, event="pull_request", pr_number=pr_number)
    primary_run, primary_jobs = _workflow_run(candidate_sha, event="push")
    repository = _request_json(f"/repos/{REPOSITORY}")
    owner = repository.get("owner") if isinstance(repository, dict) else None
    owner_login = owner.get("login") if isinstance(owner, dict) else None
    if not isinstance(owner_login, str) or not owner_login:
        raise EvidenceUnavailable("repository_owner_unavailable")
    comments = _list(f"/repos/{REPOSITORY}/issues/{LEDGER_ISSUE}/comments")
    return verify_release_admission(
        candidate_sha=candidate_sha, primary_sha=primary_sha, pr_number=pr_number,
        expected_pr_head_sha=expected_pr_head_sha, expected_base_sha=expected_base_sha,
        pr=pr, merge_commit=merge_commit, pr_ci_run=pr_run, pr_ci_jobs=pr_jobs,
        primary_ci_run=primary_run, primary_ci_jobs=primary_jobs,
        ledger_comments=comments, repository_owner=owner_login)


def main(argv=None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if len(args) != 5 or args[0] != "verify-release":
        print(json.dumps({"result": "HOLD", "reason": "operation_not_allowed"}, separators=(",", ":")))
        return 3
    try:
        if not args[2].isdigit():
            raise AdmissionRefused("release_subject_invalid")
        result = verify(args[1], int(args[2]), args[3], args[4])
    except AdmissionRefused as error:
        result = {"result": "HOLD", "reason": str(error)}
    except EvidenceUnavailable as error:
        result = {"result": "HOLD", "reason": str(error)}
    except Exception:
        result = {"result": "HOLD", "reason": "release_evidence_internal_error"}
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0 if result.get("result") == "PASS" else 3


if __name__ == "__main__":
    raise SystemExit(main())
