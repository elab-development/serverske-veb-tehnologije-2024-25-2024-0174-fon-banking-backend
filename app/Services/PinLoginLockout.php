<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

class PinLoginLockout
{
    public function isLocked(string $deviceIdentifier): bool
    {
        return RateLimiter::tooManyAttempts($this->key($deviceIdentifier), $this->maxAttempts());
    }

    public function recordFailure(string $deviceIdentifier): void
    {
        RateLimiter::hit($this->key($deviceIdentifier), $this->decaySeconds());
    }

    public function clear(string $deviceIdentifier): void
    {
        RateLimiter::clear($this->key($deviceIdentifier));
    }

    public function retryAfter(string $deviceIdentifier): int
    {
        return RateLimiter::availableIn($this->key($deviceIdentifier));
    }

    private function key(string $deviceIdentifier): string
    {
        return 'pin-login-lockout:'.hash('sha256', $deviceIdentifier);
    }

    private function maxAttempts(): int
    {
        return (int) config('auth.pin_login.max_attempts', 5);
    }

    private function decaySeconds(): int
    {
        return (int) config('auth.pin_login.decay_seconds', 900);
    }
}
