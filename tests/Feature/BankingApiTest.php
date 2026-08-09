<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BankingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_confirm_their_pin_without_creating_a_new_token(): void
    {
        $user = User::create([
            'first_name' => 'Marko',
            'last_name' => 'Nenadović',
            'jmbg' => '0101990712345',
            'phone_number' => '+381641111111',
            'email' => 'marko.confirm@example.com',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '1234']);

        $response->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertCount(1, $user->tokens()->get());
    }

    public function test_pin_confirmation_rejects_an_incorrect_pin(): void
    {
        $user = User::create([
            'first_name' => 'Jovana',
            'last_name' => 'Jovanović',
            'jmbg' => '0202990712345',
            'phone_number' => '+381642222222',
            'email' => 'jovana.confirm@example.com',
            'pin_hash' => Hash::make('1234'),
            'status' => 'active',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/confirm-pin', ['pin' => '9999']);

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Pogrešan PIN kod.');
    }

    public function test_accounts_endpoint_returns_authenticated_users_accounts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Account::create([
            'id' => 'acc-test-1',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_id' => 'acc-1001',
            'balance' => 45000.50,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);

        Account::create([
            'id' => 'acc-test-2',
            'user_id' => $otherUser->id,
            'title' => 'Nepristupačan',
            'name' => 'Other User',
            'account_id' => 'acc-9999',
            'balance' => 500.00,
            'color' => 'blue',
            'currency' => 'RSD',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.accountId', 'acc-1001')
            ->assertJsonPath('0.balance', 0);
    }

    public function test_cards_endpoint_returns_cards_for_account(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'id' => 'acc-test-3',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_id' => 'acc-1001',
            'balance' => 45000.50,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);

        Card::create([
            'id' => 'crd-test-1',
            'account_id' => $account->id,
            'card_id' => 'crd-9901',
            'card_type' => 'Master',
            'expire_date' => '12/28',
            'owner_name' => 'Test User',
            'currency' => 'RSD',
            'cvv' => '123',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts/acc-1001/cards');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.cardId', 'crd-9901')
            ->assertJsonPath('0.ownerName', 'Test User');
    }

    public function test_transfer_endpoint_creates_transaction_and_checks_balance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Account::create([
            'id' => 'acc-test-4',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_id' => 'acc-1001',
            'balance' => 45000.50,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);
        Account::create([
            'id' => 'acc-test-recipient',
            'user_id' => $otherUser->id,
            'title' => 'Račun primaoca',
            'name' => 'Pera Peric',
            'account_id' => '160-123456789-01',
            'balance' => 0,
            'color' => 'blue',
            'currency' => 'RSD',
        ]);
        Transaction::create([
            'id' => 'txn-funding-1',
            'recipient_account' => 'acc-1001',
            'recipient_name' => 'Test User',
            'sender_account' => '160-123456789-01',
            'amount' => 50000,
            'currency' => 'RSD',
            'transaction_time' => now(),
            'status' => 'izvrsena',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions/transfer', [
                'senderAccount' => 'acc-1001',
                'recipientAccount' => '160-123456789-01',
                'recipientName' => 'Pera Peric',
                'amount' => 5000.00,
                'currency' => 'RSD',
                'paymentPurpose' => 'Uplata za racun',
                'paymentCode' => '289',
                'model' => 97,
                'referenceNumber' => '12-3456-7890',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('transactions', ['sender_account' => 'acc-1001']);
    }

    public function test_transactions_endpoint_returns_history_for_account(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Account::create([
            'id' => 'acc-test-5',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_id' => 'acc-1001',
            'balance' => 45000.50,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);
        Account::create([
            'id' => 'acc-test-6',
            'user_id' => $otherUser->id,
            'title' => 'Račun primaoca',
            'name' => 'Pera Peric',
            'account_id' => '160-123456789-01',
            'balance' => 0,
            'color' => 'blue',
            'currency' => 'RSD',
        ]);

        Transaction::create([
            'id' => 'txn-test-1',
            'transaction_type' => 'odliv',
            'recipient_account' => '160-123456789-01',
            'recipient_name' => 'Pera Peric',
            'sender_account' => 'acc-1001',
            'sender_name' => 'Test User',
            'model' => 97,
            'reference_number' => '12-3456-7890',
            'amount' => 5000.00,
            'currency' => 'RSD',
            'payment_purpose' => 'Uplata za racun',
            'payment_code' => '289',
            'transaction_time' => now(),
            'status' => 'izvrsena',
            'card_number' => null,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts/acc-1001/transactions');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.senderAccount', 'acc-1001');
    }
}
