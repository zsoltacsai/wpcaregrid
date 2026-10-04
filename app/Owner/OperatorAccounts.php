<?php

namespace App\Owner;

use App\Mail\StaffInvitation;
use App\Models\User;
use App\Operator\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Operators managed from the owner area. Operators are invited: they set
 * their own password from an emailed link; nobody else ever sees or sets
 * it. Permissions are exactly what the owner ticks, nothing is granted by
 * default. Disabling keeps the permissions but blocks sign-in at once
 * (EnsureOperator checks on every request). php artisan operator:user
 * keeps working alongside.
 */
final class OperatorAccounts
{
    /** Permissions that change sites or open them as administrator: shown with a warning, need confirming. */
    public const SENSITIVE = [Permission::BackupsRestore, Permission::SecurityRemediate, Permission::AdminAccessFull];

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws ValidationException
     */
    public function invite(string $name, string $email, array $permissions, User $owner): User
    {
        $email = mb_strtolower(trim($email));

        if (User::where('email', $email)->exists()) {
            // Customer, owner or existing operator: operators always get a login of their own.
            throw ValidationException::withMessages(['email' => __('owner.operators.errors.email_taken')]);
        }

        $operator = DB::transaction(function () use ($name, $email, $permissions, $owner) {
            // An unusable random password until the invitation is accepted; never shown or sent.
            $operator = new User(['name' => $name, 'email' => $email, 'password' => Str::password(64)]);
            $operator->forceFill([
                'role' => User::ROLE_OPERATOR,
                'operator_permissions' => self::values($permissions),
                'email_verified_at' => null,
            ])->save();

            AdminAudit::record($owner, 'operator.invited', 'operator', (string) $operator->id, ['operator' => $operator->id, 'permissions' => self::values($permissions)]);

            return $operator;
        });

        $this->sendInvitation($operator);

        return $operator;
    }

    /** @throws ValidationException */
    public function resendInvitation(User $operator, User $owner): void
    {
        if ($operator->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['invitation' => __('owner.operators.errors.already_active')]);
        }

        $this->sendInvitation($operator);
        AdminAudit::record($owner, 'operator.invitation_resent', 'operator', (string) $operator->id, ['operator' => $operator->id]);
    }

    /**
     * @param  list<Permission>  $permissions  The complete new set.
     * @return array{granted: list<string>, revoked: list<string>}
     */
    public function setPermissions(User $operator, array $permissions, User $owner): array
    {
        $before = self::values($operator->operatorPermissions());
        $after = self::values($permissions);
        $change = ['granted' => array_values(array_diff($after, $before)), 'revoked' => array_values(array_diff($before, $after))];

        if ($change['granted'] === [] && $change['revoked'] === []) {
            return $change;
        }

        DB::transaction(function () use ($operator, $after, $change, $owner) {
            $operator->forceFill(['operator_permissions' => $after])->save();
            AdminAudit::record($owner, 'operator.permissions_changed', 'operator', (string) $operator->id, ['operator' => $operator->id] + $change);
        });

        return $change;
    }

    public function disable(User $operator, User $owner): void
    {
        if ($operator->operator_disabled_at !== null) {
            return;
        }

        DB::transaction(function () use ($operator, $owner) {
            $operator->forceFill(['operator_disabled_at' => now()])->save();
            // A pending invitation stops working too.
            Password::broker('staff_invitations')->deleteToken($operator);
            AdminAudit::record($owner, 'operator.disabled', 'operator', (string) $operator->id, ['operator' => $operator->id]);
        });
    }

    public function enable(User $operator, User $owner): void
    {
        if ($operator->operator_disabled_at === null) {
            return;
        }

        DB::transaction(function () use ($operator, $owner) {
            $operator->forceFill(['operator_disabled_at' => null])->save();
            AdminAudit::record($owner, 'operator.enabled', 'operator', (string) $operator->id, ['operator' => $operator->id]);
        });
    }

    /** A new single-use link (an earlier one stops working), emailed to the operator. */
    public function sendInvitation(User $user): void
    {
        $token = Password::broker('staff_invitations')->createToken($user);
        Mail::to($user->email)->queue(new StaffInvitation($user, $token));
    }

    /**
     * @param  list<Permission>  $permissions
     * @return list<string>
     */
    private static function values(array $permissions): array
    {
        $values = array_values(array_unique(array_map(fn (Permission $p) => $p->value, $permissions)));
        sort($values);

        return $values;
    }
}
