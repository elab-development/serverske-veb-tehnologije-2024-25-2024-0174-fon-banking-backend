<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use JsonException;
use LogicException;

class PinConfirmationGrant
{
    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(int $userId, int $accessTokenId, string $purpose): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresIn = $this->ttl();

        Cache::put($this->key($token), json_encode([
            'user_id' => $userId,
            'access_token_id' => $accessTokenId,
            'purpose' => $purpose,
        ], JSON_THROW_ON_ERROR), $expiresIn);

        return ['token' => $token, 'expires_in' => $expiresIn];
    }

    public function consume(string $token, int $userId, int $accessTokenId, string $purpose): bool
    {
        $lock = Cache::lock($this->lockKey($token), 10);

        if (! $lock->get()) {
            return false;
        }

        try {
            $payload = $this->decode(Cache::get($this->key($token)));

            if ($payload === null
                || $payload['user_id'] !== $userId
                || $payload['access_token_id'] !== $accessTokenId
                || $payload['purpose'] !== $purpose) {
                return false;
            }

            Cache::forget($this->key($token));

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{user_id: int, access_token_id: int, purpose: string}|null
     */
    private function decode(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $payload = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ! is_int($payload['user_id'] ?? null)
            || ! is_int($payload['access_token_id'] ?? null)
            || ! is_string($payload['purpose'] ?? null)) {
            return null;
        }

        return $payload;
    }

    private function ttl(): int
    {
        $ttl = (int) config('auth.pin_confirmation.ttl', 300);

        if ($ttl < 1) {
            throw new LogicException('PIN confirmation grant TTL must be positive.');
        }

        return $ttl;
    }

    private function key(string $token): string
    {
        return 'pin-confirmation-grant:'.$token;
    }

    private function lockKey(string $token): string
    {
        return 'pin-confirmation-grant-lock:'.$token;
    }
}
