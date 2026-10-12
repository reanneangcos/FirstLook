<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreviewEsiRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_demonstrates_all_branches_without_provider_calls_or_study_records(): void
    {
        Http::preventStrayRequests();
        $before = ScreeningSession::count();
        $approvals = config('esi.approvals');
        $this->assertSame(0, Artisan::call('esi:preview', ['--json' => true, '--no-interaction' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($report['study_eligible']);
        $this->assertCount(6, $report['rules']);
        $this->assertTrue($report['rules']['ESI4-D-PROVISIONAL']['is_study_adaptation']);
        $this->assertSame([1, 2, 2, 2, 5, 4, 3, null, null, null, null, 1, null, 4, 3, 5, null, null], array_map(fn (array $case): ?int => $case['result']['candidate_priority'], $report['examples']));
        $this->assertSame('AHRQ 05-0046-2', $report['source_document']['publication_number']);
        foreach ($report['examples'] as $case) {
            $this->assertSame('draft_preview', $case['result']['status']);
            $this->assertNull($case['result']['priority']);
            $this->assertFalse($case['result']['study_eligible']);
        }
        $this->assertSame($before, ScreeningSession::count());
        $this->assertSame($approvals, config('esi.approvals'));
        Http::assertNothingSent();
    }

    public function test_human_readable_preview_is_available(): void
    {
        $this->artisan('esi:preview')->expectsOutputToContain('Most likely ESI 3')
            ->expectsOutputToContain('No OpenAI request')->assertSuccessful();
    }

    public function test_selectable_handbook_adaptation_uses_its_own_mode_without_activating_approvals(): void
    {
        $engine = app(RuleLayer::class);
        $this->assertInstanceOf(EsiV4RuleLayer::class, $engine);
        $this->assertStringContainsString('-provisional-d+', $engine->version());
        $this->assertSame([], config('esi.approvals'));
        $rules = (new EsiV4RuleCatalog(deferVitalSigns: true))->rules();
        $this->assertFalse($rules['ESI4-D-PROVISIONAL']['approved']);
    }
}
