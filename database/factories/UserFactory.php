<?php

namespace Database\Factories;

use App\Models\User;
use App\Operator\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An internal operator with exactly these permissions (none by default).
     *
     * @param  list<Permission>  $permissions
     */
    public function operator(array $permissions = []): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill([
            'role' => User::ROLE_OPERATOR,
            'operator_permissions' => array_map(fn (Permission $p) => $p->value, $permissions),
        ]));
    }

    /** An owner login (business administration). */
    public function owner(): static
    {
        return $this->afterMaking(fn (User $user) => $user->forceFill(['role' => User::ROLE_OWNER]));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
