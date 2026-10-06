<?php

namespace App\Providers;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EsiV4RuleCatalog::class, fn (): EsiV4RuleCatalog => new EsiV4RuleCatalog(config('esi.approvals', [])));
        $this->app->bind(RuleLayer::class, EsiV4RuleLayer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
