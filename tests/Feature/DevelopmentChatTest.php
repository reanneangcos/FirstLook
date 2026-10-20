<?php

namespace Tests\Feature;

use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Screening\PatientFields;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MockScreening;
use Tests\TestCase;

class DevelopmentChatTest extends TestCase
{
    use RefreshDatabase;

    private string $inputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['triage.api_key' => 'mock-only', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
        $this->inputPath = tempnam(sys_get_temp_dir(), 'firstlook-chat-');
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

    private function writeManifest(string $split = 'DEV'): void
    {
        $case = ['split' => $split, 'dataset_case_id' => 'DEVCASE', 'variant_id' => 'DEVCASE-V1',
            'language' => 'English–Bisaya', 'patient' => PatientFields::only(MockScreening::input()['patient']),
            'paragraph' => '34 years old. Pangunang reklamo: Itchy arm. All other details are unknown.'];
        file_put_contents($this->inputPath, json_encode(['split' => $split, 'source_sha256' => str_repeat('a', 64),
            'paragraph_sha256' => str_repeat('b', 64), 'cases' => [$case]], JSON_UNESCAPED_UNICODE));
    }

    private function completeIntake(): array
    {
        $facts = [];
        foreach (PatientFields::NAMES as $field) {
            $evidence = match ($field) {
                'age' => '34', 'main_complaint', 'symptom_description' => 'Itchy arm', default => 'All other details are unknown'
            };
            $facts[$field] = ['state' => in_array($field, ['age', 'main_complaint', 'symptom_description']) ? 'reported' : 'unknown',
                'evidence' => $evidence, 'needs_detail' => false, 'replaces_previous' => false];
        }

        return MockScreening::envelope(['facts' => $facts, 'next_question' => ['fields' => [], 'text' => ''], 'language_change' => null]);
    }

    public function test_paragraph_chat_and_llm_only_result_are_visible_to_admin_with_no_metadata_leakage(): void
    {
        $this->writeManifest();
        Http::fake(['api.openai.com/*' => Http::sequence()->push($this->completeIntake())->push(MockScreening::envelope(MockScreening::output(4)))]);
        $options = ['input' => $this->inputPath, '--run-id' => 'paragraph-test'];
        $this->artisan('study:dev-chat', $options)->assertSuccessful();
        $this->artisan('study:dev-chat', $options)->assertSuccessful();
        $visit = PatientVisit::firstOrFail();
        $this->assertSame('completed', $visit->status);
        $this->assertSame('ready', $visit->interview_state['development']['intake_status']);
        $this->assertSame('Bisaya', $visit->language);
        $this->assertSame('English–Bisaya', $visit->screening->language);
        $this->assertSame('DEVCASE-V1', $visit->screening->variant_id);
        $this->assertSame('method_a_only', $visit->screening->request_settings['evaluation_method']);
        $this->assertSame('not_evaluated', $visit->screening->method_b_status);
        $this->assertNull($visit->screening->method_b_result);
        $this->assertSame(4, $visit->screening->method_a_priority);
        $this->assertNull($visit->screening->patient_input['reported_sex']);
        $this->assertSame(1, $visit->messages()->where('role', 'patient')->count());
        $this->actingAs(User::factory()->create());
        $this->get('/admin/patients?q=DEVCASE-V1')->assertInertia(fn (Assert $page) => $page->where('patients.total', 1));
        $this->get('/admin/patients/'.$visit->id)->assertInertia(fn (Assert $page) => $page->has('patient.messages', 6)->where('patient.screening.method_b_status', 'not_evaluated'));
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $payload = json_encode($request->data());
            $this->assertStringNotContainsString('DEVCASE', $payload);
            $this->assertStringNotContainsString('paragraph-test', $payload);
            $this->assertStringNotContainsString('source_patient', $payload);
        }
    }

    public function test_unresolved_paragraph_is_saved_without_inventing_answers_or_calling_screening(): void
    {
        $this->writeManifest();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope([
            'facts' => array_fill_keys(PatientFields::NAMES, null), 'next_question' => ['fields' => ['main_complaint', 'symptom_description'], 'text' => 'What do you mean?'], 'language_change' => null,
        ]))]);
        $this->artisan('study:dev-chat', ['input' => $this->inputPath, '--run-id' => 'unclear'])->assertSuccessful();
        $visit = PatientVisit::firstOrFail();
        $this->assertSame('clarification_required', $visit->interview_state['development']['intake_status']);
        $this->assertSame([], $visit->answers);
        $this->assertCount(15, $visit->interview_state['development']['missing_fields']);
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertSentCount(1);
    }

    public function test_failed_intake_keeps_original_paragraph_and_failure_code_in_the_chat(): void
    {
        $this->writeManifest();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete'])]);
        $this->artisan('study:dev-chat', ['input' => $this->inputPath, '--run-id' => 'failure'])->assertSuccessful();
        $visit = PatientVisit::firstOrFail();
        $this->assertSame('incomplete_response', $visit->interview_state['development']['failure_code']);
        $this->assertSame('technical_failure', $visit->interview_state['development']['intake_status']);
        $this->assertSame(200, $visit->interview_state['development']['intake_http_status']);
        $this->assertSame(['status' => 'incomplete'], json_decode($visit->interview_state['development']['original_intake_response'], true));
        $this->assertSame(1, $visit->messages()->where('role', 'patient')->count());
        $this->assertFalse($visit->messages()->where('role', 'patient')->first()->metadata['accepted']);
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertSentCount(1);
    }

    public function test_test_manifest_is_rejected_before_saving_visits_or_sending_requests(): void
    {
        $this->writeManifest('TEST');
        $this->artisan('study:dev-chat', ['input' => $this->inputPath, '--run-id' => 'no-test'])->assertFailed();
        $this->assertDatabaseCount('patient_visits', 0);
        Http::assertNothingSent();
    }

    public function test_changed_configuration_cannot_resume_an_old_run(): void
    {
        $this->writeManifest();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete'])]);
        $options = ['input' => $this->inputPath, '--run-id' => 'frozen-chat'];
        $this->artisan('study:dev-chat', $options)->assertSuccessful();
        config(['triage.intake_max_output_tokens' => 3000]);
        $this->artisan('study:dev-chat', $options)->assertFailed();
        $this->assertDatabaseCount('patient_visits', 1);
        Http::assertSentCount(1);
    }

    public function test_worker_partition_and_dry_run_do_not_create_duplicate_chats(): void
    {
        $this->writeManifest();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete'])]);
        $options = ['input' => $this->inputPath, '--run-id' => 'workers', '--shards' => 2];
        $this->artisan('study:dev-chat', $options + ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('patient_visits', 0);
        $this->artisan('study:dev-chat', $options + ['--shard' => 1])->assertSuccessful();
        $this->assertDatabaseCount('patient_visits', 0);
        $this->artisan('study:dev-chat', $options + ['--shard' => 0])->assertSuccessful();
        $this->artisan('study:dev-chat', $options + ['--shard' => 0])->assertSuccessful();
        $this->assertDatabaseCount('patient_visits', 1);
        Http::assertSentCount(1);
    }
}
