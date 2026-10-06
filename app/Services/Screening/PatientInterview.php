<?php

namespace App\Services\Screening;

final class PatientInterview
{
    /** @return array<string, string> */
    public static function questions(string $language = 'English'): array
    {
        $questions = [];
        foreach (PatientFields::NAMES as $field) {
            $questions[$field] = PatientChatContent::text($language, 'questions', $field);
        }

        return $questions;
    }

    /** @return array{field: string, text: string}|null */
    public static function current(int $index, string $language = 'English'): ?array
    {
        $field = PatientFields::NAMES[$index] ?? null;

        return $field === null ? null : ['field' => $field, 'text' => PatientChatContent::text($language, 'questions', $field)];
    }
}
