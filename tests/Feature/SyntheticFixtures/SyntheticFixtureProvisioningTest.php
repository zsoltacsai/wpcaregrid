<?php

namespace Tests\Feature\SyntheticFixtures;

use App\Backups\BackupPolicy;
use App\Backups\Storage\LocalStorage;
use App\Backups\Storage\StorageManager;
use App\Connector\JobType;
use App\Models\BillingInvoice;
use App\Models\CheckoutIntent;
use App\Models\Customer;
use App\Models\CustomerBillingIdentity;
use App\Models\StripeEvent;
use App\Models\Subscription;
use App\Models\SyntheticSiteFixture;
use App\Models\User;
use App\Models\Website;
use App\SyntheticFixtures\StagingIdentity;
use App\SyntheticFixtures\SyntheticFixturePolicy;
use App\SyntheticFixtures\SyntheticFixtureProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsOwner;
use Tests\TestCase;

final class SyntheticFixtureProvisioningTest extends TestCase
{
    use ActsAsOwner, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowStagingProvisioning();
    }

    public function test_predeclared_fixture_is_created_without_user_billing_or_email_state(): void
    {
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $result = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E',
            'm8-e2e@example.test',
            'https://m8-e2e.staging.wpcaregrid.com',
        );

        $customer = $result['customer'];
        $website = $result['website'];
        $fixture = SyntheticSiteFixture::sole();

        $this->assertSame('m8-e2e@example.test', $customer->email);
        $this->assertSame('https://m8-e2e.staging.wpcaregrid.com', $website->url);
        $this->assertSame($customer->id, $fixture->customer_id);
        $this->assertSame($website->id, $fixture->website_id);
        $this->assertTrue($fixture->backup_restore_entitled);
        $this->assertNull($customer->user_id);
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, BillingInvoice::count());
        $this->assertSame(0, CheckoutIntent::count());
        $this->assertSame(0, CustomerBillingIdentity::count());
        $this->assertSame(0, StripeEvent::count());
        $this->assertSame(0, User::count());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_production_and_missing_deployment_identity_fail_closed_without_writes(): void
    {
        foreach ([
            ['environment' => 'production', 'hostname' => 'wpcaregrid.com', 'url' => 'https://wpcaregrid.com'],
            ['environment' => 'staging', 'hostname' => '', 'url' => 'https://staging.wpcaregrid.com'],
            ['environment' => 'staging', 'hostname' => 'stg1.wpcaregrid.com', 'url' => 'https://wpcaregrid.com'],
        ] as $identity) {
            config([
                'app.deployment_environment' => $identity['environment'],
                'app.deployment_hostname' => $identity['hostname'],
                'app.url' => $identity['url'],
            ]);

            try {
                app(SyntheticFixtureProvisioner::class)->provision(
                    'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
                );
                $this->fail('Provisioning should reject any non-staging deployment identity.');
            } catch (ValidationException) {
                $this->assertSame(0, Customer::count());
                $this->assertSame(0, Website::count());
                $this->assertSame(0, SyntheticSiteFixture::count());
            }
        }
    }

    public function test_reserved_site_url_rejects_ports_paths_credentials_and_query_strings(): void
    {
        foreach ([
            'https://m8-e2e.staging.wpcaregrid.com:443',
            'https://m8-e2e.staging.wpcaregrid.com/path',
            'https://user@m8-e2e.staging.wpcaregrid.com',
            'https://m8-e2e.staging.wpcaregrid.com/?next=example.test',
        ] as $host) {
            try {
                app(SyntheticFixtureProvisioner::class)->provision('WP CareGrid M8 E2E', 'm8-e2e@example.test', $host);
                $this->fail('Non-canonical M8 test URLs must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, Customer::count());
                $this->assertSame(0, Website::count());
            }
        }
    }

    public function test_customer_cannot_select_synthetic_classification_and_reserved_identity_is_unique(): void
    {
        $customer = Customer::factory()->create();
        $customer->fill(['synthetic' => true, 'is_synthetic' => true])->save();
        $this->assertFalse($customer->syntheticFixture()->exists());

        $this->asOwner();
        $this->put(route('owner.customers.update', $customer), [
            'first_name' => 'Synthetic',
            'last_name' => 'Attempt',
            'customer_type' => 'individual',
            'country_code' => 'HU',
            'synthetic_fixture' => true,
            'is_synthetic' => true,
        ])->assertRedirect();
        $this->assertFalse($customer->refresh()->syntheticFixture()->exists());

        app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        );

        $this->expectException(ValidationException::class);
        app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        );
    }

    public function test_fixture_entitlement_is_staging_scoped_and_does_not_change_probono_or_paid_sites(): void
    {
        $synthetic = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $policy = app(SyntheticFixturePolicy::class);

        $this->assertTrue($policy->backupRestoreAllowed($synthetic));
        $this->assertFalse($policy->controlledExecutionAllowed($synthetic));

        $ordinary = Website::factory()->create();
        $this->assertFalse($policy->backupRestoreAllowed($ordinary));

        config(['app.deployment_environment' => 'production']);
        $this->assertFalse($policy->backupRestoreAllowed($synthetic));
        $this->assertFalse($policy->jobAllowed($synthetic, JobType::Restore));
    }

    public function test_backup_and_restore_policy_requires_site_scoped_controlled_mode(): void
    {
        $website = app(SyntheticFixtureProvisioner::class)->provision(
            'WP CareGrid M8 E2E', 'm8-e2e@example.test', 'https://m8-e2e.staging.wpcaregrid.com',
        )['website'];
        $root = sys_get_temp_dir().'/synthetic-m8-policy-'.uniqid();
        config(['backup.storage' => 'local', 'backup.providers.local.driver' => 'local', 'backup.providers.local.root' => $root]);
        app(StorageManager::class)->set('local', new LocalStorage($root));

        $blocked = app(BackupPolicy::class)->for($website);
        $this->assertFalse($blocked->eligible);
        $this->assertFalse($blocked->restoreAllowed);
        $this->assertSame('SYNTHETIC_CONTROLLED_EXECUTION_DISABLED', $blocked->refusal);

        config([
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.site_uuid' => $website->uuid,
            'synthetic_fixtures.restore_enabled' => true,
        ]);
        $allowed = app(BackupPolicy::class)->for($website);
        $this->assertTrue($allowed->eligible);
        $this->assertTrue($allowed->manualAllowed);
        $this->assertTrue($allowed->restoreAllowed);

        config(['synthetic_fixtures.site_uuid' => '00000000-0000-4000-8000-000000000001']);
        $wrongSite = app(BackupPolicy::class)->for($website);
        $this->assertFalse($wrongSite->eligible);
        $this->assertFalse($wrongSite->restoreAllowed);
    }

    public function test_operator_command_creates_the_single_fixture_without_printing_pairing_or_provider_secrets(): void
    {
        $this->artisan('caregrid:provision-m8-fixture')
            ->assertExitCode(0)
            ->expectsOutputToContain('Customer UUID:')
            ->expectsOutputToContain('Website UUID:')
            ->doesntExpectOutputToContain('cgc_');

        $this->assertSame(1, SyntheticSiteFixture::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_operator_command_refuses_production_deployment_identity(): void
    {
        config([
            'app.deployment_environment' => 'production',
            'app.deployment_hostname' => 'wpcaregrid.com',
            'app.url' => 'https://wpcaregrid.com',
        ]);

        $this->artisan('caregrid:provision-m8-fixture')->assertExitCode(1);
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Website::count());
        $this->assertSame(0, SyntheticSiteFixture::count());
    }

    private function allowStagingProvisioning(): void
    {
        config([
            'app.env' => 'production',
            'app.url' => 'https://staging.wpcaregrid.com',
            'app.deployment_environment' => 'staging',
            'app.deployment_hostname' => 'stg1.wpcaregrid.com',
            'synthetic_fixtures.provisioning_enabled' => true,
            'synthetic_fixtures.controlled_execution_enabled' => true,
            'synthetic_fixtures.restore_enabled' => false,
            'synthetic_fixtures.site_uuid' => null,
        ]);
        $this->assertTrue(app(StagingIdentity::class)->allowsCurrentDeployment());
    }
}
