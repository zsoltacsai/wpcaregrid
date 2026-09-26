<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Operator\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create operators and set their permissions. There is no UI for this on
 * purpose: granting operator access is a server-side administrative act.
 *
 *   php artisan operator:user ops@wpcaregrid.com --name="Ops" --standard
 *   php artisan operator:user ops@wpcaregrid.com --grant=admin_access.full
 *   php artisan operator:user ops@wpcaregrid.com --revoke=backups.restore
 *   php artisan operator:user ops@wpcaregrid.com --disable
 */
class ManageOperator extends Command
{
    protected $signature = 'operator:user
        {email : Operator login email}
        {--name= : Display name for a new operator}
        {--grant=* : Permission to grant (repeatable)}
        {--revoke=* : Permission to revoke (repeatable)}
        {--standard : Grant every permission except admin_access.full}
        {--disable : Remove operator access from this login}';

    protected $description = 'Create an operator login or change its permissions';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $unknown = array_diff([...$this->option('grant'), ...$this->option('revoke')], Permission::values());

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $unknown !== []) {
            $this->error('Invalid email or unknown permission. Permissions: '.implode(', ', Permission::values()));

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user?->customer()->exists()) {
            $this->error('This login belongs to a customer. Operators need their own login.');

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $user?->forceFill(['role' => null, 'operator_permissions' => null])->save();
            $this->info($user ? "Operator access removed from {$email}." : "No login {$email}.");

            return self::SUCCESS;
        }

        $password = null;
        if (! $user) {
            $password = Str::password(24);
            $user = new User(['name' => $this->option('name') ?: $email, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $permissions = collect($user->isOperator() ? $user->operator_permissions ?? [] : [])
            ->merge($this->option('standard') ? array_map(fn (Permission $p) => $p->value, Permission::standard()) : [])
            ->merge($this->option('grant'))
            ->diff($this->option('revoke'))
            ->unique()->sort()->values()->all();

        $user->forceFill(['role' => User::ROLE_OPERATOR, 'operator_permissions' => $permissions])->save();

        $this->info("Operator {$email}: ".($permissions ? implode(', ', $permissions) : 'no permissions'));
        if ($password) {
            // Shown once, never stored in plain text or logged.
            $this->warn("Initial password (shown once): {$password}");
        }

        return self::SUCCESS;
    }
}
