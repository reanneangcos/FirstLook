<?php

namespace App\Services\Screening;

final class PatientFields
{
    // This is the only patient-data allowlist used to construct provider requests.
    public const NAMES = [
        'age', 'reported_sex', 'main_complaint', 'symptom_description',
        'other_symptoms', 'known_conditions', 'allergies', 'maintenance_medications',
        'onset', 'duration', 'reported_severity', 'worsening',
        'tests_completed', 'tests_requested', 'medical_devices',
    ];

    /** Patient columns B:P in each TRAIN tab of Thesis Master Data (5).xlsx. */
    public const DATASET_COLUMNS = [
        'age' => 'Age',
        'reported_sex' => 'Sex reported',
        'main_complaint' => 'Main complaint',
        'symptom_description' => 'Symptom description',
        'other_symptoms' => 'Other reported symptoms',
        'known_conditions' => 'Known conditions',
        'allergies' => 'Known allergies',
        'maintenance_medications' => 'Maintenance medications',
        'onset' => 'Onset',
        'duration' => 'Duration',
        'reported_severity' => 'Severity reported',
        'worsening' => 'Getting worse?',
        'tests_completed' => 'Tests already completed',
        'tests_requested' => 'Tests requested by a clinician',
        'medical_devices' => 'Medical devices or catheters',
    ];

    public static function only(array $input): array
    {
        $patient = [];
        foreach (self::NAMES as $field) {
            $value = $input[$field] ?? null;
            $patient[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }
        if ($patient['age'] !== null) {
            $patient['age'] = (int) $patient['age'];
        }

        return $patient;
    }
}
