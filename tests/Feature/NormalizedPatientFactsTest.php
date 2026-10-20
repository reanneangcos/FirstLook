<?php

namespace Tests\Feature;

use App\Services\Screening\InvalidModelOutput;
use App\Services\Screening\NormalizedPatientFacts;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningOutput;
use App\Services\Screening\ScreeningService;
use App\Services\TriageRules\DraftPatientMappings;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleLayer;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockScreening;
use Tests\TestCase;

class NormalizedPatientFactsTest extends TestCase
{
    use RefreshDatabase;

    private const A_REPORT = 'Breathing without help now, responding to voice, and no uncontrolled bleeding.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['triage.api_key' => 'mock-only', 'triage.retry_delay_ms' => 0]);
    }

    private function fact(string $field, string $quote, string $state = 'reported'): array
    {
        return ['field' => $field, 'state' => $state, 'value' => $quote, 'evidence' => $quote];
    }

    private function concept(string $code, string $field, string $quote, string $state = 'reported'): array
    {
        return ['concept' => $code, 'state' => $state, 'evidence' => [['field' => $field, 'quote' => $quote]]];
    }

    /** Handwritten fictional extraction fixture, not a claim of live model or clinical accuracy. */
    private function headache(string $complaint = 'My head hurts.'): array
    {
        $description = 'This is the worst headache I have ever had and it started suddenly.';
        $history = 'No history of headaches.';
        $facts = [$this->fact('age', '34'), $this->fact('main_complaint', $complaint),
            $this->fact('symptom_description', $description), $this->fact('known_conditions', $history, 'absent'),
            $this->fact('other_symptoms', self::A_REPORT)];

        return ['extracted_facts' => $facts, 'normalized_facts' => [
            $this->concept('headache', 'main_complaint', $complaint),
            $this->concept('worst_ever_headache', 'symptom_description', $description),
            $this->concept('sudden_onset', 'symptom_description', $description),
            $this->concept('previous_headaches', 'known_conditions', $history, 'absent'),
            $this->concept('breathing_without_help', 'other_symptoms', self::A_REPORT),
            $this->concept('responds_to_voice', 'other_symptoms', self::A_REPORT),
            $this->concept('uncontrolled_bleeding', 'other_symptoms', self::A_REPORT, 'absent'),
        ]];
    }

    private function engine(bool $approved = false): ProvisionalEsiRuleLayer
    {
        return new ProvisionalEsiRuleLayer(new ProvisionalEsiRuleCatalog(DraftPatientMappings::definitions(), $approved ? $this->fixtureApprovals() : []));
    }

    /** Fictional approvals exist only inside isolated software tests. */
    private function fixtureApprovals(): array
    {
        $approvals = [];
        foreach (DraftPatientMappings::definitions() as $id => $definition) {
            $approvals[$id] = ['status' => 'approved', 'reviewer' => 'FICTIONAL SOFTWARE FIXTURE',
                'reviewed_at' => '2026-10-20', 'record_id' => 'TEST ONLY', 'rule_version' => ProvisionalEsiRuleCatalog::VERSION,
                'definition_hash' => ProvisionalEsiRuleCatalog::definitionHash($definition)];
        }

        return $approvals;
    }

    private function constructedCase(string $id): array
    {
        Artisan::call('esi:patient-preview', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);

        return array_values(array_filter($report['examples'], fn (array $example): bool => $example['id'] === $id))[0]['input'];
    }

    public static function languageVariants(): array
    {
        return [['My head hurts.'], ['Masakit ang ulo ko.'], ['Sakit akong ulo.'], ['I have a hedache.'], ['Sakit akong ulo, my head hurts.']];
    }

    #[DataProvider('languageVariants')]
    public function test_equivalent_mock_extractions_match_one_rule_without_language_specific_conditions(string $complaint): void
    {
        $input = $this->headache($complaint);
        (new NormalizedPatientFacts)->validate($input['normalized_facts'], $input['extracted_facts']);
        $preview = $this->engine()->preview($input['extracted_facts'], $input['normalized_facts']);
        $this->assertSame(2, $preview['candidate_priority']);
        $this->assertSame(['DRAFT-B-HEADACHE'], $preview['matched_rule_ids']);
        $this->assertNull($preview['priority']);
        $this->assertFalse($preview['study_eligible']);
        $normal = $this->engine()->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
        $this->assertNull($normal['priority']);
        $this->assertSame('needs_review', $normal['status']);
        $approved = $this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
        $this->assertSame(2, $approved['priority']);
        $entry = array_values(array_filter($approved['trace'], fn (array $entry): bool => $entry['rule_id'] === 'DRAFT-B-HEADACHE'))[0];
        $this->assertSame($complaint, $entry['observations'][0]['evidence'][0]['quote']);
        $this->assertSame($approved, $this->engine(true)->evaluate(array_reverse($input['extracted_facts']), [], array_reverse($input['normalized_facts'])));
        Http::assertNothingSent();
    }

    public function test_paired_request_preserves_a_and_stores_shared_normalized_evidence_without_another_call(): void
    {
        config(['esi.provisional_approvals' => $this->fixtureApprovals()]);
        $input = $this->headache('Sakit akong ulo.');
        $output = array_replace(MockScreening::output(5), $input);
        $patient = array_column($input['extracted_facts'], 'value', 'field');
        $patient['age'] = 34;
        $firstRaw = json_encode(MockScreening::envelope($output), JSON_PRETTY_PRINT);
        $secondOutput = array_replace($output, ['priority' => 1]);
        Http::fake(['api.openai.com/*' => Http::sequence()->push($firstRaw)->push(MockScreening::envelope($secondOutput))]);
        $first = app(ScreeningService::class)->screen(['patient' => $patient, 'language' => 'Bisaya'], null);
        $second = app(ScreeningService::class)->screen(['patient' => $patient, 'language' => 'Bisaya'], null);
        $this->assertSame(5, $first->method_a_priority);
        $this->assertSame(1, $second->method_a_priority);
        $this->assertSame($firstRaw, $first->original_response);
        $this->assertSame($output, $first->parsed_output);
        $this->assertSame(2, $first->method_b_priority);
        $this->assertSame($first->method_b_result, $second->method_b_result);
        $this->assertSame($input['normalized_facts'], $first->method_b_input['normalized_facts']);
        $this->assertSame(['extracted_facts', 'normalized_facts', 'clinical_assessment'], array_keys($first->method_b_input));
        $this->assertSame(NormalizedPatientFacts::VERSION, $first->request_settings['normalization_version']);
        $this->assertDatabaseCount('screening_sessions', 2);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request['input'][0]['content'], 'clearly understandable typos')
            && ! str_contains($request['input'][0]['content'], '{{normalization_instructions}}')
            && in_array('normalized_facts', $request['text']['format']['schema']['required'], true));
    }

    public function test_llm_only_runs_do_not_invoke_the_rule_layer(): void
    {
        $this->mock(RuleLayer::class, function ($mock): void {
            $mock->shouldNotReceive('evaluate');
            $mock->shouldNotReceive('version');
        });
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(4)))]);
        $session = app(ScreeningService::class)->screen(MockScreening::input(), null, evaluateRules: false);
        $this->assertSame(4, $session->method_a_priority);
        $this->assertSame('not_evaluated', $session->method_b_status);
        $this->assertNull($session->method_b_input);
        Http::assertSentCount(1);
    }

    public function test_normalization_is_required_and_decision_or_measurement_concepts_are_rejected(): void
    {
        foreach (['high_risk', 'expected_resources', 'esi_level', 'blood_pressure', 'diagnosis', 'some_new_code'] as $code) {
            $input = $this->headache();
            $input['normalized_facts'][0]['concept'] = $code;
            try {
                (new NormalizedPatientFacts)->validate($input['normalized_facts'], $input['extracted_facts']);
                $this->fail('Unsupported interpretation was accepted: '.$code);
            } catch (InvalidModelOutput $error) {
                $this->assertSame('invalid_normalized_facts', $error->failureCode);
            }
        }
        $output = MockScreening::output();
        unset($output['normalized_facts']);
        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->validate($output, PatientFields::only(MockScreening::input()['patient']));
    }

    public function test_translated_invented_and_test_device_evidence_is_rejected(): void
    {
        foreach (['translated', 'test', 'device', 'duplicate'] as $scenario) {
            $input = $this->headache('Sakit akong ulo.');
            if ($scenario === 'translated') {
                $input['normalized_facts'][0]['evidence'][0]['quote'] = 'My head hurts.';
            } elseif ($scenario === 'duplicate') {
                $input['normalized_facts'][] = $input['normalized_facts'][0];
            } else {
                $field = $scenario === 'test' ? 'tests_requested' : 'medical_devices';
                $input['extracted_facts'][] = $this->fact($field, 'CBC and X-ray');
                $input['normalized_facts'][0]['evidence'] = [['field' => $field, 'quote' => 'CBC and X-ray']];
            }
            try {
                (new NormalizedPatientFacts)->validate($input['normalized_facts'], $input['extracted_facts']);
                $this->fail('Invalid evidence was accepted: '.$scenario);
            } catch (InvalidModelOutput $error) {
                $this->assertContains($error->failureCode, ['ungrounded_normalized_fact', 'invalid_normalized_facts']);
            }
            $this->assertNull($this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts'])['priority']);
        }
    }

    public static function unresolvedStates(): array
    {
        return [['missing'], ['unknown'], ['not_applicable'], ['contradictory']];
    }

    #[DataProvider('unresolvedStates')]
    public function test_unresolved_exclusions_never_become_provisional_three(string $state): void
    {
        $input = $this->constructedCase('DRAFT-C-RIGHT-LOWER-ABDOMEN');
        foreach ($input['normalized_facts'] as $index => &$fact) {
            if ($fact['concept'] === 'breathing_without_help') {
                if ($state === 'missing') {
                    unset($input['normalized_facts'][$index]);
                } else {
                    $fact['state'] = $state;
                }
            }
        }
        unset($fact);
        $input['normalized_facts'] = array_values($input['normalized_facts']);
        $result = $this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
        $this->assertNull($result['priority']);
        $this->assertSame('needs_review', $result['status']);
    }

    public function test_provisional_three_requires_a_supported_resource_profile_and_leaves_d_unassessed(): void
    {
        $input = $this->constructedCase('DRAFT-C-RIGHT-LOWER-ABDOMEN');
        $result = $this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
        $this->assertSame(3, $result['priority']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
        $this->assertStringContainsString('vital signs required', $result['display_label']);
        $this->assertTrue($result['clinical_review_required']);
        $this->assertArrayNotHasKey('expected_esi_resources', $result);
        $input['normalized_facts'] = array_values(array_filter($input['normalized_facts'], fn (array $fact): bool => $fact['concept'] !== 'right_lower_abdominal_pain'));
        $this->assertNull($this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts'])['priority']);
    }

    public function test_contradictions_and_unmapped_symptoms_prevent_lower_classification(): void
    {
        foreach (['unmapped_symptom', 'breathing'] as $code) {
            $input = $this->constructedCase('DRAFT-C-ANKLE');
            $quote = $code === 'breathing' ? 'The person is not breathing now.' : 'A further symptom has no matching concept.';
            $input['extracted_facts'][] = $this->fact('other_symptoms', $quote);
            $input['normalized_facts'][] = $this->concept($code, 'other_symptoms', $quote, $code === 'breathing' ? 'absent' : 'reported');
            $result = $this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
            $this->assertNull($result['priority']);
            $this->assertSame('needs_review', $result['status']);
        }
    }

    public function test_positive_earlier_presentation_with_missing_context_blocks_a_later_resource_profile(): void
    {
        $input = $this->constructedCase('DRAFT-C-ANKLE');
        $input['normalized_facts'] = array_values(array_filter($input['normalized_facts'], fn (array $fact): bool => $fact['concept'] !== 'chest_discomfort'));
        $input['extracted_facts'][] = $this->fact('other_symptoms', 'I also have chest pressure.');
        $input['normalized_facts'][] = $this->concept('chest_pressure', 'other_symptoms', 'I also have chest pressure.');
        $result = $this->engine(true)->evaluate($input['extracted_facts'], [], $input['normalized_facts']);
        $this->assertNull($result['priority']);
        $this->assertSame('B', $result['stopped_at']);
    }

    public function test_bare_pain_score_test_names_or_devices_cannot_supply_a_decision(): void
    {
        $facts = [$this->fact('age', '34'), $this->fact('reported_severity', '10/10'),
            $this->fact('tests_requested', 'CBC, urine and X-ray'), $this->fact('medical_devices', 'Catheter')];
        $this->assertNull($this->engine(true)->evaluate($facts, [], [])['priority']);
        $this->assertNull($this->engine()->preview($facts, [])['candidate_priority']);
    }

    public function test_invalid_normalization_retries_with_grounding_feedback_without_replaying_a_label(): void
    {
        $input = $this->headache('Sakit akong ulo.');
        $good = array_replace(MockScreening::output(4), $input);
        $bad = array_replace($good, ['priority' => 1, 'explanation' => 'DO-NOT-REPLAY']);
        $bad['normalized_facts'][0]['evidence'][0]['quote'] = 'My head hurts.';
        Http::fake(['api.openai.com/*' => Http::sequence()->push(MockScreening::envelope($bad))->push(MockScreening::envelope($good))]);
        $patient = array_column($input['extracted_facts'], 'value', 'field');
        $session = app(ScreeningService::class)->screen(['patient' => $patient, 'language' => 'Bisaya'], null, evaluateRules: false);
        $this->assertSame(4, $session->method_a_priority);
        $this->assertSame('ungrounded_normalized_fact', $session->attempts[0]->failure_code);
        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Normalize only the concept identifier', $requests[1][0]['input'][2]['content']);
        $this->assertStringNotContainsString('DO-NOT-REPLAY', json_encode($requests[1][0]->data()));
    }
}
