<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'id',
        'recipient_account_id',
        'recipient_name',
        'sender_account_id',
        'model',
        'reference_number',
        'sender_amount',
        'sender_currency',
        'recipient_amount',
        'recipient_currency',
        'exchange_rate',
        'payment_purpose',
        'payment_code',
        'transaction_time',
        'status',
        'card_number',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'sender_amount' => 'float',
            'recipient_amount' => 'float',
            'exchange_rate' => 'float',
            'transaction_time' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sender_account_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'recipient_account_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class, 'card_number', 'card_id');
    }
}
