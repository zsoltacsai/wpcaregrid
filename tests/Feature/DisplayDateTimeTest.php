<?php

namespace Tests\Feature;

use App\Compatibility\CapabilitySnapshot;
use App\Owner\OwnerSettings;
use App\Support\DisplayDateTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DisplayDateTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_timezone_changes_presentation_without_changing_operational_clock_or_instant(): void
    {
        config(['app.timezone' => 'UTC']);
        app(OwnerSettings::class)->updateGroup('general', [
            'general.business_name' => 'WP CareGrid',
            'general.support_email' => null,
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ], null);

        $instant = CarbonImmutable::parse('2026-10-24T10:06:03Z');
        $display = app(DisplayDateTime::class);

        $this->assertSame('2026-10-24 12:06:03 CEST +02:00', $display->format($instant, 'Y-m-d H:i:s T P'));
        $this->assertSame('2026-10-24T10:06:03+00:00', $instant->toIso8601String());
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_dst_repeated_hour_is_disambiguated_by_timezone_abbreviation_and_offset(): void
    {
        app(OwnerSettings::class)->updateGroup('general', [
            'general.business_name' => 'WP CareGrid',
            'general.support_email' => null,
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ], null);

        $display = app(DisplayDateTime::class);

        $this->assertSame(
            '2026-10-25 02:30:00 CEST +02:00',
            $display->format(CarbonImmutable::parse('2026-10-25T00:30:00Z'), 'Y-m-d H:i:s T P'),
        );
        $this->assertSame(
            '2026-10-25 02:30:00 CET +01:00',
            $display->format(CarbonImmutable::parse('2026-10-25T01:30:00Z'), 'Y-m-d H:i:s T P'),
        );
    }

    public function test_utc_new_york_and_tokyo_are_formatted_from_the_same_instant(): void
    {
        $instant = CarbonImmutable::parse('2026-10-24T12:00:00Z');
        foreach ([
            'UTC' => '2026-10-24 12:00 UTC +00:00',
            'America/New_York' => '2026-10-24 08:00 EDT -04:00',
            'Asia/Tokyo' => '2026-10-24 21:00 JST +09:00',
        ] as $timezone => $expected) {
            app(OwnerSettings::class)->updateGroup('general', [
                'general.business_name' => 'WP CareGrid',
                'general.support_email' => null,
                'general.timezone' => $timezone,
                'general.country' => 'HU',
            ], null);

            $this->assertSame($expected, app(DisplayDateTime::class)->format($instant, 'Y-m-d H:i T P'));
            $this->assertSame('UTC', date_default_timezone_get());
        }
    }

    public function test_invalid_persisted_timezone_fails_back_to_utc(): void
    {
        DB::table('owner_settings')->insert([
            'id' => 'general.timezone',
            'value' => json_encode('Invalid/Timezone', JSON_THROW_ON_ERROR),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->assertSame(
            '2026-10-24 10:06:03 UTC',
            app(DisplayDateTime::class)->format(CarbonImmutable::parse('2026-10-24T10:06:03Z'), 'Y-m-d H:i:s T'),
        );
        $this->assertNull(app(DisplayDateTime::class)->format(null));
    }

    public function test_blade_display_directive_uses_the_owner_timezone(): void
    {
        app(OwnerSettings::class)->updateGroup('general', [
            'general.business_name' => 'WP CareGrid',
            'general.support_email' => null,
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ], null);

        $html = Blade::render(
            '@displayDateTime($instant, "Y-m-d H:i T")',
            ['instant' => CarbonImmutable::parse('2026-10-24T10:06:03Z')],
        );

        $this->assertSame('2026-10-24 12:06 CEST', trim($html));
    }

    public function test_datetime_local_input_is_interpreted_in_owner_timezone_then_stored_as_utc(): void
    {
        app(OwnerSettings::class)->updateGroup('general', [
            'general.business_name' => 'WP CareGrid',
            'general.support_email' => null,
            'general.timezone' => 'Europe/Budapest',
            'general.country' => 'HU',
        ], null);

        $instant = app(DisplayDateTime::class)->parseLocal('2026-10-24T12:06:03');

        $this->assertSame('2026-10-24T10:06:03+00:00', $instant->toIso8601String());
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_timezone_preference_does_not_change_capability_freshness(): void
    {
        config([
            'app.timezone' => 'UTC',
            'compatibility.dynamic_ttl_seconds' => 1800,
            'compatibility.policy_version' => 'test-policy',
            'compatibility.evaluator_version' => '1',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-24T10:06:03Z'));
        $fresh = new CapabilitySnapshot([
            'checked_at' => Carbon::parse('2026-10-24T09:50:00Z'),
            'policy_version' => 'test-policy',
            'evaluator_version' => '1',
        ]);
        $stale = new CapabilitySnapshot([
            'checked_at' => Carbon::parse('2026-10-24T09:30:00Z'),
            'policy_version' => 'test-policy',
            'evaluator_version' => '1',
        ]);

        try {
            $this->assertTrue($fresh->isFresh());
            $this->assertFalse($stale->isFresh());
            app(OwnerSettings::class)->updateGroup('general', [
                'general.business_name' => 'WP CareGrid',
                'general.support_email' => null,
                'general.timezone' => 'Europe/Budapest',
                'general.country' => 'HU',
            ], null);
            app(OwnerSettings::class)->applyRuntimeConfiguration();

            $this->assertTrue($fresh->isFresh());
            $this->assertFalse($stale->isFresh());
            $this->assertSame('UTC', config('app.timezone'));
            $this->assertSame('UTC', date_default_timezone_get());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mysql_connections_keep_timestamp_sessions_in_utc(): void
    {
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
    }
}
