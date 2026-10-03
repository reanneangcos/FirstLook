<?php

namespace App\Http\Requests;

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
        return ['language' => ['required', Rule::in(config('triage.languages'))],
            'synthetic_confirmed' => ['accepted'], 'adult_confirmed' => ['accepted']];
    }
}
