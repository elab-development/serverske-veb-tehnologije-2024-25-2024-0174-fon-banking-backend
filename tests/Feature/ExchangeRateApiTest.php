<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rate_endpoint_returns_bank_buy_middle_and_sell_values(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'EUR', 'rate' => 0.0085],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'USD', 'rate' => 0.01],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'CHF', 'rate' => 0.008],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'GBP', 'rate' => 0.007],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'AUD', 'rate' => 0.014],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'CAD', 'rate' => 0.013],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'CNY', 'rate' => 0.067],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'DKK', 'rate' => 0.064],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'HUF', 'rate' => 3.1],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'JPY', 'rate' => 1.57],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'NOK', 'rate' => 0.093],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'RUB', 'rate' => 0.84],
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'SEK', 'rate' => 0.094],
            ]),
        ]);
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/exchange-rates?base=RSD');

        $response->assertOk()
            ->assertJsonPath('base', 'RSD')
            ->assertJsonPath('rates.0.quote', 'EUR')
            ->assertJsonPath('rates.0.buy', 111.7647)
            ->assertJsonPath('rates.0.middle', 117.6471)
            ->assertJsonPath('rates.0.sell', 123.5294);
    }

    public function test_cross_currency_transfer_uses_the_current_bank_sell_rate(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response([
                ['date' => '2026-08-20', 'base' => 'RSD', 'quote' => 'EUR', 'rate' => 0.0085],
            ]),
        ]);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $senderAccountNumber = app(AccountNumberService::class)->generate('RSD', '1001');
        $recipientAccountNumber = app(AccountNumberService::class)->generate('EUR', '1001');
        $senderAccount = Account::create([
            'id' => 'sender-rsd',
            'user_id' => $sender->id,
            'title' => 'RSD account',
            'name' => 'Sender',
            'account_number' => $senderAccountNumber,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);
        $recipientAccount = Account::create([
            'id' => 'recipient-eur',
            'user_id' => $recipient->id,
            'title' => 'EUR account',
            'name' => 'Recipient',
            'account_number' => $recipientAccountNumber,
            'color' => 'blue',
            'currency' => 'EUR',
        ]);
        Transaction::create([
            'id' => 'funding',
            'recipient_account_id' => $senderAccount->id,
            'recipient_name' => 'Sender',
            'sender_account_id' => $recipientAccount->id,
            'recipient_amount' => 50000,
            'recipient_currency' => 'RSD',
            'sender_amount' => 0,
            'sender_currency' => 'EUR',
            'transaction_time' => now(),
            'status' => 'realizovano',
        ]);
        $token = $sender->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions/transfer', [
                'senderAccount' => $senderAccountNumber,
                'recipientAccount' => $recipientAccountNumber,
                'recipientName' => 'Recipient',
                'amount' => 1235.29,
                'currency' => 'RSD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('senderAmount', 1235.29)
            ->assertJsonPath('senderCurrency', 'RSD')
            ->assertJsonPath('recipientAmount', 10)
            ->assertJsonPath('recipientCurrency', 'EUR')
            ->assertJsonPath('exchangeRate', 0.008095);
    }
}
