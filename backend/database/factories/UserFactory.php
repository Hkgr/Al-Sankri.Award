<?php

namespace Database\Factories;

use App\Auth\CandidateRole;
use App\Models\User;
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
            'status' => User::STATUS_ACTIVE,
        ];
    }

    /**
     * Indicate that the account has been disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
        ]);
    }

    /**
     * Indicate that the account has no local password (Google sign-up).
     */
    public function passwordless(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ]);
    }

    /**
     * Give the account the «مترشح» classification, as registration does.
     */
    public function candidate(): static
    {
        return $this->afterCreating(fn (User $user) => app(CandidateRole::class)->grant($user, CandidateRole::SOURCE_REGISTRATION));
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
