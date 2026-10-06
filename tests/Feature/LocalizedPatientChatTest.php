<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientFields;
use App\Services\Screening\PatientInterview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockScreening;
use Tests\TestCase;

class LocalizedPatientChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['triage.intake_version' => 'guided-v1', 'triage.api_key' => 'mock-only-key', 'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
    }

    public static function languages(): array
    {
        return [['English'], ['Bisaya'], ['Tagalog']];
    }

    private function answer(PatientVisit $visit, string $field, ?string $message, ?int $revision = null): TestResponse
    {
        return $this->withSession(['patient_visit_id' => $visit->id])->post('/patient/messages', [
            'field' => $field, 'message' => $message, 'skip' => $message === null,
            'question_revision' => $revision ?? $visit->messages()->count(),
        ]);
    }

    private function visitAt(string $field, string $language = 'English'): PatientVisit
    {
        $visit = PatientVisit::factory()->create(['language' => $language, 'question_index' => array_search($field, PatientFields::NAMES, true)]);
        ChatMessage::factory()->create(['patient_visit_id' => $visit->id, 'role' => 'assistant', 'source' => 'guide',
            'field' => $field, 'content' => PatientChatContent::text($language, 'questions', $field)]);

        return $visit;
    }

    #[DataProvider('languages')]
    public function test_selected_language_controls_every_question_and_survives_browser_resume(string $language): void
    {
        $this->post('/patient/start', ['language' => $language, 'synthetic_confirmed' => true, 'adult_confirmed' => true])->assertRedirect('/');
        $visit = PatientVisit::latest('id')->firstOrFail();
        $this->assertSame(PatientChatContent::text($language, 'messages', 'welcome', ['stub' => $visit->stub_number]), $visit->messages()->first()->content);

        foreach (PatientFields::NAMES as $index => $field) {
            $this->get('/')->assertInertia(fn (Assert $page) => $page
                ->where('visit.language', $language)->where('question.field', $field)
                ->where('question.text', PatientInterview::questions($language)[$field])
                ->where('question.follow_up', false)->where('visit.question_index', $index));
            $this->answer($visit, $field, null)->assertRedirect('/');
        }
        $this->assertSame('ready', $visit->fresh()->status);
        $this->assertSame(array_fill_keys(PatientFields::NAMES, null), $visit->fresh()->answers);
        $this->assertSame(PatientChatContent::text($language, 'messages', 'ready'), $visit->messages()->reorder('id', 'desc')->first()->content);
        Http::assertNothingSent();
    }

    #[DataProvider('languages')]
    public function test_follow_up_preserves_both_answers_in_the_same_field_and_staff_history(string $language): void
    {
        $visit = $this->visitAt('allergies', $language);
        $this->answer($visit, 'allergies', 'Oo')->assertRedirect('/');
        $this->assertSame(6, $visit->fresh()->question_index);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('question.follow_up', true)
            ->where('question.field', 'allergies')->where('question.text', PatientChatContent::text($language, 'followUps', 'allergies')));

        $detail = '  Penicillin; mao ra ang stated sa case.  ';
        $this->answer($visit, 'allergies', $detail)->assertRedirect('/');
        $this->assertSame("Oo\n".$detail, $visit->fresh()->answers['allergies']);
        $this->assertSame(7, $visit->fresh()->question_index);
        $this->assertSame(['Oo', $detail], $visit->messages()->where('role', 'patient')->pluck('content')->all());
        $this->actingAs(User::factory()->create())->get('/admin/patients/'.$visit->id)
            ->assertInertia(fn (Assert $page) => $page->where('patient.answers.allergies', "Oo\n".$detail)
                ->where('patient.messages.2.content', PatientChatContent::text($language, 'followUps', 'allergies'))
                ->where('patient.messages.3.content', $detail));
        Http::assertNothingSent();
    }

    public function test_stale_or_duplicate_reply_cannot_answer_its_own_follow_up(): void
    {
        $visit = $this->visitAt('allergies');
        $revision = $visit->messages()->count();
        $this->answer($visit, 'allergies', 'Yes', $revision)->assertRedirect('/');
        $this->answer($visit, 'allergies', 'Yes', $revision)->assertSessionHasErrors('message');
        $this->assertSame('Yes', $visit->fresh()->answers['allergies']);
        $this->assertSame(6, $visit->fresh()->question_index);
        $this->assertSame(3, $visit->messages()->count());
    }

    public function test_skipping_a_follow_up_keeps_the_first_reply_and_never_loops(): void
    {
        $visit = $this->visitAt('allergies', 'Bisaya');
        $this->answer($visit, 'allergies', 'Naa')->assertRedirect('/');
        $this->answer($visit, 'allergies', null)->assertRedirect('/');
        $this->assertSame('Naa', $visit->fresh()->answers['allergies']);
        $this->assertSame(7, $visit->fresh()->question_index);

        $this->answer($visit, 'maintenance_medications', 'Yes')->assertRedirect('/');
        $this->answer($visit, 'maintenance_medications', 'Yes')->assertRedirect('/');
        $this->assertSame("Yes\nYes", $visit->fresh()->answers['maintenance_medications']);
        $this->assertSame(8, $visit->fresh()->question_index);
    }

    public function test_unknown_and_explicit_absence_stay_distinct_without_follow_ups(): void
    {
        $visit = $this->visitAt('allergies', 'Tagalog');
        $this->answer($visit, 'allergies', 'Hindi ko alam')->assertRedirect('/');
        $this->answer($visit, 'maintenance_medications', 'Wala')->assertRedirect('/');
        $this->assertNull($visit->fresh()->answers['allergies']);
        $this->assertSame('Wala', $visit->fresh()->answers['maintenance_medications']);
        $this->assertSame(8, $visit->fresh()->question_index);
        $this->assertDatabaseHas('chat_messages', ['patient_visit_id' => $visit->id, 'content' => 'Hindi ko alam', 'role' => 'patient']);
    }

    public function test_age_and_confirmation_errors_use_the_selected_language(): void
    {
        $this->post('/patient/start', ['language' => 'Tagalog'])->assertSessionHasErrors([
            'synthetic_confirmed' => PatientChatContent::text('Tagalog', 'errors', 'confirmation'),
        ]);
        $visit = $this->visitAt('age', 'Bisaya');
        $this->answer($visit, 'age', '17')->assertSessionHasErrors(['message' => PatientChatContent::text('Bisaya', 'errors', 'adult')]);
        $this->answer($visit, 'age', 'adult')->assertSessionHasErrors(['message' => PatientChatContent::text('Bisaya', 'errors', 'age')]);
        $this->assertSame([], $visit->fresh()->answers);
    }

    #[DataProvider('languages')]
    public function test_screening_requests_the_selected_explanation_language_without_rewriting_evidence(string $language): void
    {
        $output = MockScreening::output();
        $output['explanation'] = PatientChatContent::text($language, 'messages', 'needsReview');
        Http::fake(['api.openai.com/v1/responses' => Http::response(MockScreening::envelope($output))]);
        $visit = PatientVisit::factory()->create(['language' => $language, 'status' => 'ready', 'question_index' => 15,
            'answers' => MockScreening::input()['patient']]);
        $this->withSession(['patient_visit_id' => $visit->id])->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $screening = $visit->fresh()->screening;
        $this->assertSame('completed', $screening->processing_status);
        $this->assertSame('screening-v0.3-llm-baseline', $screening->prompt_version);
        $this->assertSame($language, $screening->request_settings['response_language']);
        $this->assertSame('Itchy arm', $screening->parsed_output['extracted_facts'][0]['evidence']);
        $this->assertStringContainsString($output['explanation'], $visit->messages()->first()->content);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['input'][0]['content'], 'Patient-facing response language: '.$language)
            && ! str_contains(json_encode($request->data()), $visit->stub_number)
            && count(json_decode($request['input'][1]['content'], true)) === 15);
    }

    public function test_provider_failure_is_localized_and_saves_the_conversation(): void
    {
        config(['triage.api_key' => '']);
        $visit = PatientVisit::factory()->create(['language' => 'Tagalog', 'status' => 'ready', 'question_index' => 15,
            'answers' => MockScreening::input()['patient']]);
        $this->withSession(['patient_visit_id' => $visit->id])->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertSame(PatientChatContent::text('Tagalog', 'messages', 'failure', ['stub' => $visit->stub_number]), $visit->messages()->first()->content);
        Http::assertNothingSent();
    }

    #[DataProvider('languages')]
    public function test_classified_patient_result_shows_the_priority_and_exploratory_mode(string $language): void
    {
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(3)))]);
        $visit = PatientVisit::factory()->create(['language' => $language, 'status' => 'ready', 'question_index' => 15,
            'answers' => MockScreening::input()['patient']]);
        $this->withSession(['patient_visit_id' => $visit->id])->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $message = $visit->messages()->where('source', 'model')->firstOrFail();
        $this->assertStringContainsString(PatientChatContent::text($language, 'messages', 'priority', ['priority' => 3]), $message->content);
        $this->assertStringContainsString(PatientChatContent::text($language, 'messages', 'exploratoryOutput'), $message->content);
        $this->assertSame('needs_review', $visit->fresh()->screening->method_b_status);
        Http::assertSentCount(1);
    }
}
