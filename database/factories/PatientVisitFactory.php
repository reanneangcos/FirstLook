<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PatientVisitFactory extends Factory
{
    public function definition(): array
    {
        return ['stub_number' => 'FIXTURE-'.fake()->unique()->numerify('######'),
            'language' => 'English', 'status' => 'collecting', 'question_index' => 0,
            'answers' => [], 'scope_confirmed_at' => now()];
    }
}
