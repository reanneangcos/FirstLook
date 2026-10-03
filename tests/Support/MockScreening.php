<?php

namespace Tests\Support;

final class MockScreening
{
    // Temporary fictional integration fixture. Not an expert-labeled dataset case.
    public static function input(): array
    {
        return [
            'patient' => ['age' => 34, 'main_complaint' => 'Itchy arm', 'symptom_description' => 'My arm feels itchy since yesterday.'],
            'language' => 'English', 'dataset_case_id' => 'FIXTURE-ONLY', 'variant_id' => 'FIXTURE-EN',
            'synthetic_confirmed' => true, 'adult_confirmed' => true, 'scope_confirmed' => true,
        ];
    }

    public static function output(?int $priority = null): array
    {
        return [
            'status' => $priority === null ? 'needs_review' : 'classified', 'priority' => $priority,
            'extracted_facts' => [
                ['field' => 'main_complaint', 'state' => 'reported', 'value' => 'Itchy arm', 'evidence' => 'Itchy arm'],
                ['field' => 'allergies', 'state' => 'unknown', 'value' => null, 'evidence' => null],
            ],
            'missing_information' => ['allergies'],
            'explanation' => 'MOCK RESPONSE: arm itching was reported. Approved classification criteria are pending.',
        ];
    }

    public static function envelope(?array $output = null): array
    {
        return ['id' => 'resp_mock_fixture', 'status' => 'completed', 'model' => 'gpt-6-luna-mock-only',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($output ?? self::output(), JSON_THROW_ON_ERROR)],
            ]]],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 30, 'total_tokens' => 50],
        ];
    }
}
