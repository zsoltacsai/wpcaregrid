<?php

namespace App\Connector;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The complete list of jobs a connector can be asked to run. There is no
 * job that runs PHP, shell commands or arbitrary WordPress hooks, and none
 * may be added: every job is a fixed, reviewed operation.
 */
enum JobType: string
{
    case GetInventory = 'GET_INVENTORY';
    case SiteHealth = 'SITE_HEALTH';
    case LicenseCheck = 'LICENSE_CHECK';
    case UpdatePlugin = 'UPDATE_PLUGIN';
    case UpdateTheme = 'UPDATE_THEME';
    case UpdateCore = 'UPDATE_CORE';

    public function requiredCapability(): Capability
    {
        return match ($this) {
            self::GetInventory => Capability::Inventory,
            self::SiteHealth => Capability::Health,
            self::LicenseCheck => Capability::LicenseCheck,
            self::UpdatePlugin, self::UpdateTheme, self::UpdateCore => Capability::Updates,
        };
    }

    public function changesTheSite(): bool
    {
        return in_array($this, [self::UpdatePlugin, self::UpdateTheme, self::UpdateCore], true);
    }

    /**
     * Validate and normalise a payload. Unknown keys are rejected, and
     * plugins/themes are identified by WordPress identifiers only (never a
     * path, URL or code).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validatePayload(array $payload): array
    {
        $version = ['nullable', 'string', 'max:32', 'regex:/^\d+(\.\d+){0,3}([.-][0-9A-Za-z]+)?$/'];

        $rules = match ($this) {
            self::GetInventory, self::SiteHealth, self::LicenseCheck => [],
            // "akismet/akismet.php" or "hello.php": the plugin basename WordPress uses.
            self::UpdatePlugin => [
                'plugin' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/', 'not_regex:/\.\./'],
                'target_version' => $version,
            ],
            self::UpdateTheme => [
                'stylesheet' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/', 'not_regex:/\.\./'],
                'target_version' => $version,
            ],
            self::UpdateCore => [
                'policy' => ['required', Rule::in(['minor', 'major'])],
                'target_version' => $version,
            ],
        };

        $unknown = array_diff(array_keys($payload), array_keys($rules));

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($v) use ($unknown) {
            foreach ($unknown as $key) {
                $v->errors()->add((string) $key, 'Unknown payload field.');
            }
        });

        $validated = $validator->validate();

        if ($this === self::UpdateCore && $validated['policy'] === 'major' && ! config('connector.allow_major_core_updates')) {
            throw ValidationException::withMessages(['policy' => 'Major core updates are not allowed by the service policy.']);
        }

        return array_filter($validated, fn ($value) => $value !== null);
    }
}
