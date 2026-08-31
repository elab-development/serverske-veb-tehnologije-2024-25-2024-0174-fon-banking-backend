<?php

namespace App\Models;

use App\Services\ExchangeRateService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'id',
        'account_number',
        'iban',
        'user_id',
        'title',
        'name',
        'color',
        'currency',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function balance(): Attribute
    {
        return Attribute::get(function (): float {
            $incoming = $this->incomingTransactions()
                ->get()
                ->sum(fn (Transaction $transaction): float => $this->incomingAmount($transaction));

            $outgoing = $this->outgoingTransactions()
                ->get()
                ->sum(fn (Transaction $transaction): float => $this->outgoingAmount($transaction));

            return round($incoming - $outgoing, 2);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'account_id', 'id');
    }

    public function outgoingTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'sender_account_id');
    }

    public function incomingTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'recipient_account_id');
    }

    private function incomingAmount(Transaction $transaction): float
    {
        return app(ExchangeRateService::class)->convertAtMarket(
            $transaction->recipient_amount,
            $transaction->recipient_currency,
            $this->currency,
        );
    }

    private function outgoingAmount(Transaction $transaction): float
    {
        return app(ExchangeRateService::class)->convertAtMarket(
            $transaction->sender_amount,
            $transaction->sender_currency,
            $this->currency,
        );
    }
}
