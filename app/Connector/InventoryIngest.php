<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\WebsiteInventory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and stores a site inventory. Only the listed fields are kept;
 * anything else a connector sends is discarded.
 */
final class InventoryIngest
{
    /** @return array<string, mixed> */
    public static function rules(string $prefix = ''): array
    {
        $version = ['nullable', 'string', 'max:32'];
        $license = [
            "{$prefix}plugins.*.license" => ['nullable', 'array'],
            "{$prefix}plugins.*.license.status" => ['required_with:'.$prefix.'plugins.*.license', Rule::enum(LicenseStatus::class)],
            "{$prefix}plugins.*.license.expires_at" => ['nullable', 'date'],
            "{$prefix}plugins.*.license.source" => ['nullable', 'string', 'max:64'],
        ];

        return [
            "{$prefix}core" => ['required', 'array'],
            "{$prefix}core.version" => ['required', 'string', 'max:32'],
            "{$prefix}core.update_available" => ['present', 'nullable', 'boolean'],
            "{$prefix}core.latest_version" => $version,
            "{$prefix}php_version" => ['required', 'string', 'max:32'],
            "{$prefix}plugins" => ['present', 'array', 'max:500'],
            "{$prefix}plugins.*.file" => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/'],
            "{$prefix}plugins.*.slug" => ['nullable', 'string', 'max:191'],
            "{$prefix}plugins.*.name" => ['required', 'string', 'max:191'],
            "{$prefix}plugins.*.version" => $version,
            "{$prefix}plugins.*.active" => ['required', 'boolean'],
            "{$prefix}plugins.*.update_available" => ['present', 'nullable', 'boolean'],
            "{$prefix}plugins.*.new_version" => $version,
            "{$prefix}plugins.*.update_source" => ['nullable', 'string', 'max:64'],
            ...$license,
            "{$prefix}themes" => ['present', 'array', 'max:100'],
            "{$prefix}themes.*.stylesheet" => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/'],
            "{$prefix}themes.*.name" => ['required', 'string', 'max:191'],
            "{$prefix}themes.*.version" => $version,
            "{$prefix}themes.*.active" => ['required', 'boolean'],
            "{$prefix}themes.*.update_available" => ['present', 'nullable', 'boolean'],
            "{$prefix}themes.*.new_version" => $version,
            "{$prefix}woocommerce" => ['nullable', 'array'],
            "{$prefix}woocommerce.installed" => ['required_with:'.$prefix.'woocommerce', 'boolean'],
            "{$prefix}woocommerce.active" => ['required_with:'.$prefix.'woocommerce', 'boolean'],
            "{$prefix}woocommerce.version" => $version,
            "{$prefix}collected_at" => ['nullable', 'date'],
        ];
    }

    /** @param  array<string, mixed>  $inventory */
    public function store(Connector $connector, array $inventory): WebsiteInventory
    {
        $data = Validator::make($inventory, self::rules())->validate();
        $website = $connector->website;

        $record = WebsiteInventory::updateOrCreate(['website_id' => $website->id], [
            'connector_id' => $connector->id,
            'core' => $data['core'],
            'plugins' => array_values($data['plugins']),
            'themes' => array_values($data['themes']),
            'woocommerce' => $data['woocommerce'] ?? null,
            'php_version' => $data['php_version'],
            'collected_at' => $data['collected_at'] ?? now(),
        ]);

        $connector->update(['last_inventory_at' => now()]);
        $website->update(['wordpress_version' => $data['core']['version'], 'php_version' => $data['php_version']]);

        return $record;
    }

    /**
     * Merge a license check into the stored plugin list. Plugins not in the
     * report keep their previous state.
     *
     * @param  array<int, array<string, mixed>>  $licenses
     */
    public function storeLicenses(Connector $connector, array $licenses): void
    {
        $data = Validator::make(['licenses' => $licenses], [
            'licenses' => ['array', 'max:500'],
            'licenses.*.file' => ['required', 'string', 'max:191'],
            'licenses.*.status' => ['required', Rule::enum(LicenseStatus::class)],
            'licenses.*.expires_at' => ['nullable', 'date'],
            'licenses.*.source' => ['nullable', 'string', 'max:64'],
        ])->validate()['licenses'];

        $inventory = $connector->website->inventory;

        if (! $inventory) {
            return;
        }

        $byFile = collect($data)->keyBy('file');

        $inventory->update(['plugins' => collect($inventory->plugins)->map(function (array $plugin) use ($byFile) {
            if ($license = $byFile->get($plugin['file'])) {
                $plugin['license'] = collect($license)->only(['status', 'expires_at', 'source'])->all();
            }

            return $plugin;
        })->all()]);
    }
}
