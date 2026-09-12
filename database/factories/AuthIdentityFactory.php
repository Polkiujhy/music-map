<?php

namespace Database\Factories;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthIdentity>
 */
class AuthIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => fake()->unique()->uuid(),
        ];
    }
}
