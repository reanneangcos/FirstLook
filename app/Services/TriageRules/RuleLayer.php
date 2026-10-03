<?php

namespace App\Services\TriageRules;

use App\Models\ScreeningSession;

interface RuleLayer
{
    /** Consume an already persisted response; implementations must not call the LLM. */
    public function evaluate(ScreeningSession $savedSession): array;
}
