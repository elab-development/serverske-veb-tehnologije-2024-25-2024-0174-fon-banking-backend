<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PinConfirmationGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Mockery\MockInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class PasskeyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('passkeys.relying_party_id', 'fon-banking.duckdns.org');
        config()->set('passkeys.allowed_origins', ['https://fon-banking.duckdns.org']);
        config()->set('passkeys.user_handle_secret', str_repeat('s', 64));
    }

    public function test_passkey_list_exposes_only_safe_fields_for_the_current_user(): void
    {
        $user = $this->createUser('owner@example.com', 1);
        $otherUser = $this->createUser('other@example.com', 2);
        $passkey = $this->createPasskey($user, 'Owner passkey', 'owner-credential');
        $this->createPasskey($otherUser, 'Other passkey', 'other-credential');
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/passkeys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $passkey->id)
            ->assertJsonMissing(['credential_id' => $passkey->credential_id]);

        $this->assertSame(
            ['id', 'name', 'last_used_at', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_registration_options_reject_a_grant_bound_to_another_token(): void
    {
        $user = $this->createUser('owner@example.com', 1);
        $issuingToken = $user->createToken('issuing-device');
        $otherToken = $user->createToken('other-device');
        $grant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $issuingToken->accessToken->id,
            'passkeys.manage',
        );

        $this->withToken($otherToken->plainTextToken)
            ->postJson('/api/v1/passkeys/registration/options', [
                'confirmation_token' => $grant['token'],
            ])->assertUnprocessable();
    }

    public function test_registration_options_consume_a_valid_confirmation_grant(): void
    {
        $user = $this->createUser('owner@example.com', 1);
        $token = $user->createToken('issuing-device');

        $validGrant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $token->accessToken->id,
            'passkeys.manage',
        );

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/passkeys/registration/options', [
                'confirmation_token' => $validGrant['token'],
            ])->assertOk()
            ->assertJsonStructure(['ceremony_id', 'options']);
    }

    public function test_user_can_delete_only_an_owned_passkey_with_a_fresh_confirmation(): void
    {
        $user = $this->createUser('owner@example.com', 1);
        $otherUser = $this->createUser('other@example.com', 2);
        $owned = $this->createPasskey($user, 'Owner passkey', 'owner-credential');
        $foreign = $this->createPasskey($otherUser, 'Other passkey', 'other-credential');
        $token = $user->createToken('test-device');

        $foreignGrant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $token->accessToken->id,
            'passkeys.manage',
        );

        $this->withToken($token->plainTextToken)
            ->deleteJson('/api/v1/passkeys/'.$foreign->id, [
                'confirmation_token' => $foreignGrant['token'],
            ])->assertNotFound();

        $ownedGrant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $token->accessToken->id,
            'passkeys.manage',
        );

        $this->withToken($token->plainTextToken)
            ->deleteJson('/api/v1/passkeys/'.$owned->id, [
                'confirmation_token' => $ownedGrant['token'],
            ])->assertNoContent();

        $this->assertDatabaseMissing('passkeys', ['id' => $owned->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $foreign->id]);
    }

    public function test_registration_completion_is_token_bound_single_use_and_returns_safe_data(): void
    {
        $user = $this->createUser('owner@example.com', 1);
        $token = $user->createToken('issuing-device');
        $grant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $token->accessToken->id,
            'passkeys.manage',
        );

        $ceremonyId = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/passkeys/registration/options', [
                'confirmation_token' => $grant['token'],
            ])->assertOk()
            ->json('ceremony_id');

        $passkey = $this->createPasskey($user, 'My phone', 'stored-credential');
        $this->mock(StorePasskey::class, function (MockInterface $mock) use ($passkey): void {
            $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
        });

        $payload = [
            'ceremony_id' => $ceremonyId,
            'name' => 'My phone',
            'credential' => $this->attestationCredential(),
        ];

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/passkeys', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $passkey->id)
            ->assertJsonMissingPath('data.credential')
            ->assertJsonMissingPath('data.credential_id');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/passkeys', $payload)
            ->assertUnprocessable();
    }

    public function test_package_web_routes_remain_disabled(): void
    {
        $uris = collect(Route::getRoutes())->map->uri();

        $this->assertNotContains('passkeys/login', $uris);
        $this->assertNotContains('passkeys/confirm', $uris);
        $this->assertNotContains('user/passkeys', $uris);
    }

    private function createUser(string $email, int $sequence): User
    {
        return User::create([
            'first_name' => 'Management',
            'last_name' => 'User',
            'jmbg' => str_pad((string) $sequence, 13, '0', STR_PAD_LEFT),
            'phone_number' => '+38166'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT),
            'email' => $email,
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ]);
    }

    private function createPasskey(User $user, string $name, string $credentialId): Passkey
    {
        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => ['private' => 'must-not-leak'],
        ]);
    }

    private function attestationCredential(): array
    {
        $credentialId = 'credential-id';
        $credentialPublicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            .str_repeat('x', 32)."\x22\x58\x20".str_repeat('y', 32);
        $authenticatorData = str_repeat("\0", 32).chr(0x45).pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($credentialId)).$credentialId.$credentialPublicKey;
        $attestationObject = "\xa3\x63fmt\x64none\x68authData\x58".chr(strlen($authenticatorData))
            .$authenticatorData."\x67attStmt\xa0";
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => Base64UrlSafe::encodeUnpadded('challenge'),
            'origin' => 'https://fon-banking.duckdns.org',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $id = Base64UrlSafe::encodeUnpadded($credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }
}
