<?php

namespace Tests\Concerns;

use App\Release\StagingDatabaseProbe;
use Illuminate\Support\Facades\DB;

/** A configuration in which the staging-next identity and its database are proven (external and connector closed, mail not confined). */
trait ProvesStagingNext
{
    protected function provenStagingNext(): void
    {
        // The proof wants the default connection to be called "mysql"; the tests keep using their own database through that name.
        $testConnection = DB::connection();
        app('db')->extend('mysql', fn () => $testConnection);
        config([
            'marketing_release.profile' => 'staging_active',
            'marketing_release.requested_profile' => 'staging_active',
            'marketing_release.staging_active_enabled' => true,
            'marketing_release.enforcement_mode' => 'enforced',
            'marketing_release.staging_active_features' => ['connector_api' => false, 'external_services' => false, 'platform_updates_outbound' => false],
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
        ]);
        $this->stagingDatabaseProbe('wpcg_staging_next');
    }

    protected function stagingDatabaseProbe(?string $database): void
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
}
