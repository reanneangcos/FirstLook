<?php

namespace Tests\Feature;

use App\Models\PatientVisit;
use App\Services\Screening\ConversationalInterview;
use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MockScreening;
use Tests\TestCase;

class ConversationalIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['triage.intake_version' => 'intake-v1-conversational', 'triage.api_key' => 'mock-only-key',
            'triage.model' => 'gpt-6-luna', 'triage.retry_delay_ms' => 0]);
    }

    private function startChat(string $language = 'English'): PatientVisit
    {
        $this->post('/patient/start', ['language' => $language, 'synthetic_confirmed' => true, 'adult_confirmed' => true])->assertRedirect('/');

        return PatientVisit::latest('id')->firstOrFail();
    }

    private function answer(PatientVisit $visit, ?string $reply): TestResponse
    {
        $visit->refresh();

        return $this->post('/patient/messages', ['message' => $reply, 'skip' => $reply === null,
            'field' => $visit->interview_state['pending_fields'][0], 'question_revision' => $visit->messages()->count()]);
    }

    private static function fact(string $field, string $evidence, string $state = 'reported', bool $needsDetail = false, bool $corrects = false): array
    {
        return ['field' => $field, 'evidence' => $evidence, 'state' => $state, 'needs_detail' => $needsDetail, 'replaces_previous' => $corrects];
    }

    private static function intakeResponse(array $facts = [], array $fields = [], string $question = ''): array
    {
        return MockScreening::envelope(['facts' => $facts, 'next_question' => ['fields' => $fields, 'text' => $question]]);
    }

    private function skipTo(PatientVisit $visit, string $field): void
    {
        while (! in_array($field, $visit->refresh()->interview_state['pending_fields'], true)) {
            $this->answer($visit, null)->assertRedirect('/');
        }
    }

    public static function languages(): array
    {
        return [['English'], ['Bisaya'], ['Tagalog']];
    }

    #[DataProvider('languages')]
    public function test_opening_resumes_the_same_stub_and_selected_language_controls_questions(string $language): void
    {
        $visit = $this->startChat($language);
        $this->startChat($language);
        $this->assertDatabaseCount('patient_visits', 1);
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('question.text', PatientChatContent::text($language, 'conversation', 'opening'))
            ->where('visit.conversational', true)->where('visit.stub_number', $visit->stub_number)
            ->missing('visit.interview_state')->missing('visit.messages.0.metadata'));
        Http::assertNothingSent();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'Itchy arm'), self::fact('symptom_description', 'Itchy arm'),
        ]))]);
        $this->answer($visit, 'Itchy arm')->assertRedirect('/');
        $this->assertSame(['age', 'reported_sex'], $visit->refresh()->interview_state['pending_fields']);
        $this->assertSame(PatientChatContent::text($language, 'shortQuestions', 'age').' '.PatientChatContent::text($language, 'shortQuestions', 'reported_sex'),
            $visit->messages()->reorder('id', 'desc')->first()->content);
        Http::assertSent(function ($request) use ($language, $visit): bool {
            $context = json_decode($request['input'][1]['content'], true);
            $schema = $request['text']['format']['schema']['properties'];

            return $context['response_language'] === $language
                && ! str_contains(json_encode($request->data()), $visit->stub_number)
                && ! array_key_exists('priority', $schema) && ! array_key_exists('status', $schema)
                && $request['store'] === false;
        });
    }

    public function test_one_mixed_language_reply_covers_several_fields_and_skips_known_timing(): void
    {
        $visit = $this->startChat('Bisaya');
        $reply = '34, female. Sakit akong tiyan since yesterday, getting worse.';
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('age', '34'), self::fact('reported_sex', 'female'),
            self::fact('main_complaint', 'Sakit akong tiyan'), self::fact('symptom_description', 'Sakit akong tiyan'),
            self::fact('onset', 'since yesterday'), self::fact('duration', 'since yesterday'), self::fact('worsening', 'getting worse'),
        ], ['reported_severity', 'other_symptoms'], 'Unsa kakusog ang sakit sa tiyan? Naa pa kay laing gibati?'))]);
        $this->answer($visit, $reply)->assertRedirect('/');
        $this->assertSame(7, $visit->refresh()->question_index);
        $this->assertSame(34, $visit->answers['age']);
        $this->assertSame('since yesterday', $visit->answers['duration']);
        $this->assertSame(['reported_severity', 'other_symptoms'], $visit->interview_state['pending_fields']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'patient', 'content' => $reply]);
        $message = $visit->messages()->reorder('id', 'desc')->first();
        $this->assertSame('intake', $message->source);
        $this->assertSame('Bisaya', $message->metadata['response_language']);
        $this->assertStringNotContainsString('mock-only-key', json_encode($message->metadata));
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertSentCount(1);
    }

    public function test_skip_covers_all_dataset_fields_in_six_grouped_turns_without_api_calls(): void
    {
        $visit = $this->startChat();
        foreach (ConversationalInterview::GROUPS as $fields) {
            $this->assertSame($fields, $visit->refresh()->interview_state['pending_fields']);
            $this->answer($visit, null)->assertRedirect('/');
        }
        $this->assertSame('ready', $visit->refresh()->status);
        $this->assertCount(15, $visit->answers);
        $this->assertSame(array_fill_keys(PatientFields::NAMES, null), PatientFields::only($visit->answers));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('question', null)->has('review', 15));
        Http::assertNothingSent();
    }

    public function test_a_vague_medication_answer_gets_one_specific_follow_up_and_keeps_both_replies(): void
    {
        $visit = $this->startChat();
        $this->skipTo($visit, 'known_conditions');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::intakeResponse([self::fact('known_conditions', 'No conditions', 'absent'),
                self::fact('maintenance_medications', 'yes medication', needsDetail: true), self::fact('allergies', 'no allergies', 'absent')]))
            ->push(self::intakeResponse([self::fact('maintenance_medications', 'Metformin')]))]);
        $this->answer($visit, 'No conditions; yes medication; no allergies')->assertRedirect('/');
        $this->assertSame(['maintenance_medications'], $visit->refresh()->interview_state['pending_fields']);
        $this->assertSame('What are the names of your regular medications?', $visit->messages()->reorder('id', 'desc')->first()->content);
        $this->answer($visit, 'Metformin')->assertRedirect('/');
        $this->assertSame("yes medication\nMetformin", $visit->refresh()->answers['maintenance_medications']);
        $this->assertSame(['tests_completed', 'tests_requested', 'medical_devices'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(2);
    }

    public function test_skipping_a_clarification_retains_the_partial_report(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'I feel bad', needsDetail: true), self::fact('symptom_description', 'I feel bad', needsDetail: true),
        ]))]);
        $this->answer($visit, 'I feel bad')->assertRedirect('/');
        $this->answer($visit, null)->assertRedirect('/');
        $this->assertSame('I feel bad', $visit->refresh()->answers['main_complaint']);
        $this->assertSame(['age', 'reported_sex'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(1);
    }

    public function test_unanswered_fields_are_asked_at_most_twice_then_marked_unknown(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse())]);
        $this->answer($visit, 'hello')->assertRedirect('/');
        $this->answer($visit, 'hello again')->assertRedirect('/');
        $this->assertNull($visit->refresh()->answers['main_complaint']);
        $this->assertNull($visit->answers['symptom_description']);
        $this->assertSame(['age', 'reported_sex'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(2);
    }

    public function test_known_fields_cannot_be_reasked_and_only_an_explicit_correction_replaces_them(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::intakeResponse([self::fact('age', '34'), self::fact('main_complaint', 'Itchy arm'), self::fact('symptom_description', 'Itchy arm')], ['age'], 'How old are you?'))
            ->push(self::intakeResponse([self::fact('age', '35'), self::fact('reported_sex', 'female')]))
            ->push(self::intakeResponse([self::fact('age', '36', corrects: true)]))]);
        $this->answer($visit, '34. Itchy arm')->assertRedirect('/');
        $this->assertSame(['reported_sex'], $visit->refresh()->interview_state['pending_fields']);
        $this->assertSame('What sex do you report?', $visit->messages()->reorder('id', 'desc')->first()->content);
        $this->answer($visit, 'female, 35')->assertRedirect('/');
        $this->assertSame(34, $visit->refresh()->answers['age']);
        $this->answer($visit, 'Correction: I am 36')->assertRedirect('/');
        $this->assertSame(36, $visit->refresh()->answers['age']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'patient', 'content' => '34. Itchy arm']);
    }

    public function test_duplicate_submission_is_rejected_before_a_second_provider_call(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse())]);
        $data = ['message' => 'hello', 'skip' => false, 'field' => 'main_complaint', 'question_revision' => 2];
        $this->post('/patient/messages', $data)->assertRedirect('/');
        $this->post('/patient/messages', $data)->assertSessionHasErrors('message');
        $this->assertSame(4, $visit->messages()->count());
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [
            [self::intakeResponse([self::fact('main_complaint', 'Invented symptom')])],
            [self::intakeResponse([self::fact('expert_priority', 'hello')])],
            [['status' => 'completed', 'output' => [['content' => 'invalid']]]],
            [['status' => 'completed', 'output' => [['content' => [['type' => 'refusal']]]]]],
            [['status' => 'incomplete', 'output' => []]],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_output_does_not_save_or_advance_the_reply(array $body): void
    {
        $visit = $this->startChat('Tagalog');
        Http::fake(['api.openai.com/*' => Http::response($body)]);
        $this->answer($visit, 'hello')->assertSessionHasErrors(['message' => PatientChatContent::text('Tagalog', 'conversation', 'retry')]);
        $this->assertSame([], $visit->refresh()->answers);
        $this->assertSame(2, $visit->messages()->count());
        Http::assertSentCount(1);
    }

    public static function providerFailures(): array
    {
        return [['missing_key'], ['http_error'], ['connection']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_preserves_the_question_without_leaking_diagnostics(string $failure): void
    {
        $visit = $this->startChat();
        if ($failure === 'missing_key') {
            config(['triage.api_key' => '']);
        } elseif ($failure === 'connection') {
            Http::fake(fn () => throw new ConnectionException('PRIVATE-DIAGNOSTIC'));
        } else {
            Http::fake(['api.openai.com/*' => Http::response(['error' => 'PRIVATE-DIAGNOSTIC'], 429)]);
        }
        $this->answer($visit, 'hello')->assertSessionHasErrors(['message' => PatientChatContent::text('English', 'conversation', 'retry')]);
        $this->assertSame([], $visit->refresh()->answers);
        $this->assertSame(2, $visit->messages()->count());
        if ($failure === 'missing_key') {
            Http::assertNothingSent();
        }
        if ($failure === 'http_error') {
            Http::assertSentCount(1);
        }
    }

    public function test_underage_reply_cannot_advance_the_interview(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([self::fact('age', '17')]))]);
        $this->answer($visit, 'I am 17')->assertSessionHasErrors('message');
        $this->assertSame([], $visit->refresh()->answers);
        $this->assertSame(2, $visit->messages()->count());
    }

    public function test_negative_evidence_keeps_the_sentence_that_gives_it_meaning(): void
    {
        $visit = $this->startChat('Bisaya');
        $negative = 'Wala koy laing sintomas, known conditions, o allergies.';
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'Katol akong bukton'), self::fact('symptom_description', 'Katol akong bukton'),
            self::fact('known_conditions', 'known conditions', 'absent'), self::fact('allergies', 'allergies', 'absent'),
        ]))]);
        $this->answer($visit, 'Katol akong bukton. '.$negative)->assertRedirect('/');
        $this->assertSame($negative, $visit->refresh()->answers['known_conditions']);
        $this->assertSame($negative, $visit->answers['allergies']);
        $this->assertArrayNotHasKey('tests_completed', $visit->answers);
    }

    public function test_review_corrections_are_validated_audited_and_used_in_the_single_screening(): void
    {
        $visit = $this->startChat();
        foreach (ConversationalInterview::GROUPS as $group) {
            $this->answer($visit, null)->assertRedirect('/');
        }
        $review = PatientFields::only(MockScreening::input()['patient']);
        $this->post('/patient/screen', ['scope_confirmed' => true, 'patient' => ['age' => 34]])->assertSessionHasErrors('patient.main_complaint');
        $this->post('/patient/screen', ['scope_confirmed' => true, 'patient' => $review + ['expert_priority' => 1]])->assertSessionHasErrors('patient');
        $this->post('/patient/screen', ['scope_confirmed' => true, 'patient' => array_replace($review, ['age' => 17])])->assertSessionHasErrors('patient.age');
        Http::assertNothingSent();
        Http::fake(['api.openai.com/*' => Http::response(MockScreening::envelope(MockScreening::output(3)))]);
        $this->post('/patient/screen', ['scope_confirmed' => true, 'patient' => $review])->assertRedirect('/');
        $this->post('/patient/screen', ['scope_confirmed' => true, 'patient' => $review])->assertRedirect('/');
        $this->assertSame($review, $visit->refresh()->screening->patient_input);
        $this->assertSame(3, $visit->screening->method_a_priority);
        $this->assertNull($visit->screening->method_b_priority);
        $audit = $visit->messages()->where('content', PatientChatContent::text('English', 'conversation', 'reviewed'))->firstOrFail();
        $this->assertSame($review, $audit->metadata['reviewed_answers']);
        $this->assertNull($audit->metadata['previous_answers']['age']);
        $this->assertDatabaseCount('screening_sessions', 1);
        Http::assertSentCount(1);
    }
}
