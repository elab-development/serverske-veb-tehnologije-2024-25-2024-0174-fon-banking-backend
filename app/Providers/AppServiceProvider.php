<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('activation', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('activation:'.$request->ip()));

        RateLimiter::for('pin-setup', fn (Request $request): array => [
            Limit::perMinute(5)->by('pin-setup-ip:'.$request->ip()),
            Limit::perMinute(5)->by('pin-setup-token:'.hash('sha256', (string) $request->input('enrollment_token'))),
        ]);

        RateLimiter::for('pin-login', fn (Request $request): array => [
            Limit::perMinute(5)->by('pin-login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('pin-login-device:'.$this->deviceKey($request)),
        ]);

        RateLimiter::for('pin-confirmation', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('pin-confirmation:'.($request->user()?->getAuthIdentifier() ?? 'guest').'|'.$request->ip()));

    }

    private function deviceKey(Request $request): string
    {
        return hash('sha256', (string) $request->input('device_identifier'));
    }
}
