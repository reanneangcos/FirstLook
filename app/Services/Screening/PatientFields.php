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

    /** Recognize whole-field reporting placeholders without interpreting clinical content. */
    public static function isUnreported(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }

        return trim($value) === '' || self::matchesReportingPlaceholder($value, [
            'not reported', 'not stated', 'not known', 'unknown', 'hindi naiulat', 'wala gitaho',
        ]);
    }

    public static function isNoneReported(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return self::matchesReportingPlaceholder($value, [
            'none reported', 'walay gitaho', 'walay gi report', 'walay gi-report',
            'walang naiulat', 'walang iniulat', 'walang naiuulat',
        ]);
    }

    /** Match a whole value, optionally preceded by one label; never a clause inside a longer statement. */
    private static function matchesReportingPlaceholder(string $value, array $phrases): bool
    {
        $alternatives = implode('|', array_map(fn (string $phrase): string => preg_quote($phrase, '/'), $phrases));

        return preg_match('/\A(?:[^:\r\n.!?;]+:\h*)?(?:'.$alternatives.')\.*\z/u', mb_strtolower(trim($value))) === 1;
    }
}
