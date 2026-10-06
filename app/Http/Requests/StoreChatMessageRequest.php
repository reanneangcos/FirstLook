<?php

namespace App\Http\Requests;

use App\Models\PatientVisit;
use App\Services\Screening\PatientChatContent;
use App\Services\Screening\PatientFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->session()->has('patient_visit_id');
    }

    public function rules(): array
    {
        return ['field' => ['required', Rule::in(PatientFields::NAMES)],
            'question_revision' => ['required', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:3000'], 'skip' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        $language = PatientVisit::find($this->session()->get('patient_visit_id'))?->language ?? 'English';

        return ['message.max' => PatientChatContent::text($language, 'errors', 'tooLong'),
            'question_revision.required' => PatientChatContent::text($language, 'errors', 'stale')];
    }
}
