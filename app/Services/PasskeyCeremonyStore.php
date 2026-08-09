<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use JsonException;
use LogicException;

class PasskeyCeremonyStore
{
    public function store(
        string $type,
        string $options,
        int $userId,
        ?int $deviceId = null,
        ?int $accessTokenId = null,
    ): string {
        $id = bin2hex(random_bytes(32));

        Cache::put($this->key($id), json_encode([
            'type' => $type,
            'options' => $options,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'access_token_id' => $accessTokenId,
        ], JSON_THROW_ON_ERROR), $this->ttl());

        return $id;
    }

    /**
     * @return array{type: string, options: string, user_id: int, device_id: int|null, access_token_id: int|null}|null
     */
    public function consume(string $id, string $expectedType): ?array
    {
        $lock = Cache::lock($this->lockKey($id), 10);

        if (! $lock->get()) {
            return null;
        }

        try {
            $payload = $this->decode(Cache::get($this->key($id)));

            if ($payload === null || $payload['type'] !== $expectedType) {
                return null;
            }

            // Remove the challenge before WebAuthn verification can begin.
            Cache::forget($this->key($id));

            return $payload;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{type: string, options: string, user_id: int, device_id: int|null, access_token_id: int|null}|null
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
            || ! is_string($payload['type'] ?? null)
            || ! is_string($payload['options'] ?? null)
            || ! is_int($payload['user_id'] ?? null)
            || ! array_key_exists('device_id', $payload)
            || ! (is_int($payload['device_id']) || $payload['device_id'] === null)
            || ! array_key_exists('access_token_id', $payload)
            || ! (is_int($payload['access_token_id']) || $payload['access_token_id'] === null)) {
            return null;
        }

        return $payload;
    }

    private function ttl(): int
    {
        $ttl = (int) config('passkeys.ceremony_ttl', 120);

        if ($ttl < 1) {
            throw new LogicException('Passkey ceremony TTL must be positive.');
        }

        return $ttl;
    }

    private function key(string $id): string
    {
        return 'passkey-ceremony:'.$id;
    }

    private function lockKey(string $id): string
    {
        return 'passkey-ceremony-lock:'.$id;
    }
}
