<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\PlatformDatabaseBackupJob;
use App\Models\AdminAuditEvent;
use App\Models\PlatformDatabaseBackup;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Owner\IntegrationCredentials;
use App\Owner\OwnerSettings;
use App\Platform\DisasterRecovery\PlatformBackupStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlatformBackupsController extends Controller
{
    public function index(OwnerSettings $settings, PlatformBackupStorage $platformStorage): View
    {
        $disk = (string) config('platform_backup.disk', 'platform_backups');
        $configured = $this->primaryConfigured($platformStorage);
        $destination = $settings->group('backups');
        $latest = PlatformDatabaseBackup::query()->where('status', 'verified')->latest('verified_at')->first();
        $ageHours = $latest?->verified_at?->diffInHours(now());
        $storageAvailable = false;
        if ($configured && $latest) {
            try {
                $storageAvailable = $platformStorage->disk($latest->storage_disk)->exists($latest->object_key);
            } catch (\Throwable) {
                $storageAvailable = false;
            }
        }
        $lastConnectionTest = AdminAuditEvent::query()->where('event', 'platform_backup.s3_connection_tested')->latest('id')->first();
        $lastEscrowAttestation = AdminAuditEvent::query()
            ->whereIn('event', ['platform_backup.escrow_attested', 'platform_backup.escrow_revoked'])
            ->where('subject_key', 'app_key')
            ->latest('id')
            ->first();
        $appKeyConfigured = filled(config('app.key'));
        $currentEndpointHost = parse_url((string) $destination['backups.primary.endpoint'], PHP_URL_HOST);
        $connectionTestMatchesCurrentDestination = $lastConnectionTest !== null
            && data_get($lastConnectionTest->data, 'bucket') === $destination['backups.primary.bucket']
            && data_get($lastConnectionTest->data, 'region') === $destination['backups.primary.region']
            && data_get($lastConnectionTest->data, 'endpoint_host') === $currentEndpointHost
            && data_get($lastConnectionTest->data, 'path_style') === $destination['backups.primary.path_style'];
        $readiness = ! $configured ? 'not_configured' : match (true) {
            ! $appKeyConfigured => 'critical',
            ! $latest => 'stale',
            $ageHours >= 24 => 'stale',
            ! $storageAvailable => 'unavailable',
            ! (bool) $settings->get('backups.app_key_escrow_attested') => 'degraded',
            default => 'healthy',
        };

        return view('owner.platform-backups.index', [
            'settings' => $destination,
            'backups' => PlatformDatabaseBackup::query()->with('deliveries')->latest('created_for_backup_at')->limit(25)->get(),
            'configured' => $configured,
            'readiness' => $readiness,
            'latestVerified' => $latest,
            'nextRun' => $this->nextRun((string) $settings->get('backups.primary.frequency')),
            'diskName' => $disk,
            'destination' => $destination,
            'credentialStatus' => app(IntegrationCredentials::class)->configured(),
            'lastConnectionTest' => $lastConnectionTest,
            'connectionTestMatchesCurrentDestination' => $connectionTestMatchesCurrentDestination,
            'appKeyConfigured' => $appKeyConfigured,
            'lastEscrowAttestation' => $lastEscrowAttestation,
            'secondaryState' => 'not_configured',
            'destinationState' => ! $configured ? 'not_configured' : ($latest ? ($storageAvailable ? 'healthy' : 'unavailable') : 'configured'),
        ]);
    }

    public function update(Request $request, OwnerSettings $settings, PlatformBackupStorage $platformStorage): RedirectResponse
    {
        $input = (array) $request->input('settings', []);
        try {
            $validated = $settings->validateGroup('backups', $input);
            Validator::make($request->only('confirmation', 'warning_ack'), [
                'confirmation' => ['nullable', 'string', 'max:64'],
                'warning_ack' => ['nullable', 'boolean'],
            ])->validate();
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput([]);
        }

        if ($validated['backups.secondary.enabled'] === true) {
            return back()->withErrors(['settings' => __('owner.platform_backups.drive_unavailable')])->withInput([]);
        }
        $before = $settings->group('backups');
        $destinationSettingIds = [
            'backups.primary.storage_driver',
            'backups.primary.endpoint',
            'backups.primary.region',
            'backups.primary.bucket',
            'backups.primary.path_style',
        ];
        $destinationChanged = collect($destinationSettingIds)->contains(
            fn (string $id): bool => array_key_exists($id, $validated) && $before[$id] !== $validated[$id],
        );
        if ($destinationChanged && $before['backups.primary.enabled']) {
            return back()->withErrors(['settings' => __('owner.platform_backups.destination_must_be_disabled')])->withInput([]);
        }
        if ($destinationChanged && PlatformDatabaseBackup::query()->exists()) {
            return back()->withErrors(['settings' => __('owner.platform_backups.destination_immutable')])->withInput([]);
        }
        if (! $before['backups.primary.enabled'] && $validated['backups.primary.enabled']) {
            if (! $this->primaryConfigured($platformStorage, $validated)) {
                return back()->withErrors(['settings' => __('owner.platform_backups.not_ready')])->withInput([]);
            }
        }
        if ($before['backups.primary.enabled'] && ! $validated['backups.primary.enabled']) {
            abort_unless($request->input('confirmation') === 'DISABLE PLATFORM BACKUPS' && $request->boolean('warning_ack'), 422, __('owner.platform_backups.disable_confirmation_required'));
        }
        if (! $before['backups.app_key_escrow_attested'] && $validated['backups.app_key_escrow_attested']) {
            abort_unless(filled(config('app.key')), 422, __('owner.platform_backups.app_key_missing'));
            abort_unless($request->input('confirmation') === 'APP KEY ESCROW CONFIRMED', 422, __('owner.platform_backups.escrow_confirmation_required'));
        }

        $settings->updateGroup('backups', $validated, $request->user('owner'));
        /** @var User $actor */
        $actor = $request->user('owner');
        if ($before['backups.primary.enabled'] && ! $validated['backups.primary.enabled']) {
            AdminAudit::record($actor, 'platform_backup.disabled', 'platform_backup_policy', 'primary', ['setting' => 'enabled', 'artifacts_preserved' => true]);
        }
        if (! $before['backups.app_key_escrow_attested'] && $validated['backups.app_key_escrow_attested']) {
            AdminAudit::record($actor, 'platform_backup.escrow_attested', 'platform_backup_policy', 'app_key', ['attested' => true]);
        } elseif ($before['backups.app_key_escrow_attested'] && ! $validated['backups.app_key_escrow_attested']) {
            AdminAudit::record($actor, 'platform_backup.escrow_revoked', 'platform_backup_policy', 'app_key', ['attested' => false]);
        }
        AdminAudit::record($actor, 'platform_backup.configuration_updated', 'platform_backup_policy', 'settings', ['changed' => true]);

        return to_route('owner.platform-backups.index')->with('status', __('owner.platform_backups.saved'));
    }

    public function run(Request $request, PlatformBackupStorage $platformStorage): RedirectResponse
    {
        abort_unless($request->input('confirmation') === 'RUN PLATFORM BACKUP', 422, __('owner.platform_backups.run_confirmation_required'));
        // The persisted Owner setting is authoritative. A long-lived process may
        // have stale config after an operator changes the schedule.
        abort_unless((bool) app(OwnerSettings::class)->get('backups.primary.enabled'), 409, __('owner.platform_backups.disabled'));
        abort_unless($this->primaryConfigured($platformStorage), 409, __('owner.platform_backups.not_ready'));
        abort_unless(config('queue.connections.'.config('queue.default').'.driver') !== 'sync', 409, __('owner.platform_backups.async_queue_required'));
        PlatformDatabaseBackupJob::dispatch()->onQueue((string) config('queue.connections.'.config('queue.default').'.queue', 'default'));
        /** @var User $actor */
        $actor = $request->user('owner');
        AdminAudit::record($actor, 'platform_backup.manual_requested', 'platform_backup_policy', 'primary', ['queued' => true]);

        return to_route('owner.platform-backups.index')->with('status', __('owner.platform_backups.requested'));
    }

    public function testConnection(Request $request, PlatformBackupStorage $platformStorage): RedirectResponse
    {
        abort_unless($platformStorage->configured(), 409, __('owner.platform_backups.not_ready'));
        $configuration = $platformStorage->configuration();
        $endpointHost = parse_url((string) $configuration['endpoint'], PHP_URL_HOST);
        $result = 'passed';
        $errorCode = null;
        try {
            $platformStorage->testConnection();
        } catch (\Throwable $error) {
            $result = 'failed';
            $errorCode = method_exists($error, 'getAwsErrorCode') ? $error->getAwsErrorCode() : class_basename($error);
            if (! is_string($errorCode) || ! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $errorCode)) {
                $errorCode = 'provider_error';
            }
        }

        /** @var User $actor */
        $actor = $request->user('owner');
        AdminAudit::record($actor, 'platform_backup.s3_connection_tested', 'platform_backup_destination', 'primary', [
            'result' => $result,
            'error_code' => $errorCode,
            'bucket' => $configuration['bucket'],
            'endpoint_host' => is_string($endpointHost) ? $endpointHost : null,
            'region' => $configuration['region'],
            'path_style' => $configuration['use_path_style_endpoint'],
        ]);

        return to_route('owner.platform-backups.index')->with(
            $result === 'passed' ? 'status' : 'error',
            $result === 'passed' ? __('owner.platform_backups.connection_passed') : __('owner.platform_backups.connection_failed'),
        );
    }

    private function nextRun(string $frequency): string
    {
        $now = now('UTC');
        $next = match ($frequency) {
            '6h' => $now->copy()->startOfDay()->addHours((intdiv($now->hour, 6) + 1) * 6),
            '12h' => $now->copy()->startOfDay()->addHours((intdiv($now->hour, 12) + 1) * 12),
            'daily' => $now->copy()->addDay()->startOfDay(),
            'weekly' => $now->copy()->next('monday')->startOfDay(),
            default => $now->copy()->addHours(12),
        };

        return $next->toIso8601String();
    }

    /** @param array<string, mixed> $settingsOverride */
    private function primaryConfigured(PlatformBackupStorage $storage, array $settingsOverride = []): bool
    {
        $keyId = (string) config('platform_backup.encryption.current_key_id');
        $key = config('platform_backup.encryption.keys.'.$keyId);

        $decodedKey = is_string($key) ? base64_decode($key, true) : false;

        return $storage->configured($settingsOverride)
            && $storage->driver($settingsOverride) === 's3'
            && filled($keyId)
            && is_string($decodedKey)
            && strlen($decodedKey) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
    }
}
