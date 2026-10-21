#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "staging-read-only-diagnostics.yml"
RUNNER = ROOT / "scripts" / "staging-diagnostics" / "run-m12-readback.sh"
DISPATCH = ROOT / "scripts" / "staging-diagnostics" / "dispatch.sh"


class StagingDiagnosticsPolicyTest(unittest.TestCase):
    def test_workflow_is_manual_protected_read_only_and_branch_scoped(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("environment:\n      name: staging-diagnostics", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("actions: write", workflow)
        self.assertNotIn("id-token: write", workflow)
        self.assertNotIn("deployments: write", workflow)
        self.assertNotIn("upload-artifact", workflow)
        self.assertIn("refs/heads/claude/ecstatic-wozniak-oemyuf", workflow)
        self.assertNotIn("schedule:", workflow)
        self.assertNotIn("push:", workflow)
        self.assertNotIn("pull_request:", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("if: github.ref == 'refs/heads/claude/ecstatic-wozniak-oemyuf' && inputs.diagnostic == 'm12-config-readback'", workflow)
        self.assertIn(
            "        env:\n"
            "          DIAGNOSTIC_ID: ${{ inputs.diagnostic }}\n"
            "          STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY: ${{ secrets.STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY }}\n"
            "          STAGING_DIAGNOSTICS_KNOWN_HOSTS: ${{ secrets.STAGING_DIAGNOSTICS_KNOWN_HOSTS }}\n"
            "        run: bash scripts/staging-diagnostics/run-m12-readback.sh",
            workflow,
        )
        job_environment = workflow.split("    steps:", maxsplit=1)[0]
        self.assertNotIn("STAGING_DIAGNOSTICS_SSH_PRIVATE_KEY", job_environment)
        self.assertNotIn("STAGING_DIAGNOSTICS_KNOWN_HOSTS", job_environment)
        self.assertNotIn("${{ github.event", workflow)
        self.assertNotIn("${{ inputs.diagnostic }}'", workflow)

    def test_runner_has_fixed_target_command_and_strict_ssh_options(self) -> None:
        runner = RUNNER.read_text(encoding="utf-8")
        for required in (
            "caregrid-diagnostics",
            "stg1.wpcaregrid.com",
            "m12-config-readback",
            "StrictHostKeyChecking=yes",
            "ConnectionAttempts=1",
            "-F /dev/null",
            "ProxyCommand=none",
            "ProxyJump=none",
            "timeout --signal=TERM --kill-after=2 45s",
            "head -c 4097",
            "2>/dev/null",
            "sanitize_m12_output.py",
        ):
            with self.subTest(required=required):
                self.assertIn(required, runner)
        self.assertNotIn("ssh-keyscan", runner)
        self.assertNotIn("eval ", runner)

    def test_server_dispatcher_rejects_every_command_except_the_single_id(self) -> None:
        dispatcher = DISPATCH.read_text(encoding="utf-8")
        self.assertIn('"${SSH_ORIGINAL_COMMAND-}" != \'m12-config-readback\'', dispatcher)
        self.assertIn("sudo -n -- /usr/bin/php /usr/local/libexec/wpcaregrid-staging-m12-readback.php", dispatcher)
        self.assertIn("ulimit -c 0", dispatcher)
        self.assertNotIn("eval", dispatcher)
        self.assertNotIn("exec \"$SSH_ORIGINAL_COMMAND\"", dispatcher)


if __name__ == "__main__":
    unittest.main()
