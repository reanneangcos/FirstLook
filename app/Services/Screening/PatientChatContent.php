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
}
