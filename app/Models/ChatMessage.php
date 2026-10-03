<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function patientVisit(): BelongsTo
    {
        return $this->belongsTo(PatientVisit::class);
    }
}
