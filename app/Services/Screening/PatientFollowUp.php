<?php

namespace App\Services\Screening;

final class PatientFollowUp
{
    public static function isUnknown(string $answer): bool
    {
        return in_array(self::normalize($answer), [
            'unknown', 'not known', 'not reported', 'not supplied', 'not stated', 'not given',
            'not sure', 'unsure', 'i don\'t know', 'i dont know', 'i do not know', 'unable to report',
            'ambot', 'wala kabalo', 'wala ko kabalo', 'wala mahibaloi', 'wala gihatag', 'wala gisulti',
            'hindi alam', 'di alam', 'hindi ko alam', 'hindi sinabi', 'hindi ibinigay',
        ], true);
    }

    public static function question(string $field, string $answer, string $language): ?string
    {
        $value = self::normalize($answer);
        if (self::isUnknown($answer) || in_array($value, [
            'none', 'none reported', 'no', 'nope', 'not applicable', 'n/a', 'na',
            'no symptoms', 'no current symptoms', 'no other symptoms', 'wala', 'wala po',
            'walay lain', 'walang iba', 'walang sintomas', 'walay sintomas', 'hindi', 'dili',
        ], true)) {
            return null;
        }

        $affirmative = in_array($value, [
            'yes', 'yes i do', 'yes there are', 'yes po', 'oo', 'oo po', 'opo',
            'naa', 'naa koy', 'aduna', 'meron', 'mayroon', 'meron po', 'mayroon po',
        ], true);
        $vague = in_array($value, [
            'pain', 'hurts', 'bad', 'unwell', 'not well', 'sick', 'sakit', 'masakit',
            'masama', 'di maayo', 'dili maayo', 'grabe', 'recently', 'a while', 'dugay', 'matagal',
        ], true);
        $needsDetail = match ($field) {
            'other_symptoms', 'known_conditions', 'allergies', 'maintenance_medications',
            'tests_completed', 'tests_requested', 'medical_devices' => $affirmative,
            'main_complaint', 'reported_severity', 'onset' => $affirmative || $vague,
            'duration' => $affirmative || $vague || preg_match('/^\d+(?:[.,]\d+)?$/u', $value) === 1,
            'worsening' => $affirmative,
            'symptom_description' => count(preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY)) <= 4,
            default => false,
        };

        return $needsDetail ? PatientChatContent::text($language, 'followUps', $field) : null;
    }

    private static function normalize(string $answer): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($answer)), " \t\n\r\0\x0B.!?…");
    }
}
