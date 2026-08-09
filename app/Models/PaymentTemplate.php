<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'receiver_name',
        'receiver_account_number',
        'payment_code',
        'reference_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
