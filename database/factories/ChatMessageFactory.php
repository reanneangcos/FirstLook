<?php

namespace Database\Factories;

use App\Models\PatientVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['patient_visit_id' => PatientVisit::factory(), 'role' => 'patient',
            'source' => 'patient', 'field' => 'main_complaint', 'content' => 'FICTIONAL FIXTURE: itchy arm.'];
    }
}
