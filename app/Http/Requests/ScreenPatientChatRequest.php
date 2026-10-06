<?php

namespace App\Http\Requests;

use App\Models\PatientVisit;
use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScreenPatientChatRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = ['scope_confirmed' => ['accepted'],
            'patient' => ['sometimes', 'array:'.implode(',', PatientFields::NAMES)]];
        if ($this->has('patient')) {
            foreach (PatientFields::NAMES as $field) {
                $rules['patient.'.$field] = $field === 'age'
                    ? ['present', 'nullable', 'integer', 'min:18']
                    : ['present', 'nullable', 'string', 'max:3000'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        $language = PatientVisit::find($this->session()->get('patient_visit_id'))?->language ?? 'English';

        return ['scope_confirmed.accepted' => PatientChatContent::text($language, 'errors', 'confirmation'),
            'patient.age.min' => PatientChatContent::text($language, 'errors', 'adult'),
            'patient.age.integer' => PatientChatContent::text($language, 'errors', 'age'),
            'patient.*.max' => PatientChatContent::text($language, 'errors', 'tooLong')];
    }
}
