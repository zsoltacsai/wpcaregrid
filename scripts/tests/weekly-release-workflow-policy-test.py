#!/usr/bin/env python3
"""Static guards for scheduled dependency intake and dormant deployment jobs."""

from pathlib import Path
import subprocess
import sys
import tempfile
import textwrap


ROOT = Path(__file__).resolve().parents[2]
workflow = (ROOT / ".github/workflows/weekly-dependency-release.yml").read_text(encoding="utf-8")
dependabot = (ROOT / ".github/dependabot.yml").read_text(encoding="utf-8")

for cron in ("0 19 * * 5", "0 18 * * 6", "0 20 * * 0", "0 8 * * 1"):
    assert f"cron: '{cron}'" in workflow, f"missing Budapest schedule {cron}"
assert workflow.count("timezone: Europe/Budapest") == 4
dispatch_choices = workflow.split("  workflow_dispatch:\n", 1)[1].split("\n\npermissions:", 1)[0]
assert "          - production\n" not in dispatch_choices, \
    "manual dispatch must not offer an unimplemented production phase"
assert dependabot.count("day: friday") == 4
assert dependabot.count("timezone: Europe/Budapest") == 4
assert "applies-to: security-updates" in dependabot
assert "applies-to: version-updates" in dependabot

candidate = workflow.split("  candidate:\n", 1)[1].split("  staging-deploy:\n", 1)[0]
assert "production_release_controller_v3.py verify-release" in candidate
assert "verify-release-ci.py" not in candidate  # V3 performs exact candidate, PR and primary CI checks.
assert "open Dependabot PRs remain" in candidate
assert "review-subject" in candidate and "review-ledger.json" in candidate
assert "github.event.schedule == '0 20 * * 0'" in candidate, \
    "Sunday promotion must rerun current candidate admission"
for request_id in ("open-pulls", "dependabot-alerts", "issue-174-comments", "issue-174-metadata",
                   "merged-prs-for-sha", "exact-ci-runs", "exact-ci-jobs"):
    assert candidate.count(f"gh_api_read {request_id} ") == 1, \
        f"candidate API request must have one stable diagnostic id: {request_id}"
assert "generate-ci-staging-receipt" in candidate and "staging-equivalent-ci-receipt.json" in candidate
assert ('gh_api_read issue-174-metadata "$RUNNER_TEMP/review-ledger-meta.json" '
        '"repos/$GITHUB_REPOSITORY/issues/174"') in candidate, \
    "the ledger completeness proof needs one read-only issue metadata request"
assert "review-ledger-meta.json" in candidate and "--ledger-metadata" in candidate, \
    "review-subject must receive the issue metadata comment count"
metadata_request = [line for line in candidate.splitlines()
                    if "gh_api_read issue-174-metadata " in line]
assert len(metadata_request) == 1 and "--paginate" not in metadata_request[0] \
    and "--slurp" not in metadata_request[0], \
    "the issue metadata count must come from one unpaginated document"
assert "issues/174/comments?per_page=100" in candidate, \
    "the streamed ledger page size must stay the documented 100 items"
assert "GH_API_REQUEST=%s STATE=END RC=%s" in candidate
assert '2>"$stderr_file"' in candidate
assert "HTTP=%s" in candidate and '"$stderr_file"' in candidate
assert "    permissions:\n      contents: read\n      pull-requests: read\n      security-events: read\n      vulnerability-alerts: read\n      issues: read\n      actions: read" in candidate

# Exercise the exact diagnostic snippet without reading or disclosing input content.
size_start = candidate.index("          python3 - \"$RUNNER_TEMP/open-pulls.json\"")
size_end = candidate.index("          PY_INPUT_BYTES", size_start)
assert size_end < candidate.index("python3 scripts/weekly_release_management.py intake")
size_probe = textwrap.dedent(candidate[size_start:size_end].split("\n", 1)[1])
assert "os.stat(" in size_probe
for forbidden in ("open(", "read(", "read_bytes(", "read_text(", "traceback"):
    assert forbidden not in size_probe
with tempfile.TemporaryDirectory() as temporary:
    inputs = [Path(temporary) / name for name in ("pulls", "alerts")]
    inputs[0].write_bytes(b"private-pull")
    inputs[1].write_bytes(b"private-alert")
    result = subprocess.run([sys.executable, "-c", size_probe, *map(str, inputs)],
                            capture_output=True, text=True)
    assert result.returncode == 0
    assert result.stdout == ("CANDIDATE_INPUT_BYTES name=open-pulls size=12\n"
                             "CANDIDATE_INPUT_BYTES name=dependabot-alerts size=13\n")
    assert result.stderr == ""
    inputs[1].unlink()
    result = subprocess.run([sys.executable, "-c", size_probe, *map(str, inputs)],
                            capture_output=True, text=True)
    assert result.returncode != 0
    assert result.stdout == result.stderr == ""

staging = workflow.split("  staging-deploy:\n", 1)[1].split("  production-deploy:\n", 1)[0]
assert "vars.WPCG_WEEKLY_STAGING_ENABLED == 'true'" in staging
assert "vars.WPCG_STAGING_RECOVERY_GATE_READY == 'true'" in staging
assert "self-hosted, linux, wpcg-staging" in staging
assert "bash \"$controller\" \"$CANDIDATE_SHA\"" in staging
assert "scripts/weekly_release_smoke.py" in staging
assert 'bash "$controller" --rollback "$ROLLBACK_SHA"' in staging
assert "staging-rollback-smoke.json" in staging
assert "deployments: write" in staging
assert "Create exact-SHA staging deployment record" in staging
assert "Record staging deployment outcome" in staging
assert '"freeze_status":"FROZEN"' in staging
assert "staging-marketing-smoke.json" in staging

production = workflow.split("  production-deploy:\n", 1)[1]
assert "needs: candidate" in production
assert "needs.candidate.result == 'success'" in production
assert "needs.candidate.outputs.candidate_ready == 'true'" in production
for required in (
    "vars.WPCG_FIRST_PRODUCTION_LAUNCH_SHA != ''",
    "vars.WPCG_RECURRING_PRODUCTION_ENABLED == 'true'",
    "vars.WPCG_PRODUCTION_RECOVERY_GATE_READY == 'true'",
    "vars.WPCG_PRODUCTION_ROLLBACK_READY == 'true'",
    "vars.WPCG_PRODUCTION_INCIDENTS_CLEAR == 'true'",
):
    assert required in production, f"missing disabled-by-default production gate: {required}"
assert "wpcg-production" in production
assert "production_deploy_controller.py sync" in production
assert "--rollback" not in production
assert "github.event.schedule == '0 20 * * 0'" in production
assert "inputs.phase == 'production'" not in production

assert "WPCG_GITHUB_TOKEN: ${{ github.token }}" in workflow
assert "actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9" in workflow
assert "gh issue create" in workflow
print("weekly release workflow policy passed: Budapest schedules, exact evidence, dormant gates and existing controllers")
