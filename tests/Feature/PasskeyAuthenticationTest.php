<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Mockery\MockInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('passkeys.relying_party_id', 'fon-banking.duckdns.org');
        config()->set('passkeys.allowed_origins', ['https://fon-banking.duckdns.org']);
        config()->set('passkeys.user_handle_secret', str_repeat('s', 64));
    }

    public function test_login_options_require_a_trusted_active_device_with_a_passkey(): void
    {
        $unknown = $this->postJson('/api/v1/auth/passkeys/login/options', [
            'device_identifier' => 'unknown-device-0001',
        ])->assertUnprocessable();

        $user = $this->createUser();
        $device = $this->createDevice($user);

        $withoutPasskey = $this->postJson('/api/v1/auth/passkeys/login/options', [
            'device_identifier' => $device->device_identifier,
        ])->assertUnprocessable();

        $this->assertSame($unknown->json('errors'), $withoutPasskey->json('errors'));
    }

    public function test_successful_passkey_login_issues_a_sanctum_token_for_the_bound_device(): void
    {
        $user = $this->createUser();
        $device = $this->createDevice($user);
        $passkey = $this->createPasskey($user);

        $ceremonyId = $this->postJson('/api/v1/auth/passkeys/login/options', [
            'device_identifier' => $device->device_identifier,
        ])->assertOk()
            ->assertJsonStructure(['ceremony_id', 'options'])
            ->json('ceremony_id');

        $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey): void {
            $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
        });

        $token = $this->postJson('/api/v1/auth/passkeys/login', [
            'ceremony_id' => $ceremonyId,
            'device_identifier' => $device->device_identifier,
            'credential' => $this->assertionCredential(),
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['token'])
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/accounts')->assertOk();
        $this->assertNotNull($device->fresh()->last_login_at);

        $this->postJson('/api/v1/auth/passkeys/login', [
            'ceremony_id' => $ceremonyId,
            'device_identifier' => $device->device_identifier,
            'credential' => $this->assertionCredential(),
        ])->assertUnprocessable();
    }

    public function test_login_ceremony_cannot_be_used_by_another_device(): void
    {
        $user = $this->createUser();
        $device = $this->createDevice($user);
        $this->createPasskey($user);

        $otherDevice = Device::create([
            'user_id' => $user->id,
            'device_identifier' => 'other-trusted-device-0001',
            'device_name' => 'Other phone',
            'is_trusted' => true,
        ]);

        $ceremonyId = $this->postJson('/api/v1/auth/passkeys/login/options', [
            'device_identifier' => $device->device_identifier,
        ])->json('ceremony_id');

        $this->postJson('/api/v1/auth/passkeys/login', [
            'ceremony_id' => $ceremonyId,
            'device_identifier' => $otherDevice->device_identifier,
            'credential' => $this->assertionCredential(),
        ])->assertUnprocessable();
    }

    private function assertionCredential(): array
    {
        $id = Base64UrlSafe::encodeUnpadded('credential-id');
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => Base64UrlSafe::encodeUnpadded('challenge'),
            'origin' => 'https://fon-banking.duckdns.org',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $authenticatorData = str_repeat("\0", 32).chr(5).pack('N', 0);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                'signature' => Base64UrlSafe::encodeUnpadded('signature'),
                'userHandle' => null,
            ],
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Passkey',
            'last_name' => 'User',
            'jmbg' => '1000000000001',
            'phone_number' => '+381651000001',
            'email' => 'passkey@example.com',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ]);
    }

    private function createDevice(User $user): Device
    {
        return Device::create([
            'user_id' => $user->id,
            'device_identifier' => 'trusted-passkey-device-0001',
            'device_name' => 'Passkey phone',
            'is_trusted' => true,
        ]);
    }

    private function createPasskey(User $user): Passkey
    {
        return $user->passkeys()->create([
            'name' => 'Phone passkey',
            'credential_id' => Base64UrlSafe::encodeUnpadded('credential-id'),
            'credential' => [],
        ]);
    }
}
