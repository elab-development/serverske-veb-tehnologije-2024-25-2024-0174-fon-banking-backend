<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::updated(function (User $user): void {
            if ($user->wasChanged('status') && $user->status === 'blocked') {
                $user->tokens()->delete();
                PinEnrollmentToken::query()
                    ->where('user_id', $user->getKey())
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => now()]);
            }
        });
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'jmbg',
        'phone_number',
        'email',
        'pin_hash',
        'status',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'pin_hash' => 'hashed',
        ];
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function paymentTemplates()
    {
        return $this->hasMany(PaymentTemplate::class);
    }
}
