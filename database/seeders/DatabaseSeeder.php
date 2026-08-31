<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ActivationCode;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountNumberService;
use App\Services\ExchangeRateService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $ledger = [];

        $accountTemplates = [
            [
                'title' => 'GLAVNI RACUN',
                'name' => 'Tekuci dinarski racun',
                'balance_min' => 50000,
                'balance_max' => 350000,
                'balance_decimals' => 0,
                'color' => 'magenta',
                'currency' => 'RSD',
            ],
            [
                'title' => 'DEVIZNI RACUN',
                'name' => 'Tekuci devizni racun',
                'balance_min' => 100,
                'balance_max' => 5000,
                'balance_decimals' => 2,
                'color' => 'tirquise',
                'currency' => 'EUR',
            ],
        ];

        $systemAccounts = $this->seedSystemAccounts($accountTemplates);

        $user1 = User::create([
            'first_name' => 'Luka',
            'last_name' => 'Nenadovic',
            'jmbg' => '2410001583342',
            'phone_number' => '+381669442557',
            'email' => 'luka@student.etf.bg.ac.rs',
            'pin_hash' => null,
            'status' => 'pending_activation',
        ]);

        ActivationCode::create([
            'user_id' => $user1->id,
            'code' => Hash::make('LUKA-2026'),
            'expires_at' => now()->addDays(45),
        ]);

        $this->seedAccountsForUser($user1, $accountTemplates, $systemAccounts, $ledger, config('business_categories.personal_code'));

        $user2 = User::create([
            'first_name' => 'Marko',
            'last_name' => 'Nenadovic',
            'jmbg' => '24100053783492',
            'phone_number' => '+381644546204',
            'email' => 'mn20240174@student.fon.bg.ac.rs',
            'pin_hash' => null,
            'status' => 'pending_activation',
        ]);

        ActivationCode::create([
            'user_id' => $user2->id,
            'code' => Hash::make('MARKO-2026'),
            'expires_at' => now()->addDays(45),
        ]);

        $this->seedAccountsForUser($user2, $accountTemplates, $systemAccounts, $ledger, config('business_categories.personal_code'));

        $peopleCount = (int) env('SEED_PERSON_COUNT', 30);
        $businessCount = (int) env('SEED_BUSINESS_COUNT', 24);
        $businessTransactionsPerPerson = (int) env('SEED_TRANSACTIONS_PER_PERSON', 20);

        $people = $this->seedFakePeople($peopleCount, $accountTemplates, $systemAccounts, $ledger);
        $people = array_merge($people, [$user1->load('accounts'), $user2->load('accounts')]);
        $businesses = $this->seedFakeBusinesses($businessCount, $accountTemplates, $systemAccounts, $ledger);

        $this->seedIncomeTransactions($people, $systemAccounts['income'], $ledger);
        $this->seedBusinessFundingTransactions($businesses, $systemAccounts['card_settlement'], $ledger);
        $this->seedFakeSpendingTransactions($people, $businesses, $businessTransactionsPerPerson, $ledger);
        $this->seedFakePeerTransactions($people, [
            $user1->id => $user2->id,
            $user2->id => $user1->id,
        ], $ledger);

        $this->command->info(sprintf(
            'Succesfully seeded database with %d fake people, %d fake businesses, up to %d business transactions per person, and 3-5 unique peer recipients per person.',
            $peopleCount,
            $businessCount,
            $businessTransactionsPerPerson,
        ));
    }

    private function seedSystemAccounts(array $accountTemplates): array
    {
        $definitions = [
            'opening_balance' => [
                'first_name' => 'BANK_OPENING_BALANCE',
                'last_name' => 'SYSTEM',
                'email' => 'opening-balance@fon-banking.test',
                'title' => 'OPENING BALANCE',
                'suffix' => '9991',
            ],
            'income' => [
                'first_name' => 'EMPLOYER_CLEARING',
                'last_name' => 'SYSTEM',
                'email' => 'income-clearing@fon-banking.test',
                'title' => 'EMPLOYER CLEARING',
                'suffix' => '9992',
            ],
            'card_settlement' => [
                'first_name' => 'CARD_SETTLEMENT',
                'last_name' => 'SYSTEM',
                'email' => 'card-settlement@fon-banking.test',
                'title' => 'CARD SETTLEMENT',
                'suffix' => '9993',
            ],
        ];

        $accounts = [];

        foreach ($definitions as $key => $definition) {
            $systemUser = User::create([
                'first_name' => $definition['first_name'],
                'last_name' => $definition['last_name'],
                'jmbg' => $this->uniqueDigits(13, User::class, 'jmbg'),
                'phone_number' => '+3816'.$this->uniqueDigits(8, User::class, 'phone_number'),
                'email' => $definition['email'],
                'pin_hash' => Hash::make('1234'),
                'status' => 'system',
            ]);

            foreach ($accountTemplates as $template) {
                $accountNumber = $this->makeAccountNumber($template['currency'], $definition['suffix']);
                $account = Account::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $systemUser->id,
                    'title' => $definition['title'].' '.$template['currency'],
                    'name' => 'Interni izvor sredstava',
                    'account_number' => $accountNumber,
                    'iban' => $template['currency'] === 'EUR'
                        ? app(AccountNumberService::class)->generateIban($accountNumber)
                        : null,
                    'color' => 'gray',
                    'currency' => $template['currency'],
                ]);

                $accounts[$key][$template['currency']] = $account;
            }
        }

        return $accounts;
    }

    private function seedFakePeople(int $count, array $accountTemplates, array $systemAccounts, array &$ledger): array
    {
        $people = [];
        $firstNames = ['Aleksa', 'Ana', 'Bojan', 'Jelena', 'Lazar', 'Marija', 'Milos', 'Nina', 'Nikola', 'Sara'];
        $lastNames = ['Jovanovic', 'Markovic', 'Nikolic', 'Petrovic', 'Stankovic', 'Stojanovic', 'Ilic', 'Pavlovic'];

        for ($index = 0; $index < $count; $index++) {
            $firstName = $firstNames[$index % count($firstNames)];
            $lastName = $lastNames[intdiv($index, count($firstNames)) % count($lastNames)];

            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'jmbg' => $this->uniqueDigits(13, User::class, 'jmbg'),
                'phone_number' => '+3816'.$this->uniqueDigits(8, User::class, 'phone_number'),
                'email' => Str::lower($firstName.'.'.$lastName.'.'.$index.'@example.test'),
                'pin_hash' => Hash::make('1234'),
                'status' => 'active',
            ]);

            $this->seedAccountsForUser($user, $accountTemplates, $systemAccounts, $ledger, config('business_categories.personal_code'));
            $people[] = $user->load('accounts');
        }

        return $people;
    }

    private function seedFakeBusinesses(int $count, array $accountTemplates, array $systemAccounts, array &$ledger): array
    {
        $businesses = [];
        $categories = config('business_categories.categories');
        $categoryCodes = array_keys($categories);
        $legalTypes = ['D.O.O', 'A.D', 'I.P'];

        for ($index = 0; $index < $count; $index++) {
            $categoryCode = $categoryCodes[$index % count($categoryCodes)];
            $category = $categories[$categoryCode];
            $companyName = $this->fakeCompanyName($category['key']);

            $user = User::create([
                'first_name' => $companyName,
                'last_name' => $legalTypes[array_rand($legalTypes)],
                'jmbg' => $this->uniqueDigits(13, User::class, 'jmbg'),
                'phone_number' => '+3816'.$this->uniqueDigits(8, User::class, 'phone_number'),
                'email' => Str::slug($companyName).'-'.$index.'@business.test',
                'pin_hash' => Hash::make('1234'),
                'status' => 'active',
            ]);

            $this->seedAccountsForUser($user, $accountTemplates, $systemAccounts, $ledger, $categoryCode);
            $businesses[] = [
                'user' => $user->load('accounts'),
                'category_code' => $categoryCode,
                'category' => $category,
            ];
        }

        return $businesses;
    }

    private function seedAccountsForUser(User $user, array $accountTemplates, array $systemAccounts, array &$ledger, ?string $accountSuffix = null): void
    {
        foreach ($accountTemplates as $template) {
            $accountNumber = $this->makeAccountNumber($template['currency'], $accountSuffix);
            $account = Account::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'title' => $template['title'],
                'name' => trim($user->first_name.' '.$user->last_name),
                'account_number' => $accountNumber,
                'iban' => $template['currency'] === 'EUR'
                    ? app(AccountNumberService::class)->generateIban($accountNumber)
                    : null,
                'color' => $template['color'],
                'currency' => $template['currency'],
            ]);

            $cardTemplates = [
                [
                    'card_type' => 'Master',
                    'card_prefix' => '53554600',
                ],
                [
                    'card_type' => 'Visa',
                    'card_prefix' => '40003370',
                ],
            ];

            $this->seedOpeningBalance($account, $template, $systemAccounts['opening_balance'][$account->currency], $ledger);
            switch ($account->currency) {
                case 'EUR':
                    $this->seedCardsForAccount($account, $user, $cardTemplates[1]);
                    break;
                case 'RSD':
                    $this->seedCardsForAccount($account, $user, $cardTemplates[0]);
                    break;
                default:
                    return;
            }
        }
    }

    private function seedOpeningBalance(Account $account, array $template, Account $systemAccount, array &$ledger): void
    {
        $amount = $this->randomBalance(
            $template['balance_min'],
            $template['balance_max'],
            $template['balance_decimals'],
        );

        $senderAmount = $this->createTransfer(
            $systemAccount,
            $account,
            trim($account->user->first_name.' '.$account->user->last_name),
            (float) $amount,
            'Pocetno stanje',
            'opening_balance',
            null,
            null,
            now()->subDays(180)->subMinutes(random_int(0, 1440)),
            null,
        );

        $ledger[$systemAccount->id] = ($ledger[$systemAccount->id] ?? 0) - $senderAmount;
        $ledger[$account->id] = (float) $amount;
    }

    private function seedIncomeTransactions(array $people, array $incomeAccounts, array &$ledger): void
    {
        foreach ($people as $person) {
            foreach ($person->accounts as $account) {
                $paymentCount = $account->currency === 'RSD' ? random_int(2, 4) : random_int(1, 3);

                for ($index = 0; $index < $paymentCount; $index++) {
                    $amount = $account->currency === 'RSD'
                        ? $this->randomBalance(45000, 180000, 0)
                        : $this->randomBalance(250, 1800, 2);
                    $sourceAccount = $incomeAccounts[$account->currency];
                    $senderAmount = $this->createTransfer(
                        $sourceAccount,
                        $account,
                        trim($person->first_name.' '.$person->last_name),
                        (float) $amount,
                        'Zarada i priliv sredstava',
                        'income',
                        null,
                        null,
                        now()->subDays(random_int(15, 170))->subMinutes(random_int(0, 1440)),
                        null,
                    );

                    $ledger[$sourceAccount->id] = ($ledger[$sourceAccount->id] ?? 0) - $senderAmount;
                    $ledger[$account->id] = ($ledger[$account->id] ?? 0) + (float) $amount;
                }
            }
        }
    }

    private function seedBusinessFundingTransactions(array $businesses, array $settlementAccounts, array &$ledger): void
    {
        foreach ($businesses as $business) {
            foreach ($business['user']->accounts as $account) {
                $amount = $account->currency === 'RSD'
                    ? $this->randomBalance(120000, 900000, 0)
                    : $this->randomBalance(1000, 8500, 2);
                $sourceAccount = $settlementAccounts[$account->currency];
                $senderAmount = $this->createTransfer(
                    $sourceAccount,
                    $account,
                    trim($business['user']->first_name.' '.$business['user']->last_name),
                    (float) $amount,
                    'Likvidnost za promet karticama',
                    'business_funding',
                    null,
                    null,
                    now()->subDays(random_int(30, 170))->subMinutes(random_int(0, 1440)),
                    null,
                );

                $ledger[$sourceAccount->id] = ($ledger[$sourceAccount->id] ?? 0) - $senderAmount;
                $ledger[$account->id] = ($ledger[$account->id] ?? 0) + (float) $amount;
            }
        }
    }

    private function seedFakeSpendingTransactions(array $people, array $businesses, int $transactionsPerPerson, array &$ledger): void
    {
        if ($transactionsPerPerson <= 0 || $businesses === []) {
            return;
        }

        foreach ($people as $person) {
            for ($index = 0; $index < $transactionsPerPerson; $index++) {
                $business = $businesses[array_rand($businesses)];
                $senderAccount = $this->randomAccount($person);
                $recipientAccount = $this->randomAccount($business['user']);

                if (! $senderAccount || ! $recipientAccount) {
                    continue;
                }

                $amount = $this->affordableTransactionAmount($senderAccount, $recipientAccount, true, $ledger);

                if ($amount === null) {
                    continue;
                }

                $purposes = $business['category']['payment_purposes'];
                $purpose = $purposes[array_rand($purposes)];
                $cardNumber = $this->mostlyCardNumber($senderAccount);

                $senderDebitAmount = $this->createTransfer(
                    $senderAccount,
                    $recipientAccount,
                    trim($business['user']->first_name.' '.$business['user']->last_name),
                    $amount,
                    $purpose,
                    $business['category_code'],
                    random_int(0, 1) === 1 ? 97 : null,
                    random_int(0, 1) === 1 ? $this->randomDigits(12) : null,
                    now()->subDays(random_int(0, 120))->subMinutes(random_int(0, 1440)),
                    $cardNumber,
                );

                $ledger[$senderAccount->id] -= $senderDebitAmount;
                $ledger[$recipientAccount->id] = ($ledger[$recipientAccount->id] ?? 0) + $amount;
            }
        }
    }

    private function seedFakePeerTransactions(array $people, array $preferredRecipients, array &$ledger): void
    {
        if (count($people) < 2) {
            return;
        }

        foreach ($people as $sender) {
            $recipients = array_values(array_filter(
                $people,
                fn (User $person): bool => $person->id !== $sender->id,
            ));
            shuffle($recipients);

            $preferredRecipientId = $preferredRecipients[$sender->id] ?? null;
            if ($preferredRecipientId !== null) {
                usort(
                    $recipients,
                    fn (User $first, User $second): int => ($second->id === $preferredRecipientId) <=> ($first->id === $preferredRecipientId),
                );
            }

            $maximumRecipients = min(5, count($recipients));
            $recipientCount = random_int(min(3, $maximumRecipients), $maximumRecipients);

            $selectedRecipients = array_slice($recipients, 0, $recipientCount);
            foreach ($selectedRecipients as $index => $recipient) {
                $senderAccount = $sender->accounts->firstWhere('currency', 'RSD');
                $recipientAccount = $recipient->accounts->firstWhere('currency', 'RSD');

                if (! $senderAccount || ! $recipientAccount) {
                    continue;
                }

                $available = $ledger[$senderAccount->id] ?? 0;
                $remainingRecipients = count($selectedRecipients) - $index;
                $amount = min(
                    $this->transactionAmountForCurrency('RSD', false),
                    floor($available / $remainingRecipients),
                );

                if ($amount < 100) {
                    continue;
                }

                $senderDebitAmount = $this->createTransfer(
                    $senderAccount,
                    $recipientAccount,
                    trim($recipient->first_name.' '.$recipient->last_name),
                    $amount,
                    $this->peerPaymentPurpose(),
                    null,
                    random_int(0, 1) === 1 ? 97 : null,
                    random_int(0, 1) === 1 ? $this->randomDigits(12) : null,
                    $recipient->id === $preferredRecipientId
                        ? now()->subMinutes(random_int(0, 30))
                        : now()->subDays(random_int(0, 120))->subMinutes(random_int(0, 1440)),
                    null,
                );

                $ledger[$senderAccount->id] -= $senderDebitAmount;
                $ledger[$recipientAccount->id] = ($ledger[$recipientAccount->id] ?? 0) + $amount;
            }
        }
    }

    private function createTransfer(
        Account $senderAccount,
        Account $recipientAccount,
        string $recipientName,
        float $recipientAmount,
        ?string $paymentPurpose,
        ?string $paymentCode,
        ?int $model,
        ?string $referenceNumber,
        mixed $transactionTime,
        ?string $cardNumber,
    ): float {
        $conversion = app(ExchangeRateService::class)->transferValues(
            $recipientAmount,
            $senderAccount->currency,
            $recipientAccount->currency,
        );
        $senderAmount = $conversion['senderAmount'];

        Transaction::create([
            'id' => (string) Str::uuid(),
            'recipient_account_id' => $recipientAccount->id,
            'recipient_name' => $recipientName,
            'sender_account_id' => $senderAccount->id,
            'model' => $model,
            'reference_number' => $referenceNumber,
            'sender_amount' => $senderAmount,
            'sender_currency' => $senderAccount->currency,
            'recipient_amount' => $recipientAmount,
            'recipient_currency' => $recipientAccount->currency,
            'exchange_rate' => $conversion['exchangeRate'],
            'payment_purpose' => $paymentPurpose,
            'payment_code' => $paymentCode,
            'transaction_time' => $transactionTime,
            'status' => 'realizovano',
            'card_number' => $cardNumber,
        ]);

        return $senderAmount;
    }

    private function seedCardsForAccount(Account $account, User $user, array $cardTemplate): void
    {
        $account->cards()->create([
            'id' => (string) Str::uuid(),
            'card_id' => $cardTemplate['card_prefix'].$this->randomDigits(8),
            'card_type' => $cardTemplate['card_type'],
            'expire_date' => $this->randomExpireDate(),
            'owner_name' => trim($user->first_name.' '.$user->last_name),
            'currency' => $account->currency,
            'cvv' => $this->randomDigits(3),
        ]);
    }

    private function randomAccount(User $user): ?Account
    {
        if ($user->accounts->isEmpty()) {
            return null;
        }

        return $user->accounts->random();
    }

    private function mostlyCardNumber(Account $account): ?string
    {
        if (random_int(1, 100) > 85) {
            return null;
        }

        return $account->cards()->inRandomOrder()->value('card_id');
    }

    private function affordableTransactionAmount(Account $senderAccount, Account $recipientAccount, bool $businessPayment, array $ledger): ?float
    {
        $available = $ledger[$senderAccount->id] ?? 0;

        if ($available <= 0) {
            return null;
        }

        $currency = $recipientAccount->currency;
        $desiredAmount = $this->transactionAmountForCurrency($currency, $businessPayment);
        $maxRecipientAmount = $this->convertAmount($available * 0.2, $senderAccount->currency, $recipientAccount->currency);
        $amount = min($desiredAmount, $maxRecipientAmount);

        if ($amount < ($currency === 'EUR' ? 1 : 100)) {
            return null;
        }

        return round($amount, 2);
    }

    private function transactionAmountForCurrency(string $currency, bool $businessPayment): float
    {
        if ($currency === 'EUR') {
            return $businessPayment
                ? $this->randomBalance(5, 350, 2)
                : $this->randomBalance(5, 200, 2);
        }

        return $businessPayment
            ? $this->randomBalance(350, 25000, 2)
            : $this->randomBalance(500, 15000, 2);
    }

    private function convertAmount(float $amount, string $fromCurrency, string $toCurrency): float
    {
        return app(ExchangeRateService::class)->convertAtMarket($amount, $fromCurrency, $toCurrency);
    }

    private function peerPaymentPurpose(): string
    {
        $purposes = [
            'Prenos sredstava',
            'Pozajmica',
            'Refundacija troskova',
            'Placanje usluge',
            'Privatni transfer',
        ];

        return $purposes[array_rand($purposes)];
    }

    private function randomDigits(int $length): string
    {
        $digits = '';

        for ($index = 0; $index < $length; $index++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    private function uniqueDigits(int $length, string $modelClass, string $column): string
    {
        do {
            $digits = $this->randomDigits($length);
        } while ($modelClass::query()->where($column, $digits)->exists());

        return $digits;
    }

    private function makeAccountNumber(string $currency, ?string $suffix = null): string
    {
        $accountNumbers = app(AccountNumberService::class);
        $accountNumber = $accountNumbers->generate($currency, $suffix);

        while (Account::query()->where('account_number', $accountNumber)->exists()) {
            $accountNumber = $accountNumbers->generate($currency, $suffix);
        }

        return $accountNumber;
    }

    private function fakeCompanyName(string $categoryKey): string
    {
        $prefixes = [
            'groceries' => ['Maxi', 'Fresh', 'Market', 'Dunav'],
            'restaurants' => ['Gastro', 'Urban', 'Bistro', 'Ukus'],
            'fuel' => ['Petrol', 'Eko', 'Auto', 'Nis'],
            'utilities' => ['Elektro', 'Toplana', 'Komunal', 'Vodovod'],
            'telecom' => ['Tele', 'Net', 'Mobile', 'Fiber'],
            'transport' => ['Taxi', 'Bus', 'Metro', 'Prevoz'],
            'pharmacy' => ['Apoteka', 'Zdravlje', 'Medi', 'Vita'],
            'clothing' => ['Moda', 'Style', 'Obuca', 'Trend'],
            'electronics' => ['Tech', 'Digital', 'Chip', 'Elektro'],
            'fitness' => ['Fit', 'Gym', 'Sport', 'Active'],
        ];

        $suffixes = ['Plus', 'Centar', 'Point', 'Group', 'Express', 'Pro'];
        $categoryPrefixes = $prefixes[$categoryKey] ?? ['Biz'];

        return $categoryPrefixes[array_rand($categoryPrefixes)].' '.$suffixes[array_rand($suffixes)];
    }

    private function randomBalance(int|float $min, int|float $max, int $decimals): int|float
    {
        if ($decimals === 0) {
            return random_int((int) $min, (int) $max);
        }

        $scale = 10 ** $decimals;

        return random_int((int) round($min * $scale), (int) round($max * $scale)) / $scale;
    }

    private function randomExpireDate(): string
    {
        return now('UTC')
            ->addDays(random_int(365, 1825))
            ->setTime(random_int(0, 23), random_int(0, 59), 0)
            ->format('Y-m-d\\TH:i:s\\Z');
    }
}
