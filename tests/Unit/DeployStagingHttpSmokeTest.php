<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployStagingHttpSmokeTest extends TestCase
{
    public function test_guarded_deployment_http_smoke_enforces_localized_redirect_contract(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/tests/deploy-staging-http-smoke-test.sh')],
            base_path(),
        );
        $process->setTimeout(30);
        $process->mustRun();

        $this->assertStringContainsString('passed: 10 scenarios', $process->getOutput());
    }
}
