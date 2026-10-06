<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\PatientVisit;
use App\Models\ScreeningSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MockScreening;
use Tests\TestCase;

class PatientChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['triage.intake_version' => 'guided-v1', 'triage.api_key' => 'mock-only-key', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
    }

    private function answerChat(array $data): TestResponse
    {
        $visit = PatientVisit::find(session('patient_visit_id'));

        return $this->post('/patient/messages', $data + ['question_revision' => $visit?->messages()->count() ?? 0]);
    }

    private function startChat(): PatientVisit
    {
        $this->post('/patient/start', ['language' => 'English', 'synthetic_confirmed' => true, 'adult_confirmed' => true])->assertRedirect('/');

        return PatientVisit::latest('id')->firstOrFail();
    }

    public function test_public_chat_assigns_a_stub_and_resumes_only_the_current_browser_visit(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Patient/Chat')->where('visit', null)->where('integration', null));
        $visit = $this->startChat();
        $this->assertSame('TF-000001', $visit->stub_number);
        $this->assertCount(2, $visit->messages);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('visit.stub_number', 'TF-000001')->where('question.field', 'age')->missing('visit.answers')->missing('visit.screening'));
        $this->startChat();
        $this->assertDatabaseCount('patient_visits', 1);
        Http::assertNothingSent();
    }

    public function test_start_requires_adult_synthetic_confirmation_and_a_supported_language(): void
    {
        $this->post('/patient/start', ['language' => 'unsupported'])->assertSessionHasErrors(['language', 'synthetic_confirmed', 'adult_confirmed']);
        $this->assertDatabaseCount('patient_visits', 0);
    }

    public function test_answers_preserve_wording_and_reject_stale_questions_and_children(): void
    {
        $visit = $this->startChat();
        $this->answerChat(['field' => 'age', 'message' => '17', 'skip' => false])->assertSessionHasErrors(['message' => 'This demo is for fictional adults aged 18 and above.']);
        $this->assertSame(0, $visit->fresh()->question_index);
        $this->answerChat(['field' => 'age', 'message' => '34', 'skip' => false])->assertRedirect('/');
        $this->answerChat(['field' => 'age', 'message' => '45', 'skip' => false])->assertSessionHasErrors('message');
        $this->answerChat(['field' => 'reported_sex', 'message' => '  Female  ', 'skip' => false])->assertRedirect('/');
        $this->answerChat(['field' => 'main_complaint', 'message' => '', 'skip' => true])->assertRedirect('/');
        $this->assertSame('  Female  ', $visit->fresh()->answers['reported_sex']);
        $this->assertNull($visit->fresh()->answers['main_complaint']);
        $this->assertDatabaseHas('chat_messages', ['patient_visit_id' => $visit->id, 'content' => '  Female  ', 'role' => 'patient']);
        Http::assertNothingSent();
    }

    public function test_empty_oversized_and_excluded_field_answers_cannot_advance_the_chat(): void
    {
        $visit = PatientVisit::factory()->create(['question_index' => 2]);
        $this->withSession(['patient_visit_id' => $visit->id]);
        foreach (['', '   ', str_repeat('x', 3001)] as $message) {
            $this->answerChat(['field' => 'main_complaint', 'message' => $message, 'skip' => false])->assertSessionHasErrors('message');
        }
        $this->answerChat(['field' => 'expert_priority', 'message' => '1', 'skip' => false])->assertSessionHasErrors('field');
        $this->assertSame(2, $visit->fresh()->question_index);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_entire_chat_produces_one_saved_prediction_and_one_model_reply(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(MockScreening::envelope(MockScreening::output(3)))]);
        $visit = $this->startChat();
        $answers = ['age' => '34', 'reported_sex' => null, 'main_complaint' => 'Itchy arm',
            'symptom_description' => 'My arm feels itchy since yesterday.', 'other_symptoms' => null,
            'known_conditions' => null, 'allergies' => null, 'maintenance_medications' => null,
            'onset' => null, 'duration' => null, 'reported_severity' => null, 'worsening' => null,
            'tests_completed' => null, 'tests_requested' => null, 'medical_devices' => null];
        foreach ($answers as $field => $answer) {
            $this->answerChat(['field' => $field, 'message' => $answer, 'skip' => $answer === null])->assertRedirect('/');
        }
        $this->assertSame('ready', $visit->fresh()->status);
        Http::assertNothingSent();
        $this->post('/patient/screen')->assertSessionHasErrors('scope_confirmed');
        $this->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $this->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $screening = $visit->fresh()->screening;
        $this->assertSame(3, $screening->method_a_priority);
        $this->assertNull($screening->user_id);
        $this->assertSame('needs_review', $screening->method_b_status);
        $this->assertSame($answers, $screening->original_input);
        $this->assertDatabaseCount('screening_sessions', 1);
        $this->assertSame(1, $visit->messages()->where('source', 'model')->count());
        $this->assertSame('completed', $visit->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), $visit->stub_number)
            && json_decode($request['input'][1]['content'], true)['allergies'] === null);
    }

    public function test_missing_api_configuration_saves_a_staff_visible_failure_without_exposing_configuration(): void
    {
        config(['triage.api_key' => '']);
        $visit = PatientVisit::factory()->create(['status' => 'ready', 'question_index' => 15, 'answers' => MockScreening::input()['patient']]);
        $this->withSession(['patient_visit_id' => $visit->id])->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $this->assertSame('technical_failure', $visit->fresh()->screening->processing_status);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('visit.messages.0.source', 'system')->where('integration', null)->missing('visit.screening'));
        $this->assertStringNotContainsString('OPENAI', $visit->messages()->first()->content);
        Http::assertNothingSent();
    }

    public function test_patient_cannot_read_or_modify_another_visit_by_supplying_a_stub_or_id(): void
    {
        $other = PatientVisit::factory()->create();
        ChatMessage::factory()->create(['patient_visit_id' => $other->id, 'content' => 'OTHER-CONVERSATION']);
        $own = $this->startChat();
        $this->get('/?stub_number='.$other->stub_number.'&patient_visit_id='.$other->id)->assertInertia(fn (Assert $page) => $page->where('visit.stub_number', $own->stub_number)->has('visit.messages', 2));
        $this->answerChat(['patient_visit_id' => $other->id, 'field' => 'age', 'message' => '44', 'skip' => false])->assertRedirect('/');
        $this->assertSame([], $other->fresh()->answers);
        $this->assertSame('44', $own->fresh()->answers['age']);
        $this->get('/admin/patients/'.$other->id)->assertRedirect('/login');
        $this->get('/patient/'.$other->id)->assertNotFound();
    }

    public function test_anonymous_messages_require_a_browser_visit_and_cannot_trigger_screening(): void
    {
        $this->answerChat(['field' => 'age', 'message' => '34', 'skip' => false])->assertForbidden();
        $this->post('/patient/screen', ['scope_confirmed' => true])->assertNotFound();
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_ending_browser_access_keeps_staff_history_and_assigns_a_new_stub_next_time(): void
    {
        $first = $this->startChat();
        $this->post('/patient/end')->assertRedirect('/')->assertSessionMissing('patient_visit_id');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('visit', null));
        $next = $this->startChat();
        $this->assertNotSame($first->stub_number, $next->stub_number);
        $this->assertDatabaseHas('patient_visits', ['id' => $first->id]);
        $this->assertCount(2, $first->messages);
    }

    public function test_staff_can_search_stubs_and_inspect_transcripts_and_linked_triage(): void
    {
        $visit = PatientVisit::factory()->create(['stub_number' => 'TF-000123', 'status' => 'completed']);
        ChatMessage::factory()->create(['patient_visit_id' => $visit->id, 'content' => 'FICTIONAL CASE: itching.']);
        $screening = ScreeningSession::factory()->create(['patient_visit_id' => $visit->id,
            'processing_status' => 'completed', 'method_a_status' => 'needs_review', 'method_a_priority' => null]);
        $this->get('/admin/patients')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/patients?q=000123&status=needs_review')->assertInertia(fn (Assert $page) => $page->component('Admin/Patients/Index')->where('patients.total', 1)->where('patients.data.0.stub_number', 'TF-000123')->missing('patients.data.0.answers')->missing('patients.data.0.screening.original_response'));
        $this->get('/admin/patients?q=no-match')->assertInertia(fn (Assert $page) => $page->where('patients.total', 0));
        $this->get('/admin/patients/'.$visit->id)->assertInertia(fn (Assert $page) => $page->component('Admin/Patients/Show')->where('patient.messages.0.content', 'FICTIONAL CASE: itching.')->where('patient.screening.id', $screening->id));
        $this->get('/admin/screenings/'.$screening->id)->assertInertia(fn (Assert $page) => $page->where('screening.patient_visit.stub_number', 'TF-000123'));
    }

    public function test_staff_login_redirects_to_the_staff_side_and_patient_props_stay_minimal(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/login')->assertRedirect('/admin');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user', null)->where('integration', null));
    }

    public function test_unfinished_intake_does_not_send_a_provider_request(): void
    {
        $visit = $this->startChat();
        $this->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $this->assertSame('collecting', $visit->fresh()->status);
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }
}
