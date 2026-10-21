<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Services\Screening\PatientFields;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MockScreening;
use Tests\TestCase;

class DevelopmentBaselineTest extends TestCase
{
    use RefreshDatabase;

    private string $inputPath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['triage.api_key' => 'mock-only', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
        $this->inputPath = tempnam(sys_get_temp_dir(), 'firstlook-dev-');
        $this->mock(RuleLayer::class, function ($mock): void {
            $mock->shouldNotReceive('evaluate');
            $mock->shouldNotReceive('version');
        });
    }

    protected function tearDown(): void
    {
        unlink($this->inputPath);
        parent::tearDown();
    }

    private function manifest(): array
    {
        $cases = [];
        foreach (['English', 'Tagalog', 'Bisaya', 'English–Tagalog', 'English–Bisaya', 'Tagalog–Bisaya'] as $index => $language) {
            $cases[] = ['split' => 'DEV', 'dataset_case_id' => 'LOCAL-CASE', 'variant_id' => 'LOCAL-V'.$index,
                'language' => $language, 'patient' => PatientFields::only(MockScreening::input()['patient'])];
        }

        return ['split' => 'DEV', 'source_sha256' => str_repeat('a', 64), 'cases' => $cases];
    }

    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->inputPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function test_all_languages_are_saved_with_no_rule_calls_or_metadata_leakage_and_resume_does_not_repeat(): void
    {
        $this->writeManifest($this->manifest());
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(4)))]);
        $options = ['input' => $this->inputPath, '--run-id' => 'dev-test'];
        $this->artisan('study:dev-baseline', $options + ['--limit' => 2])->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 2);
        $this->artisan('study:dev-baseline', $options)->assertSuccessful();
        $this->artisan('study:dev-baseline', $options)->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 6);
        $this->assertDatabaseCount('patient_visits', 6);
        $this->assertDatabaseCount('chat_messages', 18);
        $this->assertSame(6, ScreeningSession::distinct()->count('language'));
        foreach (ScreeningSession::all() as $session) {
            $this->assertSame('not_evaluated', $session->method_b_status);
            $this->assertNull($session->method_b_result);
            $this->assertNull($session->method_b_input);
            $this->assertNull($session->method_b_rule_version);
            $this->assertSame(4, $session->method_a_priority);
            $this->assertSame('method_a_only', $session->request_settings['evaluation_method']);
            $this->assertSame('dev-test', $session->request_settings['evaluation']['run_id']);
            $this->assertSame($session->patient_input, $session->patientVisit->answers);
            $this->assertSame($session->patient_input, json_decode($session->patientVisit->messages()->where('role', 'patient')->first()->content, true));
            $this->assertSame('original_workbook_structured_fields', $session->patientVisit->interview_state['development']['input_route']);
        }
        Http::assertSentCount(6);
        Http::assertSent(fn ($request): bool => count(json_decode($request['input'][1]['content'], true)) === 15
            && ! str_contains(json_encode($request->data()), 'LOCAL-')
            && ! str_contains(json_encode($request->data()), 'dev-test'));
    }

    public function test_test_records_and_answer_keys_are_rejected_before_any_request_or_save(): void
    {
        foreach (['test', 'answer_key', 'missing_field', 'duplicate'] as $failure) {
            $manifest = $this->manifest();
            if ($failure === 'test') {
                $manifest['cases'][0]['split'] = 'TEST';
            } elseif ($failure === 'answer_key') {
                $manifest['cases'][0]['patient']['expert_priority'] = 4;
            } elseif ($failure === 'missing_field') {
                unset($manifest['cases'][0]['patient']['allergies']);
            } else {
                $manifest['cases'][1]['variant_id'] = $manifest['cases'][0]['variant_id'];
            }
            $this->writeManifest($manifest);
            $this->artisan('study:dev-baseline', ['input' => $this->inputPath, '--run-id' => 'invalid'])->assertFailed();
        }
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_dry_run_makes_no_requests_or_records(): void
    {
        $this->writeManifest($this->manifest());
        $this->artisan('study:dev-baseline', ['input' => $this->inputPath, '--run-id' => 'preview', '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_resume_rejects_changed_inputs_or_settings(): void
    {
        $manifest = $this->manifest();
        $this->writeManifest($manifest);
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope())]);
        $options = ['input' => $this->inputPath, '--run-id' => 'frozen', '--limit' => 1];
        $this->artisan('study:dev-baseline', $options)->assertSuccessful();
        config(['triage.max_output_tokens' => 1000]);
        $this->artisan('study:dev-baseline', $options)->assertFailed();
        config(['triage.max_output_tokens' => 4000]);
        $manifest['cases'][0]['patient']['age'] = 55;
        $this->writeManifest($manifest);
        $this->artisan('study:dev-baseline', $options)->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_failures_are_retained_and_not_silently_resubmitted_on_resume(): void
    {
        $this->writeManifest($this->manifest());
        $sequence = Http::sequence()->push([], 401);
        for ($index = 0; $index < 5; $index++) {
            $sequence->push(MockScreening::envelope());
        }
        Http::fake(['api.openai.com/*' => $sequence]);
        $options = ['input' => $this->inputPath, '--run-id' => 'failed'];
        $this->artisan('study:dev-baseline', $options)->assertFailed();
        $session = ScreeningSession::firstOrFail();
        $this->assertSame('credentials_rejected', $session->failure_code);
        $this->artisan('study:dev-baseline', $options)->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 6);
        $this->assertSame('credentials_rejected', $session->fresh()->failure_code);
        $this->assertCount(1, $session->attempts);
        Http::assertSentCount(6);
    }

    public function test_transport_outage_stops_before_consuming_the_rest_of_the_manifest(): void
    {
        config(['triage.max_attempts' => 1]);
        $this->writeManifest($this->manifest());
        Http::fake(['api.openai.com/*' => Http::sequence()->push([], 503)->push([], 503)->push([], 503)
            ->push(MockScreening::envelope())->push(MockScreening::envelope())->push(MockScreening::envelope())]);
        $options = ['input' => $this->inputPath, '--run-id' => 'outage'];
        $this->artisan('study:dev-baseline', $options)->assertFailed();
        $this->assertDatabaseCount('screening_sessions', 3);
        $this->assertSame(3, ScreeningSession::where('failure_code', 'provider_unavailable')->count());
        Http::assertSentCount(3);

        $this->artisan('study:dev-baseline', $options)->assertSuccessful();
        $this->assertDatabaseCount('screening_sessions', 6);
        $this->assertSame(3, ScreeningSession::where('failure_code', 'provider_unavailable')->count());
        Http::assertSentCount(6);
    }
}
