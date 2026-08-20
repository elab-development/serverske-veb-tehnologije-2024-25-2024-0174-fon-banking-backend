<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function store(Request $request, ExchangeRateService $exchangeRates): JsonResponse
    {
        $userId = Auth::id();

        $validated = $request->validate([
            'senderAccount' => ['required', 'string'],
            'recipientAccount' => ['required', 'string'],
            'recipientName' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string'],
            'paymentPurpose' => ['nullable', 'string'],
            'paymentCode' => ['nullable', 'string'],
            'model' => ['nullable', 'integer'],
            'referenceNumber' => ['nullable', 'string'],
        ]);

        $account = Account::query()
            ->where('account_id', $validated['senderAccount'])
            ->where('user_id', $userId)
            ->firstOrFail();

        $recipientAccount = Account::query()
            ->where('account_id', $validated['recipientAccount'])
            ->first();

        if (! $recipientAccount) {
            return response()->json([
                'message' => 'Recipient account does not exist.',
            ], 422);
        }

        $recipientCurrency = $recipientAccount->currency;
        $senderCurrency = $account->currency;
        if (strtoupper($validated['currency']) !== $senderCurrency) {
            return response()->json([
                'message' => 'Transfer currency must match the sender account currency.',
            ], 422);
        }

        $senderAmount = (float) $validated['amount'];
        $conversion = $exchangeRates->transferFromSenderAmount($senderAmount, $senderCurrency, $recipientCurrency);
        $recipientAmount = $conversion['recipientAmount'];
        $exchangeRate = $conversion['exchangeRate'];

        if ($account->balance < $senderAmount) {
            return response()->json([
                'message' => 'Insufficient funds.',
            ], 422);
        }

        $transaction = Transaction::create([
            'id' => 'txn-'.Str::upper(Str::random(4)),
            'recipient_account' => $validated['recipientAccount'],
            'recipient_name' => $validated['recipientName'],
            'sender_account' => $validated['senderAccount'],
            'model' => $validated['model'] ?? null,
            'reference_number' => $validated['referenceNumber'] ?? null,
            'amount' => $recipientAmount,
            'currency' => $recipientCurrency,
            'sender_amount' => $senderAmount,
            'sender_currency' => $senderCurrency,
            'recipient_amount' => $recipientAmount,
            'recipient_currency' => $recipientCurrency,
            'exchange_rate' => $exchangeRate,
            'payment_purpose' => $validated['paymentPurpose'] ?? null,
            'payment_code' => $validated['paymentCode'] ?? null,
            'transaction_time' => now(),
            'status' => 'izvrsena',
            'card_number' => null,
        ]);

        return response()->json([
            'id' => $transaction->id,
            'recipientAccount' => $transaction->recipient_account,
            'recipientName' => $transaction->recipient_name,
            'senderAccount' => $transaction->sender_account,
            'model' => $transaction->model,
            'referenceNumber' => $transaction->reference_number,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'senderAmount' => $transaction->sender_amount,
            'senderCurrency' => $transaction->sender_currency,
            'recipientAmount' => $transaction->recipient_amount,
            'recipientCurrency' => $transaction->recipient_currency,
            'exchangeRate' => $transaction->exchange_rate,
            'paymentPurpose' => $transaction->payment_purpose,
            'paymentCode' => $transaction->payment_code,
            'transactionTime' => $transaction->transaction_time?->toISOString(),
            'status' => $transaction->status,
            'cardNumber' => $transaction->card_number,
        ], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $accountIds = Account::query()
            ->where('user_id', Auth::id())
            ->pluck('account_id');

        $query = Transaction::query()
            ->where(function (Builder $query) use ($accountIds): void {
                $query->whereIn('sender_account', $accountIds)
                    ->orWhereIn('recipient_account', $accountIds);
            });

        return $this->paginatedResponse($request, $query, $accountIds->all());
    }

    public function index(Request $request, string $accountId): JsonResponse
    {
        $userId = Auth::id();

        $account = Account::query()
            ->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $query = Transaction::query()
            ->where(function ($query) use ($accountId): void {
                $query->where('sender_account', $accountId)
                    ->orWhere('recipient_account', $accountId);
            });

        return $this->paginatedResponse($request, $query, [$account->account_id]);
    }

    private function paginatedResponse(Request $request, Builder $query, array $accountIds): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'direction' => ['sometimes', 'in:income,expense'],
            'period' => ['sometimes', 'in:7days,30days'],
            'method' => ['sometimes', 'in:card,pending'],
            'category' => ['sometimes', 'in:groceries,restaurants,fuel,utilities,telecom,transport,pharmacy,clothing,electronics,fitness,other'],
        ]);

        if (! empty($validated['search'])) {
            $search = '%'.addcslashes($validated['search'], '%_\\').'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('recipient_name', 'like', $search)
                    ->orWhere('sender_account', 'like', $search)
                    ->orWhere('recipient_account', 'like', $search)
                    ->orWhere('payment_purpose', 'like', $search)
                    ->orWhere('payment_code', 'like', $search);
            });
        }

        if (($validated['direction'] ?? null) === 'expense') {
            $query->whereIn('sender_account', $accountIds);
        } elseif (($validated['direction'] ?? null) === 'income') {
            $query->whereNotIn('sender_account', $accountIds);
        }

        if (isset($validated['period'])) {
            $days = $validated['period'] === '7days' ? 7 : 30;
            $query->where('transaction_time', '>=', now()->subDays($days));
        }

        if (($validated['method'] ?? null) === 'card') {
            $query->whereNotNull('card_number');
        } elseif (($validated['method'] ?? null) === 'pending') {
            $query->where('status', 'na_cekanju');
        }

        if (isset($validated['category'])) {
            $suffixes = [
                'groceries' => '5411',
                'restaurants' => '5812',
                'fuel' => '5541',
                'utilities' => '4900',
                'telecom' => '4814',
                'transport' => '4111',
                'pharmacy' => '5912',
                'clothing' => '5691',
                'electronics' => '5732',
                'fitness' => '7997',
            ];
            $category = $validated['category'];

            $query->where(function (Builder $query) use ($accountIds, $category, $suffixes): void {
                $applyCounterpartySuffix = function (Builder $query, string $operator, string $suffix) use ($accountIds): void {
                    $query->where(function (Builder $query) use ($accountIds, $operator, $suffix): void {
                        $query->where(function (Builder $query) use ($accountIds, $operator, $suffix): void {
                            $query->whereIn('sender_account', $accountIds)
                                ->where('recipient_account', $operator, '%'.$suffix);
                        })->orWhere(function (Builder $query) use ($accountIds, $operator, $suffix): void {
                            $query->whereNotIn('sender_account', $accountIds)
                                ->where('sender_account', $operator, '%'.$suffix);
                        });
                    });
                };

                if ($category === 'other') {
                    foreach ($suffixes as $suffix) {
                        $query->where(function (Builder $query) use ($applyCounterpartySuffix, $suffix): void {
                            $applyCounterpartySuffix($query, 'not like', $suffix);
                        });
                    }
                } else {
                    $applyCounterpartySuffix($query, 'like', $suffixes[$category]);
                }
            });
        }

        $transactions = $query
            ->orderByDesc('transaction_time')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)
            ->through(fn (Transaction $transaction): array => $this->serializeTransaction($transaction));

        return response()->json($transactions);
    }

    private function serializeTransaction(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'recipientAccount' => $transaction->recipient_account,
            'recipientName' => $transaction->recipient_name,
            'senderAccount' => $transaction->sender_account,
            'model' => $transaction->model,
            'referenceNumber' => $transaction->reference_number,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'senderAmount' => $transaction->sender_amount,
            'senderCurrency' => $transaction->sender_currency,
            'recipientAmount' => $transaction->recipient_amount,
            'recipientCurrency' => $transaction->recipient_currency,
            'exchangeRate' => $transaction->exchange_rate,
            'paymentPurpose' => $transaction->payment_purpose,
            'paymentCode' => $transaction->payment_code,
            'transactionTime' => $transaction->transaction_time?->toISOString(),
            'status' => $transaction->status,
            'cardNumber' => $transaction->card_number,
        ];
    }
}
