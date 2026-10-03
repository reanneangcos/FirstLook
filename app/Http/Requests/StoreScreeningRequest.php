<?php

namespace App\Http\Requests;

use App\Services\Screening\PatientFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScreeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'synthetic_confirmed' => ['accepted'],
            'adult_confirmed' => ['accepted'],
            'scope_confirmed' => ['accepted'],
            'language' => ['required', Rule::in(config('triage.languages'))],
            'dataset_case_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'variant_id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'patient' => ['required', 'array:'.implode(',', PatientFields::NAMES)],
        ];
        foreach (PatientFields::NAMES as $field) {
            $rules['patient.'.$field] = $field === 'age'
                ? ['nullable', 'integer', 'min:18']
                : ['nullable', 'string', 'max:3000'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'synthetic_confirmed.accepted' => 'Confirm that this is a fictional case with no personal identifiers.',
            'adult_confirmed.accepted' => 'Confirm that the fictional patient is an adult aged 18 or above.',
            'scope_confirmed.accepted' => 'Confirm that the input excludes measured vital signs, examination findings, test results and answer keys.',
            'patient.age.min' => 'This study includes adults aged 18 and above only.',
        ];
    }
}
