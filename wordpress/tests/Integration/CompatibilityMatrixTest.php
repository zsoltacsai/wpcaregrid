<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Compatibility\EnvironmentFacts;
use WPCareGrid\Connector\Tests\Support\FakeRestoreIsolationGate;
use WPCareGrid\Connector\Tests\Support\TestCase;

/** Prevent a green CI lane from silently testing a different runtime fixture. */
final class CompatibilityMatrixTest extends TestCase
{
    public function test_runtime_wordpress_and_database_match_the_declared_ci_lane(): void
    {
        global $wpdb;

        $expectedPhp = (string) getenv('WP_CAREGRID_EXPECTED_PHP_BRANCH');
        $expectedWordPress = (string) getenv('WP_CAREGRID_EXPECTED_WORDPRESS_BRANCH');
        $expectedDatabase = (string) getenv('WP_CAREGRID_EXPECTED_DATABASE');
        $expectedDatabaseBranch = (string) getenv('WP_CAREGRID_EXPECTED_DATABASE_BRANCH');

        $this->assertMatchesRegularExpression('/^8\.[2-5]$/D', $expectedPhp);
        $this->assertMatchesRegularExpression('/^(6\.8|7\.1)$/D', $expectedWordPress);
        $this->assertContains($expectedDatabase, ['sqlite', 'mysql', 'mariadb']);
        $expectedBranches = [
            'sqlite' => ['sqlite'],
            'mysql' => ['5.7', '8.0', '8.4'],
            'mariadb' => ['10.11'],
        ];
        $this->assertContains($expectedDatabaseBranch, $expectedBranches[$expectedDatabase]);
        $this->assertSame($expectedPhp, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
        $this->assertSame($expectedWordPress, preg_replace('/^(\d+\.\d+).*/', '$1', get_bloginfo('version')));
        $this->assertTrue(extension_loaded('mysqli'));

        $facts = (new EnvironmentFacts)->collect(false);
        if ($expectedDatabase === 'sqlite') {
            $this->assertFalse((bool) $wpdb->is_mysql);
            // SQLite is a supported test fixture for read-only Connector
            // behavior; it is not a database-backup compatibility claim.
            $this->assertSame('unknown', $facts['database']['engine']);
            $this->assertNull($facts['database']['version']);

            return;
        }

        $this->assertSame($expectedDatabase, $facts['database']['engine']);
        $this->assertIsString($facts['database']['version']);
        $this->assertNotSame('', $facts['database']['version']);
        $this->assertSame(
            $expectedDatabaseBranch,
            preg_match('/^(\d+\.\d+)/', $facts['database']['version'], $matches) ? $matches[1] : null,
        );
    }

    public function test_restore_isolation_provider_identity_is_reported_without_acquiring_the_gate(): void
    {
        $facts = (new EnvironmentFacts)->collect(false);
        $this->assertNull($facts['restore_isolation_provider_id']);

        $provider = new FakeRestoreIsolationGate;
        $filter = static fn () => $provider;
        add_filter('wp_caregrid_restore_isolation_gate', $filter);
        try {
            $facts = (new EnvironmentFacts)->collect(false);

            $this->assertSame('test-host-gate', $facts['restore_isolation_provider_id']);
            $this->assertFalse($provider->is_gated(), 'Readiness collection must not acquire or mutate the host gate.');
        } finally {
            remove_filter('wp_caregrid_restore_isolation_gate', $filter);
        }
    }
}
