<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinEnrollmentToken extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'token_hash',
        'purpose',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
