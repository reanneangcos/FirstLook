<?php

namespace App\Services\TriageRules;

interface RuleLayer
{
    public function version(): ?string;

    /**
     * Consume persisted understanding-only facts. The rule layer alone decides Method B.
     * Never accept Method A's priority, status, rationale or full response as rule input.
     * Implementations must not call the LLM or use its priority as a fallback.
     *
     * Clinical assessments are optional, explicit server-side observations. The
     * current patient workflow supplies none. Never construct them from symptoms,
     * a model priority, prior tests/devices, or model-inferred resource counts.
     *
     * @param  list<array{field: string, state: string, value: ?string, evidence: ?string}>  $understoodFacts
     * @param  array<string, array{state: string, value: bool|int|float|null, evidence?: string, source?: string, recorded_by?: string, recorded_at?: string, record_id?: string, unit?: string}>  $clinicalAssessment
     * @return array{status: string, priority: ?int, rule_version: ?string, trace: array, matched_rule_ids: list<string>, explanation: string, flags: list<string>, stopped_at: ?string}
     */
    public function evaluate(array $understoodFacts, array $clinicalAssessment = []): array;
}
