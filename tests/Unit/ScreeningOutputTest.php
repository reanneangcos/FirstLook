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

    public static function unreportedValues(): array
    {
        return [[null], [''], ['  '], [' Not reported. '], ['NOT STATED'],
            ['Unknown'], ['Hindi naiulat'], ['Wala gitaho']];
    }

    #[DataProvider('unreportedValues')]
    public function test_unreported_inputs_cannot_be_accepted_as_known_facts(?string $value): void
    {
        $patient = PatientFields::only(MockScreening::input()['patient']);
        $patient['allergies'] = $value;
        foreach (['absent', 'reported', 'not_applicable'] as $state) {
            $output = MockScreening::output(4);
            $output['extracted_facts'][1] = ['field' => 'allergies', 'state' => $state,
                'value' => $value, 'evidence' => $value];
            try {
                (new ScreeningOutput)->validate($output, $patient);
                $this->fail('An unreported field was accepted as known.');
            } catch (InvalidModelOutput $error) {
                $this->assertSame('unknown_reported_as_known', $error->failureCode);
                $this->assertSame('allergies', $error->field);
            }
        }
        $output = MockScreening::output(4);
        $this->assertSame($output, (new ScreeningOutput)->parse(json_encode(MockScreening::envelope($output)), $patient));
    }

    public function test_none_reported_is_accepted_as_an_explicit_absence(): void
    {
        foreach (['None reported', 'Walay gitaho', 'Walay gi-report', 'Walang naiulat', 'Walang iniulat',
            'Known allergies: None reported.', 'Mga medical device o catheter: Walay gitaho.'] as $value) {
            $patient = PatientFields::only(MockScreening::input()['patient']);
            $patient['allergies'] = $value;
            $output = MockScreening::output(4);
            $output['extracted_facts'][1] = ['field' => 'allergies', 'state' => 'absent',
                'value' => $value, 'evidence' => $value];

            $this->assertSame($output, (new ScreeningOutput)->parse(json_encode(MockScreening::envelope($output)), $patient));
        }
    }

    public function test_none_reported_cannot_be_reclassified_as_unknown(): void
    {
        $patient = PatientFields::only(MockScreening::input()['patient']);
        $patient['allergies'] = 'None reported';
        $output = MockScreening::output(4);
        $output['extracted_facts'][1] = ['field' => 'allergies', 'state' => 'unknown',
            'value' => null, 'evidence' => null];

        $this->expectException(InvalidModelOutput::class);
        (new ScreeningOutput)->validate($output, $patient);
    }

    public function test_explicit_negatives_inapplicability_and_longer_statements_keep_their_meaning(): void
    {
        foreach (['No allergies' => 'absent', 'None' => 'absent', 'Not applicable' => 'not_applicable',
            'None reported previously; now reports itching' => 'reported'] as $value => $state) {
            $patient = PatientFields::only(MockScreening::input()['patient']);
            $patient['allergies'] = $value;
            $output = MockScreening::output(4);
            $output['extracted_facts'][1] = ['field' => 'allergies', 'state' => $state, 'value' => $value, 'evidence' => $value];
            $this->assertSame($output, (new ScreeningOutput)->parse(json_encode(MockScreening::envelope($output)), $patient));
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
