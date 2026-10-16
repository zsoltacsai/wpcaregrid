<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Revoke every persisted browser session for a user across all configured guards. */
final class SessionRevoker
{
    public function revoke(User $user): void
    {
        $driver = config('session.driver');

        // PHPUnit's array store has no sessions shared across independent test requests.
        if ($driver === 'array' && app()->environment('testing')) {
            return;
        }

        if ($driver !== 'database') {
            throw new LogicException('User-wide session revocation requires the database session driver.');
        }

        $sessionConnection = config('session.connection');
        if (filled($sessionConnection) && $sessionConnection !== config('database.default')) {
            throw new LogicException('User-wide session revocation requires sessions on the application database connection.');
        }

        $table = config('session.table');
        if (! is_string($table) || ! preg_match('/\A[a-zA-Z0-9_]+\z/', $table)) {
            throw new LogicException('The configured database session table name is invalid.');
        }

        $database = DB::connection($sessionConnection);
        $database->table($table)->where('user_id', $user->getKey())->delete();

        // Laravel's database session handler attributes sessions using the default
        // guard, so Owner/Operator sessions commonly have NULL user_id. Match their
        // guard-specific session keys as well as the user_id column.
        $guardKeys = collect(array_keys(config('auth.guards', [])))
            ->filter(fn (string $guard) => (config("auth.guards.{$guard}.driver") === 'session'))
            ->mapWithKeys(fn (string $guard) => [$guard => Auth::guard($guard)->getName()]);

        if ($guardKeys->isEmpty()) {
            return;
        }

        $activeAfter = now()->subMinutes((int) config('session.lifetime', 120))->timestamp;
        $database->table($table)->select(['id', 'payload'])->whereNull('user_id')
            ->where('last_activity', '>=', $activeAfter)->chunkById(500, function ($sessions) use ($database, $table, $guardKeys, $user) {
                $matchingIds = [];
                foreach ($sessions as $session) {
                    $attributes = $this->decodePayload((string) $session->payload);
                    foreach ($guardKeys as $key) {
                        if (isset($attributes[$key]) && is_scalar($attributes[$key]) && (string) $attributes[$key] === (string) $user->getAuthIdentifier()) {
                            $matchingIds[] = $session->id;
                            break;
                        }
                    }
                }

                if ($matchingIds !== []) {
                    $database->table($table)->whereIn('id', $matchingIds)->delete();
                }
            }, 'id');
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return [];
        }

        if (config('session.encrypt', false)) {
            try {
                $raw = app('encrypter')->decryptString($raw);
            } catch (DecryptException) {
                return [];
            }
        }

        if (config('session.serialization', 'php') === 'json') {
            $attributes = json_decode($raw, true);

            return is_array($attributes) ? $attributes : [];
        }

        $attributes = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($attributes) ? $attributes : [];
    }
}
