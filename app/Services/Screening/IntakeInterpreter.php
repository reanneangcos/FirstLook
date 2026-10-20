<?php

namespace App\Services\Screening;

use App\Models\PatientVisit;
use App\Services\OpenAI\OpenAIClient;
use Illuminate\Http\Client\ConnectionException;
use JsonException;

class IntakeInterpreter
{
    public function __construct(private OpenAIClient $client) {}

    /** @return array{facts: array, next_question: array, language_change: ?array, metadata: array} */
    public function interpret(PatientVisit $visit, string $reply): array
    {
        if (! config('triage.api_key') || ! config('triage.model')) {
            throw new InvalidIntakeResponse('configuration_missing');
        }
        $version = config('triage.intake_version');
        $prompt = file_get_contents(resource_path('prompts/'.$version.'.txt'));
        $prompt .= "\n\n".PatientChatContent::voiceGuidance($visit->language, allowLanguageChange: true);
        $settings = ['model' => config('triage.model'), 'store' => false,
            'reasoning' => ['effort' => config('triage.reasoning_effort')],
            'max_output_tokens' => config('triage.intake_max_output_tokens'),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'patient_intake', 'strict' => true, 'schema' => $this->schema()]]];
        $context = [
            'response_language' => $visit->language,
            'dataset_field_labels' => PatientFields::DATASET_COLUMNS,
            'recent_messages' => $visit->messages()->whereNotNull('field')->reorder('id', 'desc')->limit(6)->get(['role', 'content'])
                ->reverse()->values()->toArray(),
            'known_answers' => $visit->answers,
            'unresolved_answers' => $visit->interview_state['unresolved_answers'] ?? [],
            'pending_fields' => $visit->interview_state['pending_fields'],
            'asked_count' => $visit->interview_state['asked_count'],
            'clarify_fields' => $visit->interview_state['clarify_fields'] ?? [],
            'question_groups' => ConversationalInterview::GROUPS,
            'last_question' => $visit->messages()->where('role', 'assistant')->whereNotNull('field')->reorder('id', 'desc')->value('content'),
            'reply' => $reply,
        ];
        try {
            $response = $this->client->send($settings + ['input' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ]]);
        } catch (ConnectionException) {
            throw new InvalidIntakeResponse('connection_timeout');
        }
        if (! $response->successful()) {
            throw new InvalidIntakeResponse('provider_unavailable');
        }
        $envelope = $response->json();
        $result = $this->parse(is_array($envelope) ? $envelope : [], $reply);

        return $result + ['metadata' => ['intake_version' => $version, 'prompt_text' => $prompt,
            'patient_voice_version' => config('triage.patient_voice_version'),
            'request_settings' => $settings, 'previous_language' => $visit->language,
            'response_language' => $result['language_change']['language'] ?? $visit->language,
            'language_change' => $result['language_change'],
            'returned_model' => $envelope['model'] ?? null, 'token_usage' => $envelope['usage'] ?? null,
            'original_response' => $response->body(), 'facts' => $result['facts']]];
    }

    public function schema(): array
    {
        $factProperties = [
            'state' => ['type' => 'string', 'enum' => ['reported', 'absent', 'not_applicable', 'unknown', 'unclear']],
            'evidence' => ['type' => 'string'], 'replaces_previous' => ['type' => 'boolean'], 'needs_detail' => ['type' => 'boolean'],
        ];
        $factsSchema = ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
            'required' => ['field', ...array_keys($factProperties)],
            'properties' => ['field' => ['type' => 'string', 'enum' => PatientFields::NAMES], ...$factProperties]]];
        if (config('triage.intake_version') === 'intake-v5-field-map') {
            $factsSchema = ['type' => 'object', 'additionalProperties' => false, 'required' => PatientFields::NAMES,
                'properties' => array_fill_keys(PatientFields::NAMES, ['anyOf' => [
                    ['type' => 'null'],
                    ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($factProperties), 'properties' => $factProperties],
                ]])];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['facts', 'next_question', 'language_change'], 'properties' => [
            'language_change' => ['anyOf' => [
                ['type' => 'null'],
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['language', 'evidence'], 'properties' => [
                    'language' => ['type' => 'string', 'enum' => array_keys(PatientChatContent::LOCALES)],
                    'evidence' => ['type' => 'string'],
                ]],
            ]],
            'facts' => $factsSchema,
            'next_question' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['fields', 'text'], 'properties' => [
                'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => PatientFields::NAMES]],
                'text' => ['type' => 'string'],
            ]],
        ]];
    }

    /** @return array{facts: array, next_question: array, language_change: ?array} */
    private function parse(array $envelope, string $reply): array
    {
        if (($envelope['status'] ?? null) !== 'completed' || ! is_array($envelope['output'] ?? null)) {
            throw new InvalidIntakeResponse('incomplete_response');
        }
        $text = '';
        foreach ($envelope['output'] as $item) {
            if (! is_array($item) || (isset($item['content']) && ! is_array($item['content']))) {
                throw new InvalidIntakeResponse('invalid_envelope');
            }
            foreach (($item['content'] ?? []) as $part) {
                if (! is_array($part)) {
                    throw new InvalidIntakeResponse('invalid_envelope');
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new InvalidIntakeResponse('model_refusal');
                }
                if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }
        try {
            $result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidIntakeResponse('invalid_json');
        }
        if (config('triage.intake_version') === 'intake-v5-field-map') {
            $map = $result['facts'] ?? null;
            if (! is_array($map) || count($map) !== count(PatientFields::NAMES)
                || array_diff(PatientFields::NAMES, array_keys($map)) !== []) {
                throw new InvalidIntakeResponse('invalid_fact_map');
            }
            $result['facts'] = [];
            foreach ($map as $field => $fact) {
                if ($fact === null) {
                    continue;
                }
                if (! is_array($fact) || array_key_exists('field', $fact)) {
                    throw new InvalidIntakeResponse('invalid_fact_map');
                }
                $result['facts'][] = ['field' => $field, ...$fact];
            }
        }
        if (! is_array($result) || count($result) !== 3 || ! array_key_exists('language_change', $result) || ! is_array($result['facts'] ?? null)
            || ! array_is_list($result['facts']) || count($result['facts']) > 15) {
            throw new InvalidIntakeResponse('invalid_facts');
        }
        $seen = [];
        foreach ($result['facts'] as $index => $fact) {
            if (! is_array($fact) || count($fact) !== 5 || ! in_array($fact['field'] ?? null, PatientFields::NAMES, true)
                || in_array($fact['field'], $seen, true)
                || ! in_array($fact['state'] ?? null, ['reported', 'absent', 'not_applicable', 'unknown', 'unclear'], true)
                || ! is_bool($fact['replaces_previous'] ?? null)
                || ! is_bool($fact['needs_detail'] ?? null)
                || ! is_string($fact['evidence'] ?? null) || trim($fact['evidence']) === ''
                || ! str_contains($reply, $fact['evidence'])) {
                throw new InvalidIntakeResponse('ungrounded_fact');
            }
            if ($fact['state'] === 'unknown' && PatientFields::isNoneReported($fact['evidence'])) {
                $fact['state'] = 'absent';
            }
            if ($fact['state'] !== 'unknown' && preg_match('/\b(maybe|perhaps|possibly|probably|unsure|not sure|i think|i guess|siguro|tingali|yata|guro)\b/u', mb_strtolower($fact['evidence']))) {
                $fact['state'] = 'unclear';
            }
            $fact['needs_detail'] = $fact['needs_detail'] || $this->needsClarification($fact);
            $result['facts'][$index] = $fact;
            if ($fact['field'] === 'age' && ! $fact['needs_detail'] && $fact['state'] !== 'unknown'
                && ($fact['state'] !== 'reported' || ! preg_match('/^\d{1,3}$/', $fact['evidence']))) {
                throw new InvalidIntakeResponse('invalid_age');
            }
            $seen[] = $fact['field'];
            if (in_array($fact['state'], ['absent', 'not_applicable'], true)) {
                $result['facts'][$index]['evidence'] = $this->sentenceContaining($reply, $fact['evidence']);
            }
        }
        $languageChange = $result['language_change'];
        if ($languageChange !== null && (! is_array($languageChange) || count($languageChange) !== 2
            || ! in_array($languageChange['language'] ?? null, array_keys(PatientChatContent::LOCALES), true)
            || ! is_string($languageChange['evidence'] ?? null) || trim($languageChange['evidence']) === ''
            || ! str_contains($reply, $languageChange['evidence']))) {
            throw new InvalidIntakeResponse('invalid_language_change');
        }
        $question = $result['next_question'] ?? null;
        if (! is_array($question) || count($question) !== 2 || ! is_array($question['fields'] ?? null) || ! array_is_list($question['fields'])
            || count($question['fields']) > 3 || count(array_unique($question['fields'], SORT_REGULAR)) !== count($question['fields'])
            || ! is_string($question['text'] ?? null) || mb_strlen($question['text']) > 350) {
            throw new InvalidIntakeResponse('invalid_question');
        }
        foreach ($question['fields'] as $field) {
            if (! in_array($field, PatientFields::NAMES, true)) {
                throw new InvalidIntakeResponse('invalid_question_field');
            }
        }

        return ['facts' => $result['facts'], 'next_question' => $question, 'language_change' => $languageChange];
    }

    /** Protect common incomplete answers even when the model marks them complete. */
    private function needsClarification(array $fact): bool
    {
        if ($fact['state'] === 'unclear') {
            return true;
        }
        if ($fact['state'] === 'unknown') {
            return false;
        }
        $value = trim(mb_strtolower($fact['evidence']), " \t\n\r\0\x0B.!?…");
        if ($fact['state'] !== 'reported') {
            return false;
        }
        if ($fact['field'] !== 'worsening' && in_array($value, ['yes', 'yes po', 'oo', 'oo po', 'opo', 'naa', 'meron', 'mayroon'], true)) {
            return true;
        }
        if ($fact['field'] === 'duration' && preg_match('/^\d+(?:[.,]\d+)?$/u', $value)) {
            return true;
        }

        return in_array($value, ['something', 'stuff', 'basta', 'i feel bad', 'i feel unwell', 'not feeling well', 'ambot unsa'], true);
    }

    /** Keep the original sentence so a shortened excerpt cannot discard its negation. */
    private function sentenceContaining(string $reply, string $evidence): string
    {
        foreach (preg_split('/(?<=[.!?。！？])\s+/u', $reply, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
            if (str_contains($sentence, $evidence)) {
                return $sentence;
            }
        }

        return $reply;
    }
}
