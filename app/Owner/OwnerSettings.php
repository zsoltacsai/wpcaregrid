<?php

namespace App\Owner;

use App\Models\User;
use App\Pricing\CurrencySuggestion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Explicit, typed Owner-controlled settings. This is not a generic key/value editor. */
final class OwnerSettings
{
    /** Request/job-scoped snapshot; never depends on a shared-cache invalidation succeeding. */
    private ?array $storedValuesCache = null;

    public const GROUPS = ['general', 'localization', 'pricing', 'mail', 'backups', 'billing_tax', 'system'];

    public const CURRENCIES = ['HUF', 'EUR', 'USD', 'GBP'];

    /** @var array<string, array{group: string, type: string, default: mixed, audit_values?: bool}> */
    private const DEFINITIONS = [
        'general.business_name' => ['group' => 'general', 'type' => 'string', 'default' => 'WP CareGrid'],
        'general.support_email' => ['group' => 'general', 'type' => 'nullable_string', 'default' => null],
        'general.timezone' => ['group' => 'general', 'type' => 'string', 'default' => 'UTC'],
        'general.country' => ['group' => 'general', 'type' => 'string', 'default' => 'HU'],
        'localization.default_language' => ['group' => 'localization', 'type' => 'string', 'default' => 'en'],
        'pricing.enabled_currencies' => ['group' => 'pricing', 'type' => 'array', 'default' => ['HUF', 'EUR', 'USD', 'GBP']],
        'pricing.default_international_currency' => ['group' => 'pricing', 'type' => 'string', 'default' => 'EUR'],
        'mail.mode' => ['group' => 'mail', 'type' => 'string', 'default' => 'local_smtp'],
        'mail.host' => ['group' => 'mail', 'type' => 'string', 'default' => '127.0.0.1'],
        'mail.port' => ['group' => 'mail', 'type' => 'integer', 'default' => 25],
        'mail.username' => ['group' => 'mail', 'type' => 'nullable_string', 'default' => null, 'audit_values' => false],
        'mail.scheme' => ['group' => 'mail', 'type' => 'nullable_string', 'default' => null],
        'mail.from_address' => ['group' => 'mail', 'type' => 'string', 'default' => 'noreply@wpcaregrid.com'],
        'mail.from_name' => ['group' => 'mail', 'type' => 'string', 'default' => 'WP CareGrid'],
        'backups.primary.enabled' => ['group' => 'backups', 'type' => 'boolean', 'default' => false],
        'backups.primary.frequency' => ['group' => 'backups', 'type' => 'string', 'default' => '12h'],
        'backups.primary.storage_driver' => ['group' => 'backups', 'type' => 'string', 'default' => 'local'],
        'backups.primary.endpoint' => ['group' => 'backups', 'type' => 'nullable_string', 'default' => null],
        'backups.primary.region' => ['group' => 'backups', 'type' => 'string', 'default' => 'us-east-1'],
        'backups.primary.bucket' => ['group' => 'backups', 'type' => 'nullable_string', 'default' => null],
        'backups.primary.path_style' => ['group' => 'backups', 'type' => 'boolean', 'default' => false],
        'backups.retention.daily' => ['group' => 'backups', 'type' => 'integer', 'default' => 7],
        'backups.retention.weekly' => ['group' => 'backups', 'type' => 'integer', 'default' => 4],
        'backups.retention.monthly' => ['group' => 'backups', 'type' => 'integer', 'default' => 12],
        'backups.secondary.enabled' => ['group' => 'backups', 'type' => 'boolean', 'default' => false],
        'backups.secondary.frequency' => ['group' => 'backups', 'type' => 'string', 'default' => 'daily'],
        'backups.app_key_escrow_attested' => ['group' => 'backups', 'type' => 'boolean', 'default' => false],
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = $this->storedValues();
        $values = [];
        foreach (self::DEFINITIONS as $id => $definition) {
            $values[$id] = array_key_exists($id, $stored) ? $stored[$id] : $this->default($id);
        }

        return $values;
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);

        return array_filter($this->all(), fn (mixed $_, string $id) => self::DEFINITIONS[$id]['group'] === $group, ARRAY_FILTER_USE_BOTH);
    }

    public function get(string $id): mixed
    {
        abort_unless(isset(self::DEFINITIONS[$id]), 404);
        $stored = $this->storedValues();

        return array_key_exists($id, $stored) ? $stored[$id] : $this->default($id);
    }

    public function hasStored(string $id): bool
    {
        abort_unless(isset(self::DEFINITIONS[$id]), 404);

        return array_key_exists($id, $this->storedValues());
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateGroup(string $group, array $input): array
    {
        abort_unless(in_array($group, self::GROUPS, true), 404);
        $ids = array_keys(array_filter(self::DEFINITIONS, fn (array $definition) => $definition['group'] === $group));
        $unknown = array_diff(array_keys($input), $ids);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['settings' => __('owner.settings.validation.unknown_field')]);
        }

        $rules = match ($group) {
            'general' => [
                'general.business_name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
                'general.support_email' => ['nullable', 'email:rfc', 'max:255'],
                'general.timezone' => ['required', 'timezone'],
                'general.country' => ['required', 'string', Rule::in(array_keys((array) __('countries')))],
            ],
            'localization' => [
                'localization.default_language' => ['required', Rule::in(['hu', 'en'])],
            ],
            'pricing' => [
                'pricing.enabled_currencies' => ['required', 'array', 'min:1', 'contains:HUF'],
                'pricing.enabled_currencies.*' => ['required', 'string', 'distinct', Rule::in(self::CURRENCIES)],
                'pricing.default_international_currency' => ['required', Rule::in(['EUR', 'USD', 'GBP'])],
            ],
            'mail' => [
                'mail.mode' => ['required', Rule::in(['local_smtp', 'external_smtp'])],
                'mail.host' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || (! filter_var($value, FILTER_VALIDATE_IP) && ! preg_match('/\A(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*\z/D', $value))) {
                        $fail(__('owner.settings.validation.mail_host'));
                    }
                }],
                'mail.port' => ['required', 'integer', 'between:1,65535'],
                'mail.username' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
                'mail.scheme' => ['nullable', Rule::in(['smtp', 'smtps'])],
                'mail.from_address' => ['required', 'email:rfc', 'max:255'],
                'mail.from_name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            ],
            'backups' => [
                'backups.primary.enabled' => ['required', 'boolean'],
                'backups.primary.frequency' => ['required', Rule::in(['6h', '12h', 'daily', 'weekly'])],
                'backups.primary.storage_driver' => ['sometimes', 'required', Rule::in(['local', 's3'])],
                'backups.primary.endpoint' => ['sometimes', 'nullable', 'string', 'max:253', 'not_regex:/[\x00-\x20\x7F]/'],
                'backups.primary.region' => ['sometimes', 'required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]*\z/D'],
                'backups.primary.bucket' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:/\A(?=.{3,63}\z)(?![0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\z)(?!.*\.\.)(?!.*\.-)(?!.*-\.)(?!.*-\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D'],
                'backups.primary.path_style' => ['sometimes', 'required', 'boolean'],
                'backups.retention.daily' => ['required', 'integer', 'between:7,365'],
                'backups.retention.weekly' => ['required', 'integer', 'between:4,104'],
                'backups.retention.monthly' => ['required', 'integer', 'between:12,120'],
                'backups.secondary.enabled' => ['required', 'boolean'],
                'backups.secondary.frequency' => ['required', Rule::in(['6h', '12h', 'daily', 'weekly'])],
                'backups.app_key_escrow_attested' => ['required', 'boolean'],
            ],
            default => [],
        };

        if ($rules === []) {
            throw ValidationException::withMessages(['settings' => __('owner.settings.validation.read_only')]);
        }

        // Setting identifiers intentionally use dotted public names, but Laravel
        // treats dots in validator keys as nested paths. Map the fixed schema to
        // temporary field names while validating so the persisted/API identifiers
        // remain literal and cannot be confused with nested input.
        $temporaryIds = [];
        $validationInput = [];
        $attributeNames = [];
        foreach ($ids as $index => $id) {
            $temporaryId = 'setting_'.$index;
            $temporaryIds[$id] = $temporaryId;
            if (array_key_exists($id, $input)) {
                $validationInput[$temporaryId] = $input[$id];
            }
            $label = __('owner.settings.'.$group.'.'.substr($id, strlen($group) + 1));
            $attributeNames[$temporaryId] = $label;
            $attributeNames[$temporaryId.'.*'] = $label;
        }
        $validationRules = [];
        foreach ($rules as $ruleId => $rule) {
            $settingId = null;
            foreach ($ids as $id) {
                if ($ruleId === $id || str_starts_with($ruleId, $id.'.')) {
                    $settingId = $id;
                    break;
                }
            }
            if ($settingId === null) {
                throw new \LogicException('Owner setting validation rule is not mapped to a setting.');
            }
            $validationRules[$temporaryIds[$settingId].substr($ruleId, strlen($settingId))] = $rule;
        }
        $validator = Validator::make($validationInput, $validationRules);
        $validator->setAttributeNames($attributeNames);
        $validatedTemporary = $validator->validate();
        $validated = [];
        foreach ($temporaryIds as $id => $temporaryId) {
            if (array_key_exists($temporaryId, $validatedTemporary)) {
                $validated[$id] = $validatedTemporary[$temporaryId];
            }
        }
        if ($group === 'mail') {
            $host = strtolower((string) $validated['mail.host']);
            $loopback = $this->loopbackHost($host);
            $configuredHost = strtolower((string) config('mail.mailers.smtp.host'));
            $configuredLocalDomain = strtolower((string) config('mail.mailers.smtp.local_domain'));
            $localHost = $loopback || (filled($configuredHost) && $host === $configuredHost
                && $host === $configuredLocalDomain);
            if (($validated['mail.mode'] === 'local_smtp' && ! $localHost)
                || ($validated['mail.mode'] === 'external_smtp'
                    && (! $this->safeExternalSmtpTarget($host, (int) $validated['mail.port'])
                        || ! in_array($validated['mail.scheme'], ['smtp', 'smtps'], true)))) {
                throw ValidationException::withMessages(['settings' => __('owner.settings.validation.mail_mode_host')]);
            }
        }

        if ($group === 'backups') {
            $deploymentEnvironment = (string) (config('platform.deployment_environment') ?: app()->environment());
            $storageDriver = $validated['backups.primary.storage_driver']
                ?? $input['backups.primary.storage_driver']
                ?? $this->default('backups.primary.storage_driver');
            if (in_array($deploymentEnvironment, ['staging', 'production'], true)
                && $storageDriver !== 's3') {
                throw ValidationException::withMessages(['settings' => __('owner.platform_backups.remote_storage_required')]);
            }
            $endpoint = $validated['backups.primary.endpoint'] ?? null;
            if (filled($endpoint) && ! $this->safePlatformBackupEndpoint((string) $endpoint)) {
                throw ValidationException::withMessages(['settings' => __('owner.platform_backups.invalid_endpoint')]);
            }
        }

        $normalized = [];
        foreach ($validated as $id => $value) {
            $normalized[$id] = match (self::DEFINITIONS[$id]['type']) {
                'integer' => (int) $value,
                'nullable_string' => $value === '' ? null : $value,
                'array' => array_values($value),
                'boolean' => (bool) $value,
                default => $value,
            };
        }

        return $normalized;
    }

    /** Persist validated keys, audit safe changes, then invalidate the settings cache. */
    public function updateGroup(string $group, array $input, ?User $actor): array
    {
        $validated = $this->validateGroup($group, $input);
        $changed = [];
        $lock = Cache::lock('owner-settings:update:'.$group, 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['settings' => __('owner.settings.validation.concurrent_update')]);
        }
        try {
            $before = $this->group($group);
            DB::transaction(function () use ($validated, $before, $group, $actor, &$changed): void {
                foreach ($validated as $id => $value) {
                    if ($this->hasStored($id) && $before[$id] === $value) {
                        continue;
                    }
                    $changed[] = $id;
                    DB::table('owner_settings')->upsert([[
                        'id' => $id,
                        'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]], ['id'], ['value', 'updated_at']);
                    $data = ['group' => $group, 'setting' => $id];
                    if (self::DEFINITIONS[$id]['audit_values'] ?? true) {
                        $data['previous'] = $this->auditValue($before[$id]);
                        $data['new'] = $this->auditValue($value);
                    }
                    AdminAudit::record($actor, 'owner_setting.updated', 'owner_setting', $id, $data);
                }
            });
        } finally {
            $lock->release();
        }

        if ($changed !== []) {
            $this->storedValuesCache = null;
            $this->applyRuntimeConfiguration();
        }

        return $changed;
    }

    /** Apply explicitly saved non-secret mail settings; never mutate config:cache output. */
    public function applyRuntimeConfiguration(): void
    {
        $general = $this->group('general');
        $localization = $this->group('localization');
        config([
            'app.name' => $general['general.business_name'],
            'app.timezone' => $general['general.timezone'],
            'app.locale' => $localization['localization.default_language'],
            'owner_settings.general.support_email' => $general['general.support_email'],
            'owner_settings.general.country' => $general['general.country'],
            'owner_settings.pricing.enabled_currencies' => $this->get('pricing.enabled_currencies'),
            'owner_settings.pricing.default_international_currency' => $this->get('pricing.default_international_currency'),
            'platform_backup.enabled' => (bool) $this->get('backups.primary.enabled'),
            'platform_backup.storage_driver' => (string) $this->get('backups.primary.storage_driver'),
            'platform_backup.owner_frequency' => (string) $this->get('backups.primary.frequency'),
            'platform_backup.retention.daily' => (int) $this->get('backups.retention.daily'),
            'platform_backup.retention.weekly' => (int) $this->get('backups.retention.weekly'),
            'platform_backup.retention.monthly' => (int) $this->get('backups.retention.monthly'),
            'platform_backup.retention_days.daily' => (int) $this->get('backups.retention.daily'),
            'platform_backup.retention_days.weekly' => (int) $this->get('backups.retention.weekly') * 7,
            'platform_backup.retention_days.monthly' => (int) $this->get('backups.retention.monthly') * 30,
            'platform_backup.s3.endpoint' => $this->get('backups.primary.endpoint'),
            'platform_backup.s3.region' => (string) $this->get('backups.primary.region'),
            'platform_backup.s3.bucket' => $this->get('backups.primary.bucket'),
            'platform_backup.s3.path_style' => (bool) $this->get('backups.primary.path_style'),
        ]);
        date_default_timezone_set((string) $general['general.timezone']);
        app()->setLocale((string) $localization['localization.default_language']);

        if (! $this->hasStored('mail.mode')) {
            return;
        }

        $values = $this->group('mail');
        $local = $values['mail.mode'] === 'local_smtp';
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $values['mail.host'],
            'mail.mailers.smtp.port' => $values['mail.port'],
            'mail.mailers.smtp.scheme' => $values['mail.scheme'],
            'mail.mailers.smtp.username' => $local ? null : $values['mail.username'],
            'mail.mailers.smtp.password' => $local ? null : config('mail.mailers.smtp.password'),
            'mail.from.address' => $values['mail.from_address'],
            'mail.from.name' => $values['mail.from_name'],
        ]);
    }

    /** Immutable initial suggestions; browser locale never overrides billing-country pricing rules. */
    public static function localeCurrencySuggestions(): array
    {
        return [
            'hu / hu-HU' => 'HUF', 'en-US' => 'USD', 'en-GB' => 'GBP',
            'de-DE, de-AT, fr-FR, fr-BE, es-ES, it-IT, nl-NL, pt-PT, el-GR, fi-FI, sk-SK, sl-SI, et-EE, lv-LV, lt-LT, ga-IE, mt-MT, hr-HR, cy-CY' => 'EUR',
            '*' => 'EUR',
        ];
    }

    public static function suggestedCurrency(string $locale): string
    {
        return CurrencySuggestion::forLocale($locale);
    }

    /** Hungarian billing is always assigned to the HUF market. */
    public static function billingMarketCurrency(string $country): ?array
    {
        return strtoupper(trim($country)) === 'HU' ? ['market' => 'hungary', 'currency' => 'HUF'] : null;
    }

    /** @return array<string, mixed> */
    private function storedValues(): array
    {
        if ($this->storedValuesCache !== null) {
            return $this->storedValuesCache;
        }

        $stored = [];
        foreach (DB::table('owner_settings')->get(['id', 'value']) as $row) {
            if (! isset(self::DEFINITIONS[$row->id])) {
                continue;
            }
            try {
                $value = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
                $validType = match (self::DEFINITIONS[$row->id]['type']) {
                    'string' => is_string($value),
                    'nullable_string' => $value === null || is_string($value),
                    'integer' => is_int($value),
                    'boolean' => is_bool($value),
                    'array' => is_array($value) && array_is_list($value) && $value !== []
                        && in_array('HUF', $value, true)
                        && collect($value)->every(fn (mixed $item) => is_string($item) && in_array($item, self::CURRENCIES, true))
                        && count($value) === count(array_unique($value)),
                    default => false,
                };
                if (! $validType) {
                    Log::warning('Invalid stored Owner setting was ignored.', ['setting' => $row->id]);

                    continue;
                }
                $stored[$row->id] = $value;
            } catch (\Throwable) {
                Log::warning('Invalid stored Owner setting was ignored.', ['setting' => $row->id]);
            }
        }

        // Stored JSON types alone are not enough: a hand-corrupted or legacy
        // row must not inject an unsupported enum, hostname, currency, or
        // timezone into runtime configuration. Validate the complete effective
        // group and ignore that group's persisted overrides if it is invalid.
        foreach (array_unique(array_column(self::DEFINITIONS, 'group')) as $group) {
            $groupIds = array_keys(array_filter(
                self::DEFINITIONS,
                fn (array $definition) => $definition['group'] === $group,
            ));
            if (! array_intersect($groupIds, array_keys($stored))) {
                continue;
            }

            $effectiveGroup = [];
            foreach ($groupIds as $id) {
                $effectiveGroup[$id] = array_key_exists($id, $stored) ? $stored[$id] : $this->default($id);
            }

            try {
                $validatedGroup = $this->validateGroup($group, $effectiveGroup);
                foreach ($validatedGroup as $id => $value) {
                    if (array_key_exists($id, $stored)) {
                        $stored[$id] = $value;
                    }
                }
            } catch (ValidationException) {
                foreach ($groupIds as $id) {
                    unset($stored[$id]);
                }
                Log::warning('Invalid stored Owner settings group was ignored.', ['group' => $group]);
            }
        }

        return $this->storedValuesCache = $stored;
    }

    private function default(string $id): mixed
    {
        return match ($id) {
            'general.business_name' => config('app.name', 'WP CareGrid'),
            'general.support_email' => null,
            'backups.primary.enabled' => (bool) config('platform_backup.enabled', false),
            'backups.primary.storage_driver' => (string) config('platform_backup.storage_driver', 'local'),
            'backups.primary.endpoint' => config('filesystems.disks.platform_backups.endpoint'),
            'backups.primary.region' => (string) config('filesystems.disks.platform_backups.region', 'us-east-1'),
            'backups.primary.bucket' => config('filesystems.disks.platform_backups.bucket'),
            'backups.primary.path_style' => (bool) config('filesystems.disks.platform_backups.use_path_style_endpoint', false),
            'general.timezone' => config('app.timezone', 'UTC'),
            'general.country' => 'HU',
            'localization.default_language' => in_array(config('app.locale'), ['hu', 'en'], true) ? config('app.locale') : 'en',
            'mail.mode' => $this->loopbackHost(config('mail.mailers.smtp.host'))
                || config('mail.mailers.smtp.host') === config('mail.mailers.smtp.local_domain')
                ? 'local_smtp' : 'external_smtp',
            'mail.host' => config('mail.mailers.smtp.host', '127.0.0.1'),
            'mail.port' => (int) config('mail.mailers.smtp.port', 25),
            'mail.username' => config('mail.mailers.smtp.username'),
            'mail.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.from_address' => config('mail.from.address', 'noreply@wpcaregrid.com'),
            'mail.from_name' => config('mail.from.name', 'WP CareGrid'),
            default => self::DEFINITIONS[$id]['default'],
        };
    }

    private function loopbackHost(mixed $host): bool
    {
        return in_array(strtolower((string) $host), ['127.0.0.1', '::1', 'localhost'], true);
    }

    private function safeExternalSmtpTarget(string $host, int $port): bool
    {
        // SMTP configuration is an intentional outbound connection. Restrict it
        // to DNS hostnames and standard submission ports; never allow literal IPs,
        // local/reserved names, URLs, or arbitrary internal-service ports.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! in_array($port, [25, 465, 587, 2525], true)) {
            return false;
        }

        if (count(explode('.', $host)) < 2) {
            return false;
        }

        foreach (['localhost', 'local', 'internal', 'test', 'invalid', 'home.arpa', 'lan', 'example.com', 'example.net', 'example.org'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return false;
            }
        }

        return true;
    }

    private function safePlatformBackupEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $environment = (string) (config('platform.deployment_environment') ?: app()->environment());
        if (in_array($environment, ['staging', 'production'], true)) {
            return $scheme === 'https'
                && (! isset($parts['port']) || (int) $parts['port'] === 443)
                && ($host === 'your-objectstorage.com' || str_ends_with($host, '.your-objectstorage.com'))
                && filter_var($host, FILTER_VALIDATE_IP) === false;
        }

        return in_array($scheme, ['http', 'https'], true)
            && (! isset($parts['port']) || in_array((int) $parts['port'], [80, 443, 9000, 9001], true));
    }

    private function auditValue(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : ($value === null ? null : (string) $value);
    }
}
