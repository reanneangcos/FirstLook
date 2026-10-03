<?php

namespace App\Services\TriageRules;

use App\Models\ScreeningSession;

final class PendingRuleLayer implements RuleLayer
{
    public function evaluate(ScreeningSession $savedSession): array
    {
        return ['status' => 'not_implemented', 'priority' => null, 'rule_version' => null, 'trace' => []];
    }
}
