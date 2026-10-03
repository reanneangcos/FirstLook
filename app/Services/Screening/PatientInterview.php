<?php

namespace App\Services\Screening;

final class PatientInterview
{
    /** @return array<string, string> */
    public static function questions(): array
    {
        return [
            'age' => 'How old is the fictional patient? Enter their age in years, or choose Unknown if the exact age is unavailable. This demo is for adults aged 18 and above.',
            'reported_sex' => 'What sex does the patient report? You can choose Unknown if it is not given.',
            'main_complaint' => 'What is the main reason for this visit? Describe the main complaint in a few words.',
            'symptom_description' => 'Tell me more about how the patient feels, in their own words. Please leave out names, contact details, measured vital signs, examination findings and test results.',
            'other_symptoms' => 'Are there any other reported symptoms? Only say none if the case explicitly reports none; otherwise choose Unknown.',
            'known_conditions' => 'What existing medical conditions does the patient report?',
            'allergies' => 'What allergies does the patient report? If the case does not say, choose Unknown.',
            'maintenance_medications' => 'Does the patient report any maintenance medications? Include only what the case states.',
            'onset' => 'When did the symptoms start?',
            'duration' => 'How long have the symptoms lasted?',
            'reported_severity' => 'How does the patient describe the severity in their own words?',
            'worsening' => 'Does the patient say the symptoms are getting worse, improving or staying the same?',
            'tests_completed' => 'Were any tests already completed? Give test names only, without results or measured values.',
            'tests_requested' => 'Has a clinician requested any tests? Give their names or request context only, without results.',
            'medical_devices' => 'Does the patient report using any medical device or catheter?',
        ];
    }

    /** @return array{field: string, text: string}|null */
    public static function current(int $index): ?array
    {
        $field = array_keys(self::questions())[$index] ?? null;

        return $field === null ? null : ['field' => $field, 'text' => self::questions()[$field]];
    }
}
