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
