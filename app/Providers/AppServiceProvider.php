<?php

namespace App\Providers;

use App\Core\Ctx;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One request context per request (café, signed-in user, permissions).
        $this->app->scoped(Ctx::class, fn () => new Ctx());
    }

    public function boot(): void
    {
        // Per IP and per café; tighter on login and OTP (SRS 16.6).
        RateLimiter::for('api', function (Request $r) {
            $key = ($r->header('X-Tenant') ?: $r->getHost()) . '|' . $r->ip();
            return str_contains($r->path(), '/auth/') || str_contains($r->path(), '/otp')
                ? Limit::perMinute(20)->by("auth|$key")
                : Limit::perMinute(600)->by($key);
        });
    }
}
