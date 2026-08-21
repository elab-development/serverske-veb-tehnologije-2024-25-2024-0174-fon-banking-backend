<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function index(AccountNumberService $accountNumbers): JsonResponse
    {
        $userId = Auth::id();

        $accounts = Account::query()
            ->where('user_id', $userId)
            ->get()
            ->map(function (Account $account) use ($accountNumbers): array {
                return [
                    'title' => $account->title,
                    'name' => $account->name,
                    'accountId' => $account->account_number,
                    'iban' => $account->iban,
                    'balance' => $account->balance,
                    'color' => $account->color,
                    'currency' => $account->currency,
                    'qrEligible' => $accountNumbers->isQrEligible($account->account_number, $account->currency),
                ];
            });

        return response()->json($accounts);
    }
}
