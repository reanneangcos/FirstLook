<?php

namespace App\Http\Requests;

use App\Services\Screening\PatientChatContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartPatientChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['language' => ['required', 'string', Rule::in(config('triage.languages'))],
            'synthetic_confirmed' => ['accepted'], 'adult_confirmed' => ['accepted']];
    }

    public function messages(): array
    {
        $language = $this->input('language', 'English');
        $language = is_string($language) ? $language : 'English';

        return ['synthetic_confirmed.accepted' => PatientChatContent::text($language, 'errors', 'confirmation'),
            'adult_confirmed.accepted' => PatientChatContent::text($language, 'errors', 'confirmation'),
            'language.in' => PatientChatContent::text($language, 'errors', 'language'),
            'language.required' => PatientChatContent::text($language, 'errors', 'language'),
            'language.string' => PatientChatContent::text($language, 'errors', 'language')];
    }
}
