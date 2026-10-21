<?php

namespace App\Providers;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleLayer;
use App\Services\TriageRules\RuleLayer;
use App\Services\TriageRules\ResearchEsiRuleLayer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EsiV4RuleCatalog::class, fn (): EsiV4RuleCatalog => new EsiV4RuleCatalog(config('esi.approvals', [])));
        $this->app->bind(ProvisionalEsiRuleCatalog::class, fn (): ProvisionalEsiRuleCatalog => new ProvisionalEsiRuleCatalog(
            config('esi.provisional_rules', []), config('esi.provisional_approvals', []),
        ));
        $this->app->bind(RuleLayer::class, fn ($app): RuleLayer => match (config('esi.mode')) {
            'research_prototype' => $app->make(ResearchEsiRuleLayer::class),
            'provisional_patient_report' => $app->make(ProvisionalEsiRuleLayer::class),
            'clinical_esi_v4' => $app->make(EsiV4RuleLayer::class),
            'handbook_pre_screening' => new EsiV4RuleLayer(new EsiV4RuleCatalog(config('esi.approvals', []), deferVitalSigns: true)),
            default => throw new \InvalidArgumentException('Unsupported ESI rule mode.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
