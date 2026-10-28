<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningService;
use App\Services\Screening\StudyProtocol;
use App\Services\TriageRules\ResearchEsiRuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MockScreening;
use Tests\TestCase;

class ResearchStudyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.mode' => 'research_prototype', 'triage.api_key' => 'mock-only', 'triage.model' => 'gpt-6-luna']);
        config(['esi.research_approval.rule_version' => (new ResearchEsiRuleCatalog)->version()]);
        Http::preventStrayRequests();
    }

    public function test_paired_pilot_runs_on_the_reviewed_catalog_and_saves_independent_outputs_and_history(): void
    {
        [$input, $output] = $this->urinaryExample();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($output))]);
        $protocol = app(StudyProtocol::class);
        $this->assertTrue($protocol->readiness()['ready']);
        $this->assertSame(92, $protocol->readiness()['approved_rule_count']);
        $path = tempnam(sys_get_temp_dir(), 'research-pilot-');
        $manifest = ['split' => 'DEV', 'source_sha256' => str_repeat('c', 64), 'cases' => [
            ['split' => 'DEV', 'dataset_case_id' => 'FIXTURE-RESEARCH', 'variant_id' => 'FIXTURE-R1', 'language' => 'English', 'patient' => $input['patient']],
        ]];
        try {
            file_put_contents($path, json_encode($manifest));
            $options = ['input' => $path, '--run-id' => 'fixture-research-paired', '--paired-pilot' => true];
            $this->artisan('study:dev-baseline', $options)->assertSuccessful();
            $this->artisan('study:dev-baseline', $options)->assertSuccessful();
            $saved = ScreeningSession::sole();
            $this->assertSame(2, $saved->method_a_priority);
            $this->assertSame(4, $saved->method_b_priority);
            $this->assertSame('classified', $saved->method_b_status);
            $this->assertSame($output, $saved->parsed_output);
            $this->assertSame($output['normalized_facts'], $saved->method_b_input['normalized_facts']);
            $this->assertSame(['extracted_facts', 'normalized_facts', 'clinical_assessment'], array_keys($saved->method_b_input));
            $this->assertSame('screening-study-v9-research', $saved->prompt_version);
            $this->assertSame('patient-concepts-v4-research', $saved->request_settings['normalization_version']);
            $this->assertSame('nurse_reviewed_research_adaptation', $saved->request_settings['criteria_status']);
            $this->assertSame(3, $saved->patientVisit->messages()->count());
            $history = $saved->patientVisit->messages()->where('source', 'model')->sole()->content;
            $this->assertStringContainsString('ESI 2', $history);
            $this->assertStringContainsString('ESI 4', $history);
            $this->assertCount(1, $saved->attempts);
            Http::assertSentCount(1);
            Http::assertSent(fn ($request): bool => count(json_decode($request['input'][1]['content'], true)) === 15
                && ! str_contains(json_encode($request->data()), 'FIXTURE-RESEARCH')
                && ! str_contains(json_encode($request->data()), 'Abigail'));
            $manifest['cases'][0]['split'] = 'TEST';
            file_put_contents($path, json_encode($manifest));
            $this->artisan('study:dev-baseline', $options)->assertFailed();
            Http::assertSentCount(1);
        } finally {
            unlink($path);
        }
    }

    public function test_changing_only_method_a_prediction_does_not_change_method_b(): void
    {
        [$input, $first] = $this->urinaryExample();
        $second = array_replace($first, ['priority' => 5, 'explanation' => 'Different mocked Method A prediction.']);
        Http::fake(['api.openai.com/*' => Http::sequence()->push(MockScreening::envelope($first))->push(MockScreening::envelope($second))]);
        $service = app(ScreeningService::class);
        $a = $service->screen($input, null, studyCriteria: true);
        $b = $service->screen($input, null, studyCriteria: true);
        $this->assertSame(2, $a->method_a_priority);
        $this->assertSame(5, $b->method_a_priority);
        $this->assertSame(4, $a->method_b_priority);
        $this->assertSame($a->method_b_result, $b->method_b_result);
        Http::assertSentCount(2);
    }

    public function test_legacy_method_a_only_command_still_skips_rule_execution(): void
    {
        [$input, $output] = $this->urinaryExample();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($output))]);
        $saved = app(ScreeningService::class)->screen($input, null, evaluateRules: false);
        $this->assertSame(2, $saved->method_a_priority);
        $this->assertSame('not_evaluated', $saved->method_b_status);
        $this->assertNull($saved->method_b_input);
    }

    private function urinaryExample(): array
    {
        $text = 'Burning and frequent urination, no fever.';
        $input = ['language' => 'English', 'patient' => PatientFields::only(['age' => 40, 'main_complaint' => 'Urinary symptoms', 'symptom_description' => $text])];
        $facts = [['field' => 'age', 'state' => 'reported', 'value' => '40', 'evidence' => '40'],
            ['field' => 'symptom_description', 'state' => 'reported', 'value' => $text, 'evidence' => $text]];
        $concepts = [];
        foreach (['burning_urination' => 'reported', 'urinary_frequency' => 'reported', 'fever' => 'absent'] as $concept => $state) {
            $concepts[] = ['concept' => $concept, 'state' => $state, 'evidence' => [['field' => 'symptom_description', 'quote' => $text]]];
        }
        $output = ['status' => 'classified', 'priority' => 2, 'extracted_facts' => $facts, 'normalized_facts' => $concepts,
            'missing_information' => [], 'explanation' => 'Mocked Method A output deliberately disagrees with Method B.'];

        return [$input, $output];
    }
}
