<?php

namespace App\Services;

use App\Models\Device;
use Laravel\Sanctum\NewAccessToken;

class DeviceTokenIssuer
{
    public function issue(Device $device): NewAccessToken
    {
        $user = $device->user;
        $name = 'device:'.$device->getKey();

        $user->tokens()->whereIn('name', [$name, $device->device_identifier])->delete();

        $ttl = (int) config('auth.device_token.ttl_minutes', 43200);
        $expiresAt = $ttl > 0 ? now()->addMinutes($ttl) : null;

        return $user->createToken($name, ['*'], $expiresAt);
    }
}
