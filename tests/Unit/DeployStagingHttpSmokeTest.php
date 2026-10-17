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

        $this->assertStringContainsString('passed: 45 scenarios', $process->getOutput());
    }

    public function test_baseline_is_pinned_after_lock_and_rechecked_before_mutation_and_checkout(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));
        $this->assertIsString($script);

        $main = substr($script, strpos($script, "\nmain() {") ?: 0);
        $backupGuard = strpos($main, '[[ -d "$BACKUP_DIR"');
        $deployLock = strpos($main, 'flock -n 9 || fail');
        $baselineCapture = strpos($main, '    capture_baseline_sha || fail');
        $baselineSmoke = strpos($main, '    pre_deploy_smoke || fail');
        $targetPin = strpos($main, '    pin_target_sha "$requested_ref" "$fetched_tip"');
        $backup = strpos($main, '    privileged db-dump | gzip -c');
        $schedulerMutation = strpos($main, "    install_scheduler_guard\n");
        $beforeGate = strpos($main, "    assert_baseline_unchanged || fail 'The live checkout changed before the deployment gate");
        $beforeCheckout = strpos($main, "    assert_baseline_unchanged || fail 'The live checkout changed before the target code switch.");
        $exactShaCheck = strrpos($main, '[[ "$(git rev-parse HEAD)" == "$TARGET_SHA" ]]');
        $targetSmoke = strpos($main, '    if ! post_deploy_smoke ||');

        $codeSwitch = strpos($main, '    git checkout --detach "$TARGET_SHA"');
        foreach ([$backupGuard, $deployLock, $baselineCapture, $baselineSmoke, $targetPin, $backup, $beforeGate, $schedulerMutation, $beforeCheckout, $codeSwitch, $exactShaCheck, $targetSmoke] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertLessThan($deployLock, $backupGuard);
        $this->assertLessThan($baselineCapture, $deployLock);
        $this->assertLessThan($baselineSmoke, $baselineCapture);
        $this->assertLessThan($targetPin, $baselineSmoke);
        $this->assertLessThan($backup, $targetPin);
        $this->assertLessThan($beforeGate, $backup);
        $this->assertLessThan($schedulerMutation, $beforeGate);
        $this->assertLessThan($schedulerMutation, $baselineSmoke);
        $this->assertLessThan($beforeCheckout, $schedulerMutation);
        $this->assertLessThan($codeSwitch, $beforeCheckout);
        $this->assertLessThan($exactShaCheck, $codeSwitch);
        $this->assertLessThan($targetSmoke, $exactShaCheck);
    }

    public function test_rollback_uses_only_the_captured_sha_and_keeps_migration_uncertainty_gated(): void
    {
        $script = file_get_contents(base_path('scripts/deploy-staging.sh'));
        $this->assertIsString($script);

        $this->assertStringNotContainsString('PRE_DEPLOY_BASELINE_SHA', $script);
        $this->assertStringNotContainsString('f0194bca2d58b8eafccb1dfd1e3fc6176ed132de', $script);
        $this->assertStringContainsString('readonly BASELINE_SHA="$current_sha"', $script);
        $this->assertStringContainsString('readonly TARGET_SHA="$target_commit"', $script);
        $this->assertStringContainsString('if (( MIGRATION_ATTEMPTED && MIGRATIONS_PENDING )); then', $script);
        $this->assertStringContainsString('git checkout --detach "$BASELINE_SHA"', $script);
        $this->assertStringContainsString('rollback_code_to_baseline || return 1', $script);

        $recovery = substr($script, strpos($script, "\nrecover_after_failure() {") ?: 0);
        $stopWorkers = strpos($recovery, 'stop_workers_for_rollback || return 1');
        $rollback = strpos($recovery, 'rollback_code_to_baseline || return 1');
        $this->assertNotFalse($stopWorkers);
        $this->assertNotFalse($rollback);
        $this->assertLessThan($rollback, $stopWorkers);
    }
}
