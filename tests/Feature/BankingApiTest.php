<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Card;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BankingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Marko',
            'last_name' => 'Nenadović',
            'phone_number' => '+381641111111',
            'email' => 'marko@example.com',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/user');

        $response->assertOk()
            ->assertExactJson([
                'firstName' => 'Marko',
                'lastName' => 'Nenadović',
                'email' => 'marko@example.com',
                'phoneNumber' => '+381641111111',
            ]);
    }

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
        $accountNumber = app(AccountNumberService::class)->generate('RSD', '1001');
        $otherAccountNumber = app(AccountNumberService::class)->generate('RSD', '9999');

        Account::create([
            'id' => 'acc-test-1',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_number' => $accountNumber,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);

        Account::create([
            'id' => 'acc-test-2',
            'user_id' => $otherUser->id,
            'title' => 'Nepristupačan',
            'name' => 'Other User',
            'account_number' => $otherAccountNumber,
            'color' => 'blue',
            'currency' => 'RSD',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/accounts');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.accountId', $accountNumber)
            ->assertJsonPath('0.balance', 0);
    }

    public function test_cards_endpoint_returns_cards_for_account(): void
    {
        $user = User::factory()->create();
        $accountNumber = app(AccountNumberService::class)->generate('RSD', '1001');
        $account = Account::create([
            'id' => 'acc-test-3',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_number' => $accountNumber,
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
            ->getJson("/api/v1/accounts/{$accountNumber}/cards");

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.cardId', 'crd-9901')
            ->assertJsonPath('0.ownerName', 'Test User');
    }

    public function test_transfer_endpoint_creates_transaction_and_checks_balance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $senderAccountNumber = app(AccountNumberService::class)->generate('RSD', '1001');
        $recipientAccountNumber = app(AccountNumberService::class)->generate('RSD', '2001');
        $senderAccount = Account::create([
            'id' => 'acc-test-4',
            'user_id' => $user->id,
            'title' => 'Glavni tekući račun',
            'name' => 'Test User',
            'account_number' => $senderAccountNumber,
            'color' => 'magenta',
            'currency' => 'RSD',
        ]);
        $recipientAccount = Account::create([
            'id' => 'acc-test-recipient',
            'user_id' => $otherUser->id,
            'title' => 'Račun primaoca',
            'name' => 'Pera Peric',
            'account_number' => $recipientAccountNumber,
            'color' => 'blue',
            'currency' => 'RSD',
        ]);
        Transaction::create([
            'id' => 'txn-funding-1',
            'recipient_account_id' => $senderAccount->id,
            'recipient_name' => 'Test User',
            'sender_account_id' => $recipientAccount->id,
            'sender_amount' => 50000,
            'sender_currency' => 'RSD',
            'recipient_amount' => 50000,
            'recipient_currency' => 'RSD',
            'transaction_time' => now(),
            'status' => 'izvrsena',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions/transfer', [
                'senderAccount' => $senderAccountNumber,
                'recipientAccount' => $recipientAccountNumber,
                'recipientName' => 'Pera Peric',
                'amount' => 5000.00,
                'currency' => 'RSD',
                'paymentPurpose' => 'Uplata za racun',
                'paymentCode' => '289',
                'model' => 97,
                'referenceNumber' => '12-3456-7890',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('transactions', [
            'sender_account_id' => $senderAccount->id,
            'status' => 'izvrsena',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions/transfer', [
                'senderAccount' => $senderAccountNumber,
                'recipientAccount' => 'missing-account',
                'recipientName' => 'Missing User',
                'amount' => 100,
                'currency' => 'RSD',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Recipient account does not exist.');

        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_transaction_history_is_paginated_without_duplicate_owned_account_transfers(): void
    {
        $user = User::factory()->create();
        $accounts = [];
        foreach (['1001', '1002'] as $index => $suffix) {
            $accounts[] = Account::create([
                'id' => 'acc-page-'.$index,
                'user_id' => $user->id,
                'title' => 'Account',
                'name' => 'Test User',
                'account_number' => app(AccountNumberService::class)->generate('RSD', $suffix),
                'color' => 'blue',
                'currency' => 'RSD',
            ]);
        }

        for ($index = 1; $index <= 5; $index++) {
            Transaction::create([
                'id' => 'txn-page-'.$index,
                'recipient_account_id' => $accounts[1]->id,
                'recipient_name' => 'Test User',
                'sender_account_id' => $accounts[0]->id,
                'sender_amount' => $index,
                'sender_currency' => 'RSD',
                'recipient_amount' => $index,
                'recipient_currency' => 'RSD',
                'transaction_time' => now()->subMinutes($index),
                'status' => 'izvrsena',
            ]);
        }

        $token = $user->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 'txn-page-3')
            ->assertJsonPath('data.1.id', 'txn-page-4')
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 5);

        $receivingAccountNumber = $accounts[1]->account_number;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/transactions?account_id={$receivingAccountNumber}&direction=income")
            ->assertOk()
            ->assertJsonPath('total', 5);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/transactions?account_id={$receivingAccountNumber}&direction=expense")
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions?direction=income')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions?direction=expense')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_transaction_history_can_be_filtered_by_owned_account_or_card(): void
    {
        $user = User::factory()->create();
        $externalUser = User::factory()->create();
        $firstAccount = Account::create([
            'id' => 'acc-filter-first',
            'user_id' => $user->id,
            'title' => 'First account',
            'name' => 'Test User',
            'account_number' => '555100000000001001',
            'color' => 'blue',
            'currency' => 'RSD',
        ]);
        $secondAccount = Account::create([
            'id' => 'acc-filter-second',
            'user_id' => $user->id,
            'title' => 'Second account',
            'name' => 'Test User',
            'account_number' => '555100000000001002',
            'color' => 'blue',
            'currency' => 'RSD',
        ]);
        $externalAccount = Account::create([
            'id' => 'acc-filter-external',
            'user_id' => $externalUser->id,
            'title' => 'External account',
            'name' => 'External User',
            'account_number' => '555100000000001003',
            'color' => 'blue',
            'currency' => 'RSD',
        ]);
        $card = Card::create([
            'id' => 'card-filter',
            'account_id' => $firstAccount->id,
            'card_id' => '5555555555551001',
            'card_type' => 'Visa',
            'expire_date' => '12/30',
            'owner_name' => 'Test User',
            'currency' => 'RSD',
            'cvv' => '123',
        ]);

        Transaction::create([
            'id' => 'txn-selected-card',
            'sender_account_id' => $firstAccount->id,
            'recipient_account_id' => $externalAccount->id,
            'recipient_name' => 'External User',
            'sender_amount' => 100,
            'sender_currency' => 'RSD',
            'recipient_amount' => 100,
            'recipient_currency' => 'RSD',
            'transaction_time' => now(),
            'status' => 'izvrsena',
            'card_number' => $card->card_id,
        ]);
        Transaction::create([
            'id' => 'txn-selected-account',
            'sender_account_id' => $secondAccount->id,
            'recipient_account_id' => $externalAccount->id,
            'recipient_name' => 'External User',
            'sender_amount' => 200,
            'sender_currency' => 'RSD',
            'recipient_amount' => 200,
            'recipient_currency' => 'RSD',
            'transaction_time' => now(),
            'status' => 'izvrsena',
            'card_number' => null,
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions?account_id='.$secondAccount->account_number)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'txn-selected-account');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/transactions?card_id='.$card->card_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'txn-selected-card');
    }
}
