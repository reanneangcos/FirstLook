<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreeningSession extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original_input' => 'array', 'patient_input' => 'array',
            'request_settings' => 'array', 'parsed_output' => 'array',
            'token_usage' => 'array', 'completed_at' => 'datetime',
            'is_fixture' => 'boolean', 'method_a_priority' => 'integer',
            'method_b_priority' => 'integer', 'method_b_input' => 'array', 'method_b_result' => 'array',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ScreeningAttempt::class);
    }

    public function patientVisit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class);
    }
}
