<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreeningAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parsed_output' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
