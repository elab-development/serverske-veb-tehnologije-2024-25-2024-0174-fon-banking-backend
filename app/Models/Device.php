<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updated(function (Device $device): void {
            if ($device->wasChanged('is_trusted') && ! $device->is_trusted) {
                $device->user->tokens()
                    ->whereIn('name', ['device:'.$device->getKey(), $device->device_identifier])
                    ->delete();
            }
        });
    }

    protected $fillable = [
        'user_id',
        'device_identifier',
        'device_name',
        'is_trusted',
        'last_login_at',
    ];

    protected $casts = [
        'is_trusted' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
