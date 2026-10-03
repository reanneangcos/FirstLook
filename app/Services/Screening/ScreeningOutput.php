<?php

namespace App\Services\Screening;

use JsonException;

final class ScreeningOutput
{
    public function schema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['status', 'priority', 'extracted_facts', 'missing_information', 'explanation'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['classified', 'needs_review']],
                'priority' => ['type' => ['integer', 'null'], 'enum' => [1, 2, 3, 4, 5, null]],
                'extracted_facts' => [
                    'type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['field', 'state', 'value', 'evidence'],
                        'properties' => [
                            'field' => ['type' => 'string', 'enum' => PatientFields::NAMES],
                            'state' => ['type' => 'string', 'enum' => ['reported', 'absent', 'unknown', 'not_applicable']],
                            'value' => ['type' => ['string', 'null']],
                            'evidence' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'missing_information' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => PatientFields::NAMES]],
                'explanation' => ['type' => 'string'],
            ],
        ];
    }

    public function parse(string $raw, array $patient): array
    {
        try {
            $response = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($response) || ($response['status'] ?? null) !== 'completed'
                || ! is_string($response['model'] ?? null) || $response['model'] === '') {
                throw new InvalidModelOutput('incomplete_response');
            }
            if (! is_array($response['output'] ?? null) || ! array_is_list($response['output'])) {
                throw new InvalidModelOutput;
            }
            $texts = [];
            foreach ($response['output'] ?? [] as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                    continue;
                }
                if (! is_array($item['content'] ?? null) || ! array_is_list($item['content'])) {
                    throw new InvalidModelOutput;
                }
                foreach ($item['content'] ?? [] as $content) {
                    if (! is_array($content)) {
                        throw new InvalidModelOutput;
                    }
                    if (($content['type'] ?? null) === 'refusal') {
                        throw new InvalidModelOutput('model_refusal');
                    }
                    if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                        $texts[] = $content['text'];
                    }
                }
            }
            if (count($texts) !== 1) {
                throw new InvalidModelOutput;
            }
            $output = json_decode($texts[0], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidModelOutput;
        }
        $this->validate($output, $patient);

        return $output;
    }

    public function validate(mixed $output, array $patient): void
    {
        if (! is_array($output) || ! $this->keysMatch($output, ['status', 'priority', 'extracted_facts', 'missing_information', 'explanation'])) {
            throw new InvalidModelOutput;
        }
        $classified = $output['status'] === 'classified';
        $review = $output['status'] === 'needs_review';
        if ((! $classified && ! $review)
            || ($classified && (! is_int($output['priority']) || $output['priority'] < 1 || $output['priority'] > 5))
            || ($review && $output['priority'] !== null)
            || ! is_string($output['explanation']) || trim($output['explanation']) === '' || mb_strlen($output['explanation']) > 3000
            || ! is_array($output['extracted_facts']) || ! array_is_list($output['extracted_facts'])
            || count($output['extracted_facts']) > count(PatientFields::NAMES)
            || ! is_array($output['missing_information']) || ! array_is_list($output['missing_information'])
            || count($output['missing_information']) > count(PatientFields::NAMES)) {
            throw new InvalidModelOutput;
        }
        $seen = [];
        foreach ($output['extracted_facts'] as $fact) {
            if (! is_array($fact) || ! $this->keysMatch($fact, ['field', 'state', 'value', 'evidence'])
                || ! in_array($fact['field'], PatientFields::NAMES, true)
                || in_array($fact['field'], $seen, true)
                || ! in_array($fact['state'], ['reported', 'absent', 'unknown', 'not_applicable'], true)) {
                throw new InvalidModelOutput;
            }
            $seen[] = $fact['field'];
            if ($fact['state'] === 'unknown') {
                if ($fact['value'] !== null || $fact['evidence'] !== null) {
                    throw new InvalidModelOutput;
                }

                continue;
            }
            $source = $patient[$fact['field']] ?? null;
            if ($source === null || ! is_string($fact['value']) || trim($fact['value']) === ''
                || $fact['value'] !== $fact['evidence'] || ! str_contains((string) $source, $fact['evidence'])) {
                throw new InvalidModelOutput('ungrounded_fact');
            }
        }
        foreach ($output['missing_information'] as $field) {
            if (! in_array($field, PatientFields::NAMES, true)) {
                throw new InvalidModelOutput;
            }
        }
    }

    private function keysMatch(array $input, array $expected): bool
    {
        return count($input) === count($expected) && array_diff($expected, array_keys($input)) === [];
    }
}
