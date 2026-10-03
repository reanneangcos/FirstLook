<?php

namespace App\Http\Requests;

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
            'message' => ['nullable', 'string', 'max:3000'], 'skip' => ['required', 'boolean']];
    }
}
