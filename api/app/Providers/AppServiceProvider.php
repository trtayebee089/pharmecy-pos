<?php

namespace App\Providers;

use App\Models\User;
use App\Services\DocumentNumbers;
use App\Services\Integrations;
use App\Services\LoyaltySettings;
use App\Services\MailSettings;
use App\Services\Pricing;
use App\Services\StockLedger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Pricing::class);
        $this->app->scoped(LoyaltySettings::class);
        $this->app->singleton(StockLedger::class);
        $this->app->singleton(DocumentNumbers::class);
        $this->app->scoped(Integrations::class);
    }

    public function boot(): void
    {
        // Catch silently discarded attributes during development.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Admin passes every permission check; everyone else needs the explicit permission.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        // Named limiters: each keeps its own count. (Plain `throttle:10,1` shares one counter
        // per IP across every route that uses it, so browsing would use up the checkout limit.)
        RateLimiter::for('customer-api', fn (Request $r) => Limit::perMinute(180)->by($r->ip()));
        RateLimiter::for('customer-auth', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('medicine-requests', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('order-tracking', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('feeds', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('staff-login', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('integration-tests', fn (Request $r) => Limit::perMinute(10)->by((string) ($r->user()?->id ?? $r->ip())));

        // SMTP from Administration › Integrations, read only when something actually sends mail.
        $this->app->afterResolving('mail.manager', fn () => $this->app->make(MailSettings::class)->apply());
    }
}
