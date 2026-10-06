<?php

namespace App\Services\Screening;

use App\Models\PatientVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PatientChatService
{
    public function __construct(private ConversationalInterview $conversation) {}

    public function start(string $language): PatientVisit
    {
        return DB::transaction(function () use ($language): PatientVisit {
            $visit = PatientVisit::create(['language' => $language, 'answers' => [], 'scope_confirmed_at' => now()]);
            $visit->update(['stub_number' => 'TF-'.str_pad((string) $visit->id, 6, '0', STR_PAD_LEFT)]);
            if (config('triage.intake_version') === 'intake-v1-conversational') {
                $this->conversation->initialize($visit);

                return $visit;
            }
            $this->message($visit, 'assistant', 'guide', PatientChatContent::text($language, 'messages', 'welcome', ['stub' => $visit->stub_number]));
            $question = PatientInterview::current(0, $language);
            $this->message($visit, 'assistant', 'guide', $question['text'], $question['field']);

            return $visit;
        });
    }

    /** @return array{field: string, text: string, revision: int, follow_up: bool}|null */
    public function currentQuestion(PatientVisit $visit): ?array
    {
        if ($visit->interview_state !== null) {
            return $this->conversation->currentQuestion($visit);
        }
        $question = PatientInterview::current($visit->question_index, $visit->language);
        if ($visit->status !== 'collecting' || $question === null) {
            return null;
        }

        $isFollowUp = array_key_exists($question['field'], $visit->answers);
        if ($isFollowUp) {
            $question['text'] = $visit->messages()->where('role', 'assistant')->where('field', $question['field'])->reorder('id', 'desc')->value('content') ?? $question['text'];
        }

        return $question + ['revision' => $visit->messages()->count(), 'follow_up' => $isFollowUp];
    }

    public function answer(PatientVisit $visit, array $data): void
    {
        if ($visit->interview_state !== null) {
            $this->conversation->answer($visit, $data);

            return;
        }
        DB::transaction(function () use ($visit, $data): void {
            $visit->refresh();
            $question = $this->currentQuestion($visit);
            if ($question === null || $question['field'] !== $data['field'] || $question['revision'] !== (int) $data['question_revision']) {
                throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'errors', 'stale')]);
            }
            $answer = $data['skip'] ? null : ($data['message'] ?? '');
            if (! $data['skip']) {
                Validator::make(['message' => $answer], ['message' => $data['field'] === 'age'
                    ? ['required', 'integer', 'min:18'] : ['required', 'string', 'max:3000']],
                    ['message.min' => PatientChatContent::text($visit->language, 'errors', 'adult'),
                        'message.integer' => PatientChatContent::text($visit->language, 'errors', 'age'),
                        'message.required' => PatientChatContent::text($visit->language, 'errors', 'message'),
                        'message.max' => PatientChatContent::text($visit->language, 'errors', 'tooLong')])->validate();
                if (trim((string) $answer) === '') {
                    throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'errors', 'message')]);
                }
            }
            $answers = $visit->answers;
            $this->message($visit, 'patient', 'patient', $answer === null
                ? PatientChatContent::text($visit->language, 'messages', $question['follow_up'] ? 'noMoreDetails' : 'unknown')
                : (string) $answer, $data['field']);
            $answer = $answer === null || PatientFollowUp::isUnknown((string) $answer) ? null : $answer;

            /** Keep verbatim replies together in their dataset field; skipping a clarification retains the first reply. */
            if ($question['follow_up']) {
                if ($answer !== null) {
                    $answers[$data['field']] .= "\n".$answer;
                }
            } else {
                $answers[$data['field']] = $answer;
            }

            $followUp = ! $question['follow_up'] && $answer !== null
                ? PatientFollowUp::question($data['field'], (string) $answer, $visit->language) : null;
            if ($followUp !== null) {
                $visit->update(['answers' => $answers]);
                $this->message($visit, 'assistant', 'guide', $followUp, $data['field']);

                return;
            }

            $index = $visit->question_index + 1;
            $next = PatientInterview::current($index, $visit->language);
            $visit->update(['answers' => $answers, 'question_index' => $index, 'status' => $next ? 'collecting' : 'ready']);
            $this->message($visit, 'assistant', 'guide', $next['text'] ?? PatientChatContent::text($visit->language, 'messages', 'ready'), $next['field'] ?? null);
        });
    }

    public function screen(PatientVisit $visit, ScreeningService $service, ?array $reviewedAnswers = null): void
    {
        /** Claim once before calling the provider, so refreshes and duplicate submissions never create a second prediction. */
        $claimed = DB::transaction(function () use ($visit, $reviewedAnswers): bool {
            $updates = ['status' => 'screening'];
            if ($reviewedAnswers !== null) {
                $updates['answers'] = json_encode(PatientFields::only($reviewedAnswers), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $before = $visit->answers;
            $claimed = PatientVisit::whereKey($visit->id)->where('status', 'ready')->update($updates);
            if (! $claimed) {
                return false;
            }
            $visit->refresh();
            if ($reviewedAnswers !== null && $before !== $visit->answers) {
                $visit->messages()->create(['role' => 'patient', 'source' => 'patient',
                    'content' => PatientChatContent::text($visit->language, 'conversation', 'reviewed'),
                    'metadata' => ['previous_answers' => $before, 'reviewed_answers' => $visit->answers]]);
            }

            return true;
        });
        if (! $claimed) {
            return;
        }
        $screening = $service->screen(['patient' => $visit->answers, 'language' => $visit->language], null, $visit->id);
        DB::transaction(function () use ($visit, $screening): void {
            if ($screening->processing_status === 'technical_failure') {
                $text = PatientChatContent::text($visit->language, 'messages', 'failure', ['stub' => $visit->stub_number]);
                $source = 'system';
            } else {
                $result = $screening->method_a_priority === null
                    ? PatientChatContent::text($visit->language, 'messages', 'needsReview')
                    : PatientChatContent::text($visit->language, 'messages', 'priority', ['priority' => $screening->method_a_priority]);
                $disclaimer = ($screening->request_settings['classification_mode'] ?? null) === 'exploratory_llm_only'
                    ? 'exploratoryOutput' : 'researchOutput';
                $text = $result.' '.$screening->parsed_output['explanation'].' '.PatientChatContent::text($visit->language, 'messages', $disclaimer);
                $source = 'model';
            }
            $this->message($visit, 'assistant', $source, $text);
            $visit->update(['status' => 'completed']);
        });
    }

    private function message(PatientVisit $visit, string $role, string $source, string $content, ?string $field = null): void
    {
        $visit->messages()->create(compact('role', 'source', 'content', 'field'));
    }
}
