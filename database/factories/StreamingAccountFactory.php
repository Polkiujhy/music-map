<?php

namespace Database\Factories;

use App\Enums\StreamingProvider;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StreamingAccount>
 */
class StreamingAccountFactory extends Factory
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
            'provider' => StreamingProvider::Spotify,
            'provider_account_id' => fake()->unique()->uuid(),
            'label' => fake()->userName(),
            'market' => 'GB',
            'scopes' => ['streaming-account-canary'],
            'refresh_token' => 'canary-spotify-refresh-token',
            'reauthorization_due_at' => null,
            'credential_version' => 1,
        ];
    }

    public function spotify(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => StreamingProvider::Spotify,
            'refresh_token' => 'canary-spotify-refresh-token',
        ]);
    }

    public function youtube(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => StreamingProvider::YouTube,
            'market' => null,
            'refresh_token' => 'canary-youtube-refresh-token',
        ]);
    }

    public function reconnectRequired(): static
    {
        return $this->state(fn (array $attributes) => [
            'refresh_token' => null,
        ]);
    }
}
