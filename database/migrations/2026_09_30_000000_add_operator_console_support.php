<?php

use App\Connector\LicenseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator console (docs/OPERATOR_CONSOLE.md):
 *
 * - users: an internal operator role with explicit permissions. Operators
 *   are ordinary logins without a customer; no separate account table.
 * - website_events: who acted, as data (actor_type, actor_user_id), not
 *   only as the "customer:12" label.
 * - website_inventories: update and expired-license counts, so lists can be
 *   filtered and sorted in the database instead of in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->nullable()->after('password')->index()->comment('null = customer login | operator');
            $table->json('operator_permissions')->nullable()->after('role')->comment('Explicit operator permissions, e.g. ["websites.view"]');
        });

        Schema::table('website_events', function (Blueprint $table) {
            $table->string('actor_type', 16)->nullable()->after('actor')->index()->comment('customer | operator | system');
            $table->foreignId('actor_user_id')->nullable()->after('actor_type')->constrained('users')->nullOnDelete();
        });

        Schema::table('website_inventories', function (Blueprint $table) {
            $table->unsignedSmallInteger('updates_pending')->default(0)->after('php_version');
            $table->unsignedSmallInteger('licenses_expired')->default(0)->after('updates_pending');
        });

        // Existing rows: the actor label already says who it was.
        DB::table('website_events')->orderBy('id')->select(['id', 'actor'])->chunkById(500, function ($events) {
            foreach ($events as $event) {
                [$type, $userId] = self::parseActor((string) $event->actor);
                DB::table('website_events')->where('id', $event->id)->update([
                    'actor_type' => $type,
                    'actor_user_id' => $userId !== null && DB::table('users')->where('id', $userId)->exists() ? $userId : null,
                ]);
            }
        });

        DB::table('website_inventories')->orderBy('id')->chunkById(200, function ($inventories) {
            foreach ($inventories as $inventory) {
                $plugins = json_decode((string) $inventory->plugins, true) ?: [];
                $themes = json_decode((string) $inventory->themes, true) ?: [];
                $core = json_decode((string) $inventory->core, true) ?: [];

                DB::table('website_inventories')->where('id', $inventory->id)->update([
                    'updates_pending' => collect($plugins)->where('update_available', true)->count()
                        + collect($themes)->where('update_available', true)->count()
                        + (($core['update_available'] ?? false) === true ? 1 : 0),
                    'licenses_expired' => collect($plugins)->filter(fn ($plugin) => ($plugin['license']['status'] ?? null) === LicenseStatus::Expired->value)->count(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('website_inventories', fn (Blueprint $table) => $table->dropColumn(['updates_pending', 'licenses_expired']));
        Schema::table('website_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropIndex(['actor_type']);
            $table->dropColumn('actor_type');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'operator_permissions']);
        });
    }

    /** @return array{0: string, 1: ?int} */
    private static function parseActor(string $actor): array
    {
        if (preg_match('/^(customer|operator):(\d+)$/', $actor, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [in_array($actor, ['customer', 'operator'], true) ? $actor : 'system', null];
    }
};
