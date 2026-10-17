#!/usr/bin/env python3
"""Verify successful mandatory CI for one exact merged-primary commit."""

import json
import re
import sys
from datetime import datetime
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import HTTPRedirectHandler, Request, build_opener


REPOSITORY = "zsoltacsai/wpcaregrid"
BRANCH = "claude/ecstatic-wozniak-oemyuf"
WORKFLOW = ".github/workflows/ci.yml"
API = "https://api.github.com"
REQUIRED_JOBS = (
    "Quality (lint, style, dependencies, build, secrets)",
    "Server tests (PHP 8.4, sqlite)",
    "Server tests (PHP 8.4, mysql)",
    "Server tests (PHP 8.4, mariadb)",
    "Connector tests in WordPress (PHP 8.4, sqlite)",
    "Connector tests in WordPress (PHP 8.4, mysql)",
    "Connector tests in WordPress (PHP 8.4, mariadb)",
)
MYSQL_GATE = "Required MySQL process-concurrency gate"
MAX_RESPONSE_BYTES = 1_000_000


class EvidenceError(Exception):
    """Safe, non-sensitive reason that release evidence could not be verified."""


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def api_json(url):
    request = Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": "WP-CareGrid-Staging-Release-Verifier",
            "X-GitHub-Api-Version": "2022-11-28",
        },
        method="GET",
    )
    try:
        with build_opener(NoRedirect).open(request, timeout=8) as response:
            if response.status != 200:
                raise EvidenceError("GitHub release-evidence API returned an unexpected status")
            raw = response.read(MAX_RESPONSE_BYTES + 1)
    except (HTTPError, URLError, TimeoutError, OSError) as error:
        raise EvidenceError("GitHub release-evidence API is unavailable") from None
    if len(raw) > MAX_RESPONSE_BYTES:
        raise EvidenceError("GitHub release-evidence response exceeded its bound")
    try:
        value = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise EvidenceError("GitHub release-evidence response was malformed") from None
    if not isinstance(value, dict):
        raise EvidenceError("GitHub release-evidence response was malformed")
    return value


def _created_at(run):
    value = run.get("created_at")
    if not isinstance(value, str):
        return ""
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).isoformat()
    except ValueError:
        return ""


def verify_release(sha):
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise EvidenceError("release target must be a lowercase full commit SHA")

    query = urlencode({"head_sha": sha, "event": "push", "branch": BRANCH, "per_page": 20})
    run_response = api_json(f"{API}/repos/{REPOSITORY}/actions/runs?{query}")
    runs = run_response.get("workflow_runs")
    if not isinstance(runs, list):
        raise EvidenceError("GitHub release-evidence response was malformed")
    total_runs = run_response.get("total_count")
    if not isinstance(total_runs, int) or total_runs > len(runs):
        raise EvidenceError("GitHub returned incomplete exact-target workflow evidence")
    matching = [
        run for run in runs
        if isinstance(run, dict)
        and run.get("head_sha") == sha
        and run.get("head_branch") == BRANCH
        and run.get("event") == "push"
        and run.get("path") == WORKFLOW
    ]
    if not matching:
        raise EvidenceError("no authoritative-branch push workflow exists for the exact target SHA")
    run = max(matching, key=_created_at)
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        raise EvidenceError("the latest exact-target workflow is not completed successfully")
    run_id = run.get("id")
    if not isinstance(run_id, int) or run_id <= 0:
        raise EvidenceError("GitHub release-evidence response was malformed")

    job_response = api_json(f"{API}/repos/{REPOSITORY}/actions/runs/{run_id}/jobs?per_page=100")
    jobs = job_response.get("jobs")
    if not isinstance(jobs, list):
        raise EvidenceError("GitHub release-evidence response was malformed")
    total_jobs = job_response.get("total_count")
    if not isinstance(total_jobs, int) or total_jobs > len(jobs):
        raise EvidenceError("GitHub returned incomplete mandatory job evidence")
    by_name = {}
    for job in jobs:
        if isinstance(job, dict) and isinstance(job.get("name"), str):
            by_name.setdefault(job["name"], []).append(job)
    for name in REQUIRED_JOBS:
        instances = by_name.get(name, [])
        if len(instances) != 1:
            raise EvidenceError("an exact-target mandatory CI job is missing or duplicated")
        job = instances[0]
        if job.get("status") != "completed" or job.get("conclusion") != "success":
            raise EvidenceError("an exact-target mandatory CI job did not succeed")
        if name == "Server tests (PHP 8.4, mysql)":
            steps = job.get("steps")
            if not isinstance(steps, list):
                raise EvidenceError("the exact-target MySQL process gate is missing")
            gates = [step for step in steps if isinstance(step, dict) and step.get("name") == MYSQL_GATE]
            if len(gates) != 1 or gates[0].get("status") != "completed" or gates[0].get("conclusion") != "success":
                raise EvidenceError("the exact-target MySQL process gate did not succeed")

    return {"target_sha": sha, "workflow_run_id": run_id, "mandatory_checks": len(REQUIRED_JOBS)}


def main(argv):
    if len(argv) != 2:
        print("usage: verify-release-ci.py FULL_COMMIT_SHA", file=sys.stderr)
        return 2
    try:
        result = verify_release(argv[1])
    except EvidenceError as error:
        print(f"Release CI verification refused: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
