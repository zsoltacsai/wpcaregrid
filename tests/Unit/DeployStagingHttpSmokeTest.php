<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployStagingHttpSmokeTest extends TestCase
{
    public function test_guarded_deployment_http_smoke_enforces_baseline_and_target_contracts(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/tests/deploy-staging-http-smoke-test.sh')],
            base_path(),
        );
        $process->setTimeout(30);
        $process->mustRun();

        $this->assertStringContainsString('passed: 27 scenarios', $process->getOutput());
    }

    public function test_baseline_smoke_precedes_mutation_and_target_smoke_follows_exact_sha_check(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));
        $this->assertIsString($script);

        $main = substr($script, strpos($script, "\nmain() {") ?: 0);
        $backupGuard = strpos($main, '[[ -d "$BACKUP_DIR"');
        $baselineSmoke = strpos($main, '    pre_deploy_smoke || fail');
        $schedulerMutation = strpos($main, "    install_scheduler_guard\n");
        $codeSwitch = strpos($main, '    git checkout --detach "$target_commit"');
        $exactShaCheck = strrpos($main, '[[ "$(git rev-parse HEAD)" == "$target_commit" ]]');
        $targetSmoke = strpos($main, '    if ! post_deploy_smoke ||');

        foreach ([$backupGuard, $baselineSmoke, $schedulerMutation, $codeSwitch, $exactShaCheck, $targetSmoke] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertLessThan($baselineSmoke, $backupGuard);
        $this->assertLessThan($schedulerMutation, $baselineSmoke);
        $this->assertLessThan($exactShaCheck, $codeSwitch);
        $this->assertLessThan($targetSmoke, $exactShaCheck);
    }
}
