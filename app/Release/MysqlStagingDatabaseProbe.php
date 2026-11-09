<?php

namespace App\Release;

use Illuminate\Support\Facades\DB;

/** Live, read-only identity queries. Any failure is "unknown", never a guess. */
final class MysqlStagingDatabaseProbe implements StagingDatabaseProbe
{
    public function currentDatabase(): ?string
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                return null;
            }
            $row = DB::selectOne('select database() as name');
            $name = is_object($row) ? ($row->name ?? null) : null;

            return is_string($name) && $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function serverUuidSha256(): ?string
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                return null;
            }
            $row = DB::selectOne('select sha2(@@server_uuid, 256) as hash');
            $hash = is_object($row) ? ($row->hash ?? null) : null;

            return is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1 ? $hash : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
