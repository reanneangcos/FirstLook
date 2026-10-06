<?php

namespace App\Services\Screening;

use App\Models\PatientVisit;
use App\Services\OpenAI\OpenAIClient;
use Illuminate\Http\Client\ConnectionException;
use JsonException;

class IntakeInterpreter
{
    public function __construct(private OpenAIClient $client) {}

    /** @return array{facts: array, next_question: array, metadata: array} */
    public function interpret(PatientVisit $visit, string $reply): array
    {
        if (! config('triage.api_key') || ! config('triage.model')) {
            throw new InvalidIntakeResponse('configuration_missing');
        }
        $version = $visit->interview_state['version'];
        $prompt = file_get_contents(resource_path('prompts/'.$version.'.txt'));
        $settings = ['model' => config('triage.model'), 'store' => false,
            'reasoning' => ['effort' => config('triage.reasoning_effort')],
            'max_output_tokens' => config('triage.intake_max_output_tokens'),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'patient_intake', 'strict' => true, 'schema' => $this->schema()]]];
        $context = [
            'response_language' => $visit->language,
            'known_answers' => $visit->answers,
            'pending_fields' => $visit->interview_state['pending_fields'],
            'asked_count' => $visit->interview_state['asked_count'],
            'clarify_fields' => $visit->interview_state['clarify_fields'] ?? [],
            'question_groups' => ConversationalInterview::GROUPS,
            'last_question' => $visit->messages()->where('role', 'assistant')->reorder('id', 'desc')->value('content'),
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
            'request_settings' => $settings, 'response_language' => $visit->language,
            'returned_model' => $envelope['model'] ?? null, 'token_usage' => $envelope['usage'] ?? null,
            'original_response' => $response->body(), 'facts' => $result['facts']]];
    }

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['facts', 'next_question'], 'properties' => [
            'facts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                'required' => ['field', 'state', 'evidence', 'replaces_previous', 'needs_detail'], 'properties' => [
                    'field' => ['type' => 'string', 'enum' => PatientFields::NAMES],
                    'state' => ['type' => 'string', 'enum' => ['reported', 'absent', 'not_applicable', 'unknown']],
                    'evidence' => ['type' => 'string'], 'replaces_previous' => ['type' => 'boolean'],
                    'needs_detail' => ['type' => 'boolean'],
                ]]],
            'next_question' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['fields', 'text'], 'properties' => [
                'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => PatientFields::NAMES]],
                'text' => ['type' => 'string'],
            ]],
        ]];
    }

    /** @return array{facts: array, next_question: array} */
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
        if (! is_array($result) || count($result) !== 2 || ! is_array($result['facts'] ?? null)
            || ! array_is_list($result['facts']) || count($result['facts']) > 15) {
            throw new InvalidIntakeResponse('invalid_facts');
        }
        $seen = [];
        foreach ($result['facts'] as $index => $fact) {
            if (! is_array($fact) || count($fact) !== 5 || ! in_array($fact['field'] ?? null, PatientFields::NAMES, true)
                || in_array($fact['field'], $seen, true)
                || ! in_array($fact['state'] ?? null, ['reported', 'absent', 'not_applicable', 'unknown'], true)
                || ! is_bool($fact['replaces_previous'] ?? null)
                || ! is_bool($fact['needs_detail'] ?? null)
                || ! is_string($fact['evidence'] ?? null) || trim($fact['evidence']) === ''
                || ! str_contains($reply, $fact['evidence'])) {
                throw new InvalidIntakeResponse('ungrounded_fact');
            }
            if ($fact['field'] === 'age' && $fact['state'] !== 'unknown'
                && ($fact['state'] !== 'reported' || ! preg_match('/^\d{1,3}$/', $fact['evidence']))) {
                throw new InvalidIntakeResponse('invalid_age');
            }
            $seen[] = $fact['field'];
            if (in_array($fact['state'], ['absent', 'not_applicable'], true)) {
                $result['facts'][$index]['evidence'] = $this->sentenceContaining($reply, $fact['evidence']);
            }
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

        return ['facts' => $result['facts'], 'next_question' => $question];
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
