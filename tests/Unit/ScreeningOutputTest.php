<?php

namespace Tests\Unit;

use App\Services\Screening\InvalidModelOutput;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockScreening;

class ScreeningOutputTest extends TestCase
{
    public static function invalidOutputs(): array
    {
        $valid = MockScreening::output();

        return [
            'review with priority' => [array_replace($valid, ['priority' => 1])],
            'classified with no priority' => [array_replace($valid, ['status' => 'classified'])],
            'out of range' => [array_replace($valid, ['status' => 'classified', 'priority' => 6])],
            'numeric string' => [array_replace($valid, ['status' => 'classified', 'priority' => '3'])],
            'unexpected field' => [$valid + ['diagnosis' => 'invented']],
            'missing field' => [array_diff_key($valid, ['explanation' => true])],
            'blank explanation' => [array_replace($valid, ['explanation' => ''])],
            'non-list facts' => [array_replace($valid, ['extracted_facts' => ['field' => 'allergies']])],
            'invented fact' => [array_replace($valid, ['extracted_facts' => [['field' => 'allergies', 'state' => 'absent', 'value' => 'none', 'evidence' => 'none']]])],
            'invented evidence' => [array_replace($valid, ['extracted_facts' => [['field' => 'main_complaint', 'state' => 'reported', 'value' => 'something else', 'evidence' => 'something else']]])],
            'unknown with value' => [array_replace($valid, ['extracted_facts' => [['field' => 'allergies', 'state' => 'unknown', 'value' => 'none', 'evidence' => null]]])],
            'invalid missing field' => [array_replace($valid, ['missing_information' => ['blood_pressure']])],
        ];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_outputs_are_rejected_instead_of_repaired(array $output): void
    {
        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->validate($output, PatientFields::only(MockScreening::input()['patient']));
    }

    public function test_all_five_priority_values_are_supported_without_relabeling(): void
    {
        $contract = new ScreeningOutput;
        foreach (range(1, 5) as $priority) {
            $output = MockScreening::output($priority);
            $parsed = $contract->parse(json_encode(MockScreening::envelope($output)), PatientFields::only(MockScreening::input()['patient']));
            $this->assertSame($priority, $parsed['priority']);
        }
    }

    public function test_refusal_is_not_a_needs_review_prediction(): void
    {
        $response = MockScreening::envelope();
        $response['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'mock refusal']];
        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->parse(json_encode($response), []);
    }

    public function test_incomplete_response_is_rejected(): void
    {
        $response = MockScreening::envelope();
        $response['status'] = 'incomplete';
        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->parse(json_encode($response), []);
    }

    public function test_malformed_envelope_content_is_rejected_without_a_runtime_error(): void
    {
        $response = MockScreening::envelope();
        $response['output'][0]['content'] = 'invalid';
        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->parse(json_encode($response), []);
    }
}
