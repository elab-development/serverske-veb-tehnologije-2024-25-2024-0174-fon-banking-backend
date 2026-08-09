<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\PinConfirmationGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

class PasskeyWebAuthnIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://fon-banking.duckdns.org';

    private const RP_ID = 'fon-banking.duckdns.org';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('passkeys.relying_party_id', self::RP_ID);
        config()->set('passkeys.allowed_origins', [self::ORIGIN]);
        config()->set('passkeys.user_handle_secret', str_repeat('s', 64));
    }

    public function test_real_registration_and_assertion_issue_a_sanctum_token(): void
    {
        $user = User::create([
            'first_name' => 'Real',
            'last_name' => 'WebAuthn',
            'jmbg' => '2000000000001',
            'phone_number' => '+381652000001',
            'email' => 'real-webauthn@example.com',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ]);
        $device = Device::create([
            'user_id' => $user->id,
            'device_identifier' => 'real-webauthn-device-0001',
            'device_name' => 'Virtual authenticator',
            'is_trusted' => true,
        ]);
        $accessToken = $user->createToken('registration-device');
        $grant = app(PinConfirmationGrant::class)->issue(
            $user->id,
            $accessToken->accessToken->id,
            'passkeys.manage',
        );

        $registration = $this->withToken($accessToken->plainTextToken)
            ->postJson('/api/v1/passkeys/registration/options', [
                'confirmation_token' => $grant['token'],
            ])->assertOk();

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);

        $credentialId = random_bytes(32);

        $this->withToken($accessToken->plainTextToken)
            ->postJson('/api/v1/passkeys', [
                'ceremony_id' => $registration->json('ceremony_id'),
                'name' => 'Virtual passkey',
                'credential' => $this->attestationCredential(
                    $credentialId,
                    $registration->json('options.challenge'),
                    $privateKey,
                ),
            ])->assertCreated();

        $this->assertDatabaseHas('passkeys', [
            'user_id' => $user->id,
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
        ]);

        $login = $this->postJson('/api/v1/auth/passkeys/login/options', [
            'device_identifier' => $device->device_identifier,
        ])->assertOk();

        $token = $this->postJson('/api/v1/auth/passkeys/login', [
            'ceremony_id' => $login->json('ceremony_id'),
            'device_identifier' => $device->device_identifier,
            'credential' => $this->assertionCredential(
                $credentialId,
                $login->json('options.challenge'),
                $user->getPasskeyUserHandle(),
                $privateKey,
            ),
        ])->assertOk()
            ->assertJsonStructure(['token'])
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/accounts')->assertOk();
        $this->assertNotNull($user->passkeys()->firstOrFail()->last_used_at);
    }

    private function attestationCredential(
        string $credentialId,
        string $challenge,
        OpenSSLAsymmetricKey $privateKey,
    ): array {
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);

        $credentialPublicKey = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20"
            .$details['ec']['x']."\x22\x58\x20".$details['ec']['y'];
        $authenticatorData = hash('sha256', self::RP_ID, true).chr(0x45).pack('N', 0)
            .str_repeat("\0", 16).pack('n', strlen($credentialId)).$credentialId.$credentialPublicKey;
        $attestationObject = "\xa3\x63fmt\x64none\x68authData\x58".chr(strlen($authenticatorData))
            .$authenticatorData."\x67attStmt\xa0";

        return [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($this->clientData('webauthn.create', $challenge)),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    private function assertionCredential(
        string $credentialId,
        string $challenge,
        string $userHandle,
        OpenSSLAsymmetricKey $privateKey,
    ): array {
        $clientData = $this->clientData('webauthn.get', $challenge);
        $authenticatorData = hash('sha256', self::RP_ID, true).chr(0x05).pack('N', 1);
        $signed = openssl_sign(
            $authenticatorData.hash('sha256', $clientData, true),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertTrue($signed);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authenticatorData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($userHandle),
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }
}
