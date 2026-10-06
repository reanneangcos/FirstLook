<?php

namespace App\Services\TriageRules;

final class PendingRuleLayer implements RuleLayer
{
    public function version(): ?string
    {
        return null;
    }

    public function evaluate(array $understoodFacts, array $clinicalAssessment = []): array
    {
        return ['status' => 'not_implemented', 'priority' => null, 'rule_version' => null, 'trace' => [],
            'matched_rule_ids' => [], 'explanation' => 'No rule evaluation was performed.', 'flags' => [], 'stopped_at' => null];
    }
}
