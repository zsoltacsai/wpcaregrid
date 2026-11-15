<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TRIGGERS = [
        'transition_v2_requests_immutable_insert',
        'transition_v2_requests_immutable_update',
        'transition_v2_requests_immutable_delete',
        'transition_history_events_immutable_insert',
        'transition_history_events_immutable_update',
        'transition_history_events_immutable_delete',
    ];

    public function up(): void
    {
        $connectionName = $this->getConnection() ?: DB::getDefaultConnection();
        $connection = DB::connection($connectionName);
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $expectedDefiner = config('database.connections.'.$connectionName.'.phase2b_trigger_definer');
        if (! is_string($expectedDefiner) || trim($expectedDefiner) === '') {
            throw new RuntimeException('The Phase 2B trigger definer is not configured.');
        }

        $triggers = $connection->table('information_schema.triggers')
            ->selectRaw('TRIGGER_NAME AS trigger_name, DEFINER AS trigger_definer')
            ->where('trigger_schema', DB::raw('DATABASE()'))
            ->whereIn('trigger_name', self::TRIGGERS)
            ->get();

        if ($triggers->count() !== count(self::TRIGGERS)) {
            throw new RuntimeException('The Phase 2B trigger inventory is incomplete.');
        }

        foreach ($triggers as $trigger) {
            if ($trigger->trigger_definer !== $expectedDefiner) {
                throw new RuntimeException('A Phase 2B trigger has an unexpected DEFINER.');
            }
        }
    }

    public function down(): void
    {
        // This migration records a verification gate and has no reversible schema change.
    }
};
