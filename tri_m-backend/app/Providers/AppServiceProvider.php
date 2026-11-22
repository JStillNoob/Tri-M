<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        RateLimiter::for('pos-login', function (Request $request): array {
            $email = $request->input('email');
            $email = is_string($email) ? mb_strtolower($email) : '';

            return [
                Limit::perMinute(30)->by($request->ip()),
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('pos-import', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
    }
}
