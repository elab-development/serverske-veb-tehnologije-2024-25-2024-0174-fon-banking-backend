<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Card;
use App\Services\AccountNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CardController extends Controller
{
    public function index(string $accountId, AccountNumberService $accountNumbers): JsonResponse
    {
        $userId = Auth::id();

        $account = Account::query()
            ->where('account_number', $accountNumbers->normalize($accountId))
            ->where('user_id', $userId)
            ->firstOrFail();

        $cards = Card::query()
            ->where('account_id', $account->id)
            ->get()
            ->map(function (Card $card): array {
                return [
                    'accountId' => $card->account->account_number,
                    'cardId' => $card->card_id,
                    'cardType' => $card->card_type,
                    'expireDate' => $card->expire_date,
                    'ownerName' => $card->owner_name,
                    'currency' => $card->currency,
                    'cvv' => $card->cvv,
                ];
            });

        return response()->json($cards);
    }
}
