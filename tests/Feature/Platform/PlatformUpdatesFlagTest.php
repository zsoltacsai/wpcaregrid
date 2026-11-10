<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * PLATFORM_UPDATES_ENABLED is a safety switch: it is read fail-closed. A plain (bool) cast made every non-empty string,
 * "no", "off" and "disabled" included, switch the feature on.
 */
class PlatformUpdatesFlagTest extends TestCase
{
    /** config/platform_updates.php as it is loaded with this environment value (null: the variable is not set at all). */
    private function enabledWith(?string $value): mixed
    {
        $name = 'PLATFORM_UPDATES_ENABLED';
        if ($value !== null) {
            [$_ENV[$name], $_SERVER[$name]] = [$value, $value];
            putenv("{$name}={$value}");
        }

        try {
            return (require config_path('platform_updates.php'))['enabled'];
        } finally {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    public function test_every_off_unknown_or_misspelled_value_leaves_the_feature_off(): void
    {
        foreach ([null, '', 'false', 'FALSE', 'False', '(false)', 'no', 'No', 'off', 'OFF', '0', 'disabled', 'disable', 'enabled-not', 'ture', 'tru', 'maybe', 'null', '(null)', 'none', '2', '-1', ' ', 'y e s'] as $value) {
            $this->assertFalse($this->enabledWith($value), 'value '.var_export($value, true));
        }
    }

    public function test_only_a_recognised_on_value_enables_the_feature_and_the_result_is_a_real_boolean(): void
    {
        foreach (['true', 'TRUE', 'True', '(true)', '1', 'yes', 'on', 'On'] as $value) {
            $this->assertTrue($this->enabledWith($value), 'value '.var_export($value, true));
        }
    }

    public function test_the_shipped_configuration_is_off_in_the_test_environment(): void
    {
        // Every reader goes through PlatformUpdateGate::enabled(), which compares with === true.
        $this->assertFalse(config('platform_updates.enabled'));
    }
}
