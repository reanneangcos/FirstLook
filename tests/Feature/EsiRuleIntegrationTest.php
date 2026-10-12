<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Models\User;
use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
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
        $this->assertSame((new EsiV4RuleCatalog(deferVitalSigns: true))->version(), $session->method_b_rule_version);
        $this->assertSame('A', $session->method_b_result['stopped_at']);
        $this->assertSame('May 2005', $session->method_b_result['source_document']['edition']);
        $this->assertSame('not_assessed', $session->method_b_result['decision_d']['status']);
        $this->get('/admin/screenings/'.$session->id)->assertInertia(fn (Assert $page) => $page
            ->where('screening.method_a_priority', 2)->where('screening.method_b_status', 'needs_review')
            ->where('screening.method_b_result.output_kind', 'provisional_esi_prediction'));
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

    public function test_provisional_b_uses_only_shared_facts_even_when_a_changes(): void
    {
        config(['esi.mode' => 'provisional_patient_report']);
        /** Fictional software tokens only. Never add these approval records to application config. */
        $definition = ['decision_point' => 'C', 'priority' => 3,
            'conditions' => [['field' => 'main_complaint', 'state' => 'reported', 'values' => ['TEST_SUPPORT']]],
            'exclusions' => [
                'A' => [['field' => 'symptom_description', 'state' => 'reported', 'values' => ['TEST_A']]],
                'B' => [['field' => 'other_symptoms', 'state' => 'absent', 'values' => ['TEST_B']]],
            ],
            'handbook_section' => 'TEST ONLY', 'adaptation_rationale' => 'TEST ONLY',
            'resource_rationale' => 'TEST ONLY', 'explanation' => 'TEST ONLY; no clinical meaning.'];
        config(['esi.provisional_rules' => ['TEST-C' => $definition],
            'esi.provisional_approvals' => ['TEST-C' => ['status' => 'approved', 'reviewer' => 'TEST ONLY',
                'reviewed_at' => '2026-10-10', 'record_id' => 'TEST ONLY', 'rule_version' => ProvisionalEsiRuleCatalog::VERSION,
                'definition_hash' => ProvisionalEsiRuleCatalog::definitionHash($definition)]]]);
        $input = MockScreening::input();
        $input['patient'] = ['age' => 34, 'main_complaint' => 'TEST_SUPPORT', 'symptom_description' => 'TEST_A', 'other_symptoms' => 'TEST_B'];
        $output = MockScreening::output(1);
        $output['extracted_facts'] = [];
        foreach ($input['patient'] as $field => $value) {
            $output['extracted_facts'][] = ['field' => $field, 'state' => $field === 'other_symptoms' ? 'absent' : 'reported',
                'value' => (string) $value, 'evidence' => (string) $value];
        }
        $sequence = Http::sequence()->push(MockScreening::envelope($output));
        $output['priority'] = 5;
        $sequence->push(MockScreening::envelope($output));
        Http::fake(['api.openai.com/*' => $sequence]);

        $this->post('/admin/screenings', $input)->assertRedirect();
        $this->post('/admin/screenings', $input)->assertRedirect();

        $sessions = ScreeningSession::all();
        $this->assertEqualsCanonicalizing([1, 5], $sessions->pluck('method_a_priority')->all());
        $this->assertSame([3, 3], $sessions->pluck('method_b_priority')->all());
        $this->assertSame($sessions[0]->method_b_input, $sessions[1]->method_b_input);
        $this->assertSame($sessions[0]->method_b_result, $sessions[1]->method_b_result);
        $this->assertSame('not_assessed', $sessions[0]->method_b_result['decision_d']['status']);
        Http::assertSentCount(2);
    }

    public function test_clinical_algorithm_remains_a_separate_explicit_mode(): void
    {
        config(['esi.mode' => 'clinical_esi_v4']);
        $engine = $this->app->make(RuleLayer::class);
        $this->assertSame((new EsiV4RuleCatalog)->version(), $engine->version());
        $result = $engine->evaluate([['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34']]);
        $this->assertNull($result['priority']);
        $this->assertSame('A', $result['stopped_at']);
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
