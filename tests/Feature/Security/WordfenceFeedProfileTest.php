<?php

namespace Tests\Feature\Security;

use Symfony\Component\Process\Process;
use PHPUnit\Framework\TestCase;

class WordfenceFeedProfileTest extends TestCase
{
    public function test_profile_reports_aggregate_categories_without_echoing_fixture_versions(): void
    {
        $root = dirname(__DIR__, 3);
        $fixture = $root.'/tests/Fixtures/wordfence-v3-schema-profile.json';
        $process = new Process([PHP_BINARY, $root.'/scripts/profile-wordfence-v3-feed.php', $fixture]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'Profiler should accept the synthetic offline fixture.');
        $output = $process->getOutput();
        $this->assertStringContainsString('Top-level vulnerability records: 1', $output);
        $this->assertStringContainsString('Software entries: 2', $output);
        $this->assertStringContainsString('Affected version ranges: 7', $output);
        $this->assertStringContainsString('rejected_by_previous_version_token_regex: 3', $output);
        $this->assertStringContainsString('standalone_wildcard: 1', $output);
        $this->assertStringContainsString('prerelease_like_suffix: 1', $output);
        $this->assertStringContainsString('to_version categories (categories may overlap)', $output);
        $this->assertStringContainsString('from_inclusive:boolean:false', $output);
        $this->assertStringContainsString('to_inclusive:boolean:false', $output);
        $this->assertStringNotContainsString('2.3.0-rc.1', $output);
        $this->assertStringNotContainsString('2.6.0', $output);
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_profile_refuses_input_larger_than_the_explicit_limit_without_printing_its_path(): void
    {
        $root = dirname(__DIR__, 3);
        $fixture = $root.'/tests/Fixtures/wordfence-v3-schema-profile.json';
        $process = new Process([PHP_BINARY, $root.'/scripts/profile-wordfence-v3-feed.php', $fixture, '--max-bytes=16']);
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('exceeds the configured size limit', $process->getErrorOutput());
        $this->assertStringNotContainsString($fixture, $process->getErrorOutput());
    }

    public function test_profile_fails_closed_on_invalid_encoding_without_echoing_source_bytes(): void
    {
        $root = dirname(__DIR__, 3);
        $path = tempnam(sys_get_temp_dir(), 'wf-profile-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, "{\"synthetic\":{\"bad\":\"\xFF\"}}");
            $process = new Process([PHP_BINARY, $root.'/scripts/profile-wordfence-v3-feed.php', $path]);
            $process->run();

            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('invalid JSON, encoding, record shape, or record size limit', $process->getErrorOutput());
            $this->assertStringNotContainsString("\xFF", $process->getErrorOutput());
            $this->assertStringNotContainsString($path, $process->getErrorOutput());
        } finally {
            @unlink($path);
        }
    }
}
