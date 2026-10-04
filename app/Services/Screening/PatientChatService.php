<?php

namespace App\Services\Screening;

use App\Models\PatientVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PatientChatService
{
    public function start(string $language): PatientVisit
    {
        return DB::transaction(function () use ($language): PatientVisit {
            $visit = PatientVisit::create(['language' => $language, 'answers' => [], 'scope_confirmed_at' => now()]);
            $visit->update(['stub_number' => 'TF-'.str_pad((string) $visit->id, 6, '0', STR_PAD_LEFT)]);
            $this->message($visit, 'assistant', 'guide', 'Welcome. Your stub is '.$visit->stub_number.'. I will collect the reported information one question at a time. Your selected language is '.$language.', but you can mix English, Bisaya and Tagalog in your replies. Choose Unknown for missing information.');
            $question = PatientInterview::current(0);
            $this->message($visit, 'assistant', 'guide', $question['text'], $question['field']);

            return $visit;
        });
    }

    public function answer(PatientVisit $visit, array $data): void
    {
        DB::transaction(function () use ($visit, $data): void {
            $visit->refresh();
            $question = PatientInterview::current($visit->question_index);
            if ($visit->status !== 'collecting' || $question === null || $question['field'] !== $data['field']) {
                throw ValidationException::withMessages(['message' => 'This question has already changed. Refresh the conversation before replying.']);
            }
            $answer = $data['skip'] ? null : ($data['message'] ?? '');
            if (! $data['skip']) {
                Validator::make(['message' => $answer], ['message' => $data['field'] === 'age'
                    ? ['required', 'integer', 'min:18'] : ['required', 'string', 'max:3000']],
                    ['message.min' => 'This demo is for fictional adults aged 18 and above.'])->validate();
                if (trim((string) $answer) === '') {
                    throw ValidationException::withMessages(['message' => 'Write a reply or choose Unknown.']);
                }
            }
            $answers = $visit->answers;
            $answers[$data['field']] = $answer;
            $this->message($visit, 'patient', 'patient', $answer === null ? 'Unknown / not supplied' : (string) $answer, $data['field']);
            $index = $visit->question_index + 1;
            $next = PatientInterview::current($index);
            $visit->update(['answers' => $answers, 'question_index' => $index, 'status' => $next ? 'collecting' : 'ready']);
            $this->message($visit, 'assistant', 'guide', $next['text'] ?? 'Thank you. Review your answers above, then submit this fictional case for preliminary screening. Your conversation and result will be available to staff under your stub number.', $next['field'] ?? null);
        });
    }

    public function screen(PatientVisit $visit, ScreeningService $service): void
    {
        /** Claim once before calling the provider, so refreshes and duplicate submissions never create a second prediction. */
        $claimed = PatientVisit::whereKey($visit->id)->where('status', 'ready')->update(['status' => 'screening']);
        if (! $claimed) {
            return;
        }
        $screening = $service->screen(['patient' => $visit->answers, 'language' => $visit->language], null, $visit->id);
        DB::transaction(function () use ($visit, $screening): void {
            if ($screening->processing_status === 'technical_failure') {
                $text = 'Your conversation is saved. Automated screening could not be completed, so no priority was assigned. Staff can inspect the issue using your stub '.$visit->stub_number.'.';
                $source = 'system';
            } else {
                $result = $screening->method_a_priority === null ? 'Needs review — no priority assigned.' : 'Preliminary result: ESI '.$screening->method_a_priority.'.';
                $text = $result.' '.$screening->parsed_output['explanation'].' This is an unvalidated research output for a fictional case.';
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
