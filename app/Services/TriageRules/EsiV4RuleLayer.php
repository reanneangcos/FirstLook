<?php

namespace App\Services\TriageRules;

final class EsiV4RuleLayer implements RuleLayer
{
    public function __construct(private readonly EsiV4RuleCatalog $catalog) {}

    public function version(): string
    {
        return $this->catalog->version();
    }

    /**
     * Follow the ESI v4 order using explicit clinical assessments only. Narrative
     * interpretation stays with the existing extraction step; no text-to-clinical
     * inference or resource prediction occurs here. Missing assessments stop the
     * current pre-screening workflow at A. No rule approval is assumed.
     *
     * @param  list<array{field: string, state: string, value: ?string, evidence: ?string}>  $understoodFacts
     * @param  array<string, array<string, mixed>>  $clinicalAssessment
     * @return array<string, mixed>
     */
    public function evaluate(array $understoodFacts, array $clinicalAssessment = []): array
    {
        $rules = $this->catalog->rules();
        $trace = [];
        $ageFacts = array_values(array_filter($understoodFacts, fn (array $fact): bool => ($fact['field'] ?? null) === 'age'));
        $age = count($ageFacts) === 1 ? $ageFacts[0] : null;
        if ($age === null || ($age['state'] ?? null) !== 'reported'
            || ! is_string($age['value'] ?? null) || ! ctype_digit($age['value'])
            || $age['value'] !== ($age['evidence'] ?? null) || (int) $age['value'] < 18) {
            return $this->result($trace, null, 'Adult eligibility is missing, conflicting, or outside the study scope.', 'scope')
                + ['scope_check' => ['required_fields' => ['age'], 'age_facts' => $ageFacts, 'status' => 'needs_review']];
        }

        $immediate = $this->observation($clinicalAssessment, 'immediate_lifesaving_intervention_required', 'boolean');
        if (! $rules['ESI4-A']['approved'] || $immediate['issue'] !== null) {
            $trace[] = $this->entry($rules['ESI4-A'], [$immediate], 'blocked', 'needs_review');

            return $this->result($trace, null, 'Decision A requires a reviewed criterion and an explicit assessment of immediate life-saving intervention need.', 'A');
        }
        $trace[] = $this->entry($rules['ESI4-A'], [$immediate], $immediate['value'] ? 'matched' : 'not_matched', $immediate['value'] ? 'esi_1' : 'continue');
        if ($immediate['value']) {
            return $this->result($trace, 1, 'The approved Decision A criterion establishes an immediate life-saving intervention requirement.', 'A');
        }

        $blocked = false;
        foreach (['ESI4-B-HIGH-RISK', 'ESI4-B-MENTAL-STATUS', 'ESI4-B-PAIN-DISTRESS'] as $id) {
            $rule = $rules[$id];
            $observation = $this->observation($clinicalAssessment, $rule['required_fields'][0], 'boolean');
            if (! $rule['approved'] || $observation['issue'] !== null) {
                $blocked = true;
                $trace[] = $this->entry($rule, [$observation], 'blocked', 'needs_review');

                continue;
            }
            $trace[] = $this->entry($rule, [$observation], $observation['value'] ? 'matched' : 'not_matched', $observation['value'] ? 'esi_2' : 'continue');
            if ($observation['value']) {
                return $this->result($trace, 2, 'An approved Decision B assessment supports ESI 2 after Decision A was explicitly negative.', 'B');
            }
        }
        if ($blocked) {
            return $this->result($trace, null, 'Decision B cannot be excluded using the available approved assessments. A pain score alone does not establish ESI 2.', 'B');
        }

        $resources = $this->observation($clinicalAssessment, 'expected_esi_resources', 'resources');
        if (! $rules['ESI4-C']['approved'] || $resources['issue'] !== null) {
            $trace[] = $this->entry($rules['ESI4-C'], [$resources], 'blocked', 'needs_review');

            return $this->result($trace, null, 'Decision C requires an explicit clinical estimate of expected ESI resources for this visit. Tests and devices are not substitutes.', 'C');
        }
        $priority = match ($resources['value']) {
            0 => 5, 1 => 4, default => null
        };
        $trace[] = $this->entry($rules['ESI4-C'], [$resources], 'matched', $priority === null ? 'continue' : 'esi_'.$priority);
        if ($priority !== null) {
            return $this->result($trace, $priority, 'The approved resource estimate supports ESI '.$priority.' after Decisions A and B were explicitly negative.', 'C');
        }

        $vitals = [
            $this->observation($clinicalAssessment, 'heart_rate', 'measurement', 'beats/min'),
            $this->observation($clinicalAssessment, 'respiratory_rate', 'measurement', 'breaths/min'),
            $this->observation($clinicalAssessment, 'oxygen_saturation', 'measurement', '%'),
        ];
        if (! $rules['ESI4-D']['approved']) {
            $trace[] = $this->entry($rules['ESI4-D'], $vitals, 'blocked', 'needs_review');

            return $this->result($trace, null, 'The Decision D criterion is pending documented clinical approval.', 'D');
        }
        $danger = array_values(array_filter($vitals, fn (array $vital): bool => $vital['issue'] === null && match ($vital['field']) {
            'heart_rate' => $vital['value'] > 100,
            'respiratory_rate' => $vital['value'] > 20,
            'oxygen_saturation' => $vital['value'] < 92,
        }));
        if ($danger !== []) {
            $entry = $this->entry($rules['ESI4-D'], $vitals, 'matched', 'clinical_judgment_required');
            $entry['danger_zone_fields'] = array_column($danger, 'field');
            $trace[] = $entry;

            return $this->result($trace, null, 'Consider ESI 2 — clinical judgment required.', 'D', ['consider_esi_2']);
        }
        if (array_any($vitals, fn (array $vital): bool => $vital['issue'] !== null)) {
            $trace[] = $this->entry($rules['ESI4-D'], $vitals, 'blocked', 'needs_review');

            return $this->result($trace, null, 'Decision D requires all three valid measured adult vital signs before ESI 3 can be supported.', 'D');
        }
        $trace[] = $this->entry($rules['ESI4-D'], $vitals, 'matched', 'esi_3');

        return $this->result($trace, 3, 'At least two expected resources and measured adult vital signs outside the specified danger zones support ESI 3.', 'D');
    }

    /**
     * @param  array<string, array<string, mixed>>  $assessment
     * @return array{field: string, state: string, value: mixed, evidence: mixed, observation: mixed, issue: ?string}
     */
    private function observation(array $assessment, string $field, string $type, ?string $unit = null): array
    {
        $raw = $assessment[$field] ?? null;
        $observation = ['field' => $field, 'state' => 'missing', 'value' => null, 'evidence' => null, 'observation' => $raw, 'issue' => 'missing'];
        if (! array_key_exists($field, $assessment)) {
            return $observation;
        }
        if (! is_array($raw) || ! is_string($raw['state'] ?? null)) {
            return array_replace($observation, ['state' => 'invalid', 'issue' => 'invalid_observation']);
        }
        $observation = array_replace($observation, ['state' => $raw['state'], 'value' => $raw['value'] ?? null, 'evidence' => $raw['evidence'] ?? null]);
        if ($raw['state'] !== 'reported') {
            $observation['issue'] = in_array($raw['state'], ['unknown', 'not_applicable', 'contradictory'], true) ? $raw['state'] : 'invalid_state';

            return $observation;
        }
        $validValue = match ($type) {
            'boolean' => is_bool($observation['value']),
            'resources' => is_int($observation['value']) && $observation['value'] >= 0,
            'measurement' => (is_int($observation['value']) || is_float($observation['value']))
                && is_finite((float) $observation['value']) && $observation['value'] > 0
                && ($field !== 'oxygen_saturation' || $observation['value'] <= 100),
        };
        $expectedSource = $type === 'measurement' ? 'measured' : 'clinical_assessment';
        $validProvenance = ($raw['source'] ?? null) === $expectedSource
            && ($unit === null || ($raw['unit'] ?? null) === $unit);
        foreach (['evidence', 'recorded_by', 'recorded_at', 'record_id'] as $key) {
            $validProvenance = $validProvenance && is_string($raw[$key] ?? null) && trim($raw[$key]) !== '';
        }
        if ($validProvenance) {
            $timestamp = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $raw['recorded_at']);
            $validProvenance = $timestamp !== false && $timestamp->format(\DateTimeInterface::RFC3339) === $raw['recorded_at'];
        }
        $observation['issue'] = ! $validValue ? 'invalid_value' : (! $validProvenance ? 'unverified_source_or_unit' : null);

        return $observation;
    }

    /** @param array<string, mixed> $rule @param list<array<string, mixed>> $observations @return array<string, mixed> */
    private function entry(array $rule, array $observations, string $outcome, string $action): array
    {
        return $rule + ['outcome' => $outcome, 'action' => $action, 'observations' => $observations,
            'matched_evidence' => $outcome === 'blocked' ? [] : array_column(array_filter($observations,
                fn (array $observation): bool => $observation['issue'] === null), 'evidence'),
            'blocking_reasons' => array_values(array_filter([
                ...($rule['approved'] ? [] : ['rule_not_approved']),
                ...array_map(fn (array $observation): ?string => $observation['issue'] === null ? null : $observation['field'].':'.$observation['issue'], $observations),
            ]))];
    }

    /** @param list<array<string, mixed>> $trace @param list<string> $flags @return array<string, mixed> */
    private function result(array $trace, ?int $priority, string $explanation, string $stoppedAt, array $flags = []): array
    {
        return ['status' => $priority === null ? 'needs_review' : 'classified', 'priority' => $priority,
            'rule_version' => $this->version(), 'trace' => $trace,
            'matched_rule_ids' => array_column(array_filter($trace, fn (array $entry): bool => $entry['outcome'] === 'matched'), 'rule_id'),
            'explanation' => $explanation, 'flags' => $flags, 'stopped_at' => $stoppedAt];
    }
}
