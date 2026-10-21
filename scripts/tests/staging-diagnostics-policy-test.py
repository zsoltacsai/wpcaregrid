#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path
import sys


ROOT = Path(__file__).parents[2]
WORKFLOW = ROOT / ".github" / "workflows" / "staging-read-only-diagnostics.yml"
RUNNER = ROOT / "scripts" / "staging-diagnostics" / "run-m12-readback.sh"
DISPATCH = ROOT / "scripts" / "staging-diagnostics" / "dispatch.sh"
PROVISIONING = ROOT / "scripts" / "staging-diagnostics" / "provisioning"
sys.path.insert(0, str(PROVISIONING))
import provision


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
        self.assertIn("github.actor == 'zsoltacsai'", workflow)
        self.assertIn("deployment: false", workflow)
        self.assertNotIn("schedule:", workflow)
        self.assertNotIn("push:", workflow)
        self.assertNotIn("pull_request:", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("ref: ${{ github.workflow_sha }}", workflow)
        self.assertIn("if: github.actor == 'zsoltacsai' && github.ref == 'refs/heads/claude/ecstatic-wozniak-oemyuf' && inputs.diagnostic == 'm12-config-readback'", workflow)
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
        workflow_dir = ROOT / ".github" / "workflows"
        workflow_files = [*workflow_dir.rglob("*.yml"), *workflow_dir.rglob("*.yaml")]
        environment_users = [
            path for path in workflow_files if "name: staging-diagnostics" in path.read_text(encoding="utf-8")
        ]
        self.assertEqual([path.resolve() for path in environment_users], [WORKFLOW.resolve()])

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
        self.assertIn('[ "${SSH_ORIGINAL_COMMAND-}" = \'m12-config-readback\' ] || reject', dispatcher)
        self.assertIn("sudo -n -- /usr/local/sbin/wpcaregrid-staging-m12-readback", dispatcher)
        self.assertNotIn("sudo -n -- /usr/bin/php", dispatcher)
        self.assertIn("ulimit -c 0", dispatcher)
        self.assertNotIn("eval", dispatcher)
        self.assertNotIn("exec \"$SSH_ORIGINAL_COMMAND\"", dispatcher)

    def test_provisioning_package_pins_the_sudo_command_and_never_reads_the_cache(self) -> None:
        provision_source = (PROVISIONING / "provision.py").read_text(encoding="utf-8")
        verifier = (PROVISIONING / "verify.py").read_text(encoding="utf-8")
        rollback = (PROVISIONING / "rollback.py").read_text(encoding="utf-8")
        wrapper = (PROVISIONING / "run-m12-readback.sh").read_text(encoding="utf-8")
        account_shell = (PROVISIONING / "diagnostics-shell.sh").read_text(encoding="utf-8")

        self.assertIn("NOPASSWD:", provision_source)
        self.assertEqual(
            provision.SUDOERS_CONTENT.splitlines()[-1],
            'caregrid-diagnostics ALL=(root) NOPASSWD: /usr/local/sbin/wpcaregrid-staging-m12-readback ""',
        )
        self.assertIn("!setenv", provision_source)
        self.assertIn("SOURCE_SHA_MISMATCH", provision_source)
        self.assertIn("visudo", provision_source)
        self.assertIn("HOST_IDENTITY_MISMATCH", provision_source)
        self.assertIn('"/usr/bin/sudo", "-l", "-U", DIAGNOSTICS_USER', verifier)
        self.assertIn("SUDOERS_EFFECTIVE_POLICY_UNSAFE", provision_source)
        self.assertIn("entries != [expected]", provision_source)
        self.assertIn("SSH_ORIGINAL_COMMAND", account_shell)
        self.assertIn("if [ \"$#\" -ne 0 ]", wrapper)
        self.assertIn("exec /usr/bin/php /usr/local/libexec/wpcaregrid-staging-m12-readback.php", wrapper)
        cache_check = verifier.split("def _require_cache_metadata", maxsplit=1)[1].split("def verify(", maxsplit=1)[0]
        self.assertNotIn("read_text", cache_check)
        self.assertNotIn("read_bytes", cache_check)
        self.assertNotIn("APP_CACHE", rollback)


if __name__ == "__main__":
    unittest.main()
