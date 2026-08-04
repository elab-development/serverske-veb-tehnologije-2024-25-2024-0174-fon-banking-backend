<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        Passkeys::ignoreRoutes();
        Passkeys::useUserModel(User::class);
        Passkeys::usePasskeyModel(Passkey::class);
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('activation', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('activation:'.$request->ip()));

        RateLimiter::for('pin-setup', fn (Request $request): array => [
            Limit::perMinute(5)->by('pin-setup-ip:'.$request->ip()),
            Limit::perMinute(5)->by('pin-setup-device:'.$this->deviceKey($request)),
        ]);

        RateLimiter::for('pin-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('pin-login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('pin-login-device:'.$this->deviceKey($request)),
        ]);

        RateLimiter::for('pin-confirmation', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('pin-confirmation:'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip()));

        RateLimiter::for('passkey-options', fn (Request $request): array => [
            Limit::perMinute(10)->by('passkey-options-ip:'.$request->ip()),
            Limit::perMinute(10)->by('passkey-options-device:'.$this->deviceKey($request)),
        ]);

        RateLimiter::for('passkey-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('passkey-login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('passkey-login-device:'.$this->deviceKey($request)),
        ]);

        RateLimiter::for('passkey-management', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('passkey-management:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function deviceKey(Request $request): string
    {
        return hash('sha256', (string) $request->input('device_identifier'));
    }
}
