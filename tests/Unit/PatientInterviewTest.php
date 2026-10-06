<?php

namespace Tests\Unit;

use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientFields;
use App\Services\Screening\PatientFollowUp;
use App\Services\Screening\PatientInterview;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientInterviewTest extends TestCase
{
    public function test_all_languages_cover_the_train_patient_columns_and_have_complete_copy(): void
    {
        $headers = ['Age', 'Sex reported', 'Main complaint', 'Symptom description', 'Other reported symptoms',
            'Known conditions', 'Known allergies', 'Maintenance medications', 'Onset', 'Duration',
            'Severity reported', 'Getting worse?', 'Tests already completed', 'Tests requested by a clinician',
            'Medical devices or catheters'];
        $this->assertSame($headers, array_values(PatientFields::DATASET_COLUMNS));
        $this->assertSame(PatientFields::NAMES, array_keys(PatientFields::DATASET_COLUMNS));
        $english = PatientChatContent::catalog('English');
        foreach (['English', 'Bisaya', 'Tagalog'] as $language) {
            $catalog = PatientChatContent::catalog($language);
            $this->assertSame(PatientFields::NAMES, array_keys(PatientInterview::questions($language)));
            foreach (['ui', 'questions', 'followUps', 'messages', 'errors', 'conversation', 'shortQuestions', 'detailQuestions', 'fieldLabels'] as $section) {
                $this->assertSame(array_keys($english[$section]), array_keys($catalog[$section]));
                foreach ($catalog[$section] as $key => $text) {
                    $this->assertNotSame('', trim($text), "$language.$section.$key");
                    if ($language !== 'English' && $section === 'questions') {
                        $this->assertNotSame($english[$section][$key], $text);
                    }
                }
            }
            $this->assertNull(PatientInterview::current(15, $language));
        }
    }

    #[DataProvider('clarificationAnswers')]
    public function test_clarifications_target_only_the_answered_field(string $field, string $answer, bool $expected): void
    {
        foreach (['English', 'Bisaya', 'Tagalog'] as $language) {
            $question = PatientFollowUp::question($field, $answer, $language);
            $this->assertSame($expected, $question !== null);
            if ($expected) {
                $this->assertSame(PatientChatContent::text($language, 'followUps', $field), $question);
            }
        }
    }

    public static function clarificationAnswers(): array
    {
        return [
            ['allergies', 'Yes', true], ['allergies', 'Oo po!', true], ['allergies', 'Naa.', true],
            ['allergies', 'Penicillin', false], ['known_conditions', 'Asthma', false],
            ['maintenance_medications', 'yes i do', true], ['medical_devices', 'Meron', true],
            ['tests_completed', 'Oo', true], ['tests_requested', 'Blood test requested yesterday', false],
            ['duration', '3', true], ['duration', '3 days', false], ['reported_severity', '6/10', false],
            ['main_complaint', 'Pain', true], ['symptom_description', 'Sakit akong tiyan', true],
            ['symptom_description', 'My wrist hurts when I twist it.', false],
            ['worsening', 'Yes', true], ['worsening', 'It is getting worse since yesterday', false],
            ['allergies', 'None reported', false], ['symptom_description', 'No current symptoms', false],
            ['other_symptoms', 'Wala po', false], ['allergies', 'Hindi ko alam', false],
            ['medical_devices', 'Wala ko kabalo', false], ['reported_severity', 'Unable to report', false],
            ['expert_priority', 'Yes', false], ['age', '34', false],
        ];
    }
}
