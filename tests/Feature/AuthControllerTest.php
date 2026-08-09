<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\PinEnrollmentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_issues_a_hashed_one_time_pin_enrollment_token(): void
    {
        [$user] = $this->activationFixture('LUKA-2026');

        $activation = $this->postJson('/api/v1/activate', [
            'code' => '  LUKA-2026  ',
            'device_identifier' => 'trusted-device-0001',
            'device_name' => 'Luka phone',
        ])->assertOk()
            ->assertJsonPath('user_status', 'pending_pin')
            ->assertJsonPath('expires_in', 600)
            ->assertJsonStructure(['enrollment_token']);

        $plainToken = $activation->json('enrollment_token');
        $enrollment = PinEnrollmentToken::firstOrFail();

        $this->assertSame(64, strlen($plainToken));
        $this->assertNotSame($plainToken, $enrollment->token_hash);
        $this->assertSame(hash('sha256', $plainToken), $enrollment->token_hash);

        $this->postJson('/api/v1/set_pin', [
            'enrollment_token' => $plainToken,
            'pin' => '1234',
        ])->assertOk()
            ->assertJsonStructure(['token']);

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('1234', $user->pin_hash));

        $this->postJson('/api/v1/set_pin', [
            'enrollment_token' => $plainToken,
            'pin' => '5678',
        ])->assertUnprocessable();
    }

    public function test_device_identifier_alone_cannot_set_the_first_pin(): void
    {
        [$user] = $this->activationFixture('SAFE-2026');
        Device::create([
            'user_id' => $user->id,
            'device_identifier' => 'trusted-device-0002',
            'device_name' => 'Phone',
            'is_trusted' => true,
        ]);

        $this->postJson('/api/v1/set_pin', [
            'device_identifier' => 'trusted-device-0002',
            'pin' => '1234',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('enrollment_token');

        $this->assertNull($user->fresh()->pin_hash);
    }

    public function test_activation_cannot_reassign_another_users_device(): void
    {
        $owner = $this->createUser('owner@example.com');
        Device::create([
            'user_id' => $owner->id,
            'device_identifier' => 'shared-device-0001',
            'device_name' => 'Owner phone',
            'is_trusted' => true,
        ]);

        [$activatingUser] = $this->activationFixture('OTHER-2026', 'other@example.com');

        $this->postJson('/api/v1/activate', [
            'code' => 'OTHER-2026',
            'device_identifier' => 'shared-device-0001',
            'device_name' => 'Other phone',
        ])->assertUnprocessable();

        $this->assertSame($owner->id, Device::where('device_identifier', 'shared-device-0001')->value('user_id'));
        $this->assertSame('pending_activation', $activatingUser->fresh()->status);
    }

    /**
     * @return array{User, ActivationCode}
     */
    private function activationFixture(string $code, string $email = 'luka@example.com'): array
    {
        $user = $this->createUser($email);
        $activationCode = ActivationCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'expires_at' => now()->addDay(),
        ]);

        return [$user, $activationCode];
    }

    private function createUser(string $email): User
    {
        static $sequence = 100;

        $sequence++;

        return User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'jmbg' => str_pad((string) $sequence, 13, '0', STR_PAD_LEFT),
            'phone_number' => '+38165'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
            'email' => $email,
            'status' => 'pending_activation',
        ]);
    }
}
