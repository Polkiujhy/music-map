<?php

namespace App\Integrations\StreamingAccounts;

use App\Enums\StreamingProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StreamingOAuthAttemptStore
{
    private const SESSION_KEY = 'streaming_oauth.attempts';

    private const TTL_SECONDS = 600;

    private const MAX_ATTEMPTS = 5;

    public function create(
        Request $request,
        StreamingProvider $provider,
        string $purpose,
        int $userId,
    ): string {
        $this->assertPurpose($purpose);

        $state = Str::random(64);
        $attempts = $this->current($request);
        $attempts[] = [
            'state_hash' => hash('sha256', $state),
            'provider' => $provider->value,
            'purpose' => $purpose,
            'user_id' => $userId,
            'created_at' => now()->getTimestamp(),
        ];

        $request->session()->put(self::SESSION_KEY, array_slice($attempts, -self::MAX_ATTEMPTS));

        return $state;
    }

    public function consume(
        Request $request,
        string $state,
        StreamingProvider $provider,
        string $purpose,
        int $userId,
    ): bool {
        $this->assertPurpose($purpose);

        if ($state === '') {
            return false;
        }

        $attempts = $this->current($request);
        $expectedHash = hash('sha256', $state);
        $matched = false;

        foreach ($attempts as $index => $attempt) {
            if ($attempt['provider'] === $provider->value
                && $attempt['purpose'] === $purpose
                && $attempt['user_id'] === $userId
                && hash_equals($attempt['state_hash'], $expectedHash)) {
                unset($attempts[$index]);
                $matched = true;
                break;
            }
        }

        $request->session()->put(self::SESSION_KEY, array_values($attempts));

        return $matched;
    }

    /**
     * @return list<array{state_hash: string, provider: string, purpose: string, user_id: int, created_at: int}>
     */
    private function current(Request $request): array
    {
        $attempts = $request->session()->get(self::SESSION_KEY, []);

        if (! is_array($attempts)) {
            return [];
        }

        $cutoff = now()->getTimestamp() - self::TTL_SECONDS;

        return array_values(array_filter($attempts, static function (mixed $attempt) use ($cutoff): bool {
            return is_array($attempt)
                && is_string($attempt['state_hash'] ?? null)
                && is_string($attempt['provider'] ?? null)
                && is_string($attempt['purpose'] ?? null)
                && is_int($attempt['user_id'] ?? null)
                && is_int($attempt['created_at'] ?? null)
                && $attempt['created_at'] >= $cutoff;
        }));
    }

    private function assertPurpose(string $purpose): void
    {
        if (! in_array($purpose, ['link', 'reconnect'], true)) {
            throw new InvalidArgumentException('Unsupported streaming OAuth purpose.');
        }
    }
}
