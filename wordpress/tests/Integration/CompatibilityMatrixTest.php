<?php

namespace WPCareGrid\Connector\Tests\Integration;

use WPCareGrid\Connector\Compatibility\EnvironmentFacts;
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

        $this->assertMatchesRegularExpression('/^8\.[2-5]$/D', $expectedPhp);
        $this->assertMatchesRegularExpression('/^(6\.8|7\.1)$/D', $expectedWordPress);
        $this->assertContains($expectedDatabase, ['sqlite', 'mysql', 'mariadb']);
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
            $expectedDatabase === 'mysql' ? '8.4' : '10.11',
            preg_match('/^(\d+\.\d+)/', $facts['database']['version'], $matches) ? $matches[1] : null,
        );
    }
}
