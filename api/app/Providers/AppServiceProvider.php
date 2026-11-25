<?php

namespace App\Providers;

use App\Models\User;
use App\Services\DocumentNumbers;
use App\Services\LoyaltySettings;
use App\Services\Pricing;
use App\Services\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Pricing::class);
        $this->app->scoped(LoyaltySettings::class);
        $this->app->singleton(StockLedger::class);
        $this->app->singleton(DocumentNumbers::class);
    }

    public function boot(): void
    {
        // Catch silently discarded attributes during development.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Admin passes every permission check; everyone else needs the explicit permission.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
