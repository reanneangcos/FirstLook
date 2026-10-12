<?php

namespace App\Services\Screening;

final class PatientChatContent
{
    public const LOCALES = ['English' => 'en', 'Bisaya' => 'ceb', 'Tagalog' => 'fil'];

    /** @var array<string, array<string, mixed>> */
    private static array $catalogs = [];

    /** @return array<string, mixed> */
    public static function catalog(string $language): array
    {
        $locale = self::LOCALES[$language] ?? 'en';

        return self::$catalogs[$locale] ??= json_decode(
            file_get_contents(resource_path('chat/'.$locale.'.json')), true, 512, JSON_THROW_ON_ERROR,
        );
    }

    /** @param array<string, string|int> $replacements */
    public static function text(string $language, string $section, string $key, array $replacements = []): string
    {
        $text = self::catalog($language)[$section][$key];
        foreach ($replacements as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    public static function voiceGuidance(string $language, bool $forScreening = false, bool $allowLanguageChange = false): string
    {
        $language = array_key_exists($language, self::LOCALES) ? $language : 'English';
        $catalog = self::catalog($language);
        $template = file_get_contents(resource_path('prompts/'.config('triage.patient_voice_version').'.txt'));
        if ($allowLanguageChange) {
            $template = str_replace(
                'Use the selected language even when',
                'If the latest reply explicitly requests a supported language change, use language_change.language immediately and apply its voice below. Otherwise use the selected language even when',
                $template,
            );
        }
        $examples = $forScreening
            ? ['reviewMessage' => $catalog['conversation']['ready'], 'retryMessage' => $catalog['conversation']['retry']]
            : ['shortQuestions' => $catalog['shortQuestions'], 'detailQuestions' => $catalog['detailQuestions']];

        return strtr($template, [
            '{{response_language}}' => $language,
            '{{message_field}}' => $forScreening ? 'explanation' : 'next_question.text',
            '{{wording_examples}}' => json_encode($examples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
