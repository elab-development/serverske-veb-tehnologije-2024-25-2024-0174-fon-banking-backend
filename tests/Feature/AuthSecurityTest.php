<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\PinConfirmationGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_active_user_cannot_access_sanctum_routes(): void
    {
        $user = $this->createUser('pending_pin');
        $token = $user->createToken('test-device')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/accounts')
            ->assertForbidden()
            ->assertJsonPath('message', 'Nalog nije aktivan.');
    }

    public function test_changing_status_to_blocked_revokes_existing_tokens(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test-device')->plainTextToken;

        $user->update(['status' => 'blocked']);

        $this->assertCount(0, $user->tokens()->get());
        $this->withToken($token)
            ->getJson('/api/v1/accounts')
            ->assertUnauthorized();
    }

    public function test_blocked_user_cannot_log_in_with_pin(): void
    {
        $user = $this->createUser('blocked');
        $device = $this->createDevice($user);

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '1234',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Nalog nije aktivan.');

        $this->assertCount(0, $user->tokens()->get());
    }

    public function test_repeated_invalid_pin_attempts_lock_device_beyond_minute_throttle(): void
    {
        $user = $this->createUser();
        $device = $this->createDevice($user);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/login', [
                'device_identifier' => $device->device_identifier,
                'pin' => '9999',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '9999',
        ])->assertTooManyRequests()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['retry_after']);

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '1234',
        ])->assertTooManyRequests();

        $this->travel(15)->minutes();

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '1234',
        ])->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_successful_login_clears_previous_pin_failures(): void
    {
        $user = $this->createUser();
        $device = $this->createDevice($user);

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '9999',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/login', [
            'device_identifier' => $device->device_identifier,
            'pin' => '1234',
        ])->assertOk();

        $this->travel(61)->seconds();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/v1/login', [
                'device_identifier' => $device->device_identifier,
                'pin' => '9999',
            ])->assertUnauthorized();
        }
    }

    public function test_pin_confirmation_route_is_rate_limited(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test-device')->plainTextToken;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withToken($token)
                ->postJson('/api/v1/auth/confirm-pin', ['pin' => '9999'])
                ->assertUnauthorized();
        }

        $this->withToken($token)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '9999'])
            ->assertTooManyRequests();
    }

    public function test_pin_confirmation_issues_a_single_use_token_bound_grant(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test-device');

        $response = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('expires_in', 300);

        $confirmationToken = $response->json('confirmation_token');
        $grant = app(PinConfirmationGrant::class);

        $this->assertIsString($confirmationToken);
        $this->assertTrue($grant->consume($confirmationToken, $user->id, $token->accessToken->id, 'sensitive-action'));
        $this->assertFalse($grant->consume($confirmationToken, $user->id, $token->accessToken->id, 'sensitive-action'));
    }

    public function test_pin_confirmation_grant_cannot_be_redeemed_by_another_bearer_token(): void
    {
        $user = $this->createUser();
        $issuingToken = $user->createToken('issuing-device');
        $otherToken = $user->createToken('other-device');

        $confirmationToken = $this->withToken($issuingToken->plainTextToken)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '1234'])
            ->assertOk()
            ->json('confirmation_token');

        $grant = app(PinConfirmationGrant::class);

        $this->assertFalse($grant->consume($confirmationToken, $user->id, $otherToken->accessToken->id, 'sensitive-action'));
        $this->assertTrue($grant->consume($confirmationToken, $user->id, $issuingToken->accessToken->id, 'sensitive-action'));
    }

    public function test_pin_confirmation_grant_expires_after_five_minutes(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('test-device');

        $confirmationToken = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '1234'])
            ->assertOk()
            ->json('confirmation_token');

        $this->travel(301)->seconds();

        $this->assertFalse(app(PinConfirmationGrant::class)->consume(
            $confirmationToken,
            $user->id,
            $token->accessToken->id,
            'sensitive-action',
        ));
    }

    private function createUser(string $status = 'active'): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'first_name' => 'Security',
            'last_name' => 'Test',
            'jmbg' => str_pad((string) $sequence, 13, '0', STR_PAD_LEFT),
            'phone_number' => '+38164'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
            'email' => "security{$sequence}@example.com",
            'pin_hash' => Hash::make('1234'),
            'status' => $status,
        ]);
    }

    private function createDevice(User $user): Device
    {
        return Device::create([
            'user_id' => $user->id,
            'device_identifier' => 'trusted-device-'.$user->id,
            'device_name' => 'Test Phone',
            'is_trusted' => true,
        ]);
    }
}
