<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NbsQrApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_validate_ips_qr_text(): void
    {
        Http::fake([
            'https://nbs.rs/QRcode/api/qr/v1/validate*' => Http::response([
                's' => ['code' => 0, 'desc' => 'OK'],
                't' => 'K:PR|V:01|C:1|R:845000000040484987',
                'n' => ['K' => 'PR', 'R' => '845000000040484987'],
            ]),
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/qr/validate', [
                'text' => 'K:PR|V:01|C:1|R:845000000040484987',
            ])
            ->assertOk()
            ->assertJsonPath('s.code', 0)
            ->assertJsonPath('n.R', '845000000040484987');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/validate?lang=sr_RS_Latn')
            && $request->body() === 'K:PR|V:01|C:1|R:845000000040484987'
            && str_starts_with((string) $request->header('Content-Type')[0], 'text/plain'));
    }

    public function test_authenticated_user_can_generate_ips_qr_image(): void
    {
        $accountNumber = app(AccountNumberService::class)->generate('RSD', '4987');
        $qrText = "K:PR|V:01|C:1|R:{$accountNumber}|I:RSD100,00";
        Http::fake([
            'https://nbs.rs/QRcode/api/qr/v1/generate/400*' => Http::response([
                's' => ['code' => 0, 'desc' => 'OK'],
                't' => $qrText,
                'n' => ['K' => 'PR', 'R' => $accountNumber],
                'i' => 'base64-image',
            ]),
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/qr/generate', [
                'text' => $qrText,
            ])
            ->assertOk()
            ->assertJsonPath('s.code', 0)
            ->assertJsonPath('i', 'base64-image');
    }

    public function test_qr_endpoints_require_valid_text_and_authentication(): void
    {
        $this->postJson('/api/v1/qr/validate', ['text' => 'test'])
            ->assertUnauthorized();

        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/qr/generate', ['text' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('text');

        Http::assertNothingSent();
    }
}
