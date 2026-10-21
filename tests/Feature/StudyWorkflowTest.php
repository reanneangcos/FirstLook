<?php

namespace Tests\Feature;

use App\Models\PatientVisit;
use App\Models\ScreeningSession;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningService;
use App\Services\Screening\StudyManifest;
use App\Services\Screening\StudyProtocol;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MockScreening;
use Tests\TestCase;

class StudyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $inputPath;

    private string $referencePath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
        config(['triage.api_key' => 'MOCK_SECRET_NEVER_EXPORT', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
        $this->inputPath = tempnam(sys_get_temp_dir(), 'firstlook-study-input-');
        $this->referencePath = tempnam(sys_get_temp_dir(), 'firstlook-study-reference-');
    }

    protected function tearDown(): void
    {
        unlink($this->inputPath);
        unlink($this->referencePath);
        parent::tearDown();
    }

    /** These symbolic rules and approvals test software only; they have no clinical meaning. */
    private function configureFixtureRules(): void
    {
        $rules = $approvals = [];
        foreach (range(1, 5) as $priority) {
            $point = match ($priority) {
                1 => 'A', 2 => 'B', default => 'C'
            };
            $definition = ['decision_point' => $point, 'priority' => $priority,
                'conditions' => [['field' => 'main_complaint', 'state' => 'reported', 'values' => ['TEST_SUPPORT_'.$priority]]],
                'exclusions' => $priority === 1 ? [] : ['A' => [['field' => 'symptom_description', 'state' => 'reported', 'values' => ['TEST_A_EXCLUDED']]]],
                'handbook_section' => 'TEST ONLY', 'adaptation_rationale' => 'TEST ONLY',
                'resource_rationale' => $point === 'C' ? 'TEST ONLY' : null, 'explanation' => 'TEST ONLY'];
            if ($point === 'C') {
                $definition['exclusions']['B'] = [['field' => 'other_symptoms', 'state' => 'absent', 'values' => ['TEST_B_EXCLUDED']]];
            }
            $rules['TEST-'.$priority] = $definition;
            $approvals['TEST-'.$priority] = ['status' => 'approved', 'reviewer' => 'FICTIONAL_REVIEWER_NEVER_SEND',
                'reviewed_at' => '2026-10-20', 'record_id' => 'FIXTURE-REVIEW', 'rule_version' => ProvisionalEsiRuleCatalog::VERSION,
                'definition_hash' => ProvisionalEsiRuleCatalog::definitionHash($definition)];
        }
        config(['esi.mode' => 'provisional_patient_report', 'esi.provisional_rules' => $rules, 'esi.provisional_approvals' => $approvals]);
    }

    private function manifest(): array
    {
        $cases = [];
        foreach (range(1, 120) as $id) {
            $mixed = ['English–Tagalog', 'English–Bisaya', 'Tagalog–Bisaya'];
            $languages = ['English', 'Tagalog', 'Bisaya', $mixed[$id % 3], $mixed[($id + 1) % 3]];
            foreach ($languages as $index => $language) {
                $cases[] = ['split' => 'TEST', 'dataset_case_id' => 'FIXTURE-C'.$id, 'variant_id' => 'FIXTURE-C'.$id.'-V'.$index,
                    'language' => $language, 'patient' => PatientFields::only(['age' => 34, 'main_complaint' => 'TEST_SUPPORT_5',
                        'symptom_description' => 'TEST_A_EXCLUDED', 'other_symptoms' => 'TEST_B_EXCLUDED'])];
            }
        }

        return ['split' => 'TEST', 'source_sha256' => str_repeat('a', 64), 'cases' => $cases];
    }

    private function freeze(): void
    {
        $this->configureFixtureRules();
        $manifest = $this->manifest();
        file_put_contents($this->inputPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($this->referencePath, json_encode(array_map(fn (array $case): array => ['variant_id' => $case['variant_id'], 'expert_priority' => 5], $manifest['cases']), JSON_THROW_ON_ERROR));
        $this->artisan('study:freeze', ['input' => $this->inputPath, '--study-id' => 'fixture-study', '--reference' => $this->referencePath,
            '--secondary-run' => 1, '--dataset-review-record' => 'FIXTURE-RELEASE', '--pilot-record' => 'FIXTURE-PILOT'])->assertSuccessful();
    }

    public function test_frozen_run_pauses_on_sustained_outage_and_keeps_failed_records_on_resume(): void
    {
        config(['triage.max_attempts' => 1]);
        $this->freeze();
        Http::fake(['api.openai.com/*' => Http::sequence()->push([], 503)->push([], 503)->push([], 503)
            ->push(MockScreening::envelope($this->screeningOutput()))]);
        $options = ['study' => 'fixture-study', '--run' => 1, '--limit' => 4];
        $this->artisan('study:run', $options)->assertFailed();
        $this->assertDatabaseCount('screening_sessions', 3);
        Http::assertSentCount(3);

        $options['--limit'] = 1;
        $this->artisan('study:run', $options)->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 4);
        $this->assertSame(3, ScreeningSession::where('failure_code', 'provider_unavailable')->count());
        Http::assertSentCount(4);
    }

    private function screeningOutput(?int $priority = 2): array
    {
        $output = MockScreening::output($priority);
        $output['extracted_facts'] = [
            ['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34'],
            ['field' => 'main_complaint', 'state' => 'reported', 'value' => 'TEST_SUPPORT_5', 'evidence' => 'TEST_SUPPORT_5'],
            ['field' => 'symptom_description', 'state' => 'reported', 'value' => 'TEST_A_EXCLUDED', 'evidence' => 'TEST_A_EXCLUDED'],
            ['field' => 'other_symptoms', 'state' => 'absent', 'value' => 'TEST_B_EXCLUDED', 'evidence' => 'TEST_B_EXCLUDED'],
        ];

        return $output;
    }

    public function test_unreviewed_configuration_is_blocked_without_requests_or_writes(): void
    {
        config(['esi.provisional_approvals' => []]);
        $report = app(StudyProtocol::class)->readiness();
        $this->assertFalse($report['ready']);
        $this->assertSame(18, $report['rule_count']);
        $this->assertSame(0, $report['approved_rule_count']);
        $this->artisan('study:check')->assertFailed();
        $this->artisan('study:freeze', ['input' => $this->inputPath, '--study-id' => 'blocked'])->assertFailed();
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_changed_mapping_invalidates_approval_and_requires_each_level(): void
    {
        $this->configureFixtureRules();
        $this->assertTrue(app(StudyProtocol::class)->readiness()['ready']);
        config(['esi.provisional_rules.TEST-3.explanation' => 'CHANGED FIXTURE']);
        $report = app(StudyProtocol::class)->readiness();
        $this->assertFalse($report['ready']);
        $this->assertSame([3], $report['missing_levels']);
    }

    public function test_active_intake_screening_and_study_prompts_share_the_same_reporting_state_policy(): void
    {
        $policies = [];
        foreach ([config('triage.intake_version'), config('triage.prompt_version'), config('triage.patient_prompt_version'), StudyProtocol::PROMPT] as $version) {
            $prompt = file_get_contents(resource_path('prompts/'.$version.'.txt'));
            $this->assertSame(1, preg_match('/REPORTING STATES: .*?not_applicable remains a distinct state\./s', $prompt, $matches));
            $policies[] = $matches[0];
            $this->assertStringContainsString('"Wala gitaho" means unknown', $matches[0]);
            $this->assertStringContainsString('"Walang naiuulat" means absent', $matches[0]);
            $this->assertStringContainsString('A placeholder inside a longer statement must not erase', $matches[0]);
        }
        $this->assertCount(1, array_unique($policies));
        Http::assertNothingSent();
    }

    public function test_study_request_preserves_absence_unknown_and_mixed_positive_facts_without_retry(): void
    {
        $this->configureFixtureRules();
        $input = MockScreening::input();
        $input['patient']['allergies'] = 'Known allergies: None reported.';
        $input['patient']['medical_devices'] = 'Mga device o catheter: Wala gitaho.';
        $input['patient']['known_conditions'] = 'Known conditions: None reported. Actually, I have asthma.';
        $output = MockScreening::output(4);
        $output['extracted_facts'][1] = ['field' => 'allergies', 'state' => 'absent', 'value' => $input['patient']['allergies'], 'evidence' => $input['patient']['allergies']];
        $output['extracted_facts'][] = ['field' => 'medical_devices', 'state' => 'unknown', 'value' => null, 'evidence' => null];
        $output['extracted_facts'][] = ['field' => 'known_conditions', 'state' => 'reported', 'value' => $input['patient']['known_conditions'], 'evidence' => $input['patient']['known_conditions']];
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($output))]);

        $session = app(ScreeningService::class)->screen($input, null, studyCriteria: true);

        $this->assertSame('completed', $session->processing_status);
        $this->assertSame(StudyProtocol::PROMPT, $session->prompt_version);
        $this->assertSame($output, $session->parsed_output);
        $this->assertSame($output['extracted_facts'], $session->method_b_input['extracted_facts']);
        $this->assertCount(1, $session->attempts);
        $this->assertStringContainsString('REPORTING STATES:', $session->prompt_text);
        $this->assertStringNotContainsString('"Not reported", "None reported", "Not stated"', $session->prompt_text);
        Http::assertSentCount(1);
    }

    public function test_frozen_pair_uses_one_call_keeps_disagreement_and_saves_history_without_label_leakage(): void
    {
        $this->freeze();
        $raw = json_encode(MockScreening::envelope($this->screeningOutput()), JSON_PRETTY_PRINT);
        Http::fake(['api.openai.com/*' => Http::response($raw)]);
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--limit' => 1])->assertSuccessful();
        $session = ScreeningSession::firstOrFail();
        $this->assertSame(2, $session->method_a_priority);
        $this->assertSame(5, $session->method_b_priority);
        $this->assertSame($raw, $session->original_response);
        $this->assertSame($this->screeningOutput()['extracted_facts'], $session->method_b_input['extracted_facts']);
        $this->assertSame('reviewed_definitions', $session->request_settings['criteria_status']);
        $this->assertSame(StudyProtocol::PROMPT, $session->prompt_version);
        $this->assertSame(3, $session->patientVisit->messages()->count());
        $this->assertSame($session->patient_input, $session->patientVisit->answers);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = json_encode($request->data());

            return ! str_contains($text, 'expert_priority') && ! str_contains($text, 'FIXTURE-C')
                && ! str_contains($text, 'FICTIONAL_REVIEWER_NEVER_SEND') && ! str_contains($text, 'fixture-study')
                && str_contains($text, 'TEST_SUPPORT_5');
        });
        $freeze = Storage::disk('local')->get('studies/fixture-study/freeze.json');
        $this->assertStringNotContainsString('MOCK_SECRET_NEVER_EXPORT', $freeze);
        $this->assertStringNotContainsString('expert_priority', $freeze);
        $this->artisan('study:score', ['study' => 'fixture-study', 'reference' => $this->referencePath])->assertFailed();
    }

    public function test_three_runs_get_distinct_observations_and_resuming_does_not_repeat_or_retry_needs_review(): void
    {
        $this->freeze();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($this->screeningOutput(null)))]);
        foreach ([1, 2, 3] as $run) {
            $this->artisan('study:run', ['study' => 'fixture-study', '--run' => $run, '--limit' => 1])->assertSuccessful();
        }
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 4);
        $this->assertDatabaseCount('screening_attempts', 4);
        $runOne = ScreeningSession::where('request_settings->evaluation->run', 1)->get();
        $this->assertCount(2, $runOne);
        $this->assertNotSame($runOne[0]->variant_id, $runOne[1]->variant_id);
        $this->assertSame([42, 43, 44], ScreeningSession::all()->pluck('request_settings.evaluation.order_seed')->unique()->values()->all());
        Http::assertSentCount(4);
    }

    public function test_freeze_drift_dry_run_and_interrupted_records_never_trigger_a_repeat(): void
    {
        $this->freeze();
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--dry-run' => true])->assertSuccessful();
        Http::assertNothingSent();
        config(['triage.max_output_tokens' => 3000]);
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1])->assertFailed();
        Http::assertNothingSent();
        config(['triage.max_output_tokens' => 4000]);
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($this->screeningOutput()))]);
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--limit' => 1])->assertSuccessful();
        ScreeningSession::firstOrFail()->update(['processing_status' => 'processing']);
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--limit' => 1])->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_manifest_rejects_labels_missing_fields_duplicate_versions_and_wrong_languages(): void
    {
        $loader = app(StudyManifest::class);
        $loader->validate($this->manifest());
        foreach (['label', 'missing_field', 'duplicate', 'language', 'split'] as $fault) {
            $manifest = $this->manifest();
            match ($fault) {
                'label' => $manifest['cases'][0]['patient']['expert_priority'] = 1,
                'missing_field' => $manifest['cases'][0]['patient'] = [],
                'duplicate' => $manifest['cases'][1]['variant_id'] = $manifest['cases'][0]['variant_id'],
                'language' => $manifest['cases'][1]['language'] = 'English',
                'split' => $manifest['cases'][0]['split'] = 'DEV',
            };
            try {
                $loader->validate($manifest);
                $this->fail('Invalid manifest accepted: '.$fault);
            } catch (\RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_complete_fictional_three_run_export_joins_labels_offline_and_rejects_changed_predictions(): void
    {
        $this->freeze();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope($this->screeningOutput()))]);
        $this->artisan('study:run', ['study' => 'fixture-study', '--run' => 1, '--limit' => 1])->assertSuccessful();
        $template = ScreeningSession::firstOrFail();
        $attributes = Arr::except($template->attributesToArray(), ['id', 'created_at', 'updated_at', 'patient_visit_id']);
        foreach ([1, 2, 3] as $run) {
            foreach ($this->manifest()['cases'] as $case) {
                if ($run === 1 && $case['variant_id'] === $template->variant_id) {
                    continue;
                }
                $settings = $template->request_settings;
                $settings['evaluation']['run'] = $run;
                $settings['evaluation']['run_id'] = 'fixture-study-r'.$run;
                $settings['evaluation']['order_seed'] = 41 + $run;
                ScreeningSession::factory()->create(array_replace($attributes, [
                    'user_id' => null, 'is_fixture' => false, 'request_settings' => $settings,
                    'dataset_case_id' => $case['dataset_case_id'], 'variant_id' => $case['variant_id'], 'language' => $case['language'],
                    'patient_visit_id' => PatientVisit::factory()->create()->id,
                ]));
            }
        }
        $this->artisan('study:score', ['study' => 'fixture-study', 'reference' => $this->referencePath])->assertSuccessful();
        $report = json_decode(Storage::disk('local')->get('studies/fixture-study/results-v1.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1800, $report['observations']);
        $this->assertEquals(1, $report['three_run_mean']['a']['common_pairs']['overtriage_rate']);
        $this->assertEquals(1, $report['three_run_mean']['b']['common_pairs']['accuracy']);
        $this->assertSame(600, $report['runs'][1]['common_pair_count']);
        $this->assertSame(120, $report['runs'][1]['language_consistency']['b']['consistent_cases']);
        $this->assertSame(1801, substr_count(Storage::disk('local')->get('studies/fixture-study/results-v1.csv'), "\n"));
        $template->update(['method_a_priority' => 1]);
        $this->artisan('study:score', ['study' => 'fixture-study', 'reference' => $this->referencePath, '--output' => 'invalid'])->assertFailed();
        Storage::disk('local')->assertMissing('studies/fixture-study/invalid.json');
        Http::assertSentCount(1);
    }
}
