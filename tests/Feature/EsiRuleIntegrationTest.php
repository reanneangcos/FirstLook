<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Models\User;
use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MockScreening;
use Tests\TestCase;

class EsiRuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['triage.api_key' => 'test-only', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_same_accepted_facts_are_saved_for_b_without_rewriting_a_or_calling_the_provider_again(): void
    {
        $output = MockScreening::output(2);
        $output['extracted_facts'][] = ['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34'];
        $raw = json_encode(MockScreening::envelope($output), JSON_PRETTY_PRINT);
        Http::fake(['api.openai.com/*' => Http::response($raw)]);

        $this->post('/admin/screenings', MockScreening::input())->assertRedirect();

        $session = ScreeningSession::firstOrFail();
        $this->assertSame(2, $session->method_a_priority);
        $this->assertSame($raw, $session->original_response);
        $this->assertSame($output, $session->parsed_output);
        $this->assertSame(['extracted_facts' => $output['extracted_facts'], 'clinical_assessment' => []], $session->method_b_input);
        $this->assertSame('needs_review', $session->method_b_status);
        $this->assertNull($session->method_b_priority);
        $this->assertSame((new EsiV4RuleCatalog)->version(), $session->method_b_rule_version);
        $this->assertSame('A', $session->method_b_result['stopped_at']);
        $this->assertSame('pending_review', $session->method_b_result['trace'][0]['reviewer_approval']['status']);
        $this->get('/admin/screenings/'.$session->id)->assertInertia(fn (Assert $page) => $page
            ->where('screening.method_a_priority', 2)->where('screening.method_b_status', 'needs_review')
            ->where('screening.method_b_result.trace.0.rule_id', 'ESI4-A'));
        Http::assertSentCount(1);
    }

    public function test_a_rule_failure_preserves_method_a_and_does_not_trigger_an_llm_retry(): void
    {
        $this->app->instance(RuleLayer::class, new class implements RuleLayer
        {
            public function version(): string
            {
                return 'failure-fixture-only';
            }

            public function evaluate(array $understoodFacts, array $clinicalAssessment = []): array
            {
                throw new \RuntimeException('Private diagnostic should not reach the result.');
            }
        });
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(4)))]);

        $this->post('/admin/screenings', MockScreening::input())->assertRedirect();

        $session = ScreeningSession::firstOrFail();
        $this->assertSame('completed', $session->processing_status);
        $this->assertSame(4, $session->method_a_priority);
        $this->assertSame('technical_failure', $session->method_b_status);
        $this->assertNull($session->method_b_priority);
        $this->assertStringNotContainsString('Private diagnostic', json_encode($session->method_b_result));
        Http::assertSentCount(1);
    }

    public function test_request_cannot_supply_clinical_assessments_or_approve_rules(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(1)))]);
        $input = MockScreening::input();
        $input['clinical_assessment'] = ['immediate_lifesaving_intervention_required' => true];
        $input['esi'] = ['approvals' => ['ESI4-A' => ['status' => 'approved']]];

        $this->post('/admin/screenings', $input)->assertRedirect();

        $session = ScreeningSession::firstOrFail();
        $this->assertSame([], $session->method_b_input['clinical_assessment']);
        $this->assertNull($session->method_b_priority);
        Http::assertSentCount(1);
    }

    public function test_missing_provider_response_leaves_b_unevaluated_and_historical_screenings_are_unchanged(): void
    {
        $old = ScreeningSession::factory()->create(['method_a_priority' => 3, 'method_a_status' => 'classified']);
        config(['triage.api_key' => '']);

        $this->post('/admin/screenings', MockScreening::input())->assertRedirect();

        $new = ScreeningSession::where('id', '!=', $old->id)->firstOrFail();
        $this->assertSame('not_evaluated', $new->method_b_status);
        $this->assertNull($new->method_b_result);
        $this->assertSame('not_implemented', $old->fresh()->method_b_status);
        $this->assertSame(3, $old->method_a_priority);
        Http::assertNothingSent();
    }
}
