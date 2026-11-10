<?php

namespace Tests\Feature;

use App\Owner\SystemStatus;
use App\Release\StagingDatabaseProbe;
use App\Release\StagingSchedulePolicy;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The central, fail-closed scheduler allowlist of staging-next. Production and every other
 * environment keep exactly their schedule; on staging-next a task runs only if it is
 * classified, the environment is proven and its isolation group is open.
 */
class StagingSchedulerPolicyTest extends TestCase
{
    private const LOCAL = [
        'connector:update-presence', 'backup:monitor', 'backup:retention', 'privacy:exports-expire', 'admin-access:expire',
        'security:monitor', 'monitoring:run', 'system:scheduler-heartbeat', 'internal-plans:sync', 'billing:subscriptions:expire-grace',
    ];

    private const STRIPE = ['offers:expire', 'stripe:webhooks:drain', 'stripe:subscriptions:reconcile'];

    private const MAIL = ['monitoring:deliver-alerts', 'billing:emails:drain'];

    private const CONNECTOR = ['backup:schedule', 'security:schedule'];

    private const EXTERNAL = ['platform:database-backup-schedule', 'security:vulnerabilities:sync', 'platform:updates:check'];

    protected function setUp(): void
    {
        parent::setUp();

        // The vulnerability sync has its own configuration condition; make it due so only the policy decides.
        config([
            'platform_updates.enabled' => true,
            'security.vulnerabilities.source' => 'wordfence',
            'security.vulnerabilities.feed_url' => 'https://feed.invalid/x',
            'security.vulnerabilities.scheduled_sync_enabled' => true,
        ]);
    }

    public function test_every_scheduled_task_is_classified_and_every_classification_is_scheduled(): void
    {
        $scheduled = $this->scheduledKeys();

        $this->assertSame([], array_diff($scheduled, array_keys(StagingSchedulePolicy::TASKS)), 'a scheduled task without a classification');
        $this->assertSame([], array_diff(array_keys(StagingSchedulePolicy::TASKS), $scheduled), 'a classification for a task that is not scheduled');
        $this->assertCount(20, $scheduled);
        $this->assertEqualsCanonicalizing(
            array_merge(self::LOCAL, self::STRIPE, self::MAIL, self::CONNECTOR, self::EXTERNAL),
            array_keys(StagingSchedulePolicy::TASKS),
        );
    }

    public function test_production_and_legacy_staging_keep_their_exact_schedule(): void
    {
        foreach ([
            ['production', 'https://wpcaregrid.com'],
            ['staging', 'https://staging.wpcaregrid.com'],
            ['testing', 'http://localhost'],
        ] as [$deployment, $url]) {
            config(['app.deployment_environment' => $deployment, 'app.url' => $url]);
            foreach (StagingSchedulePolicy::TASKS as $task => $group) {
                $this->assertTrue($this->due($task), "{$deployment}: {$task} must stay scheduled");
            }
            $this->assertNull(app(StagingSchedulePolicy::class)->denial('anything:at-all'), $deployment.': no policy outside staging-next');
            $this->assertNull(app(StagingSchedulePolicy::class)->denial(null));
            $this->assertSame([], app(StagingSchedulePolicy::class)->held());
        }
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertNotSame(StagingSchedulePolicy::MUTEX_MINUTES, $event->expiresAt, 'mutex expiry is unchanged outside staging-next');
        }
    }

    public function test_a_proven_staging_next_runs_only_the_local_tasks_by_default(): void
    {
        $this->proven();

        $this->assertEqualsCanonicalizing(self::LOCAL, array_values(array_filter(array_keys(StagingSchedulePolicy::TASKS), fn (string $task) => $this->due($task))));
        $expected = array_fill_keys(self::STRIPE, 'stripe_closed')
            + array_fill_keys(self::MAIL, 'mail_closed')
            + array_fill_keys(self::CONNECTOR, 'connector_closed')
            + array_fill_keys(self::EXTERNAL, 'external_closed');
        ksort($expected);
        $this->assertSame($expected, $this->sorted(app(StagingSchedulePolicy::class)->held()));
    }

    public function test_each_group_opens_only_on_its_own_condition(): void
    {
        $this->proven();

        // Stripe: proven test-mode keys only.
        config(['services.stripe.secret' => 'sk_test_unit', 'services.stripe.key' => 'pk_test_unit']);
        $this->assertGroupDue(self::STRIPE);
        $this->assertGroupHeld(self::MAIL);
        $this->assertGroupHeld(self::CONNECTOR);
        $this->assertGroupHeld(self::EXTERNAL);
        foreach (['sk_live_unit', 'rk_live_unit', 'not-a-key', ''] as $secret) {
            config(['services.stripe.secret' => $secret]);
            $this->assertGroupHeld(self::STRIPE, 'stripe secret '.var_export($secret, true));
        }
        config(['services.stripe.secret' => 'sk_test_unit', 'services.stripe.key' => 'pk_live_unit']);
        $this->assertGroupHeld(self::STRIPE, 'live publishable key');

        // Mail: a sink, or a recipient allowlist.
        $this->proven();
        config(['mail.default' => 'array']);
        $this->assertGroupDue(self::MAIL);
        config(['mail.default' => 'log']);
        $this->assertGroupDue(self::MAIL);
        config(['mail.default' => 'smtp', 'marketing_release.staging_mail_allowlist' => ['@example.test']]);
        $this->assertGroupDue(self::MAIL);
        config(['marketing_release.staging_mail_allowlist' => []]);
        $this->assertGroupHeld(self::MAIL);

        // Connector and external services: the Owner's explicit switches, independent of each other.
        config(['marketing_release.staging_active_features.connector_api' => true]);
        $this->assertGroupDue(self::CONNECTOR);
        $this->assertGroupHeld(self::EXTERNAL);
        config(['marketing_release.staging_active_features.connector_api' => false, 'marketing_release.staging_active_features.external_services' => true]);
        $this->assertGroupDue(self::EXTERNAL);
        $this->assertGroupHeld(self::CONNECTOR);
    }

    public function test_an_unproven_environment_holds_everything_except_the_heartbeat(): void
    {
        $deviations = [
            'profile missing' => ['marketing_release.profile' => 'marketing_only', 'marketing_release.requested_profile' => null, 'marketing_release.staging_active_enabled' => false],
            'profile refused at config load' => ['marketing_release.staging_active_enabled' => false],
            'production app env' => ['app.env' => 'production'],
            'debug on' => ['app.debug' => true],
            'enforcement testing' => ['marketing_release.enforcement_mode' => 'testing'],
            'production url' => ['app.url' => 'https://wpcaregrid.com'],
            'sqlite driver' => ['database.default' => 'sqlite'],
            'legacy database' => ['database.connections.mysql.database' => 'wpcaregrid'],
            'remote database host' => ['database.connections.mysql.host' => '10.0.0.5'],
            'live stripe mode' => ['services.stripe.allow_live_mode' => true],
        ];
        foreach ($deviations as $label => $override) {
            $this->proven();
            config($override);
            if (! array_key_exists('app.url', $override)) {
                config(['app.deployment_environment' => 'staging_next']);
            }
            $this->assertSame('environment_unproven', app(StagingSchedulePolicy::class)->denial('internal-plans:sync'), $label);
            $this->assertSame('environment_unproven', app(StagingSchedulePolicy::class)->denial('monitoring:run'), $label);
            $this->assertNull(app(StagingSchedulePolicy::class)->denial(StagingSchedulePolicy::HEARTBEAT), $label.': the heartbeat still proves the scheduler runs');
        }

        foreach ([null, 'wpcaregrid', 'wpcg_prod_main', 'wpcg_staging_next_restore'] as $database) {
            $this->proven();
            $this->probe($database);
            $this->assertSame('environment_unproven', app(StagingSchedulePolicy::class)->denial('connector:update-presence'), 'live database '.var_export($database, true));
        }
    }

    public function test_an_unclassified_or_unreadable_task_never_runs_on_staging_next(): void
    {
        $this->proven();
        $policy = app(StagingSchedulePolicy::class);

        $this->assertSame('task_not_allowlisted', $policy->denial('brand:new-task'));
        $this->assertSame('task_not_allowlisted', $policy->denial(''));
        $this->assertSame('task_not_allowlisted', $policy->denial(null));
        $this->assertSame('task_not_allowlisted', $policy->denial('MONITORING:RUN'));
        $this->assertSame('task_not_allowlisted', $policy->denial('monitoring:run --force'));

        $unparseable = new Event(new CacheEventMutex(app('cache')), 'echo hello');
        $this->assertNull(StagingSchedulePolicy::taskKey($unparseable));
        $this->assertFalse($policy->permits($unparseable));
        $this->assertFalse($policy->permits(new CallbackEvent(new CacheEventMutex(app('cache')), fn () => null)));
    }

    public function test_staging_next_is_recognised_by_its_origin_even_with_a_wrong_deployment_id(): void
    {
        $this->proven();
        config(['app.deployment_environment' => 'production']);

        $this->assertTrue(StagingSchedulePolicy::appliesHere());
        $this->assertSame('environment_unproven', app(StagingSchedulePolicy::class)->denial('internal-plans:sync'));
    }

    public function test_a_requested_staging_active_profile_holds_everything_even_with_a_wrong_deployment_id_and_url(): void
    {
        // The stack asks for staging_active but identifies itself as neither staging-next by id nor by origin.
        $this->proven();
        config([
            'app.deployment_environment' => 'production',
            'app.url' => 'https://somewhere-else.example',
            'marketing_release.profile' => 'marketing_only',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_active_enabled' => false,
        ]);

        $this->assertTrue(StagingSchedulePolicy::appliesHere());
        $policy = app(StagingSchedulePolicy::class);
        foreach (array_keys(StagingSchedulePolicy::TASKS) as $task) {
            if ($task === StagingSchedulePolicy::HEARTBEAT) {
                $this->assertNull($policy->denial($task));

                continue;
            }
            $this->assertSame('environment_unproven', $policy->denial($task), $task);
        }
        $this->assertSame('task_not_allowlisted', $policy->denial('brand:new-task'));
    }

    public function test_the_task_name_is_read_from_commands_with_arguments_and_from_the_heartbeat(): void
    {
        $this->assertContains('privacy:exports-expire', $this->scheduledKeys());
        $this->assertContains('stripe:subscriptions:reconcile', $this->scheduledKeys());
        $this->assertContains('system:scheduler-heartbeat', $this->scheduledKeys());

        // Linux (the VPS) quotes the entry point with ', Windows with ": both must resolve to the task.
        $mutex = new CacheEventMutex(app('cache'));
        foreach ([
            "'/usr/bin/php8.5' 'artisan' privacy:exports-expire --limit=100" => 'privacy:exports-expire',
            "'php' 'artisan' monitoring:run" => 'monitoring:run',
            '"C:\\php\\php.exe" "artisan" stripe:subscriptions:reconcile --limit=100' => 'stripe:subscriptions:reconcile',
            "'php' 'artisan'" => null,
            "'php' 'not-artisan' monitoring:run" => null,
            "echo 'artisan' injected; monitoring:run" => null,
        ] as $command => $expected) {
            $this->assertSame($expected, StagingSchedulePolicy::taskKey(new Event($mutex, $command)), $command);
        }
    }

    public function test_backup_monitor_needs_its_staging_directory_inside_this_applications_storage(): void
    {
        $this->proven();
        $policy = fn () => app(StagingSchedulePolicy::class)->denial('backup:monitor');

        config(['backup.upload.staging_path' => storage_path('app/private/backup-staging')]);
        $this->assertNull($policy());
        foreach (['/var/lib/other-app/backups', '/var/www/wpcaregrid/storage/app/private/backup-staging', '', null] as $path) {
            config(['backup.upload.staging_path' => $path]);
            $this->assertSame('backup_staging_path_outside_storage', $policy(), var_export($path, true));
        }
    }

    public function test_a_killed_run_cannot_lock_a_task_for_a_day_on_staging_next(): void
    {
        config(['app.deployment_environment' => 'staging_next', 'app.url' => 'https://staging-next.wpcaregrid.com']);
        // routes/console.php is evaluated again under the staging-next identity, on a fresh schedule.
        $this->app->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $locked = collect(app(Schedule::class)->events())->filter(fn (Event $event) => $event->withoutOverlapping);
        $this->assertGreaterThan(10, $locked->count());
        foreach ($locked as $event) {
            $this->assertSame(StagingSchedulePolicy::MUTEX_MINUTES, $event->expiresAt, (string) StagingSchedulePolicy::taskKey($event));
        }
    }

    public function test_the_system_check_lists_held_tasks_by_name_and_reason_only(): void
    {
        $this->proven();
        config(['services.stripe.secret' => 'sk_live_super_secret_value', 'marketing_release.staging_mail_allowlist' => ['private.person@example.com']]);

        $status = app(SystemStatus::class);
        $original = config('database.default');
        config(['database.default' => 'mysql']);
        try {
            $check = (new ReflectionMethod($status, 'stagingIsolation'))->invoke($status);
        } finally {
            config(['database.default' => $original]);
        }
        $json = json_encode($check);

        $this->assertStringContainsString('stripe:webhooks:drain (stripe_closed)', $json);
        $this->assertStringContainsString('security:schedule (connector_closed)', $json);
        $this->assertStringContainsString('platform:database-backup-schedule (external_closed)', $json);
        $this->assertStringNotContainsString('super_secret', $json);
        $this->assertStringNotContainsString('private.person', $json);
    }

    // ---- helpers ----

    private function proven(): void
    {
        config([
            'marketing_release.profile' => 'staging_active',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_active_enabled' => true,
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false],
            'marketing_release.staging_active_db_uuid_sha256' => null,
            'marketing_release.staging_mail_allowlist' => [],
            'app.env' => 'staging',
            'app.deployment_environment' => 'staging_next',
            'app.url' => 'https://staging-next.wpcaregrid.com',
            'app.debug' => false,
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'wpcg_staging_next',
            'database.connections.mysql.host' => '127.0.0.1',
            'services.stripe.allow_live_mode' => false,
            'services.stripe.secret' => null,
            'services.stripe.key' => null,
            'mail.default' => 'smtp',
            'backup.upload.staging_path' => storage_path('app/private/backup-staging'),
        ]);
        $this->probe('wpcg_staging_next');
    }

    private function probe(?string $database): void
    {
        $this->app->instance(StagingDatabaseProbe::class, new class($database) implements StagingDatabaseProbe
        {
            public function __construct(private readonly ?string $database) {}

            public function currentDatabase(): ?string
            {
                return $this->database;
            }

            public function serverUuidSha256(): ?string
            {
                return null;
            }
        });
    }

    /** @return list<string> */
    private function scheduledKeys(): array
    {
        Artisan::call('list');
        $keys = array_map(fn (Event $event) => (string) StagingSchedulePolicy::taskKey($event), app(Schedule::class)->events());
        sort($keys);

        return $keys;
    }

    private function due(string $task): bool
    {
        Artisan::call('list');
        $event = collect(app(Schedule::class)->events())->first(fn (Event $event) => StagingSchedulePolicy::taskKey($event) === $task);
        $this->assertNotNull($event, $task.' is scheduled');

        return $event->filtersPass($this->app);
    }

    /** @param  list<string>  $tasks */
    private function assertGroupDue(array $tasks, string $context = ''): void
    {
        foreach ($tasks as $task) {
            $this->assertTrue($this->due($task), trim($context.' '.$task.' must run'));
        }
    }

    /** @param  list<string>  $tasks */
    private function assertGroupHeld(array $tasks, string $context = ''): void
    {
        foreach ($tasks as $task) {
            $this->assertFalse($this->due($task), trim($context.' '.$task.' must be held'));
        }
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
