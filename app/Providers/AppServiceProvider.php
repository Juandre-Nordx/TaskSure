<?php

namespace App\Providers;

use App\Contracts\WhatsAppGateway;
use App\Services\UnconfiguredWhatsApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, UnconfiguredWhatsApp::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by(strtolower($r->input('email', '')).'|'.$r->ip()), Limit::perMinute(30)->by($r->ip())]);
        RateLimiter::for('reset', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        RateLimiter::for('mobile', fn (Request $r) => Limit::perMinute(120)->by($r->user()->id));
        RateLimiter::for('mobile-uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()->id));
        Paginator::useTailwind();
    }
}
