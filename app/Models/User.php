<?php

namespace App\Models;

use App\Operator\Permission;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'operator_permissions'])]
/**
 * A login. The commercial side (billing, subscriptions, websites) lives on
 * Customer. Accounts are created after a confirmed payment and activated by
 * the customer setting a password, which also verifies the email address.
 *
 * Internal operators are logins with role "operator", no customer, and an
 * explicit permission list (never mass-assignable; set by operator:user).
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_OPERATOR = 'operator';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'operator_permissions' => 'array',
        ];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function isOperator(): bool
    {
        return $this->role === self::ROLE_OPERATOR;
    }

    /** Only operators have permissions, and only those explicitly granted. */
    public function hasOperatorPermission(Permission $permission): bool
    {
        return $this->isOperator() && in_array($permission->value, $this->operator_permissions ?? [], true);
    }

    /** @return list<Permission> */
    public function operatorPermissions(): array
    {
        return $this->isOperator()
            ? array_values(array_filter(array_map(fn ($value) => is_string($value) ? Permission::tryFrom($value) : null, $this->operator_permissions ?? [])))
            : [];
    }
}
