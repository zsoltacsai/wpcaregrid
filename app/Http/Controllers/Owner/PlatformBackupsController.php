<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Jobs\PlatformDatabaseBackupJob;
use App\Models\PlatformDatabaseBackup;
use App\Models\User;
use App\Owner\AdminAudit;
use App\Owner\OwnerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlatformBackupsController extends Controller
{
    public function index(OwnerSettings $settings): View
    {
        $disk = (string) config('platform_backup.disk');
        $driver = (string) config("filesystems.disks.{$disk}.driver");
        $configured = $this->primaryConfigured($disk, $driver);
        $latest = PlatformDatabaseBackup::query()->where('status', 'verified')->latest('verified_at')->first();
        $ageHours = $latest?->verified_at?->diffInHours(now());
        $storageAvailable = false;
        if ($configured && $latest) {
            try {
                $storageAvailable = Storage::disk($disk)->exists($latest->object_key);
            } catch (\Throwable) {
                $storageAvailable = false;
            }
        }
        $readiness = ! $configured ? 'not_configured' : match (true) {
            ! $latest => 'stale',
            $ageHours >= 24 => 'stale',
            ! $storageAvailable => 'unavailable',
            ! (bool) $settings->get('backups.app_key_escrow_attested') => 'degraded',
            default => 'healthy',
        };

        return view('owner.platform-backups.index', [
            'settings' => $settings->group('backups'),
            'backups' => PlatformDatabaseBackup::query()->with('deliveries')->latest('created_for_backup_at')->limit(25)->get(),
            'configured' => $configured,
            'readiness' => $readiness,
            'latestVerified' => $latest,
            'nextRun' => $this->nextRun((string) $settings->get('backups.primary.frequency')),
            'diskName' => $disk,
            'secondaryState' => 'not_configured',
            'destinationState' => ! $configured ? 'not_configured' : ($storageAvailable ? 'healthy' : 'unavailable'),
        ]);
    }

    public function update(Request $request, OwnerSettings $settings): RedirectResponse
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
        if (! $before['backups.primary.enabled'] && $validated['backups.primary.enabled']) {
            $disk = (string) config('platform_backup.disk');
            if (! $this->primaryConfigured($disk, (string) config("filesystems.disks.{$disk}.driver"))) {
                return back()->withErrors(['settings' => __('owner.platform_backups.not_ready')])->withInput([]);
            }
        }
        if ($before['backups.primary.enabled'] && ! $validated['backups.primary.enabled']) {
            abort_unless($request->input('confirmation') === 'DISABLE PLATFORM BACKUPS' && $request->boolean('warning_ack'), 422, __('owner.platform_backups.disable_confirmation_required'));
        }
        if (! $before['backups.app_key_escrow_attested'] && $validated['backups.app_key_escrow_attested']) {
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
        }
        AdminAudit::record($actor, 'platform_backup.configuration_updated', 'platform_backup_policy', 'settings', ['changed' => true]);

        return to_route('owner.platform-backups.index')->with('status', __('owner.platform_backups.saved'));
    }

    public function run(Request $request): RedirectResponse
    {
        abort_unless($request->input('confirmation') === 'RUN PLATFORM BACKUP', 422, __('owner.platform_backups.run_confirmation_required'));
        // The persisted Owner setting is authoritative. A long-lived process may
        // have stale config after an operator changes the schedule.
        abort_unless((bool) app(OwnerSettings::class)->get('backups.primary.enabled'), 409, __('owner.platform_backups.disabled'));
        $disk = (string) config('platform_backup.disk');
        abort_unless($this->primaryConfigured($disk, (string) config("filesystems.disks.{$disk}.driver")), 409, __('owner.platform_backups.not_ready'));
        abort_unless(config('queue.connections.'.config('queue.default').'.driver') !== 'sync', 409, __('owner.platform_backups.async_queue_required'));
        PlatformDatabaseBackupJob::dispatch()->onQueue((string) config('queue.connections.'.config('queue.default').'.queue', 'default'));
        /** @var User $actor */
        $actor = $request->user('owner');
        AdminAudit::record($actor, 'platform_backup.manual_requested', 'platform_backup_policy', 'primary', ['queued' => true]);

        return to_route('owner.platform-backups.index')->with('status', __('owner.platform_backups.requested'));
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

    private function primaryConfigured(string $disk, string $driver): bool
    {
        $keyId = (string) config('platform_backup.encryption.current_key_id');
        $key = config('platform_backup.encryption.keys.'.$keyId);

        $decodedKey = is_string($key) ? base64_decode($key, true) : false;

        return $driver === 's3'
            && filled(config("filesystems.disks.{$disk}.bucket"))
            && filled(config("filesystems.disks.{$disk}.key"))
            && filled(config("filesystems.disks.{$disk}.secret"))
            && filled($keyId)
            && is_string($decodedKey)
            && strlen($decodedKey) === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES;
    }
}
