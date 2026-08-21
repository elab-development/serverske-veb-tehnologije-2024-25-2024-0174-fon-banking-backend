<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\NbsQrController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\PasskeyLoginController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'active-user'])->prefix('v1')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/accounts', [AccountController::class, 'index']);
    Route::apiResource('exchange-rates', ExchangeRateController::class)->only('index');
    Route::get('/accounts/{accountId}/cards', [CardController::class, 'index']);
    Route::post('/transactions/transfer', [TransactionController::class, 'store']);
    Route::post('/qr/validate', [NbsQrController::class, 'validateQr']);
    Route::post('/qr/generate', [NbsQrController::class, 'generate']);
    Route::get('/transactions', [TransactionController::class, 'history']);
    Route::get('/accounts/{accountId}/transactions', [TransactionController::class, 'index']);
    Route::post('/auth/confirm-pin', [AuthController::class, 'confirmPin'])
        ->middleware('throttle:pin-confirmation');
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('throttle:passkey-management')->group(function (): void {
        Route::get('/passkeys', [PasskeyController::class, 'index']);
        Route::post('/passkeys/registration/options', [PasskeyController::class, 'registrationOptions'])
            ->middleware('throttle:passkey-options');
        Route::post('/passkeys', [PasskeyController::class, 'store']);
        Route::delete('/passkeys/{passkey}', [PasskeyController::class, 'destroy']);
    });
});

Route::middleware('api')->prefix('v1')->group(function (): void {
    Route::post('/activate', [AuthController::class, 'activate'])
        ->middleware('throttle:activation');
    Route::post('/set_pin', [AuthController::class, 'setupPin'])
        ->middleware('throttle:pin-setup');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:pin-login');
    Route::post('/auth/passkeys/login/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:passkey-options');
    Route::post('/auth/passkeys/login', [PasskeyLoginController::class, 'store'])
        ->middleware('throttle:passkey-login');
});
