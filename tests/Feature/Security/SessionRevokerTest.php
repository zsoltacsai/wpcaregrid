<?php

namespace Tests\Feature\Security;

use App\Auth\SessionRevoker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class SessionRevokerTest extends TestCase
{
    use RefreshDatabase;

    public function test_revokes_current_and_legacy_null_user_id_sessions_across_all_session_guards(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ownedIds = [];

        foreach (['web', 'owner', 'operator'] as $guard) {
            $id = Str::random(40);
            $ownedIds[] = $id;
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $guard === 'web' ? $user->id : null,
                'payload' => base64_encode($this->serializeSession([Auth::guard($guard)->getName() => $user->getAuthIdentifier()])),
                'last_activity' => now()->timestamp,
            ]);
        }

        $unrelatedId = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $unrelatedId,
            'user_id' => null,
            'payload' => base64_encode($this->serializeSession([Auth::guard('owner')->getName() => $other->getAuthIdentifier()])),
            'last_activity' => now()->timestamp,
        ]);

        app(SessionRevoker::class)->revoke($user);

        foreach ($ownedIds as $id) {
            $this->assertDatabaseMissing('sessions', ['id' => $id]);
        }
        $this->assertDatabaseHas('sessions', ['id' => $unrelatedId]);
    }

    private function serializeSession(array $attributes): string
    {
        return config('session.serialization', 'php') === 'json'
            ? json_encode($attributes, JSON_THROW_ON_ERROR)
            : serialize($attributes);
    }

    public function test_separate_session_database_connection_fails_closed(): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'sessions']);

        $this->expectException(LogicException::class);
        app(SessionRevoker::class)->revoke(User::factory()->create());
    }

    public function test_unsupported_persistent_session_driver_fails_closed(): void
    {
        config(['session.driver' => 'file']);

        $this->expectException(LogicException::class);
        app(SessionRevoker::class)->revoke(User::factory()->create());
    }
}
