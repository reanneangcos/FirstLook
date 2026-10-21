<?php

namespace Tests\Feature;

use App\Models\PatientVisit;
use App\Services\Screening\ConversationalInterview;
use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientChatService;
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
        config(['triage.intake_version' => 'intake-v6-reporting-states', 'triage.api_key' => 'mock-only-key',
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

    private static function intakeResponse(array $facts = [], array $fields = [], string $question = '', ?array $languageChange = null): array
    {
        $map = array_fill_keys(PatientFields::NAMES, null);
        foreach ($facts as $fact) {
            $field = $fact['field'];
            unset($fact['field']);
            $map[$field] = $fact;
        }

        return MockScreening::envelope(['facts' => $map, 'next_question' => ['fields' => $fields, 'text' => $question], 'language_change' => $languageChange]);
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

    public function test_field_map_schema_preserves_unmentioned_fields_and_complete_multisentence_evidence(): void
    {
        $visit = $this->startChat();
        $description = 'My arm itches. No swelling. I can move it normally.';
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'My arm itches'), self::fact('symptom_description', $description),
            self::fact('allergies', 'Allergies: Not reported', 'unknown'),
        ]))]);
        $this->answer($visit, $description.' Allergies: Not reported.')->assertRedirect('/');
        $this->assertSame($description, $visit->refresh()->answers['symptom_description']);
        $this->assertArrayHasKey('allergies', $visit->answers);
        $this->assertNull($visit->answers['allergies']);
        $this->assertArrayNotHasKey('maintenance_medications', $visit->answers);
        Http::assertSent(function ($request): bool {
            $schema = $request['text']['format']['schema']['properties']['facts'];

            return $schema['type'] === 'object' && $schema['additionalProperties'] === false
                && $schema['required'] === PatientFields::NAMES
                && count($schema['properties']) === 15
                && $schema['properties']['allergies']['anyOf'][0]['type'] === 'null';
        });
    }

    #[DataProvider('languages')]
    public function test_complete_record_preserves_explicit_unknowns_and_absences_without_repeating_questions(string $language): void
    {
        $visit = $this->startChat($language);
        $values = ['age' => '44', 'reported_sex' => 'Female', 'main_complaint' => 'Itchy arm',
            'symptom_description' => 'My arm itches', 'other_symptoms' => 'No other symptoms',
            'known_conditions' => 'None reported', 'allergies' => 'Not reported', 'maintenance_medications' => 'None reported',
            'onset' => 'Yesterday', 'duration' => '1 day', 'reported_severity' => 'Mild', 'worsening' => 'No',
            'tests_completed' => 'Not reported', 'tests_requested' => 'Not reported', 'medical_devices' => 'None reported'];
        $facts = [];
        $clauses = [];
        foreach ($values as $field => $value) {
            $clause = PatientFields::DATASET_COLUMNS[$field].': '.$value;
            $clauses[] = $clause;
            $state = in_array($value, ['Not reported', 'None reported'], true) ? 'unknown' : 'reported';
            $facts[] = self::fact($field, $field === 'age' ? $value : $clause, $state);
        }
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse($facts))]);
        $this->answer($visit, implode('. ', $clauses).'.')->assertRedirect('/');
        $this->assertSame('ready', $visit->refresh()->status);
        $this->assertCount(15, $visit->answers);
        $this->assertSame([], $visit->interview_state['pending_fields']);
        $this->assertSame(12, $visit->question_index);
        $this->assertNull($visit->answers['allergies']);
        $this->assertSame('Known conditions: None reported.', $visit->answers['known_conditions']);
        $this->assertSame('Maintenance medications: None reported.', $visit->answers['maintenance_medications']);
        $this->assertSame('Medical devices or catheters: None reported.', $visit->answers['medical_devices']);
        $this->assertNull($visit->answers['tests_completed']);
        $this->assertNull($visit->answers['tests_requested']);
        $this->assertSame(44, $visit->answers['age']);
        $this->assertNull(app(PatientChatService::class)->currentQuestion($visit));
        Http::assertSent(fn ($request): bool => json_decode($request['input'][1]['content'], true)['dataset_field_labels'] === PatientFields::DATASET_COLUMNS);
        Http::assertSentCount(1);
    }

    public function test_bisaya_none_reported_placeholders_are_saved_as_absent(): void
    {
        $visit = $this->startChat('Bisaya');
        $facts = [
            self::fact('tests_completed', 'Mga nahimong eksaminasyon: Walay gitaho.', 'unknown'),
            self::fact('tests_requested', 'Mga eksaminasyon nga gipangayo sa doktor: Walay gitaho.', 'unknown'),
            self::fact('medical_devices', 'Mga medical device o catheter: Walay gitaho.', 'unknown'),
        ];
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse($facts))]);

        $this->answer($visit, 'Mga nahimong eksaminasyon: Walay gitaho. Mga eksaminasyon nga gipangayo sa doktor: Walay gitaho. Mga medical device o catheter: Walay gitaho.')
            ->assertRedirect('/');

        $this->assertSame('Mga nahimong eksaminasyon: Walay gitaho.', $visit->refresh()->answers['tests_completed']);
        $this->assertSame('Mga eksaminasyon nga gipangayo sa doktor: Walay gitaho.', $visit->answers['tests_requested']);
        $this->assertSame('Mga medical device o catheter: Walay gitaho.', $visit->answers['medical_devices']);
    }

    public function test_bisaya_unknown_synonyms_remain_unknown(): void
    {
        $visit = $this->startChat('Bisaya');
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('medical_devices', 'Mga medical device o catheter: Wala ko kabalo.', 'unknown'),
        ]))]);

        $this->answer($visit, 'Mga medical device o catheter: Wala ko kabalo.')->assertRedirect('/');

        $this->assertArrayHasKey('medical_devices', $visit->refresh()->answers);
        $this->assertNull($visit->answers['medical_devices']);
    }

    public function test_a_reported_correction_after_none_reported_keeps_the_full_statement(): void
    {
        $visit = $this->startChat();
        $reply = 'Known conditions: None reported. Actually, I have asthma.';
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('known_conditions', $reply),
        ]))]);

        $this->answer($visit, $reply)->assertRedirect('/');

        $this->assertSame($reply, $visit->refresh()->answers['known_conditions']);
        $fact = $visit->messages()->reorder('id', 'desc')->first()->metadata['facts'][0];
        $this->assertSame('reported', $fact['state']);
        $this->assertSame($reply, $fact['evidence']);
        Http::assertSentCount(1);
    }

    public function test_unclear_mixed_statement_remains_pending_without_becoming_absent(): void
    {
        $visit = $this->startChat();
        $reply = 'Known conditions: None reported. I may have asthma, I am not sure.';
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('known_conditions', $reply, 'unclear', true),
        ]))]);

        $this->answer($visit, $reply)->assertRedirect('/');

        $this->assertArrayNotHasKey('known_conditions', $visit->refresh()->answers);
        $this->assertContains('known_conditions', $visit->interview_state['clarify_fields']);
        $this->assertSame(['state' => 'unclear', 'evidence' => $reply], $visit->interview_state['unresolved_answers']['known_conditions']);
        $this->assertSame(0, $visit->question_index);
        Http::assertSentCount(1);
    }

    public function test_resolved_both_reply_receives_prior_clarification_context_and_advances(): void
    {
        $visit = $this->startChat();
        $this->skipTo($visit, 'known_conditions');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::intakeResponse([self::fact('known_conditions', 'No conditions', 'absent')],
                ['maintenance_medications', 'allergies'], 'Do you mean no regular medications, no allergies, or both?'))
            ->push(self::intakeResponse([
                self::fact('maintenance_medications', 'both', 'absent'), self::fact('allergies', 'both', 'absent'),
            ]))]);
        $this->answer($visit, 'No conditions')->assertRedirect('/');
        $this->answer($visit, 'both')->assertRedirect('/');
        $this->assertSame(['tests_completed', 'tests_requested', 'medical_devices'], $visit->refresh()->interview_state['pending_fields']);
        Http::assertSent(function ($request): bool {
            $context = json_decode($request['input'][1]['content'], true);

            return $context['reply'] === 'both'
                && in_array('Do you mean no regular medications, no allergies, or both?', array_column($context['recent_messages'], 'content'), true)
                && in_array('No conditions', array_column($context['recent_messages'], 'content'), true);
        });
        Http::assertSentCount(2);
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
        $metadata = $visit->messages()->reorder('id', 'desc')->first()->metadata;
        $this->assertSame('patient-voice-v2-casual', $metadata['patient_voice_version']);
        $this->assertStringContainsString('FirstLook patient voice v2', $metadata['prompt_text']);
        $this->assertStringContainsString('Patient-facing output field for this request: next_question.text', $metadata['prompt_text']);
        $this->assertStringNotContainsString('"reviewMessage":', $metadata['prompt_text']);
        $this->assertStringContainsString(PatientChatContent::text($language, 'detailQuestions', 'medical_devices'), $metadata['prompt_text']);
        Http::assertSent(function ($request) use ($language, $visit, $metadata): bool {
            $context = json_decode($request['input'][1]['content'], true);
            $schema = $request['text']['format']['schema']['properties'];

            return $context['response_language'] === $language
                && $request['input'][0]['content'] === $metadata['prompt_text']
                && str_contains($request['input'][0]['content'], 'Patient-facing response language: '.$language)
                && ! str_contains($request['input'][0]['content'], '{{wording_examples}}')
                && ! str_contains(json_encode($request->data()), $visit->stub_number)
                && ! array_key_exists('patient_voice_version', $request->data())
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

    public static function languageRequests(): array
    {
        return [
            'switch to Bisaya' => ['English', 'Bisaya', 'boss namali kog pili sa language pwede ka magbisaya?'],
            'switch to Tagalog' => ['Bisaya', 'Tagalog', 'Tagalog na lang please'],
            'switch to English' => ['Tagalog', 'English', 'Please use English'],
        ];
    }

    #[DataProvider('languageRequests')]
    public function test_language_requests_change_the_saved_language_without_counting_as_patient_answers(string $initial, string $target, string $reply): void
    {
        $visit = $this->startChat($initial);
        $counts = $visit->interview_state['asked_count'];
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(self::intakeResponse([], ['main_complaint', 'symptom_description'], 'Stale wording in the wrong language.',
                ['language' => $target, 'evidence' => $reply]))
            ->push(self::intakeResponse([self::fact('main_complaint', 'Itchy arm'), self::fact('symptom_description', 'Itchy arm')]))]);

        $this->answer($visit, $reply)->assertRedirect('/');

        $this->assertSame($target, $visit->refresh()->language);
        $this->assertSame([], $visit->answers);
        $this->assertSame(0, $visit->question_index);
        $this->assertSame($counts, $visit->interview_state['asked_count']);
        $this->assertSame(['main_complaint', 'symptom_description'], $visit->interview_state['pending_fields']);
        $message = $visit->messages()->reorder('id', 'desc')->first();
        $this->assertSame(PatientChatContent::text($target, 'conversation', 'languageChanged').' '
            .PatientChatContent::text($target, 'shortQuestions', 'main_complaint').' '
            .PatientChatContent::text($target, 'shortQuestions', 'symptom_description'), $message->content);
        $this->assertSame($initial, $message->metadata['previous_language']);
        $this->assertSame($target, $message->metadata['response_language']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('visit.language', $target)->where('visit.question_index', 0));

        $this->answer($visit, 'Itchy arm')->assertRedirect('/');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => json_decode($request['input'][1]['content'], true)['response_language'] === $target
            && str_contains($request['input'][0]['content'], 'Patient-facing response language: '.$target));
        $this->assertSame(2, $visit->refresh()->question_index);
        $this->assertSame(PatientChatContent::text($target, 'shortQuestions', 'age').' '.PatientChatContent::text($target, 'shortQuestions', 'reported_sex'),
            $visit->messages()->reorder('id', 'desc')->first()->content);
    }

    public function test_language_change_and_clear_symptoms_in_one_reply_preserve_the_original_evidence(): void
    {
        $visit = $this->startChat();
        $reply = 'Magbisaya ta. Katol akong bukton.';
        Http::fake(['api.openai.com/v1/responses' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'Katol akong bukton'), self::fact('symptom_description', 'Katol akong bukton'),
        ], languageChange: ['language' => 'Bisaya', 'evidence' => 'Magbisaya ta']))]);

        $this->answer($visit, $reply)->assertRedirect('/');

        $this->assertSame('Bisaya', $visit->refresh()->language);
        $this->assertSame('Katol akong bukton', $visit->answers['main_complaint']);
        $this->assertSame(2, $visit->question_index);
        $this->assertSame(['age', 'reported_sex'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(1);
    }

    public function test_reply_language_alone_does_not_change_the_selected_response_language(): void
    {
        $visit = $this->startChat('Tagalog');
        Http::fake(['api.openai.com/v1/responses' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'Katol akong bukton'), self::fact('symptom_description', 'Katol akong bukton'),
        ]))]);

        $this->answer($visit, 'Katol akong bukton')->assertRedirect('/');

        $this->assertSame('Tagalog', $visit->refresh()->language);
        $this->assertSame(PatientChatContent::text('Tagalog', 'shortQuestions', 'age').' '.PatientChatContent::text('Tagalog', 'shortQuestions', 'reported_sex'),
            $visit->messages()->reorder('id', 'desc')->first()->content);
        Http::assertSentCount(1);
    }

    public static function incompleteAnswers(): array
    {
        return [
            'English medication yes' => ['English', 'maintenance_medications', 'yes', 'reported'],
            'Tagalog medication yes' => ['Tagalog', 'maintenance_medications', 'oo', 'reported'],
            'Bisaya medication yes' => ['Bisaya', 'maintenance_medications', 'naa', 'reported'],
            'tentative device' => ['Bisaya', 'medical_devices', 'catheter man guro', 'reported'],
            'tentative negative' => ['English', 'allergies', 'Maybe no allergies', 'absent'],
            'duration without units' => ['English', 'duration', '3', 'reported'],
            'ambiguous age' => ['Tagalog', 'age', '34 o 35', 'unclear'],
        ];
    }

    #[DataProvider('incompleteAnswers')]
    public function test_unclear_answers_stay_pending_after_repeated_attempts_even_when_marked_complete(string $language, string $field, string $reply, string $state): void
    {
        $visit = $this->startChat($language);
        $this->skipTo($visit, $field);
        Http::fake(['api.openai.com/v1/responses' => Http::response(self::intakeResponse([self::fact($field, $reply, $state)]))]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->answer($visit, $reply)->assertRedirect('/');
        }

        $this->assertArrayNotHasKey($field, $visit->refresh()->answers);
        $this->assertSame(0, $visit->question_index);
        $this->assertSame([$field], $visit->interview_state['pending_fields']);
        $this->assertSame('collecting', $visit->status);
        $this->assertSame($reply, $visit->interview_state['unresolved_answers'][$field]['evidence']);
        $this->post('/patient/screen', ['scope_confirmed' => true])->assertRedirect('/');
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertSentCount(3);
    }

    public function test_clear_negative_resolves_a_tentative_positive_without_combining_conflicting_evidence(): void
    {
        $visit = $this->startChat('Bisaya');
        $this->skipTo($visit, 'medical_devices');
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(self::intakeResponse([self::fact('medical_devices', 'catheter man guro')]))
            ->push(self::intakeResponse([self::fact('medical_devices', 'Wala diay koy catheter', 'absent', corrects: true)]))]);

        $this->answer($visit, 'catheter man guro')->assertRedirect('/');
        $this->answer($visit, 'Wala diay koy catheter')->assertRedirect('/');

        $this->assertSame('Wala diay koy catheter', $visit->refresh()->answers['medical_devices']);
        $this->assertSame(1, $visit->question_index);
        $this->assertArrayNotHasKey('medical_devices', $visit->interview_state['unresolved_answers']);
        Http::assertSentCount(2);
    }

    public function test_explicit_unknown_resolves_clarification_without_counting_as_a_clear_answer(): void
    {
        $visit = $this->startChat();
        $this->skipTo($visit, 'known_conditions');
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(self::intakeResponse([self::fact('maintenance_medications', 'yes')]))
            ->push(self::intakeResponse([self::fact('maintenance_medications', "I don't know the name", 'unknown')]))]);

        $this->answer($visit, 'yes')->assertRedirect('/');
        $this->answer($visit, "I don't know the name")->assertRedirect('/');

        $this->assertNull($visit->refresh()->answers['maintenance_medications']);
        $this->assertSame(0, $visit->question_index);
        $this->assertNotContains('maintenance_medications', $visit->interview_state['pending_fields']);
        Http::assertSentCount(2);
    }

    public function test_existing_conversations_use_the_new_intake_without_rewriting_old_messages(): void
    {
        $visit = $this->startChat();
        $oldMessage = $visit->messages()->first()->content;
        $visit->update(['answers' => ['main_complaint' => 'I feel bad'], 'question_index' => 1,
            'interview_state' => array_replace($visit->interview_state, ['version' => 'intake-v1-conversational', 'clarify_fields' => ['main_complaint']])]);
        Http::fake(['api.openai.com/v1/responses' => Http::response(self::intakeResponse([], languageChange: ['language' => 'Bisaya', 'evidence' => 'Bisaya please']))]);

        $this->answer($visit, 'Bisaya please')->assertRedirect('/');

        $this->assertSame('intake-v6-reporting-states', $visit->refresh()->interview_state['version']);
        $this->assertArrayNotHasKey('main_complaint', $visit->answers);
        $this->assertSame('I feel bad', $visit->interview_state['unresolved_answers']['main_complaint']['evidence']);
        $this->assertSame(0, $visit->question_index);
        $this->assertSame($oldMessage, $visit->messages()->first()->content);
        $this->assertSame('intake-v6-reporting-states', $visit->messages()->reorder('id', 'desc')->first()->metadata['intake_version']);
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

    public function test_a_vague_medication_answer_is_counted_only_after_a_specific_clarification(): void
    {
        $visit = $this->startChat();
        $this->skipTo($visit, 'known_conditions');
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(self::intakeResponse([self::fact('known_conditions', 'No conditions', 'absent'),
                self::fact('maintenance_medications', 'yes medication', needsDetail: true), self::fact('allergies', 'no allergies', 'absent')]))
            ->push(self::intakeResponse([self::fact('maintenance_medications', 'Metformin')]))]);
        $this->answer($visit, 'No conditions; yes medication; no allergies')->assertRedirect('/');
        $this->assertSame(['maintenance_medications'], $visit->refresh()->interview_state['pending_fields']);
        $this->assertArrayNotHasKey('maintenance_medications', $visit->answers);
        $this->assertSame(2, $visit->question_index);
        $this->assertSame('What are the names of your regular medications?', $visit->messages()->reorder('id', 'desc')->first()->content);
        $this->answer($visit, 'Metformin')->assertRedirect('/');
        $this->assertSame("yes medication\nMetformin", $visit->refresh()->answers['maintenance_medications']);
        $this->assertSame(3, $visit->question_index);
        $this->assertSame(['tests_completed', 'tests_requested', 'medical_devices'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(2);
    }

    public function test_skipping_a_clarification_keeps_the_transcript_but_leaves_the_answer_unknown(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse([
            self::fact('main_complaint', 'I feel bad', needsDetail: true), self::fact('symptom_description', 'I feel bad', needsDetail: true),
        ]))]);
        $this->answer($visit, 'I feel bad')->assertRedirect('/');
        $this->answer($visit, null)->assertRedirect('/');
        $this->assertNull($visit->refresh()->answers['main_complaint']);
        $this->assertSame(0, $visit->question_index);
        $this->assertDatabaseHas('chat_messages', ['patient_visit_id' => $visit->id, 'role' => 'patient', 'content' => 'I feel bad']);
        $this->assertSame(['age', 'reported_sex'], $visit->interview_state['pending_fields']);
        Http::assertSentCount(1);
    }

    public function test_unrelated_replies_never_automatically_complete_or_skip_pending_fields(): void
    {
        $visit = $this->startChat();
        Http::fake(['api.openai.com/*' => Http::response(self::intakeResponse())]);
        $this->answer($visit, 'hello')->assertRedirect('/');
        $this->answer($visit, 'hello again')->assertRedirect('/');
        $this->answer($visit, 'asdfgh')->assertRedirect('/');
        $this->assertSame([], $visit->refresh()->answers);
        $this->assertSame(0, $visit->question_index);
        $this->assertSame(['main_complaint', 'symptom_description'], $visit->interview_state['pending_fields']);
        $this->assertSame('collecting', $visit->status);
        Http::assertSentCount(3);
    }

    public function test_conflicting_known_answers_require_clarification_before_a_correction_is_accepted(): void
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
        $this->assertArrayNotHasKey('age', $visit->refresh()->answers);
        $this->assertSame(['age'], $visit->interview_state['pending_fields']);
        $this->assertSame('unclear', $visit->interview_state['unresolved_answers']['age']['state']);
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
            [MockScreening::envelope(['facts' => [], 'next_question' => ['fields' => [], 'text' => ''], 'language_change' => null])],
            [MockScreening::envelope(['facts' => array_fill_keys(array_slice(PatientFields::NAMES, 1), null), 'next_question' => ['fields' => [], 'text' => ''], 'language_change' => null])],
            [MockScreening::envelope(['facts' => array_replace(array_fill_keys(PatientFields::NAMES, null), ['age' => ['field' => 'age']]), 'next_question' => ['fields' => [], 'text' => ''], 'language_change' => null])],
            [self::intakeResponse(languageChange: ['language' => 'Spanish', 'evidence' => 'hello'])],
            [self::intakeResponse(languageChange: ['language' => 'Bisaya', 'evidence' => 'not in the reply'])],
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
