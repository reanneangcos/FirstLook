<?php

namespace App\Services\TriageRules;

use App\Services\Screening\InvalidModelOutput;
use App\Services\Screening\NormalizedPatientFacts;
use App\Services\Screening\PatientFields;

final class ResearchEsiRuleLayer implements RuleLayer
{
    public function __construct(private readonly ResearchEsiRuleCatalog $catalog) {}

    public function version(): string
    {
        return $this->catalog->version();
    }

    /** @param list<array<string, mixed>> $understoodFacts @param array<string, mixed> $clinicalAssessment @param list<array<string, mixed>> $normalizedFacts */
    public function evaluate(array $understoodFacts, array $clinicalAssessment = [], array $normalizedFacts = []): array
    {
        if ($clinicalAssessment !== []) {
            return $this->result([], [], null, 'scope', 'Clinical assessments are outside this patient-report prototype.', []);
        }
        $fields = [];
        foreach ($understoodFacts as $fact) {
            if (! is_array($fact) || ! in_array($fact['field'] ?? null, PatientFields::NAMES, true)
                || isset($fields[$fact['field']]) || ! in_array($fact['state'] ?? null, ['reported', 'absent', 'unknown', 'not_applicable'], true)
                || ($fact['state'] === 'unknown' && (($fact['value'] ?? null) !== null || ($fact['evidence'] ?? null) !== null))
                || ($fact['state'] !== 'unknown' && (! is_string($fact['value'] ?? null) || trim($fact['value']) === '' || $fact['value'] !== ($fact['evidence'] ?? null)))) {
                return $this->result([], [], null, 'input', 'Invalid or duplicate extracted fields.', []);
            }
            $fields[$fact['field']] = $fact;
        }
        try {
            (new NormalizedPatientFacts)->validate($normalizedFacts, $understoodFacts);
        } catch (InvalidModelOutput $error) {
            return $this->result([], [], null, 'input', 'Evidence validation failed: '.$error->failureCode.'.', []);
        }
        $concepts = array_column($normalizedFacts, null, 'concept');
        $age = $fields['age'] ?? [];
        if (($age['state'] ?? null) !== 'reported' || ! ctype_digit($age['value'] ?? '') || (int) $age['value'] < 18 || (int) $age['value'] > 120) {
            return $this->result([], [], null, 'scope', 'An explicit adult age from 18 to 120 is required.', $concepts);
        }
        if ($this->contradictory($concepts)) {
            return $this->result([], [], null, 'input', 'The reported facts contain an unresolved contradiction.', $concepts);
        }
        $trace = [];
        foreach (['A', 'B', 'C'] as $point) {
            $matches = [];
            foreach ($this->catalog->rules() as $rule) {
                if ($rule['decision_point'] !== $point) {
                    continue;
                }
                $entry = $this->evaluateRule($rule, $concepts, (int) $age['value']);
                $trace[] = $entry;
                if ($entry['outcome'] === 'matched') {
                    $matches[] = $entry;
                }
            }
            if ($point !== 'C' && $matches !== []) {
                return $this->result($trace, $matches, $point === 'A' ? 1 : 2, $point, $matches[0]['explanation'], $concepts);
            }
            if ($point === 'A' || $point === 'B') {
                $signals = $point === 'A' ? ResearchEsiRuleCatalog::A_SIGNALS : ResearchEsiRuleCatalog::B_SIGNALS;
                $blocking = array_values(array_filter($signals, fn (string $name): bool => $this->reported($concepts, $name)
                    || (($concepts[$name]['state'] ?? null) === 'unknown' && ($concepts[$name]['evidence'] ?? []) !== [])));
                if ($point === 'A' && (($concepts['breathing']['state'] ?? null) === 'unknown') && ($concepts['breathing']['evidence'] ?? []) !== []) {
                    $blocking[] = 'breathing';
                }
                /** Both editions let severe orthopedic-injury pain wait for resource prediction (v4 pp. 12, 26; v5 p. 12). */
                if ($point === 'B' && $this->reported($concepts, 'limb_injury')) {
                    $blocking = array_values(array_diff($blocking, ['severe_pain']));
                }
                if ($blocking !== []) {
                    return $this->result($trace, [], null, $point, 'A reported or explicitly uncertain danger signal lacks a complete supported profile: '.implode(', ', $blocking).'.', $concepts);
                }
            }
            if ($point === 'C') {
                $unsupported = $this->unsupportedForResources($concepts, $matches);
                if ($unsupported !== []) {
                    return $this->result($trace, [], null, 'C', 'Resource prediction remains unsupported with these reports: '.implode(', ', $unsupported).'.', $concepts);
                }
                if ($matches === []) {
                    return $this->result($trace, [], null, 'C', 'No research resource profile matches the available facts. No default ESI 3 is assigned.', $concepts);
                }
                $resources = array_values(array_unique(array_merge(...array_column($matches, 'expected_resource_types'))));
                $priority = match (count($resources)) {
                    0 => 5, 1 => 4, default => 3
                };

                return $this->result($trace, $matches, $priority, 'C', 'Matched resource-profile hypotheses: '.implode(', ', array_column($matches, 'rule_id')).'.', $concepts);
            }
        }

        return $this->result($trace, [], null, 'no_matching_rule', 'No supported research profile.', $concepts);
    }

    private function evaluateRule(array $rule, array $concepts, int $age): array
    {
        $observations = [];
        $check = function (string $name, string $expected, string $group) use ($concepts, &$observations): bool {
            $fact = $concepts[$name] ?? [];
            $state = $fact['state'] ?? 'missing';
            $matched = $state === $expected;
            $observations[] = ['concept' => $name, 'required_state' => $expected, 'state' => $state,
                'group' => $group, 'matches' => $matched, 'evidence' => $fact['evidence'] ?? []];

            return $matched;
        };
        $matched = true;
        foreach ($rule['all'] as $name => $state) {
            $matched = $check($name, $state, 'all') && $matched;
        }
        foreach ($rule['any'] as $index => $alternatives) {
            $groupMatched = false;
            foreach ($alternatives as $name => $state) {
                $groupMatched = $check($name, $state, 'any_'.$index) || $groupMatched;
            }
            $matched = $groupMatched && $matched;
        }
        foreach ($rule['none'] as $name) {
            $excluded = $this->reported($concepts, $name);
            $observations[] = ['concept' => $name, 'required_state' => 'not_reported', 'state' => $concepts[$name]['state'] ?? 'missing',
                'group' => 'none', 'matches' => ! $excluded, 'evidence' => $concepts[$name]['evidence'] ?? []];
            $matched = ! $excluded && $matched;
        }
        if ($rule['min_age'] !== null) {
            $observations[] = ['concept' => 'age', 'required_state' => 'at_least_'.$rule['min_age'], 'state' => (string) $age,
                'group' => 'age', 'matches' => $age >= $rule['min_age'], 'evidence' => []];
            $matched = $age >= $rule['min_age'] && $matched;
        }

        return $rule + ['outcome' => $matched ? 'matched' : 'not_matched', 'observations' => $observations,
            'matched_evidence' => array_values(array_filter($observations, fn (array $item): bool => $item['matches'])),
            'approval_enforced' => false, 'execution_basis' => 'user_authorized_fictional_research_prototype'];
    }

    private function reported(array $concepts, string $name): bool
    {
        return ($concepts[$name]['state'] ?? null) === 'reported';
    }

    private function contradictory(array $concepts): bool
    {
        if (array_any($concepts, fn (array $fact): bool => $fact['state'] === 'contradictory')) {
            return true;
        }
        foreach (ResearchEsiRuleCatalog::SPECIFIC_GENERAL_CONFLICTS as [$specific, $general]) {
            if ($this->reported($concepts, $specific) && ($concepts[$general]['state'] ?? null) === 'absent') {
                return true;
            }
        }
        foreach (ResearchEsiRuleCatalog::MUTUALLY_EXCLUSIVE as [$left, $right]) {
            if ($this->reported($concepts, $left) && $this->reported($concepts, $right)) {
                return true;
            }
        }

        return false;
    }

    /** Unmatched known symptoms cannot disappear behind a low-resource administrative or minor-complaint profile. */
    private function unsupportedForResources(array $concepts, array $matches): array
    {
        $blocked = [];
        /** Like danger signals, an unknown state blocks only when it carries quoted evidence; an empty unknown is unmentioned. */
        foreach (['unmapped_symptom', 'head_injury'] as $name) {
            if ($this->reported($concepts, $name) || (($concepts[$name]['state'] ?? null) === 'unknown' && ($concepts[$name]['evidence'] ?? []) !== [])) {
                $blocked[] = $name;
            }
        }
        if ($this->reported($concepts, 'pregnancy') && ($this->reported($concepts, 'abdominal_pain') || $this->reported($concepts, 'burning_urination'))) {
            $blocked[] = 'pregnancy_with_abdominal_or_urinary_symptoms';
        }
        $byRule = ResearchEsiRuleCatalog::RESOURCE_ACCOUNTED;
        $symptoms = ResearchEsiRuleCatalog::resourceSymptoms();
        $accounted = [];
        foreach ($matches as $match) {
            foreach ($byRule[$match['rule_id']] ?? [] as $concept) {
                $accounted = [...$accounted, $concept, ...(ResearchEsiRuleCatalog::SPECIFIC_FORMS[$concept] ?? [])];
            }
        }
        foreach ($symptoms as $name) {
            if ($this->reported($concepts, $name) && ! in_array($name, $accounted, true)) {
                $blocked[] = $name;
            }
        }
        if ($matches !== [] && ! array_any($matches, fn (array $rule): bool => $rule['expected_resource_types'] !== []) && $this->reported($concepts, 'worsening')) {
            $blocked[] = 'worsening_without_resource_profile';
        }

        return array_values(array_unique($blocked));
    }

    private function result(array $trace, array $selected, ?int $priority, string $point, string $explanation, array $concepts): array
    {
        $unassessed = [];
        foreach (['breathing', ...ResearchEsiRuleCatalog::A_SIGNALS, ...ResearchEsiRuleCatalog::B_SIGNALS] as $name) {
            $state = $concepts[$name]['state'] ?? 'missing';
            if (in_array($state, ['missing', 'unknown', 'not_applicable'], true)) {
                $unassessed[$name] = $state;
            }
        }
        $resources = $selected === [] ? [] : array_values(array_unique(array_merge(...array_column($selected, 'expected_resource_types'))));
        $approval = $this->catalog->approval();

        return ['status' => $priority === null ? 'needs_review' : 'classified', 'priority' => $priority,
            'display_label' => $priority === null ? 'Needs Review' : ($priority === 3 ? EsiV4RuleCatalog::PROVISIONAL_THREE_LABEL : 'Provisional ESI '.$priority),
            'explanation' => $explanation.($priority === null ? '' : ' Research prediction from available reports; unreported danger signs are not ruled out. Nurse assessment is pending.'),
            'rule_version' => $this->version(), 'trace' => $trace, 'matched_rule_ids' => array_column($selected, 'rule_id'),
            'stopped_at' => $point, 'output_kind' => 'research_provisional_esi_prediction',
            'clinical_review_required' => true, 'clinically_validated' => false,
            'reviewer_approval_status' => $approval['status'] ?? 'not_clinically_validated',
            'reviewer_approval_record' => $approval === null ? null : $approval['record_id'],
            'flags' => ['fictional_research_only', 'patient_report_only', $approval === null ? 'unvalidated_research_adaptation' : 'nurse_reviewed_research_adaptation', 'decision_d_not_assessed', 'earlier_risk_not_clinically_excluded'],
            'unassessed_danger_signs' => $unassessed,
            'expected_resources' => ['basis' => 'fixed_research_profile_hypothesis', 'types' => $point === 'C' && $priority !== null ? $resources : [],
                'count_band' => $point === 'C' && $priority !== null ? min(2, count($resources)) : null, 'observed_or_ordered' => false],
            'decision_d' => ['status' => 'not_assessed', 'reason' => 'Measured vital signs are outside this prototype.'],
            'normalization_version' => NormalizedPatientFacts::version(), 'sources' => ResearchEsiRuleCatalog::SOURCES];
    }
}
