<?php

namespace Tests\Feature\Connector;

use App\Connector\ConnectorPackageArtifact;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\ActsAsOwner;
use Tests\Concerns\PairsConnectors;
use Tests\TestCase;
use ZipArchive;

final class ConnectorPackageArtifactTest extends TestCase
{
    use ActsAsOwner, PairsConnectors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $directory = storage_path('framework/testing/connector-package-'.bin2hex(random_bytes(6)));
        config(['connector.package_path' => $directory]);
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($directory.'/.package-build.lock');
            @rmdir($directory);
        });
    }

    public function test_build_creates_verified_immutable_package_with_runner_and_manifest(): void
    {
        $this->artisan('connector:package')->assertSuccessful();
        $package = app(ConnectorPackageArtifact::class)->verify();
        $this->assertSame('0.4.2', $package['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $package['package_sha256']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path'], ZipArchive::RDONLY) === true);
        $runner = $zip->getFromName('wp-caregrid-connector/bin/caregrid-controlled-poll.php');
        $manifest = json_decode((string) $zip->getFromName('wp-caregrid-connector/package-manifest.json'), true);
        $this->assertIsString($runner);
        $this->assertSame(hash_file('sha256', config('connector.plugin_source').'/bin/caregrid-controlled-poll.php'), $manifest['files']['bin/caregrid-controlled-poll.php']);
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')", $runner);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $this->assertStringNotContainsString('/tests/', '/'.$zip->getNameIndex($index));
        }
        $zip->close();

        $this->artisan('connector:package')->assertSuccessful();
        $this->assertSame($package['package_sha256'], app(ConnectorPackageArtifact::class)->verify()['package_sha256']);
    }

    public function test_package_hash_tampering_fails_closed(): void
    {
        $this->artisan('connector:package')->assertSuccessful();
        $package = app(ConnectorPackageArtifact::class)->verify();
        file_put_contents($package['path'], 'tampered');

        $this->expectException(RuntimeException::class);
        app(ConnectorPackageArtifact::class)->verify();
    }

    public function test_untracked_connector_source_is_rejected_before_packaging(): void
    {
        $source = config('connector.plugin_source');
        $untracked = $source.'/release-test-extra.php';
        file_put_contents($untracked, '<?php // untracked package input');

        try {
            $this->artisan('connector:package')->assertFailed();
        } finally {
            @unlink($untracked);
        }
    }

    public function test_owner_download_is_restricted_to_configured_synthetic_fixture_and_reports_hash(): void
    {
        $this->artisan('connector:package')->assertSuccessful();
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'synthetic_fixtures.site_uuid' => null,
        ]);
        $owner = $this->asOwner();
        $fixture = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        config(['synthetic_fixtures.site_uuid' => $fixture->uuid]);

        $response = $this->actingAs($owner, 'owner')->get(route('owner.websites.connector-package', $fixture));
        $response->assertOk()->assertDownload('wp-caregrid-connector-0.4.2.zip')
            ->assertHeader('X-Connector-Version', '0.4.2')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->headers->get('X-Connector-Source-SHA256'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->headers->get('X-Connector-Package-SHA256'));

        $ordinary = $this->websiteWithPlan();
        $this->get(route('owner.websites.connector-package', $ordinary))->assertNotFound();
        config(['app.deployment_environment' => 'production', 'app.url' => 'https://wpcaregrid.com', 'app.deployment_hostname' => 'prod1.wpcaregrid.com']);
        $this->get(route('owner.websites.connector-package', $fixture))->assertNotFound();
    }
}
