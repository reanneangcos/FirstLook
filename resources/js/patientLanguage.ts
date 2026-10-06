import english from '../chat/en.json';
import bisaya from '../chat/ceb.json';
import tagalog from '../chat/fil.json';

export type PatientLanguage = 'English' | 'Bisaya' | 'Tagalog';
export type PatientChatCopy = typeof english;

const catalogs: Record<PatientLanguage, PatientChatCopy> = {
    English: english,
    Bisaya: bisaya,
    Tagalog: tagalog,
};

export function patientChatCopy(language: string): PatientChatCopy {
    return catalogs[language as PatientLanguage] ?? english;
}

export function interpolate(text: string, values: Record<string, string | number>): string {
    return text.replace(/:([a-zA-Z]+)/g, (placeholder, key: string) =>
        values[key] === undefined ? placeholder : String(values[key]),
    );
}
