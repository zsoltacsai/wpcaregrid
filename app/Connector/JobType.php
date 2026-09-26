<?php

namespace App\Connector;

use App\Backups\BackupReason;
use App\Backups\BackupType;
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
    case BackupFiles = 'BACKUP_FILES';
    case BackupDatabase = 'BACKUP_DATABASE';
    case BackupFull = 'BACKUP_FULL';
    case Restore = 'RESTORE';
    // Delivered only in answer to a claim from the browser's request on the site, never by polling.
    case CreateAdminSession = 'CREATE_ADMIN_SESSION';
    // Security & Malware Engine (connector 0.4.0). Scans are read-only; the
    // others each make one reversible or verified change to one file or plugin.
    case SecurityScan = 'SECURITY_SCAN';
    case SecurityQuarantine = 'SECURITY_QUARANTINE';
    case SecurityRestoreQuarantine = 'SECURITY_RESTORE_QUARANTINE';
    case SecurityRepairFile = 'SECURITY_REPAIR_FILE';
    case SecurityDisablePlugin = 'SECURITY_DISABLE_PLUGIN';

    /** A path relative to the WordPress root: a root file, or inside wp-admin, wp-includes or wp-content. */
    public const SITE_PATH = '/^(?:(?:wp-admin|wp-includes|wp-content)\/[A-Za-z0-9._\-\/@+~ ]{1,480}|[A-Za-z0-9._-]{1,191})$/';

    /** No "." or ".." segments and no empty segments: never outside the site. */
    public const SITE_PATH_UNSAFE = '/(^|\/)\.{1,2}(\/|$)|\/\/|\/$/';

    /** @return list<string> */
    public static function sitePathRules(): array
    {
        return ['required', 'string', 'regex:'.self::SITE_PATH, 'not_regex:'.self::SITE_PATH_UNSAFE];
    }

    /** @param  array<string, mixed>  $payload  RESTORE needs the capability of its scope. */
    public function requiredCapability(array $payload = []): Capability
    {
        return match ($this) {
            self::GetInventory => Capability::Inventory,
            self::SiteHealth => Capability::Health,
            self::LicenseCheck => Capability::LicenseCheck,
            self::UpdatePlugin, self::UpdateTheme, self::UpdateCore => Capability::Updates,
            self::BackupFiles => Capability::BackupFiles,
            self::BackupDatabase => Capability::BackupDatabase,
            self::BackupFull => Capability::BackupFull,
            self::Restore => Capability::tryFrom('restore_'.($payload['scope'] ?? '')) ?? Capability::RestoreFull,
            self::CreateAdminSession => Capability::AdminAccess,
            self::SecurityScan => Capability::SecurityScan,
            self::SecurityQuarantine, self::SecurityRestoreQuarantine => Capability::SecurityQuarantine,
            self::SecurityRepairFile, self::SecurityDisablePlugin => Capability::SecurityRemediate,
        };
    }

    public function changesTheSite(): bool
    {
        return in_array($this, [self::UpdatePlugin, self::UpdateTheme, self::UpdateCore, self::Restore, ...self::securityChanges()], true);
    }

    /** @return list<self> Security jobs that change a file or plugin on the site. */
    public static function securityChanges(): array
    {
        return [self::SecurityQuarantine, self::SecurityRestoreQuarantine, self::SecurityRepairFile, self::SecurityDisablePlugin];
    }

    public function isUpdate(): bool
    {
        return in_array($this, [self::UpdatePlugin, self::UpdateTheme, self::UpdateCore], true);
    }

    public function isBackup(): bool
    {
        return in_array($this, [self::BackupFiles, self::BackupDatabase, self::BackupFull], true);
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
        // Set by the server when a verified backup protects the update.
        $protectingBackup = ['nullable', 'uuid'];
        $backup = [
            'backup_uuid' => ['required', 'uuid'],
            'reason' => ['required', Rule::enum(BackupReason::class)],
            'chunk_size' => ['required', 'integer', 'min:1048576', 'max:'.config('backup.upload.max_chunk_size')],
        ];

        $rules = match ($this) {
            self::GetInventory, self::SiteHealth, self::LicenseCheck => [],
            // "akismet/akismet.php" or "hello.php": the plugin basename WordPress uses.
            self::UpdatePlugin => [
                'plugin' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/', 'not_regex:/\.\./'],
                'target_version' => $version,
                'backup_uuid' => $protectingBackup,
            ],
            self::UpdateTheme => [
                'stylesheet' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/', 'not_regex:/\.\./'],
                'target_version' => $version,
                'backup_uuid' => $protectingBackup,
            ],
            self::UpdateCore => [
                'policy' => ['required', Rule::in(['minor', 'major'])],
                'target_version' => $version,
                'backup_uuid' => $protectingBackup,
            ],
            self::BackupFiles, self::BackupDatabase, self::BackupFull => $backup,
            // The objects and checksums of the backup to restore; the connector
            // checks them against its own record of the backups it made.
            self::CreateAdminSession => [
                'request_uuid' => ['required', 'uuid'],
                'access_level' => ['required', Rule::in(['maintenance', 'full_admin'])],
                'website_uuid' => ['nullable', 'uuid'],
                'session_expires_at' => ['nullable', 'date'],
            ],
            self::SecurityScan => [
                'scan_uuid' => ['required', 'uuid'],
                'scan_type' => ['required', Rule::in(['integrity', 'malware', 'full'])],
                'part' => ['required', 'integer', 'min:1', 'max:'.(int) config('security.scan.max_parts')],
                'time_budget' => ['required', 'integer', 'min:10', 'max:600'],
            ],
            // The site checks the file's current hash before touching it, so a
            // stale or replayed job never acts on a different file.
            self::SecurityQuarantine => [
                'remediation_uuid' => ['required', 'uuid'],
                'finding_uuid' => ['required', 'uuid'],
                'path' => self::sitePathRules(),
                'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            ],
            self::SecurityRestoreQuarantine => [
                'remediation_uuid' => ['required', 'uuid'],
                'quarantine_uuid' => ['required', 'uuid'],
                'path' => self::sitePathRules(),
                'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            ],
            // The clean copy comes from WordPress.org for the installed version,
            // fetched and verified by the site: no URL, no content from the server.
            self::SecurityRepairFile => [
                'remediation_uuid' => ['required', 'uuid'],
                'source' => ['required', Rule::in(['core', 'plugin'])],
                'plugin' => ['required_if:source,plugin', 'nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+\.php$/', 'not_regex:/\.\./'],
                'path' => self::sitePathRules(),
                'sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            ],
            self::SecurityDisablePlugin => [
                'remediation_uuid' => ['required', 'uuid'],
                'plugin' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)?\.php$/', 'not_regex:/\.\./'],
            ],
            self::Restore => [
                'restore_uuid' => ['required', 'uuid'],
                'backup_uuid' => ['required', 'uuid'],
                'scope' => ['required', Rule::enum(BackupType::class)],
                'objects' => ['required', 'array', 'min:1', 'max:2'],
                'objects.*.name' => ['required', Rule::in(['files', 'database'])],
                'objects.*.size' => ['required', 'integer', 'min:1'],
                'objects.*.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            ],
        };

        $unknown = array_diff(array_keys($payload), array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.')));
        foreach ((array) ($payload['objects'] ?? []) as $i => $object) {
            foreach (is_array($object) ? array_diff(array_keys($object), ['name', 'size', 'sha256']) : [] as $key) {
                $unknown[] = "objects.{$i}.{$key}";
            }
        }

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
