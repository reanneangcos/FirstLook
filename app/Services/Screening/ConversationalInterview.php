<?php

namespace App\Services\Screening;

use App\Models\PatientVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConversationalInterview
{
    public const GROUPS = [
        ['main_complaint', 'symptom_description'],
        ['age', 'reported_sex'],
        ['onset', 'duration', 'worsening'],
        ['reported_severity', 'other_symptoms'],
        ['known_conditions', 'maintenance_medications', 'allergies'],
        ['tests_completed', 'tests_requested', 'medical_devices'],
    ];

    public function __construct(private IntakeInterpreter $interpreter) {}

    public function initialize(PatientVisit $visit): void
    {
        $fields = self::GROUPS[0];
        $visit->update(['interview_state' => ['version' => config('triage.intake_version'),
            'pending_fields' => $fields, 'asked_count' => array_fill_keys($fields, 1)]]);
        $visit->messages()->create(['role' => 'assistant', 'source' => 'guide',
            'content' => PatientChatContent::text($visit->language, 'conversation', 'welcome', ['stub' => $visit->stub_number])]);
        $visit->messages()->create(['role' => 'assistant', 'source' => 'guide', 'field' => $fields[0],
            'content' => PatientChatContent::text($visit->language, 'conversation', 'opening')]);
    }

    /** @return array{field: string, fields: array, text: string, revision: int, follow_up: bool}|null */
    public function currentQuestion(PatientVisit $visit): ?array
    {
        $fields = $visit->interview_state['pending_fields'];
        if ($visit->status !== 'collecting' || $fields === []) {
            return null;
        }

        return ['field' => $fields[0], 'fields' => $fields,
            'text' => $visit->messages()->where('role', 'assistant')->reorder('id', 'desc')->value('content'),
            'revision' => $visit->messages()->count(),
            'follow_up' => max(array_intersect_key($visit->interview_state['asked_count'], array_flip($fields))) > 1];
    }

    public function answer(PatientVisit $visit, array $data): void
    {
        $visit->refresh();
        $this->validateRevision($visit, $data);
        $reply = $data['skip'] ? null : ($data['message'] ?? '');
        if ($reply !== null && trim($reply) === '') {
            throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'errors', 'message')]);
        }

        /** Provider work happens outside the database transaction. A failed call keeps the draft in the composer. */
        $result = null;
        if ($reply !== null) {
            try {
                $result = $this->interpreter->interpret($visit, $reply);
            } catch (InvalidIntakeResponse $error) {
                Log::notice('Patient intake request failed.', ['failure_code' => $error->getMessage(),
                    'intake_version' => $visit->interview_state['version']]);
                throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'conversation', 'retry')]);
            }
        }

        DB::transaction(function () use ($visit, $data, $reply, $result): void {
            $visit->refresh();
            $this->validateRevision($visit, $data);
            $answers = $visit->answers;
            $state = $visit->interview_state;
            $clarify = array_fill_keys($state['clarify_fields'] ?? [], true);
            foreach ($result['facts'] ?? [] as $fact) {
                $field = $fact['field'];
                $wasClarifying = isset($clarify[$field]);
                if (array_key_exists($field, $answers) && ! $fact['replaces_previous'] && ! $wasClarifying) {
                    continue;
                }
                $value = $fact['state'] === 'unknown' ? null : $fact['evidence'];
                if ($fact['field'] === 'age' && $value !== null) {
                    if ((int) $value < 18) {
                        throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'errors', 'adult')]);
                    }
                    $value = (int) $value;
                }
                if ($wasClarifying && ! $fact['replaces_previous'] && ($answers[$field] ?? null) !== null) {
                    if ($value !== null && $value !== $answers[$field]) {
                        $answers[$field] .= "\n".$value;
                    }
                } else {
                    $answers[$field] = $value;
                }
                if ($fact['needs_detail'] && $fact['state'] === 'reported' && $field !== 'age'
                    && ($state['asked_count'][$field] ?? 0) < 2) {
                    $clarify[$field] = true;
                } else {
                    unset($clarify[$field]);
                }
            }
            foreach ($state['pending_fields'] as $field) {
                if ($reply === null || $state['asked_count'][$field] >= 2) {
                    if (! array_key_exists($field, $answers)) {
                        $answers[$field] = null;
                    }
                    unset($clarify[$field]);
                }
            }
            $state['clarify_fields'] = array_keys($clarify);
            $fields = $this->nextFields($answers, $state['clarify_fields']);
            $proposed = $result['next_question'] ?? null;
            $useProposed = $fields !== [] && $proposed !== null && $proposed['fields'] === $fields && trim($proposed['text']) !== '';
            $question = $fields === [] ? PatientChatContent::text($visit->language, 'conversation', 'ready')
                : ($useProposed ? $proposed['text'] : $this->fallbackQuestion($fields, $visit->language, $state['clarify_fields']));
            $state['pending_fields'] = $fields;
            foreach ($fields as $field) {
                $state['asked_count'][$field] = ($state['asked_count'][$field] ?? 0) + 1;
            }
            $visit->messages()->create(['role' => 'patient', 'source' => 'patient', 'field' => $data['field'],
                'content' => $reply ?? PatientChatContent::text($visit->language, 'messages', 'unknown'),
                'metadata' => ['requested_fields' => $visit->interview_state['pending_fields']]]);
            $visit->messages()->create(['role' => 'assistant', 'source' => $useProposed ? 'intake' : 'guide',
                'field' => $fields[0] ?? null, 'content' => $question, 'metadata' => $result['metadata'] ?? null]);
            $visit->update(['answers' => $answers, 'interview_state' => $state,
                'question_index' => count($answers), 'status' => $fields === [] ? 'ready' : 'collecting']);
        });
    }

    /** @return list<string> */
    private function nextFields(array $answers, array $clarifyFields): array
    {
        foreach (self::GROUPS as $group) {
            $missing = array_values(array_filter($group, fn (string $field): bool => ! array_key_exists($field, $answers) || in_array($field, $clarifyFields, true)));
            if ($missing !== []) {
                return $missing;
            }
        }

        return [];
    }

    /** @param list<string> $fields */
    private function fallbackQuestion(array $fields, string $language, array $clarifyFields): string
    {
        return implode(' ', array_map(fn (string $field): string => PatientChatContent::text($language,
            in_array($field, $clarifyFields, true) ? 'detailQuestions' : 'shortQuestions', $field), $fields));
    }

    private function validateRevision(PatientVisit $visit, array $data): void
    {
        $question = $this->currentQuestion($visit);
        if ($question === null || $question['field'] !== $data['field'] || $question['revision'] !== (int) $data['question_revision']) {
            throw ValidationException::withMessages(['message' => PatientChatContent::text($visit->language, 'errors', 'stale')]);
        }
    }
}
