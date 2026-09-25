<?php

namespace App\Backups;

use App\Connector\ConnectorError;
use App\Models\Backup;
use App\Models\BackupObject;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The manifest stored next to every backup. It describes what the backup
 * contains and how to check it, and nothing else: unknown keys are
 * rejected, so credentials, license keys or site data cannot end up in it.
 */
class BackupManifest
{
    public const FORMAT = 'wp-caregrid-backup/1';

    private const OBJECT_KEYS = 'size,sha256,compression';

    /**
     * @param  array<string, mixed>  $input  From the connector.
     * @param  array<string, BackupObject>  $objects  Received objects by name.
     * @return array<string, mixed> The manifest document.
     */
    public function build(Backup $backup, array $input, array $objects): array
    {
        $hasFiles = in_array(BackupObjectName::Files->value, $backup->type->objects(), true);
        $hasDatabase = in_array(BackupObjectName::Database->value, $backup->type->objects(), true);
        $sha = ['required', 'string', 'regex:/^[a-f0-9]{64}$/'];
        $version = ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z.+-]+$/'];
        $count = ['required', 'integer', 'min:0'];

        $validator = Validator::make($input, [
            'format' => ['required', Rule::in([self::FORMAT])],
            'backup_uuid' => ['required', Rule::in([$backup->uuid])],
            'type' => ['required', Rule::in([$backup->type->value])],
            'installation_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'connector_version' => $version,
            'wordpress_version' => $version,
            'php_version' => $version,
            'created_at' => ['required', 'date'],
            'completed_at' => ['required', 'date'],
            'site' => ['required', 'array:multisite,table_prefix'],
            'site.multisite' => ['required', 'boolean'],
            'site.table_prefix' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/'],

            'files' => [$hasFiles ? 'required' : 'prohibited', 'array:'.self::OBJECT_KEYS.',format,file_count,uncompressed_size,policy,wp_config,skipped'],
            'files.size' => ['required_with:files', 'integer', 'min:1'],
            'files.sha256' => $hasFiles ? $sha : [],
            'files.compression' => ['required_with:files', Rule::in(['gzip', 'none'])],
            'files.format' => ['required_with:files', Rule::in(['ustar'])],
            'files.file_count' => $hasFiles ? $count : [],
            'files.uncompressed_size' => $hasFiles ? $count : [],
            'files.policy' => ['required_with:files', 'string', 'max:32', 'regex:/^[a-z0-9.-]+$/'],
            'files.wp_config' => ['required_with:files', Rule::in(['redacted', 'excluded', 'missing'])],
            'files.skipped' => ['required_with:files', 'array:symlinks,too_large,unreadable,excluded'],
            'files.skipped.*' => ['integer', 'min:0'],

            'database' => [$hasDatabase ? 'required' : 'prohibited', 'array:'.self::OBJECT_KEYS.',engine,server_version,charset,table_prefix,tables,rows,excluded'],
            'database.size' => ['required_with:database', 'integer', 'min:1'],
            'database.sha256' => $hasDatabase ? $sha : [],
            'database.compression' => ['required_with:database', Rule::in(['gzip', 'none'])],
            'database.engine' => ['required_with:database', Rule::in(['mysql', 'mariadb'])],
            'database.server_version' => ['required_with:database', 'string', 'max:64', 'regex:/^[0-9A-Za-z.+~_-]+$/'],
            'database.charset' => ['required_with:database', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'database.table_prefix' => ['required_with:database', 'string', 'regex:/^[A-Za-z0-9_]{1,32}$/'],
            'database.tables' => $hasDatabase ? $count : [],
            'database.rows' => $hasDatabase ? $count : [],
            'database.excluded' => ['required_with:database', 'array'],
            'database.excluded.*' => [Rule::in(['transients', 'connector_state'])],
        ]);

        $unknown = array_diff(array_keys($input), ['format', 'backup_uuid', 'type', 'installation_id', 'connector_version', 'wordpress_version', 'php_version', 'created_at', 'completed_at', 'site', 'files', 'database']);
        $validator->after(fn ($v) => array_map(fn ($key) => $v->errors()->add((string) $key, 'Unknown manifest field.'), $unknown));

        if ($validator->fails()) {
            throw new ConnectorError('INVALID_MANIFEST', 'The manifest is invalid: '.implode(', ', $validator->errors()->keys()), 422);
        }

        $data = $validator->validated();
        $checksums = [];

        foreach (['files' => BackupObjectName::Files, 'database' => BackupObjectName::Database] as $key => $name) {
            if (! isset($data[$key])) {
                continue;
            }

            $object = $objects[$name->value];
            if ((int) $data[$key]['size'] !== $object->size || ! hash_equals($object->sha256, $data[$key]['sha256'])) {
                throw new ConnectorError('MANIFEST_MISMATCH', "The manifest does not match the received {$key} object.", 422);
            }

            $data[$key] = ['object' => $name->filename()] + $data[$key];
            $checksums[$name->filename()] = $object->sha256;
        }

        return [
            'format' => self::FORMAT,
            'backup_uuid' => $backup->uuid,
            'website_uuid' => $backup->website->uuid,
            'type' => $backup->type->value,
            'reason' => $backup->reason->value,
            'installation_id' => $data['installation_id'],
            'connector_version' => $data['connector_version'],
            'wordpress_version' => $data['wordpress_version'],
            'php_version' => $data['php_version'],
            'created_at' => $data['created_at'],
            'completed_at' => $data['completed_at'],
            'site' => $data['site'],
            'files' => $data['files'] ?? null,
            'database' => $data['database'] ?? null,
            'total_size' => array_sum(array_map(fn ($key) => (int) ($data[$key]['size'] ?? 0), ['files', 'database'])),
            'checksums' => ['algorithm' => 'sha256'] + $checksums,
        ];
    }
}
