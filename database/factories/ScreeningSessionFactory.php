<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\Screening\PatientFields;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScreeningSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'language' => 'English',
            'original_input' => ['age' => 34, 'main_complaint' => 'Temporary fictional fixture'],
            'patient_input' => PatientFields::only(['age' => 34, 'main_complaint' => 'Temporary fictional fixture']),
            'requested_model' => 'mock-only', 'prompt_version' => 'fixture-only',
            'prompt_text' => 'MOCK FIXTURE. No provider request.', 'request_settings' => [], 'is_fixture' => true,
        ];
    }
}
