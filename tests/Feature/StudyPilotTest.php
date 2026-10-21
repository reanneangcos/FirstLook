<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Services\Screening\PatientFields;
use App\Services\Screening\StudyProtocol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MockScreening;
use Tests\TestCase;

class StudyPilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_saves_both_methods_using_study_prompt_without_repeating_and_rejects_test_inputs(): void
    {
        config(['triage.api_key' => 'mock-only', 'triage.model' => 'gpt-6-luna']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(4)))]);
        $path = tempnam(sys_get_temp_dir(), 'firstlook-pilot-');
        $manifest = ['split' => 'DEV', 'source_sha256' => str_repeat('a', 64), 'cases' => [
            ['split' => 'DEV', 'dataset_case_id' => 'PILOT-CASE', 'variant_id' => 'PILOT-V1', 'language' => 'English',
                'patient' => PatientFields::only(MockScreening::input()['patient'])],
        ]];
        try {
            file_put_contents($path, json_encode($manifest));
            $options = ['input' => $path, '--run-id' => 'paired-pilot-test', '--paired-pilot' => true];
            $this->artisan('study:dev-baseline', $options)->assertSuccessful();
            $this->artisan('study:dev-baseline', $options)->assertSuccessful();
            $session = ScreeningSession::sole();
            $this->assertSame(StudyProtocol::PROMPT, $session->prompt_version);
            $this->assertSame('paired', $session->request_settings['evaluation_method']);
            $this->assertSame(4, $session->method_a_priority);
            $this->assertSame('needs_review', $session->method_b_status);
            $this->assertSame($session->parsed_output['normalized_facts'], $session->method_b_input['normalized_facts']);
            $this->assertArrayNotHasKey('priority', $session->method_b_input);
            $this->assertGreaterThanOrEqual(0, $session->request_settings['runtime_measurements']['rule_stage_wall_ms']);
            $this->assertSame(3, $session->patientVisit->messages()->count());
            $this->assertStringContainsString('Paired development pilot', $session->patientVisit->messages()->first()->content);
            Http::assertSentCount(1);
            Http::assertSent(fn ($request): bool => count(json_decode($request['input'][1]['content'], true)) === 15
                && ! str_contains(json_encode($request->data()), 'PILOT-CASE'));
            $manifest['cases'][0]['split'] = 'TEST';
            file_put_contents($path, json_encode($manifest));
            $this->artisan('study:dev-baseline', $options)->assertFailed();
            Http::assertSentCount(1);
        } finally {
            unlink($path);
        }
    }
}
